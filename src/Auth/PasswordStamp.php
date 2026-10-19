<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

use FloCMS\Admin\Admin;
use FloCMS\Core\Auth;
use FloCMS\Core\Session;

/**
 * Ends a user's other sessions when their password changes (0.26).
 *
 * Each signed-in session keeps a short fingerprint of the account's password
 * hash. FloCMS reloads the user on every admin request (Auth::refresh(), core
 * 2.5+); when the fingerprint no longer matches, that session ends with the
 * usual "Your session has ended" notice. A session without a fingerprint gets
 * one on its next request. After changing their own password, the person who
 * changed it keeps their session (restamp()).
 */
final class PasswordStamp
{
    public const SESSION_KEY = 'admin_password_stamp';

    private static bool $registered = false;

    public static function boot(): void
    {
        if (self::$registered || !method_exists(Auth::class, 'checkSessionsUsing')) { return; }
        self::$registered = true;
        Auth::checkSessionsUsing(static fn (array $user): bool => self::check($user));
    }

    /** @param array<string, mixed> $user */
    public static function check(array $user): bool
    {
        if (!Admin::config()->isInstalled() || !array_key_exists('password', $user) || !is_string($user['password'])) {
            return true;
        }
        $stamp = self::of($user['password']);
        $current = Session::get(self::SESSION_KEY);
        if (!is_string($current) || $current === '') {
            Session::set(self::SESSION_KEY, $stamp);
            return true;
        }
        return hash_equals($current, $stamp);
    }

    /** Keep this session after changing its own account's password. */
    public static function restamp(string $passwordHash): void
    {
        Session::set(self::SESSION_KEY, self::of($passwordHash));
    }

    public static function of(string $passwordHash): string
    {
        return substr(hash('sha256', 'flo-admin-password|' . $passwordHash), 0, 24);
    }
}
