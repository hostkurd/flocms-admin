<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Users\UsersResource;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Database;

/**
 * Email verification (0.35): one-time links that confirm an address.
 *
 *  - 'verify'  confirms the account's current address (users.is_verified)
 *  - 'invite'  a new account made in the panel: confirms the address, the
 *              person chooses a password, and the account becomes active
 *  - 'change'  a new address from the profile page: it replaces the old one
 *              only when the link sent to it is opened
 *
 * Only a hash of each token is stored. A newer link of the same kind replaces
 * older ones; links work once, for 'expires' minutes; 3 links per account
 * and hour.
 */
final class EmailVerification
{
    public const PATH = 'verify';
    public const PER_HOUR = 3;
    public const KINDS = ['verify', 'invite', 'change'];

    private static ?string $status = null;
    private static ?Database $statusFor = null;

    /** 'off' (config), 'missing' (no table, no is_verified column, or no database) or 'ready'. */
    public static function status(?Database $db = null): string
    {
        if (!Admin::config()->isInstalled() || !Admin::config()->emailVerification()['enabled']) { return 'off'; }
        $db ??= App::db();
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            try {
                $db->query('SELECT `id`, `user_id`, `kind`, `email`, `token_hash`, `expires_at`, `used_at` FROM `' . self::table() . '` WHERE 1 = 0');
                $db->query('SELECT `is_verified` FROM `users` WHERE 1 = 0');
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

    public static function boot(): void
    {
        if (!method_exists(App::class, 'allowGuestAdminActions')) { return; }
        App::allowGuestAdminActions(self::PATH, ['admin_index', 'admin_link', 'admin_send'], \FloCMS\Admin\Http\VerifyController::class);
        if (method_exists(Auth::class, 'holdSessionsUsing')) {
            Auth::holdSessionsUsing(static fn (array $user): ?string => self::holdFor($user));
        }
    }

    /** With 'required' => true, an unverified sign-in waits at "Check your email". @param array<string, mixed> $user */
    public static function holdFor(array $user): ?string
    {
        if (!Admin::config()->emailVerification()['required'] || !self::available()) { return null; }
        return (int) ($user['is_verified'] ?? 0) === 1 ? null : self::PATH;
    }

    /**
     * The panel's "Confirm your email address" banner for a signed-in user whose
     * address isn't confirmed yet, or ''.
     */
    public static function banner(): string
    {
        if (!Admin::config()->emailVerification()['enabled']) { return ''; }
        $id = \FloCMS\Core\Session::get('user_id');
        if (!is_numeric($id) || !self::available()) { return ''; }
        try {
            $user = App::db()?->table('users')->where('id', '=', (int) $id)->first();
        } catch (\Throwable) {
            return '';
        }
        if ($user === null || self::isVerified($user) || filter_var((string) ($user->email ?? ''), FILTER_VALIDATE_EMAIL) === false) { return ''; }
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = static fn (string $key, array $replace = []): string => \FloCMS\Admin\Support\AdminLang::get($key, $replace);
        return '<form class="fa-security-banner" method="post" action="' . $e(Admin::url('profile/security')) . '" role="status">'
            . \FloCMS\Core\Csrf::field()
            . '<span class="fa-security-banner-icon" aria-hidden="true">' . \FloCMS\Admin\Support\Icons::svg('mail') . '</span>'
            . '<span class="fa-security-banner-text"><strong>' . $e($t('security.banner_title')) . '</strong> '
            . $e($t('security.banner_text', ['email' => \FloCMS\Admin\Http\TwoFactorController::masked((string) $user->email)])) . '</span>'
            . '<button type="submit" name="_action" value="send_verification" class="fa-btn fa-btn-sm">' . $e($t('security.send_link')) . '</button></form>';
    }

    public static function isVerified(object $user): bool
    {
        return (int) ($user->is_verified ?? 0) === 1;
    }

    /** Email a link that confirms the account's current address. Throws MailException. */
    public static function sendVerification(object $user, ?Database $db = null): bool
    {
        $token = self::issue($user, 'verify', (string) $user->email, $db);
        if ($token === null) { return false; }
        SecurityMail::verify($user, self::link($token));
        self::log('email_link_sent', $user, $db ?? App::db(), ['kind' => 'verify']);
        return true;
    }

    /** Email an invitation to a new account. Throws MailException. */
    public static function invite(object $user, ?string $by = null, ?Database $db = null): bool
    {
        $token = self::issue($user, 'invite', (string) $user->email, $db);
        if ($token === null) { return false; }
        SecurityMail::invite($user, self::link($token), $by);
        self::log('invite_sent', $user, $db ?? App::db());
        return true;
    }

    /**
     * Ask to change the account's address: the link goes to the new address,
     * a notice to the old one. Throws MailException when the link can't be sent.
     */
    public static function requestChange(object $user, string $newEmail, ?Database $db = null): bool
    {
        $token = self::issue($user, 'change', $newEmail, $db);
        if ($token === null) { return false; }
        SecurityMail::change($user, $newEmail, self::link($token));
        try {
            SecurityMail::changeNotice($user, $newEmail);
        } catch (\Throwable $error) {
            error_log('flocms-admin: could not tell user ' . ($user->id ?? '?') . "'s old address about an email change: " . $error->getMessage());
        }
        self::log('email_change_requested', $user, $db ?? App::db());
        return true;
    }

    /** The address waiting for confirmation, or null. */
    public static function pendingChange(int $userId, ?Database $db = null): ?object
    {
        $db ??= App::db();
        if ($db === null || !self::available($db)) { return null; }
        return $db->table(self::table())->where('user_id', '=', $userId)->where('kind', '=', 'change')->whereNull('used_at')
            ->where('expires_at', '>', gmdate('Y-m-d H:i:s'))->orderBy('id', 'DESC')->first();
    }

    public static function cancelChange(int $userId, ?Database $db = null): void
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        $now = gmdate('Y-m-d H:i:s');
        $db->table(self::table())->where('user_id', '=', $userId)->where('kind', '=', 'change')->whereNull('used_at')
            ->where('expires_at', '>', $now)->update(['expires_at' => $now]);
    }

    /**
     * What a link is about, when it still works.
     *
     * @return array{kind: string, email: string, user: object}|null
     */
    public static function find(string $token, ?Database $db = null): ?array
    {
        $db ??= App::db();
        if ($db === null || !self::available($db) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) { return null; }
        $row = $db->table(self::table())->where('token_hash', '=', hash('sha256', $token))->whereNull('used_at')
            ->where('expires_at', '>', gmdate('Y-m-d H:i:s'))->first();
        if ($row === null || !in_array((string) $row->kind, self::KINDS, true)) { return null; }
        $user = $db->table('users')->where('id', '=', (int) $row->user_id)->first();
        if ($user === null) { return null; }
        // A verify or invite link is for the address the account still has.
        if ($row->kind !== 'change' && strcasecmp((string) $row->email, (string) $user->email) !== 0) { return null; }
        if ($row->kind === 'invite' && (int) ($user->status ?? 0) !== UsersResource::PENDING) { return null; }
        if ($row->kind !== 'invite' && (int) ($user->status ?? 0) !== UsersResource::ACTIVE) { return null; }
        return ['kind' => (string) $row->kind, 'email' => (string) $row->email, 'user' => $user];
    }

    /**
     * Use a link: 'done', 'taken' (a change to an address another account has
     * meanwhile) or 'invalid'. An invitation needs the new $password.
     */
    public static function complete(string $token, ?string $password = null, ?Database $db = null): string
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        $found = self::find($token, $db);
        if ($found === null) { return 'invalid'; }
        if ($found['kind'] === 'invite' && ($password === null || $password === '')) { return 'invalid'; }
        $user = $found['user'];
        try {
        $result = $db->transaction(static function (Database $db) use ($token, $found, $user, $password): string {
            $stamp = gmdate('Y-m-d H:i:s');
            $claimed = $db->query('UPDATE `' . self::table() . '` SET `used_at` = ? WHERE `token_hash` = ? AND `used_at` IS NULL', [$stamp, hash('sha256', $token)])->rowCount();
            if ($claimed !== 1) { return 'invalid'; }
            $data = ['is_verified' => 1];
            if ($found['kind'] === 'change') {
                $taken = $db->table('users')->whereRaw('LOWER(`email`) = ?', [mb_strtolower($found['email'])])->where('id', '!=', (int) $user->id)->count() > 0;
                if ($taken) { return 'taken'; }
                $data['email'] = $found['email'];
            }
            if ($found['kind'] === 'invite') {
                $data['status'] = UsersResource::ACTIVE;
                $data['password'] = password_hash((string) $password, PASSWORD_DEFAULT);
            }
            // The account as the link was read: an admin may have suspended it or changed its address meanwhile.
            $sets = implode(', ', array_map(static fn (string $column): string => '`' . $column . '` = ?', array_keys($data)));
            $expectedStatus = $found['kind'] === 'invite' ? UsersResource::PENDING : UsersResource::ACTIVE;
            $changed = $db->query('UPDATE `users` SET ' . $sets . ' WHERE `id` = ? AND `status` = ? AND LOWER(`email`) = ?',
                [...array_values($data), (int) $user->id, $expectedStatus, mb_strtolower((string) $user->email)])->rowCount();
            if ($changed !== 1) { throw new \RuntimeException('account changed'); }
            if ($found['kind'] === 'change') {
                // Links sent to the old address stop working: password resets and verification links.
                $db->table(self::table())->where('user_id', '=', (int) $user->id)->whereNull('used_at')->where('expires_at', '>', $stamp)->update(['expires_at' => $stamp]);
                if (PasswordReset::available($db)) {
                    $db->table(PasswordReset::table())->where('user_id', '=', (int) $user->id)->where('kind', '=', 'request')->whereNull('used_at')
                        ->where('expires_at', '>', $stamp)->update(['expires_at' => $stamp]);
                }
            }
            // Other links of the same kind stop working.
            $db->table(self::table())->where('user_id', '=', (int) $user->id)->where('kind', '=', $found['kind'])->whereNull('used_at')
                ->where('expires_at', '>', $stamp)->update(['expires_at' => $stamp]);
            return 'done';
        });
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'account changed') { throw $error; }
            return 'invalid';
        }
        if ($result === 'done') {
            self::log(match ($found['kind']) { 'change' => 'email_changed', 'invite' => 'invite_accepted', default => 'email_verified' }, $user, $db,
                $found['kind'] === 'change' ? ['from' => (string) $user->email, 'to' => $found['email']] : []);
        }
        return $result;
    }

    /** A password reset proves the inbox too (D8). */
    public static function markVerified(int $userId, ?Database $db = null): void
    {
        $db ??= App::db();
        if ($db === null || !self::available($db)) { return; }
        $db->table('users')->where('id', '=', $userId)->update(['is_verified' => 1]);
    }

    public static function link(string $token): string
    {
        return PasswordReset::absolute(self::PATH . '/link/' . $token);
    }

    public static function table(): string
    {
        return Admin::config()->emailVerification()['table'];
    }

    /** A new link, or null when the account had too many this hour. */
    private static function issue(object $user, string $kind, string $email, ?Database $db): ?string
    {
        $db ??= App::db() ?? throw new \RuntimeException('No database connection.');
        if (!self::available($db) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { return null; }
        $now = time();
        $db->table(self::table())->where('expires_at', '<', gmdate('Y-m-d H:i:s', $now - 86400))->delete();
        $recent = $db->table(self::table())->where('user_id', '=', (int) $user->id)->where('created_at', '>', gmdate('Y-m-d H:i:s', $now - 3600))->count();
        if ($recent >= self::PER_HOUR) { return null; }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $db->transaction(static function (Database $db) use ($user, $kind, $email, $token, $now, $stamp): void {
            $db->table(self::table())->where('user_id', '=', (int) $user->id)->where('kind', '=', $kind)->whereNull('used_at')
                ->where('expires_at', '>', $stamp)->update(['expires_at' => $stamp]);
            $db->table(self::table())->insert(['user_id' => (int) $user->id, 'kind' => $kind, 'email' => mb_substr($email, 0, 190),
                'token_hash' => hash('sha256', $token), 'created_at' => $stamp,
                'expires_at' => gmdate('Y-m-d H:i:s', $now + Admin::config()->emailVerification()['expires'] * 60), 'used_at' => null]);
        });
        return $token;
    }

    /** @param array<string, scalar> $meta */
    private static function log(string $action, object $user, ?Database $db, array $meta = []): void
    {
        if ($db === null) { return; }
        $name = trim((string) ($user->full_name ?? '')) ?: (string) ($user->email ?? '');
        $record = static fn () => Activity::record($action, 'accounts', (string) $user->id, $name, meta: $meta, db: $db);
        \FloCMS\Core\Session::get('isloggedin') ? $record() : Actor::as((int) $user->id, $name, $record);
    }
}
