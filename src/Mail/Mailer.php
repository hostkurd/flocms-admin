<?php
declare(strict_types=1);

namespace FloCMS\Admin\Mail;

use FloCMS\Admin\Admin;
use FloCMS\Core\Env;

/**
 * Sends the panel's emails (password reset links) without a third-party
 * package. Settings come from .env:
 *
 *     MAIL_TRANSPORT=smtp            mail (PHP mail(), the default), smtp or log
 *     MAIL_HOST=smtp.example.com
 *     MAIL_PORT=587
 *     MAIL_ENCRYPTION=tls            tls (STARTTLS), ssl, or none
 *     MAIL_USERNAME=…
 *     MAIL_PASSWORD=…
 *     MAIL_FROM=no-reply@example.com (default PRIMARY_EMAIL)
 *     MAIL_FROM_NAME="My site"       (default the panel's brand)
 *
 * "log" writes each message to storage/logs/admin-mail.log instead of sending
 * it: for a computer without mail, never for a live site (the log holds the
 * reset links). A site replaces sending altogether with
 * 'mail' => ['send' => fn (Message $message): void => …] in config/admin.php.
 */
final class Mailer
{
    public const TRANSPORTS = ['mail', 'smtp', 'log'];

    /** @var (callable(Message): void)|null */
    private static $sender = null;

    /** Replace sending (tests, or a site's own mailer). null restores the default. */
    public static function sendUsing(?callable $sender): void
    {
        self::$sender = $sender;
    }

    /** @throws MailException when the message could not be handed over */
    public static function send(Message $message): void
    {
        $sender = self::$sender ?? Admin::config()->mail()['send'];
        if (is_callable($sender)) {
            try {
                $sender($message);
            } catch (\Throwable $error) {
                throw new MailException('The site\'s mail sender failed: ' . $error->getMessage(), 0, $error);
            }
            return;
        }

        $settings = self::settings();
        $from = $message->from ?? $settings['from'];
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('No sender address: set MAIL_FROM (or PRIMARY_EMAIL) in .env.');
        }
        $fromName = $message->fromName ?? $settings['from_name'];
        $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
        $mime = $message->mime($from, $fromName, $domain);

        match ($settings['transport']) {
            'smtp' => (new SmtpTransport($settings))->send($message, $from, $mime),
            'log' => self::log($message, $mime),
            default => self::phpMail($message, $from, $mime),
        };
    }

    /**
     * The mail settings from .env, with the password left out unless asked.
     *
     * @return array{transport: string, host: string, port: int, encryption: string, username: string, password: string, from: string, from_name: string, timeout: int}
     */
    public static function settings(): array
    {
        $env = static fn (string $key, string $default = ''): string => is_scalar($value = Env::get($key)) && trim((string) $value) !== '' ? trim((string) $value) : $default;
        $transport = strtolower($env('MAIL_TRANSPORT', 'mail'));
        $encryption = strtolower($env('MAIL_ENCRYPTION', 'tls'));
        return [
            'transport' => in_array($transport, self::TRANSPORTS, true) ? $transport : 'mail',
            'host' => $env('MAIL_HOST', 'localhost'),
            'port' => (int) $env('MAIL_PORT', $encryption === 'ssl' ? '465' : '587'),
            'encryption' => in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls',
            'username' => $env('MAIL_USERNAME'),
            'password' => $env('MAIL_PASSWORD'),
            'from' => $env('MAIL_FROM', $env('PRIMARY_EMAIL')),
            'from_name' => $env('MAIL_FROM_NAME', Admin::config()->brand()),
            'timeout' => max(3, min(60, (int) $env('MAIL_TIMEOUT', '15'))),
        ];
    }

    /** What admin:doctor reports: null when sending can work, else why not. */
    public static function problem(): ?string
    {
        if (is_callable(self::$sender ?? Admin::config()->mail()['send'])) { return null; }
        $settings = self::settings();
        if ($settings['from'] === '' || filter_var($settings['from'], FILTER_VALIDATE_EMAIL) === false) {
            return 'no sender address: set MAIL_FROM (or PRIMARY_EMAIL) in .env';
        }
        if ($settings['transport'] === 'mail' && !function_exists('mail')) {
            return 'PHP mail() is disabled on this server: set MAIL_TRANSPORT=smtp and the MAIL_* settings in .env';
        }
        if ($settings['transport'] === 'smtp' && $settings['host'] === '') {
            return 'MAIL_TRANSPORT=smtp needs MAIL_HOST';
        }
        return null;
    }

    public static function logPath(): string
    {
        return rtrim(Admin::config()->root(), '/\\') . '/storage/logs/admin-mail.log';
    }

    /** @param array{headers: list<string>, body: string} $mime */
    private static function phpMail(Message $message, string $from, array $mime): void
    {
        if (!function_exists('mail')) {
            throw new MailException('PHP mail() is disabled on this server: use MAIL_TRANSPORT=smtp.');
        }
        $to = Message::mailbox($message->to, $message->toName);
        $parameters = preg_match('/^[A-Za-z0-9._%+@-]+$/', $from) === 1 ? '-f' . $from : '';
        $sent = @mail($to, Message::encodeHeader($message->subject), $mime['body'], implode("\r\n", $mime['headers']), $parameters);
        if ($sent !== true) {
            $error = error_get_last();
            throw new MailException('PHP mail() did not accept the message' . ($error !== null ? ': ' . $error['message'] : '.'));
        }
    }

    /** @param array{headers: list<string>, body: string} $mime */
    private static function log(Message $message, array $mime): void
    {
        $file = self::logPath();
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new MailException('Could not create ' . dirname($file) . '.');
        }
        $entry = '==== ' . date('Y-m-d H:i:s') . "\nTo: " . $message->to . "\nSubject: " . $message->subject . "\n\n" . $message->text . "\n\n";
        if (@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new MailException('Could not write ' . $file . '.');
        }
    }
}
