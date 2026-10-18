<?php
declare(strict_types=1);

namespace FloCMS\Admin\Roles;

use FloCMS\Admin\Admin;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Config;
use FloCMS\Core\Database;

/**
 * The Roles page (0.21): roles and permissions edited in the panel.
 *
 * config/config.php stays the default. The admin_roles table holds a row for
 * each config role changed in the panel and for each role added there. The
 * package gives the result to flocms-core (2.4+) with Auth::loadRolesUsing(),
 * so every permission check in the site sees the same roles: the panel, the
 * skeleton's controllers, the site's own controllers and templates.
 *
 * With 'roles' => false in config/admin.php, without the table, or when the
 * table cannot be read, the config roles apply unchanged.
 */
final class Roles
{
    /** The permission of the Roles page. */
    public const PERMISSION = 'roles.manage';

    private static ?string $status = null;
    private static ?Database $statusFor = null;

    /** @var array<int, array<string, mixed>>|null stored rows, read once per request */
    private static ?array $rows = null;

    /**
     * Register the roles loader (from Admin::boot()). It reads nothing until
     * FloCMS checks a permission.
     */
    public static function boot(): void
    {
        if (method_exists(Auth::class, 'loadRolesUsing')) {
            Auth::loadRolesUsing(static fn (): ?array => self::definitions());
        }
    }

    /** Whether flocms-core can take roles from the panel (2.4+). */
    public static function coreSupportsRoles(): bool
    {
        return method_exists(Auth::class, 'loadRolesUsing');
    }

