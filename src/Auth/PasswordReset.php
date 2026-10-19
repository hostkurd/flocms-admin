<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Mail\MailException;
use FloCMS\Admin\Mail\Mailer;
use FloCMS\Admin\Mail\Message;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Users\UsersResource;
use FloCMS\Core\App;
use FloCMS\Core\Database;
use FloCMS\Core\Env;

/**
 * Forgot password (0.26): reset requests, one-time links and the new password.
 *
 * Only hashes are stored (of the address, the IP and the token). A request
 * always gets the same answer; an email goes out only for an active account
 * and within the limits. A link works once, for 'expires' minutes, and a newer
 * link replaces older ones. Saving the new password ends the account's other
 * sessions (PasswordStamp).
 */
final class PasswordReset
{
    public const USERS_TABLE = 'users';

    private static ?string $status = null;
    private static ?Database $statusFor = null;

    /** 'off' (config), 'missing' (no table or database yet) or 'ready'. */
    public static function status(?Database $db = null): string
    {
        $config = Admin::config();
        if (!$config->isInstalled() || !$config->passwordReset()['enabled']) { return 'off'; }
        $db ??= App::db();
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            try {
                $db->query('SELECT `id`, `kind`, `user_id`, `email_hash`, `ip_hash`, `token_hash`, `created_at`, `expires_at`, `used_at` FROM `' . self::table() . '` WHERE 1 = 0');
                self::$status = 'ready';
            } catch (\Throwable) {
                self::$status = 'missing';
            }
        }
        return self::$status;
    }

    public static function available(?Database $db = null): bool
    {
        return self::status($db) === 'ready';
    }

    public static function reset(): void
    {
        self::$status = null;
        self::$statusFor = null;
    }

    public static function table(): string
    {
        return Admin::config()->passwordReset()['table'];
    }

    /**
     * Record a request and, for an active account within the limits, create
     * a link. Returns what to email (or null): the caller sends it after the
     * answer has gone out, so both cases take the same time for the visitor.
     *
     * @return array{user: object, token: string}|null
     */
    public static function request(string $email, string $ip, ?Database $db = null): ?array
    {
        $db ??= App::db();
        if ($db === null || !self::available($db)) { return null; }
        $settings = Admin::config()->passwordReset();
        $email = mb_strtolower(trim($email));
        $emailHash = self::hash('email', $email);
        $ipHash = self::hash('ip', $ip);
        $now = time();
        self::purge($db, $now);

        $hourAgo = date('Y-m-d H:i:s', $now - 3600);
        $byEmail = $db->table(self::table())->where('kind', '=', 'request')->where('email_hash', '=', $emailHash)->where('created_at', '>', $hourAgo)->count();
        $byIp = $db->table(self::table())->where('kind', '=', 'request')->where('ip_hash', '=', $ipHash)->where('created_at', '>', $hourAgo)->count();
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? self::userByEmail($db, $email) : null;
        $active = $user !== null && (int) ($user->status ?? 0) === UsersResource::ACTIVE;
        $row = ['kind' => 'request', 'user_id' => $user !== null ? (int) $user->id : null, 'email_hash' => $emailHash, 'ip_hash' => $ipHash,
            'token_hash' => null, 'created_at' => date('Y-m-d H:i:s', $now), 'expires_at' => null, 'used_at' => null];

        if ($byEmail >= $settings['per_email'] || $byIp >= $settings['per_ip']) {
            $db->table(self::table())->insert($row);
            // One entry when a limit is reached, not one per further request
            if ($byEmail === $settings['per_email'] || $byIp === $settings['per_ip']) {
                self::log('password_reset_limited', $user, ['limit' => $byIp >= $settings['per_ip'] ? 'ip' : 'email'], $db);
            }
            return null;
        }

        if (!$active) {
            $db->table(self::table())->insert($row);
            self::log('password_reset_requested', $user, $user === null ? ['unknown' => 1] : ['inactive' => 1], $db);
            return null;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->transaction(static function (Database $db) use ($row, $token, $user, $now, $settings): void {
            // A newer link replaces older ones
            $db->table(self::table())->where('user_id', '=', (int) $user->id)->where('kind', '=', 'request')
                ->whereNotNull('token_hash')->whereNull('used_at')->where('expires_at', '>', date('Y-m-d H:i:s', $now))
                ->update(['expires_at' => date('Y-m-d H:i:s', $now)]);
            $db->table(self::table())->insert(['token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', $now + $settings['expires'] * 60)] + $row);
        });
        self::log('password_reset_requested', $user, [], $db);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * The account a link belongs to, when the link still works; null for a
     * wrong, used or expired link (recorded as an attempt, limited per IP).
     */
    public static function find(string $token, string $ip, ?Database $db = null): ?object
    {
        $db ??= App::db();
        if ($db === null || !self::available($db)) { return null; }
        $ipHash = self::hash('ip', $ip);
        $now = time();
        $attempts = $db->table(self::table())->where('kind', '=', 'attempt')->where('ip_hash', '=', $ipHash)
            ->where('created_at', '>', date('Y-m-d H:i:s', $now - 3600))->count();
        if ($attempts >= Admin::config()->passwordReset()['per_ip']) { return null; }

        $user = self::userForToken($db, $token, $now);
        if ($user === null) {
            $db->table(self::table())->insert(['kind' => 'attempt', 'user_id' => null, 'email_hash' => null, 'ip_hash' => $ipHash,
                'token_hash' => null, 'created_at' => date('Y-m-d H:i:s', $now), 'expires_at' => null, 'used_at' => null]);
        }
        return $user;
    }

    /**
     * Save the new password and use up the link; null when the link stopped
     * working meanwhile. Other sessions of the account end (PasswordStamp).
     */
    public static function complete(string $token, string $password, ?Database $db = null): ?object
    {
        $db ??= App::db();
        if ($db === null || !self::available($db)) { return null; }
        $now = time();
        $user = $db->transaction(static function (Database $db) use ($token, $password, $now): ?object {
            $user = self::userForToken($db, $token, $now);
            if ($user === null) { return null; }
            $stamp = date('Y-m-d H:i:s', $now);
            // Used up here, unless a request at the same moment used it first
            $claimed = $db->query('UPDATE `' . self::table() . '` SET `used_at` = ? WHERE `token_hash` = ? AND `used_at` IS NULL', [$stamp, hash('sha256', $token)])->rowCount();
            if ($claimed !== 1) { return null; }
            $db->table(self::table())->where('user_id', '=', (int) $user->id)->where('kind', '=', 'request')->whereNull('used_at')
                ->where('expires_at', '>', $stamp)->update(['expires_at' => $stamp]);
            $db->table(self::USERS_TABLE)->where('id', '=', (int) $user->id)->update(['password' => password_hash($password, PASSWORD_DEFAULT)]);
            return $user;
        });
        if ($user !== null) {
            self::log('password_reset', $user, [], $db);
        }
        return $user;
    }

    /** The reset link sent by email (absolute: APP_URL must be a full address). */
    public static function link(string $token): string
    {
        $url = Admin::url('password/reset/' . $token);
        // A site whose SITE_URI is a path: start the link with APP_URL
        $app = (string) Env::get('APP_URL', '');
        if (preg_match('#^https?://#i', $url) !== 1 && preg_match('#^(https?://[^/]+)#i', $app, $origin) === 1) {
            $url = $origin[1] . '/' . ltrim($url, '/');
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new MailException('APP_URL must be a full address (https://…) to put a link in an email.');
        }
        return $url;
    }

    /** Email the link. Throws MailException when it could not be sent. */
    public static function sendLink(object $user, string $token): void
    {
        $minutes = Admin::config()->passwordReset()['expires'];
        Mailer::send(self::message($user, 'reset', self::link($token), ['minutes' => $minutes]));
    }

    /** Email the "your password was changed" note. */
    public static function sendChanged(object $user): void
    {
        Mailer::send(self::message($user, 'changed', Admin::url('users/login'), []));
    }

    /**
     * Send after the answer has gone out (PHP-FPM), else at the end of the
     * request. A failure is logged, never shown: the visitor gets the same
     * answer either way.
     */
    public static function later(callable $send): void
    {
        register_shutdown_function(static function () use ($send): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            try {
                $send();
            } catch (\Throwable $error) {
                error_log('flocms-admin: could not send a password email: ' . $error->getMessage());
            }
        });
    }

    /** @param array<string, int|string> $replace */
    private static function message(object $user, string $kind, string $url, array $replace): Message
    {
        $brand = Admin::config()->brand();
        $name = trim((string) ($user->full_name ?? '')) ?: (string) ($user->login ?? '') ?: (string) $user->email;
        $replace += ['brand' => $brand, 'name' => $name];
        $text = static fn (string $key): string => AdminLang::get('mail.' . $kind . '_' . $key, $replace);
        $lines = [$text('greeting'), '', $text('body'), '', $text('button') . ':', $url, ''];
        if ($kind === 'reset') { $lines[] = $text('note'); $lines[] = ''; }
        $lines[] = $text('ignore');
        $lines[] = '';
        $lines[] = '-- ';
        $lines[] = AdminLang::get('mail.footer', $replace);
        $lang = AdminLang::language();
        $html = \FloCMS\Core\TemplateEngine::renderFile(dirname(__DIR__, 2) . '/resources/mail/password.html', [
            'color' => Admin::config()->primaryColor() ?? '#0b6e91',
            'lang' => $lang, 'dir' => \FloCMS\Admin\Http\PanelView::direction($lang), 'brand' => $brand,
            'initial' => mb_strtoupper(mb_substr(trim($brand), 0, 1)), 'url' => $url, 'isReset' => $kind === 'reset',
            'heading' => $text('heading'), 'greeting' => $text('greeting'), 'body' => $text('body'), 'button' => $text('button'),
            'note' => $kind === 'reset' ? $text('note') : '', 'ignore' => $text('ignore'),
            'fallback' => AdminLang::get('mail.fallback'), 'footer' => AdminLang::get('mail.footer', $replace),
        ]);
        return new Message((string) $user->email, $text('subject'), implode("\n", $lines), $html, toName: $name !== (string) $user->email ? $name : null);
    }

    private static function userByEmail(Database $db, string $email): ?object
    {
        return $db->table(self::USERS_TABLE)->whereRaw('LOWER(`email`) = ?', [$email])->orderBy('id')->limit(1)->first();
    }

    private static function userForToken(Database $db, string $token, int $now): ?object
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) { return null; }
        $row = $db->table(self::table())->where('kind', '=', 'request')->where('token_hash', '=', hash('sha256', $token))
            ->whereNull('used_at')->where('expires_at', '>', date('Y-m-d H:i:s', $now))->first();
        if ($row === null || $row->user_id === null) { return null; }
        $user = $db->table(self::USERS_TABLE)->where('id', '=', (int) $row->user_id)->first();
        return $user !== null && (int) ($user->status ?? 0) === UsersResource::ACTIVE ? $user : null;
    }

    /** Rows older than a day are no longer needed for the limits. */
    private static function purge(Database $db, int $now): void
    {
        try {
            $db->table(self::table())->where('created_at', '<', date('Y-m-d H:i:s', $now - 86400))->delete();
        } catch (\Throwable $error) {
            error_log('flocms-admin: could not clean up password reset requests: ' . $error->getMessage());
        }
    }

    /** @param array<string, scalar> $meta */
    private static function log(string $action, ?object $user, array $meta, Database $db): void
    {
        $id = $user !== null ? (int) $user->id : null;
        $name = $user !== null ? (trim((string) ($user->full_name ?? '')) ?: (string) ($user->email ?? '')) : null;
        Actor::as($id, $name, static fn () => Activity::record($action, $user !== null ? 'accounts' : null, $id !== null ? (string) $id : null, $name, meta: $meta, db: $db));
    }

    /** A keyed hash, so the stored values can't be matched against a list of addresses or IPs. */
    private static function hash(string $kind, string $value): string
    {
        $key = Env::get('APP_KEY');
        $key = is_string($key) && $key !== '' ? $key : 'flocms-admin';
        return hash_hmac('sha256', $kind . '|' . $value, $key);
    }
}
