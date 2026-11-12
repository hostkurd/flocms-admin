<?php
declare(strict_types=1);

namespace FloCMS\Admin\Mail;

use FloCMS\Admin\Admin;
use FloCMS\Core\Config;
use FloCMS\Core\Mail\Mailer as CoreMailer;

/**
 * Sends the panel's emails (password reset links, security notices,
 * mailings). Since 0.43 the work is done by core's mailer (flocms-core 2.10,
 * \FloCMS\Core\Mail\Mailer), with the same MAIL_* settings in .env:
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
 * The panel keeps what it did before: "log" writes the panel's messages to
 * storage/logs/admin-mail.log (or the site's Config 'mail.log_path' when set), and 'mail' => ['send' => fn (Message $message): void => …]
 * in config/admin.php replaces sending for them. Message, MailException and
 * SmtpTransport in this namespace are aliases of core's classes.
 */
final class Mailer
{
    public const TRANSPORTS = CoreMailer::TRANSPORTS;

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

        // The panel's name as the sender name, unless MAIL_FROM_NAME or the message says otherwise
        if (($message->fromName ?? '') === '' && !self::fromNameSet()) {
            $message = new Message($message->to, $message->subject, $message->text, $message->html, $message->from, self::brand(), $message->toName, $message->headers);
        }

        // The panel's log file, unless the site chose one
        $logPath = Config::get('mail.log_path');
        if ($logPath === null) {
            Config::set('mail.log_path', self::logPath());
        }
        try {
            CoreMailer::send($message);
        } finally {
            if ($logPath === null) {
                unset(Config::$settings['mail.log_path']);
            }
        }
    }

    /**
     * The mail settings from .env, as core reads them, with the panel's brand
     * as the default sender name.
     *
     * @return array{transport: string, host: string, port: int, encryption: string, username: string, password: string, from: string, from_name: string, timeout: int}
     */
    public static function settings(): array
    {
        $settings = CoreMailer::settings();
        if (!self::fromNameSet()) {
            $settings['from_name'] = self::brand();
        }
        return $settings;
    }

    /** What admin:doctor reports: null when sending can work, else why not. */
    public static function problem(): ?string
    {
        if (is_callable(self::$sender ?? Admin::config()->mail()['send'])) { return null; }
        return CoreMailer::problem();
    }

    public static function logPath(): string
    {
        return rtrim(Admin::config()->root(), '/\\') . '/storage/logs/admin-mail.log';
    }

    /** The panel's brand on one line (a header can't hold a line break). */
    private static function brand(): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', Admin::config()->brand()));
    }

    private static function fromNameSet(): bool
    {
        $value = \FloCMS\Core\Env::get('MAIL_FROM_NAME');
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
