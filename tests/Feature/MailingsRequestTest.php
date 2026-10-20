<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Console\Stub;

/**
 * Emails to users (0.28) through whole requests: direct emails to chosen
 * users, mailings sent in batches within the hour's limit, unsubscribing,
 * and the permissions. Emails go to the "log" transport.
 */
final class MailingsRequestTest extends RequestTestCase
{
    private const MAIL = ['MAIL_TRANSPORT' => 'log', 'MAIL_FROM' => 'panel@example.test', 'APP_URL' => 'https://example.test', 'APP_KEY' => 'request-test-key-0123456789abcdef'];
    private const TABLES = ['admin_mailings', 'admin_mailing_recipients', 'admin_mail_optouts', 'admin_trash', 'admin_activity'];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (self::TABLES as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        foreach ([['mailings-migration', ['mailings_table' => 'admin_mailings', 'recipients_table' => 'admin_mailing_recipients', 'optouts_table' => 'admin_mail_optouts']], ['trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']]] as [$stub, $values]) {
            $file = self::$root . '/' . $stub . '.php';
            file_put_contents($file, Stub::render($stub, $values));
            (require $file)->up(new \FloCMS\Core\Database($pdo));
        }
        $pdo->exec("UPDATE users SET full_name = '<b>Dara</b> Aziz' WHERE id = 4");
        @unlink(self::mailLog());
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) { self::pdo()->exec('DROP TABLE IF EXISTS ' . $table); }
        parent::tearDown();
    }

    /** Root (Super Admin) may do everything; Ali gets users.email only when asked. */
    private static function as(array $session, array $extra = []): array
    {
        return ['session' => $session, 'admin_config' => ($extra['admin_config'] ?? []) + ['mailings' => ['per_batch' => 2, 'per_hour' => 3]], 'env' => self::MAIL] + $extra;
    }

    private static function emailer(): array
    {
        return self::as(self::ADMIN, ['permissions' => array_replace(self::PERMISSIONS, [2 => ['content.*', 'users.manage', 'users.email']])]);
    }

    private static function mailLog(): string { return self::$root . '/storage/logs/admin-mail.log'; }

    /** @return list<string> */
    private static function mails(): array
    {
        $log = is_file(self::mailLog()) ? (string) file_get_contents(self::mailLog()) : '';
        return array_values(array_filter(explode('==== ', $log)));
    }

    public function testADirectEmailGoesToTheChosenUsersAtOnce(): void
    {
        self::assertSame(403, self::request('GET', '/en/admin/mailings/compose?users=1', self::as(self::ADMIN))['status'], 'Admins need users.email');
        $page = self::request('GET', '/en/admin/mailings/compose?users=1,4', self::emailer());
        self::assertSame(200, $page['status'], $page['body']);
        self::assertSame(2, substr_count($page['body'], 'class="fa-to-chip" data-id='), 'two chips');
        self::assertStringContainsString('Send to 2 people', $page['body']);
        self::assertStringNotContainsString('value="everyone"', $page['body'], 'without users.mailing: chosen users only');

        $sent = self::post('/en/admin/mailings/compose', ['users' => '1,4', 'to' => 'users', 'subject' => 'Hello {first_name}', 'body' => '<p>Hi {name}, see <a href="https://example.test/x">this</a>.</p><script>alert(1)</script>', '_send' => '1'], self::emailer());
        self::assertTrue($sent['redirected'], $sent['body']);
        $mails = self::mails();
        self::assertCount(2, $mails);
        self::assertStringContainsString('To: sara@example.test', $mails[0]);
        self::assertStringContainsString('Subject: Hello Sara', $mails[0]);
        self::assertStringContainsString('Hi Sara, see this (https://example.test/x).', $mails[0]);
        self::assertStringNotContainsString('alert', implode('', $mails), 'the message is cleaned like rich text');
        self::assertStringNotContainsString('Unsubscribe', $mails[0], 'a direct email has no unsubscribe link');
        self::assertStringContainsString('Hi <b>Dara</b> Aziz', $mails[1], 'the name as text in the plain part');
        self::assertSame(2, (int) self::pdo()->query("SELECT COUNT(*) FROM admin_activity WHERE action = 'email_sent'")->fetchColumn());
        self::assertSame('Hello {first_name}', json_decode((string) self::pdo()->query("SELECT meta FROM admin_activity WHERE action = 'email_sent' LIMIT 1")->fetchColumn(), true)['subject'], 'the subject, never the text');
        // More than 20 chosen people is a mailing, which needs users.mailing
        $insert = self::pdo()->prepare('INSERT INTO users (full_name, login, email, role, status) VALUES (?, ?, ?, 0, 1)');
        foreach (range(5, 24) as $n) { $insert->execute(['Person ' . $n, 'p' . $n . '@example.test', 'p' . $n . '@example.test']); }
        $many = self::post('/en/admin/mailings/compose', ['users' => implode(',', range(1, 24)), 'to' => 'users', 'subject' => 'x', 'body' => '<p>x</p>', '_send' => '1'], self::emailer());
        self::assertFalse($many['redirected']);
        self::assertStringContainsString('You may email up to 20 chosen users', $many['body']);
        self::assertCount(2, self::mails());
    }

    public function testTheHtmlPartEscapesNamesAndStylesTheText(): void
    {
        $message = (function () {
            return \FloCMS\Admin\Mailings\MailMessage::html('<p>Hi {name}</p><a href="{email}">x</a>', \FloCMS\Admin\Mailings\MailMessage::values('d@example.test', '<b>Dara</b>'));
        })();
        self::assertStringContainsString('Hi &lt;b&gt;Dara&lt;/b&gt;', $message);
        self::assertStringContainsString('href="{email}"', $message, 'never inside a tag');
        self::assertStringContainsString('<p style="margin:0 0 12px">', $message);
    }

    public function testAMailingIsSentInBatchesWithinTheHoursLimit(): void
    {
        self::assertSame(403, self::request('GET', '/en/admin/mailings', self::emailer())['status'], 'Site → Mailings needs users.mailing');
        $page = self::request('GET', '/en/admin/mailings/compose', self::as(self::SUPER));
        self::assertSame(200, $page['status'], $page['body']);
        self::assertStringContainsString('value="everyone"', $page['body']);
        self::assertStringContainsString('4 people will get it.', $page['body']);

        $started = self::post('/en/admin/mailings/compose', ['to' => 'everyone', 'active_only' => '1', 'subject' => 'News for {first_name}', 'body' => '<p>New listings.</p>', '_send' => '1'], self::as(self::SUPER));
        self::assertTrue($started['redirected'], $started['body']);
        $mailing = self::pdo()->query('SELECT * FROM admin_mailings')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('sending', $mailing['status']);
        self::assertSame(4, (int) $mailing['total']);
        self::assertSame([], self::mails(), 'nothing is sent while saving');

        $show = self::request('GET', '/en/admin/mailings/show/' . $mailing['id'], self::as(self::SUPER));
        self::assertSame(200, $show['status'], $show['body']);
        self::assertStringContainsString('0 of 4 sent', $show['body']);
        self::assertStringContainsString('data-step-url=', $show['body']);

        // A step sends per_batch (2); the next one reaches per_hour (3); then it waits
        $json = ['headers' => ['Accept' => 'application/json']];
        $step = json_decode(self::post('/en/admin/mailings/step/' . $mailing['id'], [], self::as(self::SUPER) + $json)['body'], true);
        self::assertSame(['sent', 2], [$step['state'], $step['sent']]);
        $step = json_decode(self::post('/en/admin/mailings/step/' . $mailing['id'], [], self::as(self::SUPER) + $json)['body'], true);
        self::assertSame(3, $step['sent']);
        $step = json_decode(self::post('/en/admin/mailings/step/' . $mailing['id'], [], self::as(self::SUPER) + $json)['body'], true);
        self::assertSame('waiting', $step['state'], 'the hour\'s limit');
        $mails = self::mails();
        self::assertCount(3, $mails);
        self::assertStringContainsString('Subject: News for Sara', $mails[0]);
        self::assertMatchesRegularExpression('~Unsubscribe: https://example.test/en/admin/mailings/unsubscribe/[a-f0-9]{64}\.[a-f0-9]{32}~', $mails[0]);

        // An hour later the rest goes out (cron or the page)
        self::pdo()->exec("UPDATE admin_mailing_recipients SET sent_at = '2000-01-01 00:00:00'");
        $step = json_decode(self::post('/en/admin/mailings/step/' . $mailing['id'], [], self::as(self::SUPER) + $json)['body'], true);
        self::assertSame(['done', 'finished', 4], [$step['state'], $step['status'], $step['sent']]);
        self::assertSame(1, (int) self::pdo()->query("SELECT COUNT(*) FROM admin_activity WHERE action = 'mailing_finished'")->fetchColumn());
        $list = self::request('GET', '/en/admin/mailings', self::as(self::SUPER))['body'];
        self::assertStringContainsString('News for {first_name}', $list);
        self::assertStringContainsString('Finished', $list);
    }

    public function testUnsubscribingLeavesPeopleOutOfLaterMailings(): void
    {
        self::post('/en/admin/mailings/compose', ['to' => 'everyone', 'active_only' => '1', 'subject' => 'One', 'body' => '<p>x</p>', '_send' => '1'], self::as(self::SUPER));
        $id = (int) self::pdo()->query('SELECT id FROM admin_mailings')->fetchColumn();
        self::post('/en/admin/mailings/step/' . $id, [], self::as(self::SUPER));
        preg_match('~/admin/mailings/unsubscribe/([a-f0-9]{64}\.[a-f0-9]{32})~', self::mails()[0], $match);
        $guest = ['session' => ['_csrf_token' => 'tok'], 'env' => self::MAIL];
        $ask = self::request('GET', '/en/admin/mailings/unsubscribe/' . $match[1], $guest);
        self::assertSame(200, $ask['status'], $ask['body']);
        self::assertStringContainsString('Unsubscribe?', $ask['body']);
        self::assertSame(404, self::request('GET', '/en/admin/mailings/unsubscribe/' . substr($match[1], 0, -1) . '0', $guest)['status'], 'a changed token');
        $done = self::post('/en/admin/mailings/unsubscribe/' . $match[1], [], $guest);
        self::assertStringContainsString('You’re unsubscribed', $done['body']);
        self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_mail_optouts')->fetchColumn());
        self::assertSame(0, (int) self::pdo()->query("SELECT COUNT(*) FROM admin_mail_optouts WHERE email_hash LIKE '%@%'")->fetchColumn(), 'only a hash');

        $page = self::request('GET', '/en/admin/mailings/compose', self::as(self::SUPER))['body'];
        self::assertStringContainsString('3 people will get it.', $page);
        self::assertStringContainsString('1 who unsubscribed are left out.', $page);
        // A direct email still reaches them
        $direct = self::request('GET', '/en/admin/mailings/compose?users=1', self::as(self::SUPER))['body'];
        self::assertStringContainsString('Send', $direct);
    }

    public function testPauseResumeCancelAndRetry(): void
    {
        self::post('/en/admin/mailings/compose', ['to' => 'everyone', 'active_only' => '1', 'subject' => 'Two', 'body' => '<p>x</p>', '_send' => '1'], self::as(self::SUPER));
        $id = (int) self::pdo()->query('SELECT id FROM admin_mailings')->fetchColumn();
        $status = static fn (): string => (string) self::pdo()->query('SELECT status FROM admin_mailings WHERE id = ' . $id)->fetchColumn();
        self::assertTrue(self::post('/en/admin/mailings/pause/' . $id, [], self::as(self::SUPER))['redirected']);
        self::assertSame('paused', $status());
        self::post('/en/admin/mailings/step/' . $id, [], self::as(self::SUPER));
        self::assertSame([], self::mails(), 'a paused mailing sends nothing');
        self::post('/en/admin/mailings/resume/' . $id, [], self::as(self::SUPER));
        self::assertSame('sending', $status());
        self::post('/en/admin/mailings/cancel/' . $id, [], self::as(self::SUPER));
        self::assertSame('cancelled', $status());
        self::post('/en/admin/mailings/step/' . $id, [], self::as(self::SUPER));
        self::assertSame([], self::mails());
        self::assertSame(405, self::request('GET', '/en/admin/mailings/cancel/' . $id, self::as(self::SUPER))['status']);
    }

    public function testAStepNeverSendsAnEmailTwiceAndWaitsAfterAFailure(): void
    {
        self::post('/en/admin/mailings/compose', ['to' => 'everyone', 'active_only' => '1', 'subject' => 'Three', 'body' => '<p>x</p>', '_send' => '1'], self::as(self::SUPER));
        $id = (int) self::pdo()->query('SELECT id FROM admin_mailings')->fetchColumn();
        $json = ['headers' => ['Accept' => 'application/json']];
        // Another step holds the lock: this one sends nothing
        self::pdo()->exec("UPDATE admin_mailings SET locked_until = '" . (time() + 60) . "|other' WHERE id = " . $id);
        self::assertSame('busy', json_decode(self::post('/en/admin/mailings/step/' . $id, [], self::as(self::SUPER) + $json)['body'], true)['state']);
        self::assertSame([], self::mails());
        // A step that ended halfway: its email may have gone, so it is marked failed, not sent again
        self::pdo()->exec("UPDATE admin_mailings SET locked_until = NULL WHERE id = " . $id);
        $first = (int) self::pdo()->query('SELECT MIN(id) FROM admin_mailing_recipients')->fetchColumn();
        self::pdo()->exec("UPDATE admin_mailing_recipients SET status = 'sending' WHERE id = " . $first);
        self::post('/en/admin/mailings/step/' . $id, [], self::as(self::SUPER) + $json);
        self::assertSame('failed', self::pdo()->query('SELECT status FROM admin_mailing_recipients WHERE id = ' . $first)->fetchColumn());
        self::assertCount(2, self::mails(), 'the next two, not the interrupted one');
        // Retry keeps a paused mailing paused
        self::post('/en/admin/mailings/pause/' . $id, [], self::as(self::SUPER));
        self::post('/en/admin/mailings/retry/' . $id, [], self::as(self::SUPER));
        self::assertSame('paused', self::pdo()->query('SELECT status FROM admin_mailings WHERE id = ' . $id)->fetchColumn());
        self::assertSame(0, (int) self::pdo()->query("SELECT COUNT(*) FROM admin_mailing_recipients WHERE status = 'failed'")->fetchColumn());
    }

    public function testMailingsNeedAnAppKeyAndTheUsersPage(): void
    {
        $noKey = array_replace(self::as(self::SUPER), ['env' => ['APP_KEY' => ''] + self::MAIL]);
        $refused = self::post('/en/admin/mailings/compose', ['to' => 'everyone', 'active_only' => '1', 'subject' => 'Four', 'body' => '<p>x</p>', '_send' => '1'], $noKey);
        self::assertStringContainsString('Mailings need APP_KEY', $refused['body']);
        self::assertSame(0, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_mailings')->fetchColumn());
        $emailOnly = self::as(self::ADMIN, ['permissions' => array_replace(self::PERMISSIONS, [2 => ['content.*', 'users.email']])]);
        self::assertSame(403, self::request('GET', '/en/admin/mailings/compose?users=1,4', $emailOnly)['status'], 'names and addresses need the Users page');
    }

    public function testTheToFieldWorksWithoutJavaScriptAndAsks(): void
    {
        // A group from the menu replaces the chosen users; × on its chip leaves nobody
        $page = self::post('/en/admin/mailings/compose', ['users' => '1,4', 'to' => 'users', '_to' => 'role:1', 'subject' => 'Kept', 'body' => ''], self::as(self::SUPER));
        self::assertStringContainsString('data-group="role:1"', $page['body']);
        self::assertStringContainsString('value="Kept"', $page['body'], 'what was typed stays');
        self::assertStringNotContainsString('fa-alert-danger', $page['body'], 'no errors before sending');
        $page = self::post('/en/admin/mailings/compose', ['to' => 'role', 'role' => '1', '_remove' => 'group', 'subject' => '', 'body' => ''], self::as(self::SUPER));
        self::assertStringContainsString('0 people will get it.', $page['body']);
        $page = self::post('/en/admin/mailings/compose', ['users' => '1,4', 'to' => 'users', '_remove' => '4', 'subject' => '', 'body' => ''], self::as(self::SUPER));
        self::assertSame(1, substr_count($page['body'], 'class="fa-to-chip" data-id='));

        $json = ['headers' => ['Accept' => 'application/json']];
        $found = json_decode(self::request('GET', '/en/admin/mailings/people?q=dar', self::as(self::SUPER) + $json)['body'], true);
        self::assertSame([4], array_column($found['people'], 'id'));
        self::assertStringNotContainsString('<b>', $found['people'][0]['avatar'], 'names are escaped');
        $count = json_decode(self::request('GET', '/en/admin/mailings/count?to=users&users=1,4', self::as(self::SUPER) + $json)['body'], true);
        self::assertSame([2, true, 'Send email'], [$count['count'], $count['direct'], $count['title']]);
        $count = json_decode(self::request('GET', '/en/admin/mailings/count?to=everyone&active_only=1', self::as(self::SUPER) + $json)['body'], true);
        self::assertSame([4, false], [$count['count'], $count['direct']]);
        $emailOnly = self::as(self::ADMIN, ['permissions' => array_replace(self::PERMISSIONS, [2 => ['content.*', 'users.email']])]);
        self::assertSame(403, self::request('GET', '/en/admin/mailings/people?q=a', $emailOnly)['status']);
        self::assertSame(403, self::request('GET', '/en/admin/mailings/count?to=everyone', $emailOnly)['status']);
        // Without users.mailing a group is never used: chosen users only
        $count = json_decode(self::request('GET', '/en/admin/mailings/count?to=everyone&users=1', self::emailer() + $json)['body'], true);
        self::assertSame(1, $count['count']);
    }
}
