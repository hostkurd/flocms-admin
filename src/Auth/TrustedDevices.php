<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

use FloCMS\Admin\Admin;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/**
 * "Remember this device" (0.35): a browser that passed two-factor sign-in
 * skips the code for 'remember_days'. The browser keeps a random token in a
 * cookie; only its keyed hash is stored, with a stamp of the password hash,
 * so a password change forgets every device at once.
 */
final class TrustedDevices
{
    private const COOKIE = 'flo_admin_device_';

    public static function enabled(): bool
    {
        return Admin::config()->twoFactor()['remember_days'] > 0;
    }

    /** Whether this browser is a remembered device of $userId (and records that it was used). */
    public static function check(int $userId, string $passwordHash, ?Database $db = null): bool
    {
        if (!self::enabled()) { return false; }
        $token = $_COOKIE[self::cookieName($userId)] ?? null;
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) { return false; }
        $db ??= App::db();
        if ($db === null) { return false; }
        $row = $db->table(self::table())->where('user_id', '=', $userId)->where('token_hash', '=', SecretBox::hash('device', $token))
            ->where('expires_at', '>', gmdate('Y-m-d H:i:s'))->first();
        if ($row === null || !hash_equals((string) $row->password_stamp, PasswordStamp::of($passwordHash))) { return false; }
        $db->table(self::table())->where('id', '=', (int) $row->id)->update(['last_used_at' => gmdate('Y-m-d H:i:s')]);
        return true;
    }

    /** Remember this browser for $userId and set its cookie. */
    public static function remember(int $userId, string $passwordHash, string $userAgent, ?Database $db = null): void
    {
        if (!self::enabled()) { return; }
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = time();
        $expires = $now + Admin::config()->twoFactor()['remember_days'] * 86400;
        $db->table(self::table())->where('expires_at', '<', gmdate('Y-m-d H:i:s', $now))->delete();
        $db->table(self::table())->insert([
            'user_id' => $userId, 'token_hash' => SecretBox::hash('device', $token), 'password_stamp' => PasswordStamp::of($passwordHash),
            'name' => mb_substr(self::describe($userAgent), 0, 120), 'created_at' => gmdate('Y-m-d H:i:s', $now),
            'last_used_at' => gmdate('Y-m-d H:i:s', $now), 'expires_at' => gmdate('Y-m-d H:i:s', $expires),
        ]);
        if (!headers_sent()) {
            setcookie(self::cookieName($userId), $token, [
                'expires' => $expires, 'path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::cookieName($userId)] = $token;
    }

    /**
     * The remembered devices of $userId, newest first; 'current' marks this browser.
     *
     * @return list<array{id: int, name: string, created: string, last_used: string, expires: string, current: bool}>
     */
    public static function list(int $userId, ?Database $db = null): array
    {
        $db ??= App::db();
        if ($db === null) { return []; }
        $token = $_COOKIE[self::cookieName($userId)] ?? null;
        $mine = is_string($token) ? SecretBox::hash('device', $token) : null;
        $out = [];
        foreach ($db->table(self::table())->where('user_id', '=', $userId)->where('expires_at', '>', gmdate('Y-m-d H:i:s'))->orderBy('last_used_at', 'DESC')->get() as $row) {
            $out[] = ['id' => (int) $row->id, 'name' => (string) $row->name, 'created' => (string) $row->created_at, 'last_used' => (string) $row->last_used_at,
                'expires' => (string) $row->expires_at, 'current' => $mine !== null && hash_equals((string) $row->token_hash, $mine)];
        }
        return $out;
    }

    public static function forget(int $userId, int $id, ?Database $db = null): bool
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        if ($db->table(self::table())->where('user_id', '=', $userId)->where('id', '=', $id)->count() === 0) { return false; }
        $db->table(self::table())->where('user_id', '=', $userId)->where('id', '=', $id)->delete();
        return true;
    }

    public static function forgetAll(int $userId, ?Database $db = null): void
    {
        $db ??= App::db();
        if ($db === null) { return; }
        try {
            $db->table(self::table())->where('user_id', '=', $userId)->delete();
        } catch (\Throwable $error) {
            error_log('flocms-admin: could not forget the remembered devices of user ' . $userId . ': ' . $error->getMessage());
        }
    }

    /** "Chrome on Windows", from the browser's user agent. */
    public static function describe(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => '',
        };
        $system = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => '',
        };
        if ($browser !== '' && $system !== '') { return \FloCMS\Admin\Support\AdminLang::get('security.device_on', ['browser' => $browser, 'system' => $system]); }
        return $browser !== '' ? $browser : ($system !== '' ? $system : \FloCMS\Admin\Support\AdminLang::get('security.device_unknown'));
    }

    public static function table(): string
    {
        return Admin::config()->twoFactor()['devices_table'];
    }

    private static function cookieName(int $userId): string
    {
        return self::COOKIE . substr(SecretBox::hash('device-cookie', (string) $userId), 0, 12);
    }

    private static function https(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') { return true; }
        return defined('SITE_URI') && str_starts_with(strtolower((string) SITE_URI), 'https://');
    }
}
