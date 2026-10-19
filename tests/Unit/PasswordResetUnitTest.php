<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Auth\LoginView;
use FloCMS\Admin\Auth\PasswordReset;
use FloCMS\Admin\Auth\PasswordStamp;
use FloCMS\Admin\Mail\MailException;
use FloCMS\Admin\Mail\Mailer;
use FloCMS\Admin\Mail\Message;
use FloCMS\Admin\Mail\SmtpTransport;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Support\ClientIp;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;
use FloCMS\Core\Config;
use FloCMS\Core\Database;

/** Forgot password (0.26): messages, the SMTP client, client IPs, session stamps and settings. */
final class PasswordResetUnitTest extends TestCase
{
    private array $session;

    public static function setUpBeforeClass(): void
    {
        if (!defined('VIEWS_PATH')) { define('VIEWS_PATH', sys_get_temp_dir()); }
        if (!defined('DS')) { define('DS', DIRECTORY_SEPARATOR); }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $_SESSION ?? [];
        $_SESSION = ['_csrf_token' => 'tok'];
        PasswordReset::reset();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        Mailer::sendUsing(null);
        App::$db = null;
        PasswordReset::reset();
        foreach (['MAIL_TRANSPORT', 'MAIL_FROM', 'MAIL_FROM_NAME', 'MAIL_ENCRYPTION', 'MAIL_PORT', 'MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD'] as $key) { unset($_ENV[$key]); putenv($key); }
        Config::set('trusted_proxies', []);
        parent::tearDown();
    }

