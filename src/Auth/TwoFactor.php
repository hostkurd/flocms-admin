<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\Actor;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Database;

/**
 * Two-factor sign-in (0.35): authenticator app codes after the password.
 *
 * A user's row exists only while it is on. The secret is encrypted with
 * APP_KEY (SecretBox); backup codes and device tokens are stored as keyed
 * hashes. A fresh sign-in is held at /admin/two-factor (flocms-core 2.7 held
 * sign-ins) until a code is entered, or set up when the user's role requires
 * it. Wrong codes: 10 within an hour lock the account's codes for 15 minutes.
 */
final class TwoFactor
{
    public const BACKUP_CODES = 10;
    public const MAX_FAILURES = 10;
    public const LOCK_MINUTES = 15;
    public const PATH = 'two-factor';
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private static ?string $status = null;
    private static ?Database $statusFor = null;

    /**
     * 'off' (config), 'core' (flocms-core before 2.7), 'key' (APP_KEY too short),
     * 'missing' (tables not migrated, or no database) or 'ready'.
     */
    public static function status(?Database $db = null): string
    {
        if (!Admin::config()->isInstalled() || !Admin::config()->twoFactor()['enabled']) { return 'off'; }
        if (!method_exists(Auth::class, 'holdSessionsUsing')) { return 'core'; }
        if (!SecretBox::ready()) { return 'key'; }
        $db ??= App::db();
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            $config = Admin::config()->twoFactor();
            try {
                $db->query('SELECT `user_id`, `secret`, `last_step`, `backup_codes`, `failures`, `failures_since`, `locked_until` FROM `' . $config['table'] . '` WHERE 1 = 0');
                $db->query('SELECT `role`, `required` FROM `' . $config['roles_table'] . '` WHERE 1 = 0');
                $db->query('SELECT `id`, `user_id`, `token_hash`, `password_stamp`, `expires_at` FROM `' . $config['devices_table'] . '` WHERE 1 = 0');
                self::$status = 'ready';
            } catch (\Throwable) {
                // Not cached: a passing database error must not count as "no tables" for the rest of the request.
                self::$status = null;
                return 'missing';
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

    /** Hold every fresh sign-in of a user with two-factor on (or required) at /admin/two-factor. */
    public static function boot(): void
    {
        if (!method_exists(Auth::class, 'holdSessionsUsing')) { return; }
        \FloCMS\Core\App::allowGuestAdminActions(self::PATH, ['admin_index', 'admin_backup', 'admin_codes'], \FloCMS\Admin\Http\TwoFactorController::class);
        Auth::holdSessionsUsing(static fn (array $user): ?string => self::holdFor($user));
    }

    /**
     * Fails closed: when the feature is on but not ready (APP_KEY too short,
     * tables unreadable), a user who has it on, or whose role requires it, is
     * still held; the step page then explains and ends the sign-in.
     *
     * @param array<string, mixed> $user the row FloCMS loaded for the session
     */
    public static function holdFor(array $user): ?string
    {
        $status = self::status();
        if ($status === 'off' || $status === 'core') { return null; }
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) { return null; }
        if ($status !== 'ready') {
            return self::hasRecord($id) || self::requiredWhileBroken($user['role'] ?? null) ? self::PATH : null;
        }
        if (self::isOn($id)) {
            return TrustedDevices::check($id, (string) ($user['password'] ?? '')) ? null : self::PATH;
        }
        return self::isRequired($user['role'] ?? null) ? self::PATH : null;
    }

    /**
     * Whether $userId has a two-factor row, read even when the feature isn't
     * ready. A table that doesn't exist means nobody has it on; any other
     * database error counts as "has it" (closed).
     */
    public static function hasRecord(int $userId, ?Database $db = null): bool
    {
        $db ??= App::db();
        if ($db === null) { return true; }
        try {
            $db->query('SELECT 1 FROM `' . self::table() . '` WHERE 1 = 0');
        } catch (\Throwable) {
            return false;
        }
        try {
            return $db->table(self::table())->where('user_id', '=', $userId)->count() > 0;
        } catch (\Throwable) {
            return true;
        }
    }

    private static function requiredWhileBroken(mixed $role): bool
    {
        if (!is_numeric($role)) { return false; }
        if (in_array((int) $role, Admin::config()->twoFactor()['required_roles'], true)) { return true; }
        $db = App::db();
        try {
            $row = $db?->table(Admin::config()->twoFactor()['roles_table'])->where('role', '=', (int) $role)->first();
            return $row !== null && (int) $row->required === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function record(int $userId, ?Database $db = null): ?object
    {
        $db ??= App::db();
        if ($db === null || !self::available($db)) { return null; }
        return $db->table(self::table())->where('user_id', '=', $userId)->first();
    }

    public static function isOn(int $userId, ?Database $db = null): bool
    {
        return self::record($userId, $db) !== null;
    }

    /* ---------------------------------------------------------------
     * Roles that require it
     * ------------------------------------------------------------- */

    /** @return list<int> roles whose people must use two-factor sign-in */
    public static function requiredRoles(?Database $db = null): array
    {
        $roles = array_fill_keys(Admin::config()->twoFactor()['required_roles'], true);
        $db ??= App::db();
        if ($db !== null && self::available($db)) {
            foreach ($db->table(Admin::config()->twoFactor()['roles_table'])->get() as $row) {
                $roles[(int) $row->role] = (int) $row->required === 1;
            }
        }
        return array_keys(array_filter($roles));
    }

    public static function isRequired(mixed $role, ?Database $db = null): bool
    {
        return is_numeric($role) && in_array((int) $role, self::requiredRoles($db), true);
    }

    public static function setRequired(int $role, bool $required, ?Database $db = null): void
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        $table = Admin::config()->twoFactor()['roles_table'];
        $now = gmdate('Y-m-d H:i:s');
        $db->transaction(static function (Database $db) use ($table, $role, $required, $now): void {
            if ($db->table($table)->where('role', '=', $role)->count() > 0) {
                $db->table($table)->where('role', '=', $role)->update(['required' => $required ? 1 : 0, 'updated_at' => $now]);
            } else {
                $db->table($table)->insert(['role' => $role, 'required' => $required ? 1 : 0, 'updated_at' => $now]);
            }
        });
    }

    /**
     * How many people of $role have it on, of how many active ones.
     *
     * @return array{on: int, total: int}
     */
    public static function roleCoverage(int $role, ?Database $db = null): array
    {
        $db ??= App::db();
        if ($db === null || !self::available($db)) { return ['on' => 0, 'total' => 0]; }
        $total = $db->table('users')->where('role', '=', $role)->where('status', '=', \FloCMS\Admin\Users\UsersResource::ACTIVE)->count();
        $on = (int) ($db->query('SELECT COUNT(*) FROM `users` u JOIN `' . self::table() . '` t ON t.`user_id` = u.`id` WHERE u.`role` = ? AND u.`status` = ?',
            [$role, \FloCMS\Admin\Users\UsersResource::ACTIVE])->fetchColumn());
        return ['on' => $on, 'total' => $total];
    }

    /* ---------------------------------------------------------------
     * Turning it on and off
     * ------------------------------------------------------------- */

    /**
     * Turn it on with a confirmed secret. Returns the backup codes (shown once).
     *
     * @return list<string>
     */
    public static function enable(int $userId, string $secret, ?Database $db = null): array
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        [$codes, $stored] = self::newCodes($userId);
        $now = gmdate('Y-m-d H:i:s');
        $row = ['secret' => SecretBox::encrypt($secret), 'last_step' => Totp::step(), 'backup_codes' => $stored,
            'failures' => 0, 'failures_since' => null, 'locked_until' => null, 'confirmed_at' => $now, 'updated_at' => $now];
        $db->transaction(static function (Database $db) use ($userId, $row): void {
            $db->table(self::table())->where('user_id', '=', $userId)->delete();
            $db->table(self::table())->insert(['user_id' => $userId] + $row);
        });
        TrustedDevices::forgetAll($userId, $db);
        self::log('two_factor_enabled', $userId, $db);
        self::notify($userId, $db, true);
        return $codes;
    }

    /** Turn it off: the secret, the backup codes and the remembered devices go. */
    public static function disable(int $userId, string $action = 'two_factor_disabled', ?Database $db = null): void
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        $db->table(self::table())->where('user_id', '=', $userId)->delete();
        TrustedDevices::forgetAll($userId, $db);
        self::log($action, $userId, $db);
        // A reset has its own optional notice; turning it off yourself always tells the owner.
        if ($action === 'two_factor_disabled') { self::notify($userId, $db, false); }
    }

