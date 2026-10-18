<?php
declare(strict_types=1);

namespace FloCMS\Admin\Roles;

use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Database;

/**
 * The admin_roles table: roles changed or added on the Roles page.
 *
 * @internal
 */
final class RoleStore
{
    /** Role numbers fit the skeleton's users.role column (TINYINT). */
    public const MAX_NUMBER = 127;
    public const MAX_LEVEL = 1000;

    public function __construct(private readonly Database $db, private readonly string $table)
    {
    }

    public function connection(): Database
    {
        return $this->db;
    }

    /** Whether the table exists with the expected columns. */
    public function exists(): bool
    {
        try {
            $this->db->query('SELECT `role`, `name`, `level`, `admin_access`, `permissions`, `created_at`, `updated_at` FROM `' . $this->table . '` WHERE 1 = 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The stored rows by role number.
     *
     * @return array<int, array{name: array<string, string>, level: int, admin_access: bool, permissions: list<string>}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->db->table($this->table)->get() as $row) {
            $number = (int) $row->role;
            $permissions = json_decode((string) ($row->permissions ?? '[]'), true);
            $rows[$number] = [
                'name' => Translations::store()->decode($row->name ?? '', \FloCMS\Admin\Translation\Languages::default()),
                'level' => max(0, min(self::MAX_LEVEL, (int) $row->level)),
                'admin_access' => (bool) (int) $row->admin_access,
                'permissions' => Roles::cleanPatterns(is_array($permissions) ? $permissions : []),
            ];
        }
        ksort($rows);
        return $rows;
    }

    /** Insert or replace the row of $role. */
    public function put(Role $role): void
    {
        $names = is_array($role->name) ? $role->name : [\FloCMS\Admin\Translation\Languages::default() => $role->name];
        $now = date('Y-m-d H:i:s');
        $data = [
            'name' => (string) Translations::store()->encode($names),
            'level' => $role->level,
            'admin_access' => $role->adminAccess ? 1 : 0,
            'permissions' => json_encode(array_values($role->permissions), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];
        $this->db->transaction(function () use ($role, $data, $now): void {
            if ($this->db->table($this->table)->where('role', '=', $role->number)->count() > 0) {
                $this->db->table($this->table)->where('role', '=', $role->number)->update($data);
            } else {
                $this->db->table($this->table)->insert(['role' => $role->number] + $data + ['created_at' => $now]);
            }
        });
    }

    public function delete(int $number): void
    {
        $this->db->table($this->table)->where('role', '=', $number)->delete();
    }
}