    /**
     * 'off' (config/admin.php, or the panel is not installed), 'unsupported'
     * (flocms-core before 2.4, or config/config.php has no 'permissions' or
     * 'admin_access_roles' map), 'missing' (no table yet) or 'ready'.
     */
    public static function status(?Database $db = null): string
    {
        $config = Admin::config();
        if (!$config->isInstalled() || !$config->roles()['enabled']) { return 'off'; }
        if (!self::coreSupportsRoles() || !is_array(Config::get('permissions')) || !is_array(Config::get('admin_access_roles'))) { return 'unsupported'; }
        try {
            $db ??= App::db();
        } catch (\Throwable) {
            return 'missing';
        }
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            self::$status = (new RoleStore($db, self::table()))->exists() ? 'ready' : 'missing';
        }
        return self::$status;
    }

    public static function enabled(?Database $db = null): bool
    {
        return self::status($db) === 'ready';
    }

    public static function table(): string
    {
        return Admin::config()->roles()['table'];
    }

    public static function store(?Database $db = null): RoleStore
    {
        $db ??= App::db() ?? throw new \RuntimeException('The Roles page needs a database connection.');
        return new RoleStore($db, self::table());
    }

    /** Forget what was read (tests, and after a save). */
    public static function reset(): void
    {
        self::$status = null;
        self::$statusFor = null;
        self::$rows = null;
        if (method_exists(Auth::class, 'forgetLoadedRoles')) { Auth::forgetLoadedRoles(); }
    }

    /** Re-read the stored roles and let flocms-core ask again (after a save). */
    public static function refresh(): void
    {
        self::$rows = null;
        if (method_exists(Auth::class, 'forgetLoadedRoles')) { Auth::forgetLoadedRoles(); }
    }

    /** Whether the current user may open the Roles page. */
    public static function canManage(): bool
    {
        return self::enabled() && Auth::can(self::PERMISSION);
    }

    /* ---------------------------------------------------------------
     * What flocms-core sees
     * ------------------------------------------------------------- */

    /**
     * The definitions handed to Auth::loadRolesUsing(): null (the config roles)
     * unless the table holds rows.
     *
     * @return array{roles: array<int, string>, permissions: array<int, list<string>>, admin_access_roles: list<string>, levels: array<int, int>}|null
     */
    public static function definitions(): ?array
    {
        try {
            if (!self::enabled() || self::rows() === []) { return null; }
            $out = ['roles' => [], 'permissions' => [], 'admin_access_roles' => [], 'levels' => []];
            foreach (self::all() as $number => $role) {
                $out['roles'][$number] = $role->label();
                $out['permissions'][$number] = $role->permissions;
                $out['levels'][$number] = $role->level;
                if ($role->adminAccess) { $out['admin_access_roles'][] = (string) $number; }
            }
            return $out;
        } catch (\Throwable $error) {
            error_log('flocms-admin: could not read the roles table, the config roles apply: ' . $error->getMessage());
            return null;
        }
    }

    /* ---------------------------------------------------------------
     * Roles
     * ------------------------------------------------------------- */

    /**
     * The roles of config/config.php, read from Config (never from the loader).
     *
     * @return array<int, Role>
     */
    public static function configRoles(): array
    {
        $names = Config::get('roles');
        if (!is_array($names) || $names === []) {
            $names = [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin'];
        }
        $map = Config::get('permissions');
        $map = is_array($map) ? $map : [];
        $access = array_map('strval', array_filter((array) Config::get('admin_access_roles', []), 'is_scalar'));
        $levels = Config::get('levels');
        $levels = is_array($levels) ? $levels : [];

        $numbers = [];
        foreach (array_merge(array_keys($names), array_keys($map)) as $key) {
            if (is_numeric($key)) { $numbers[(int) $key] = true; }
        }
        $roles = [];
        foreach (array_keys($numbers) as $number) {
            $label = $names[$number] ?? null;
            $roles[$number] = new Role(
                $number,
                is_scalar($label) && (string) $label !== '' ? (string) $label : 'Role ' . $number,
                isset($levels[$number]) && is_numeric($levels[$number]) ? (int) $levels[$number] : $number,
                in_array((string) $number, $access, true),
                self::cleanPatterns(is_array($map[$number] ?? null) ? $map[$number] : []),
                Role::SOURCE_CONFIG,
            );
        }
        ksort($roles);
        return $roles;
    }

    /**
     * Every role: the config roles with their changes, then the roles added in the
     * panel. Highest level first.
     *
     * @return array<int, Role>
     */
    public static function all(): array
    {
        $roles = self::configRoles();
        foreach (self::enabled() ? self::rows() : [] as $number => $row) {
            $roles[$number] = new Role($number, $row['name'] !== [] ? $row['name'] : ($roles[$number]->name ?? 'Role ' . $number),
                $row['level'], $row['admin_access'], $row['permissions'],
                isset($roles[$number]) ? Role::SOURCE_CHANGED : Role::SOURCE_CUSTOM);
        }
        uasort($roles, static fn (Role $a, Role $b): int => [$b->level, $b->number] <=> [$a->level, $a->number]);
        return $roles;
    }

    public static function find(int $number): ?Role
    {
        return self::all()[$number] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(): array
    {
        return self::$rows ??= self::store()->rows();
    }

    /**
     * The role nobody can change from the page: the highest-level role holding "*"
     * (Super Admin by default). Null when no role holds "*".
     */
    public static function topRole(): ?Role
    {
        $top = null;
        foreach (self::all() as $role) {
            if ($role->holdsEverything() && ($top === null || [$role->level, $role->number] > [$top->level, $top->number])) { $top = $role; }
        }
        return $top;
    }

    public static function isLocked(Role $role): bool
    {
        return self::topRole()?->number === $role->number;
    }

    /** The next unused role number (null when all 128 are taken). */
    public static function nextNumber(): ?int
    {
        $used = array_keys(self::all());
        for ($number = $used === [] ? 1 : max($used) + 1; $number <= RoleStore::MAX_NUMBER; $number++) {
            if (!in_array($number, $used, true)) { return $number; }
        }
        for ($number = 1; $number <= RoleStore::MAX_NUMBER; $number++) {
            if (!in_array($number, $used, true)) { return $number; }
        }
        return null;
    }

    /* ---------------------------------------------------------------
     * What the current user may do
     * ------------------------------------------------------------- */

    /** The current user's level, or null when not logged in. */
    public static function ownLevel(): ?int
    {
        $role = Auth::role();
        return $role === null ? null : Auth::level($role);
    }

    /** Edit a role: only roles below your own level, never the top role. */
    public static function canEdit(Role $role): bool
    {
        $own = self::ownLevel();
        return self::canManage() && $own !== null && !self::isLocked($role) && $role->level < $own && $role->number !== Auth::role();
    }

    public static function canReset(Role $role): bool
    {
        return $role->changed() && self::canEdit($role);
    }

    public static function canDelete(Role $role): bool
    {
        return $role->source === Role::SOURCE_CUSTOM && self::canEdit($role);
    }

    public static function canCreate(): bool
    {
        $own = self::ownLevel();
        return self::canManage() && $own !== null && $own > 0 && self::nextNumber() !== null;
    }

    /**
     * Whether the current user holds $pattern, so they may give it or take it away:
     * a name they have, a wildcard they have or a wider one ("*" covers all).
     */
    public static function holds(string $pattern): bool
    {
        if (!str_contains($pattern, '*')) { return Auth::can($pattern); }
        $role = Auth::role();
        $own = $role === null ? [] : (Auth::permissionMap()[$role] ?? []);
        foreach ($own as $granted) {
            if ($granted === '*' || $granted === $pattern) { return true; }
            if ($pattern !== '*' && str_ends_with($granted, '.*') && str_starts_with(substr($pattern, 0, -1), substr($granted, 0, -1))) { return true; }
        }
        return false;
    }

    /* ---------------------------------------------------------------
     * Users holding roles
     * ------------------------------------------------------------- */

    /** @return array<int, int> role number => number of users (empty when the users table cannot be read) */
    public static function userCounts(): array
    {
        try {
            $db = App::db();
            if ($db === null) { return []; }
            $rows = $db->query('SELECT `role`, COUNT(*) AS `n` FROM `' . self::usersTable() . '` GROUP BY `role`')->fetchAll(\PDO::FETCH_ASSOC);
            $counts = [];
            foreach ($rows as $row) { $counts[(int) $row['role']] = (int) $row['n']; }
            return $counts;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array{id: string, name: string, email: string}> a few users holding $number */
    public static function holders(int $number, int $limit = 5): array
    {
        try {
            $db = App::db();
            if ($db === null) { return []; }
            $rows = $db->table(self::usersTable())->where('role', '=', $number)->orderBy('id', 'asc')->limit($limit)->get();
            return array_map(static fn (object $row): array => [
                'id' => (string) ($row->id ?? ''),
                'name' => (string) (($row->full_name ?? '') !== '' ? $row->full_name : ($row->email ?? '')),
                'email' => (string) ($row->email ?? ''),
            ], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Whether at least one active user could still open the panel and manage roles
     * with these roles. Null when the users table cannot be read (not checked).
     *
     * @param array<int, Role> $roles
     * @param array<int, int> $moves role number => the role its users move to
     */
    public static function someoneCanManage(array $roles, array $moves = []): ?bool
    {
        try {
            $db = App::db();
            if ($db === null) { return null; }
            $active = Config::get('auth.active_status', 1);
            $rows = $db->query('SELECT `role`, COUNT(*) AS `n` FROM `' . self::usersTable() . '` WHERE `status` = ? GROUP BY `role`', [$active])->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        foreach ($rows as $row) {
            $number = (int) $row['role'];
            $role = $roles[$moves[$number] ?? $number] ?? null;
            if ($role !== null && (int) $row['n'] > 0 && $role->adminAccess && $role->grants(self::PERMISSION)) { return true; }
        }
        return false;
    }

    public static function usersTable(): string
    {
        return \FloCMS\Admin\Users\UsersResource::getTable();
    }

    /* ---------------------------------------------------------------
     * Patterns
     * ------------------------------------------------------------- */

    /** A permission name or pattern: letters, digits, "_", "-", "." and "*" only as "*" or a ".*" ending. */
    public static function validPattern(string $pattern): bool
    {
        return $pattern === '*' || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*(?:\.\*)?$/D', $pattern) === 1 && strlen($pattern) <= 120;
    }

    /**
     * Valid, unique patterns in a stable order.
     *
     * @param array<mixed> $patterns
     * @return list<string>
     */
    public static function cleanPatterns(array $patterns): array
    {
        $clean = [];
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && self::validPattern(trim($pattern))) { $clean[trim($pattern)] = true; }
        }
        return array_keys($clean);
    }

    /** As FloCMS\Core\Auth matches a granted pattern against a permission. */
    public static function matches(string $granted, string $permission): bool
    {
        if ($granted === '*' || $granted === $permission) { return true; }
        return str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1));
    }
}