    /** Tell the owner that two-factor sign-in was turned on or off (someone with only the password could). */
    private static function notify(int $userId, Database $db, bool $on): void
    {
        $user = $db->table('users')->where('id', '=', $userId)->first();
        if ($user !== null && filter_var((string) ($user->email ?? ''), FILTER_VALIDATE_EMAIL) !== false) {
            SecurityMail::later(static fn () => SecurityMail::twoFactorChanged($user, $on));
        }
    }

    /**
     * A new set of backup codes; the old ones stop working.
     *
     * @return list<string>
     */
    public static function regenerateCodes(int $userId, ?Database $db = null): array
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        [$codes, $stored] = self::newCodes($userId);
        $db->table(self::table())->where('user_id', '=', $userId)->update(['backup_codes' => $stored, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        self::log('two_factor_codes', $userId, $db);
        return $codes;
    }

    public static function codesLeft(object $record): int
    {
        $codes = json_decode((string) ($record->backup_codes ?? '[]'), true);
        return is_array($codes) ? count(array_filter($codes, static fn ($c): bool => is_array($c) && ($c['u'] ?? null) === null)) : 0;
    }

    /* ---------------------------------------------------------------
     * Checking codes
     * ------------------------------------------------------------- */

    /**
     * Check an app code for $userId: 'ok', 'wrong', 'locked' (too many wrong
     * codes lately) or 'off' (two-factor isn't on). A code works once.
     */
    public static function verifyCode(int $userId, string $code, ?Database $db = null): string
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        $record = self::record($userId, $db);
        if ($record === null) { return 'off'; }
        if (self::locked($record)) { return 'locked'; }
        $secret = SecretBox::decrypt((string) $record->secret);
        $step = $secret !== null ? Totp::verify($secret, $code, (int) $record->last_step) : null;
        if ($step !== null) {
            // Claimed here, unless a request at the same moment used this code first.
            $claimed = $db->query('UPDATE `' . self::table() . '` SET `last_step` = ?, `failures` = 0, `failures_since` = NULL WHERE `user_id` = ? AND `last_step` < ?',
                [$step, $userId, $step])->rowCount();
            if ($claimed === 1) { return 'ok'; }
        }
        return self::fail($record, $db);
    }