    public function testMessagesRefuseHeaderInjection(): void
    {
        foreach ([['a@example.test', "Hi\r\nBcc: x@example.test"], ["a@example.test\nBcc: x@example.test", 'Hi'], ['not-an-address', 'Hi']] as [$to, $subject]) {
            try {
                new Message($to, $subject, 'Body');
                self::fail('accepted ' . json_encode([$to, $subject]));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMimeHasATextAndAnHtmlPartInUtf8(): void
    {
        $message = new Message('sara@example.test', 'إعادة تعيين كلمة المرور', "مرحبًا\nسطر", '<p>مرحبًا</p>', toName: 'Sara');
        $mime = $message->mime('panel@example.test', 'لوحة', 'example.test');
        $headers = implode("\n", $mime['headers']);

        self::assertStringContainsString('From: =?UTF-8?B?', $headers);
        self::assertStringContainsString('<panel@example.test>', $headers);
        self::assertStringContainsString('multipart/alternative', $headers);
        self::assertStringContainsString('Message-ID: <', $headers);
        self::assertSame(2, substr_count($mime['body'], 'charset=UTF-8'));
        preg_match_all('/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+?)\r\n--/', $mime['body'], $parts);
        self::assertSame("مرحبًا\r\nسطر", base64_decode(str_replace("\r\n", '', $parts[1][0])));
        self::assertStringStartsWith('=?UTF-8?B?', Message::encodeHeader('إعادة'));
        self::assertSame('Plain subject', Message::encodeHeader('Plain subject'));
        self::assertSame('"Sara J." <s@example.test>', Message::mailbox('s@example.test', 'Sara J.'));
        // A name can never add an address
        self::assertSame('"evil@attacker.test, Bob" <v@example.test>', Message::mailbox('v@example.test', 'evil@attacker.test, Bob'));
        self::assertSame('"Doe, \\"J\\" <x>" <v@example.test>', Message::mailbox('v@example.test', 'Doe, "J" <x>'));
    }

    public function testTheLogTransportAndTheSendHook(): void
    {
        $root = sys_get_temp_dir() . '/flo-admin-mail-' . bin2hex(random_bytes(4));
        Admin::useConfig(['brand' => 'Panel'], true, $root);
        $_ENV['MAIL_TRANSPORT'] = 'log';
        $_ENV['MAIL_FROM'] = 'panel@example.test';
        Mailer::send(new Message('sara@example.test', 'Hello', 'Line one'));
        self::assertStringContainsString("To: sara@example.test\nSubject: Hello\n\nLine one", (string) file_get_contents($root . '/storage/logs/admin-mail.log'));

        $sent = [];
        Mailer::sendUsing(static function (Message $message) use (&$sent): void { $sent[] = $message->subject; });
        Mailer::send(new Message('sara@example.test', 'Hooked', 'Body'));
        self::assertSame(['Hooked'], $sent);

        Mailer::sendUsing(static function (): void { throw new \RuntimeException('down'); });
        $this->expectException(MailException::class);
        Mailer::send(new Message('sara@example.test', 'Hooked', 'Body'));
        exec('rm -rf ' . escapeshellarg($root));
    }

    public function testMailSettingsAndProblems(): void
    {
        Admin::useConfig(['brand' => 'Panel']);
        $_ENV['MAIL_FROM'] = '';
        $_ENV['PRIMARY_EMAIL'] = 'info@example.test';
        self::assertSame('info@example.test', Mailer::settings()['from']);
        self::assertSame('Panel', Mailer::settings()['from_name']);
        self::assertSame('mail', Mailer::settings()['transport']);
        $_ENV['MAIL_TRANSPORT'] = 'SMTP';
        $_ENV['MAIL_ENCRYPTION'] = 'ssl';
        self::assertSame(['smtp', 465, 'ssl'], [Mailer::settings()['transport'], Mailer::settings()['port'], Mailer::settings()['encryption']]);
        $_ENV['MAIL_TRANSPORT'] = 'carrier-pigeon';
        self::assertSame('mail', Mailer::settings()['transport']);
        unset($_ENV['PRIMARY_EMAIL']);
        self::assertStringContainsString('MAIL_FROM', (string) Mailer::problem());
    }

    public function testTheSmtpClientTalksToAServer(): void
    {
        [$server, $port, $log] = self::fakeSmtpServer();
        try {
            $transport = new SmtpTransport(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'user', 'password' => 'p4ss', 'timeout' => 5]);
            $message = new Message('sara@example.test', 'Reset', "Hello\n.leading dot", '<p>Hi</p>');
            $transport->send($message, 'panel@example.test', $message->mime('panel@example.test', 'Panel', 'example.test'));
        } finally {
            proc_close($server);
        }
        $dialog = (string) file_get_contents($log);
        self::assertStringContainsString('EHLO ', $dialog);
        self::assertStringContainsString('AUTH PLAIN ' . base64_encode("\0user\0p4ss"), $dialog);
        self::assertStringContainsString('MAIL FROM:<panel@example.test>', $dialog);
        self::assertStringContainsString('RCPT TO:<sara@example.test>', $dialog);
        self::assertStringContainsString("To: sara@example.test\r\nSubject: Reset", $dialog);
        self::assertStringContainsString('QUIT', $dialog);
        @unlink($log);
    }

    public function testSmtpErrorsDoNotShowThePassword(): void
    {
        [$server, $port, $log] = self::fakeSmtpServer(refuseAuth: true);
        try {
            $transport = new SmtpTransport(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'user', 'password' => 'secret-p4ss', 'timeout' => 5]);
            $message = new Message('sara@example.test', 'Reset', 'Hello');
            $transport->send($message, 'panel@example.test', $message->mime('panel@example.test', 'Panel', 'example.test'));
            self::fail('sent');
        } catch (MailException $error) {
            self::assertStringContainsString('refused the login', $error->getMessage());
            self::assertStringNotContainsString('secret-p4ss', $error->getMessage());
        } finally {
            proc_close($server);
            @unlink($log);
        }
    }

    public function testTheSmtpPasswordIsNeverSentUnencryptedToAnotherHost(): void
    {
        $transport = new SmtpTransport(['host' => '192.0.2.1', 'port' => 25, 'encryption' => 'none', 'username' => 'user', 'password' => 'p', 'timeout' => 3]);
        $message = new Message('sara@example.test', 'Reset', 'Hello');
        try {
            $transport->send($message, 'panel@example.test', $message->mime('panel@example.test', 'Panel', 'example.test'));
            self::fail('sent');
        } catch (MailException $error) {
            self::assertStringContainsString('without encryption', $error->getMessage(), 'refused before connecting');
        }
        [$server, $port, $log] = self::fakeSmtpServer();
        try {
            $remote = new SmtpTransport(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'user', 'password' => 'p', 'timeout' => 5]);
            $remote->send($message, 'panel@example.test', $message->mime('panel@example.test', 'Panel', 'example.test'));
            self::assertStringContainsString('AUTH PLAIN', (string) file_get_contents($log), 'a local relay may take the login without TLS');
        } finally {
            proc_close($server);
            @unlink($log);
        }
    }

    public function testClientIpTrustsOnlyListedProxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, 10.0.0.9';
        self::assertSame('10.0.0.5', ClientIp::get());
        Config::set('trusted_proxies', ['10.0.0.0/24']);
        self::assertSame('198.51.100.20', ClientIp::get());
        Config::set('trusted_proxies', ['10.0.0.5']);
        self::assertSame('10.0.0.9', ClientIp::get());
        self::assertTrue(ClientIp::matchesAny('2001:db8::1', ['2001:db8::/32']));
        self::assertFalse(ClientIp::matchesAny('2001:db9::1', ['2001:db8::/32']));
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testPasswordStampsMarkSessionsAndNoticeChanges(): void
    {
        Admin::useConfig([]);
        $user = ['id' => 1, 'password' => password_hash('one', PASSWORD_DEFAULT)];
        self::assertTrue(PasswordStamp::check($user));
        self::assertSame(PasswordStamp::of($user['password']), $_SESSION[PasswordStamp::SESSION_KEY]);
        self::assertTrue(PasswordStamp::check($user));

        $changed = ['password' => password_hash('two', PASSWORD_DEFAULT)] + $user;
        self::assertFalse(PasswordStamp::check($changed));
        PasswordStamp::restamp($changed['password']);
        self::assertTrue(PasswordStamp::check($changed), 'the session that changed it stays');
        self::assertTrue(PasswordStamp::check(['id' => 1]), 'a loader without the password column changes nothing');
    }

    public function testActorAsCreditsWorkToSomeone(): void
    {
        $_SESSION['user_id'] = 9;
        self::assertSame([4, 'Sara'], Actor::as(4, ' Sara ', static fn (): array => [Actor::id(), Actor::name()]));
        self::assertSame(9, Actor::id());
    }

    public function testSettingsAndTheLoginLink(): void
    {
        Admin::useConfig(['login_theme' => true]);
        self::assertFalse(Admin::config()->passwordReset()['enabled'], 'existing configurations opt in');
        self::assertStringNotContainsString('password/forgot', (string) LoginView::render());

        Admin::useConfig(['login_theme' => true, 'password_reset' => ['expires' => 99999, 'per_email' => 0]]);
        $settings = Admin::config()->passwordReset();
        self::assertTrue($settings['enabled']);
        self::assertSame([1440, 1, 10, 'admin_password_resets'], [$settings['expires'], $settings['per_email'], $settings['per_ip'], $settings['table']]);

        App::$db = new Database(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        PasswordReset::reset();
        self::assertSame('missing', PasswordReset::status());
        self::assertStringNotContainsString('password/forgot', (string) LoginView::render(), 'no link before the table exists');

        App::$db->query('CREATE TABLE admin_password_resets (id INTEGER PRIMARY KEY, kind TEXT, user_id INTEGER, email_hash TEXT, ip_hash TEXT, token_hash TEXT, created_at TEXT, expires_at TEXT, used_at TEXT)');
        PasswordReset::reset();
        \FloCMS\Core\Session::setFlash('Your password has been changed.', 'success');
        $html = (string) LoginView::render();
        self::assertStringContainsString('/en/admin/password/forgot', $html);
        self::assertStringContainsString('fa-login-alert is-success', $html);
        self::assertStringContainsString('role="status"', $html);

        Admin::useConfig(['login_theme' => true, 'password_reset' => false]);
        PasswordReset::reset();
        self::assertSame('off', PasswordReset::status());
    }

    /** @return array{0: resource, 1: int, 2: string} */
    private static function fakeSmtpServer(bool $refuseAuth = false): array
    {
        $log = tempnam(sys_get_temp_dir(), 'smtp');
        $script = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $no, $err);
echo explode(':', stream_socket_get_name($server, false))[1], "\n";
$client = stream_socket_accept($server, 10);
$log = fopen($argv[1], 'w');
$say = static fn (string $line) => fwrite($client, $line . "\r\n");
$say('220 fake ESMTP');
$data = false;
while (($line = fgets($client)) !== false) {
    fwrite($log, $line);
    if ($data) { if ($line === ".\r\n") { $data = false; $say('250 queued'); } continue; }
    $command = strtoupper(substr($line, 0, 4));
    if ($command === 'EHLO') { $say('250-fake'); $say('250 AUTH PLAIN LOGIN'); }
    elseif ($command === 'AUTH') { $say($argv[2] === '1' ? '535 no' : '235 ok'); }
    elseif ($command === 'DATA') { $say('354 go'); $data = true; }
    elseif ($command === 'QUIT') { $say('221 bye'); break; }
    else { $say('250 ok'); }
}
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script, $log, $refuseAuth ? '1' : '0'], [1 => ['pipe', 'w']], $pipes);
        $port = (int) trim((string) fgets($pipes[1]));
        self::assertGreaterThan(0, $port);
        return [$process, $port, $log];
    }
}