    /** Check a backup code: 'ok' (it is used up now), 'wrong', 'locked' or 'off'. */
    public static function verifyBackupCode(int $userId, string $code, ?Database $db = null): string
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        $record = self::record($userId, $db);
        if ($record === null) { return 'off'; }
        if (self::locked($record)) { return 'locked'; }
        $hash = SecretBox::hash('backup', $userId . '|' . self::normalizeBackupCode($code));
        $codes = json_decode((string) $record->backup_codes, true);
        $codes = is_array($codes) ? $codes : [];
        $match = null;
        foreach ($codes as $i => $entry) {
            if (is_array($entry) && is_string($entry['h'] ?? null) && hash_equals($entry['h'], $hash) && ($entry['u'] ?? null) === null) { $match = $i; }
        }
        if ($match !== null) {
            $codes[$match]['u'] = gmdate('Y-m-d H:i:s');
            $json = json_encode(array_values($codes), JSON_THROW_ON_ERROR);
            // Compare-and-set: a code used by another request at the same moment counts once.
            $claimed = $db->query('UPDATE `' . self::table() . '` SET `backup_codes` = ?, `failures` = 0, `failures_since` = NULL WHERE `user_id` = ? AND `backup_codes` = ?',
                [$json, $userId, (string) $record->backup_codes])->rowCount();
            if ($claimed === 1) {
                self::log('two_factor_backup_used', $userId, $db, ['left' => self::codesLeft((object) ['backup_codes' => $json])]);
                return 'ok';
            }
        }
        return self::fail($record, $db);
    }

    public static function normalizeBackupCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', mb_substr($code, 0, 40)));
    }

    /** The secret of a record, or null when it can't be read (APP_KEY changed). */
    public static function secretOf(object $record): ?string
    {
        return SecretBox::decrypt((string) ($record->secret ?? ''));
    }

    public static function table(): string
    {
        return Admin::config()->twoFactor()['table'];
    }

    private static function locked(object $record): bool
    {
        return $record->locked_until !== null && strtotime((string) $record->locked_until . ' UTC') > time();
    }

    private static function fail(object $record, Database $db): string
    {
        $now = time();
        $userId = (int) $record->user_id;
        $hourAgo = gmdate('Y-m-d H:i:s', $now - 3600);
        $stamp = gmdate('Y-m-d H:i:s', $now);
        // One statement, so wrong codes sent at the same moment all count.
        $db->query('UPDATE `' . self::table() . '` SET '
            . '`failures` = CASE WHEN `failures_since` IS NULL OR `failures_since` < ? THEN 1 ELSE `failures` + 1 END, '
            . '`failures_since` = CASE WHEN `failures_since` IS NULL OR `failures_since` < ? THEN ? ELSE `failures_since` END WHERE `user_id` = ?',
            [$hourAgo, $hourAgo, $stamp, $userId]);
        $failures = (int) $db->query('SELECT `failures` FROM `' . self::table() . '` WHERE `user_id` = ?', [$userId])->fetchColumn();
        $lock = false;
        if ($failures >= self::MAX_FAILURES) {
            // Only the request that resets the counter locks and tells the owner.
            $lock = $db->query('UPDATE `' . self::table() . '` SET `failures` = 0, `failures_since` = NULL, `locked_until` = ? WHERE `user_id` = ? AND `failures` >= ?',
                [gmdate('Y-m-d H:i:s', $now + self::LOCK_MINUTES * 60), $userId, self::MAX_FAILURES])->rowCount() === 1;
            if (!$lock) { return 'locked'; }
        }
        if ($lock) {
            self::log('two_factor_locked', (int) $record->user_id, $db);
            $user = $db->table('users')->where('id', '=', (int) $record->user_id)->first();
            if ($user !== null) { SecurityMail::later(static fn () => SecurityMail::twoFactorLocked($user)); }
            return 'locked';
        }
        return 'wrong';
    }

    /** @return array{0: list<string>, 1: string} the codes as shown, and as stored */
    private static function newCodes(int $userId): array
    {
        $codes = [];
        $stored = [];
        $max = strlen(self::CODE_ALPHABET) - 1;
        while (count($codes) < self::BACKUP_CODES) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) { $raw .= self::CODE_ALPHABET[random_int(0, $max)]; }
            if (isset($codes[$raw])) { continue; }
            $codes[$raw] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $stored[] = ['h' => SecretBox::hash('backup', $userId . '|' . $raw), 'u' => null];
        }
        return [array_values($codes), json_encode($stored, JSON_THROW_ON_ERROR)];
    }

    /** @param array<string, scalar> $meta */
    private static function log(string $action, int $userId, Database $db, array $meta = []): void
    {
        $user = $db->table('users')->where('id', '=', $userId)->first();
        $name = $user !== null ? (trim((string) ($user->full_name ?? '')) ?: (string) ($user->email ?? '')) : null;
        $record = static fn () => Activity::record($action, 'accounts', (string) $userId, $name, meta: $meta, db: $db);
        // Signing in: nobody is signed in yet, so the entry is the user's own.
        if (Auth::heldUserId() === $userId || !\FloCMS\Core\Session::get('isloggedin')) {
            Actor::as($userId, $name, $record);
        } else {
            $record();
        }
    }
}
