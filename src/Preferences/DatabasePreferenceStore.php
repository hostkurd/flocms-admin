<?php
declare(strict_types=1);

namespace FloCMS\Admin\Preferences;

use FloCMS\Core\Database;
use PDO;

/** Table admin_user_preferences: (user_id, key) → JSON value. */
final class DatabasePreferenceStore implements PreferenceStore
{
    public const TABLE = 'admin_user_preferences';

    public function __construct(private readonly Database $db)
    {
    }

    public function all(string $user): array
    {
        $statement = $this->db->pdo()->prepare('SELECT `key`, `value` FROM `' . self::TABLE . '` WHERE `user_id` = ?');
        $statement->execute([(int) $user]);
        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$key, $value]) {
            if (!is_string($value)) { continue; }
            try {
                $values[(string) $key] = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
        }
        return $values;
    }

    public function set(string $user, string $key, mixed $value): void
    {
        $pdo = $this->db->pdo();
        $table = '`' . self::TABLE . '`';
        $this->db->transaction(function () use ($pdo, $table, $user, $key, $value): void {
            $delete = $pdo->prepare('DELETE FROM ' . $table . ' WHERE `user_id` = ? AND `key` = ?');
            $delete->execute([(int) $user, $key]);
            if ($value !== null) {
                $insert = $pdo->prepare('INSERT INTO ' . $table . ' (`user_id`, `key`, `value`, `updated_at`) VALUES (?, ?, ?, ?)');
                $insert->execute([(int) $user, $key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s')]);
            }
        });
    }

    /** Remove every preference of a user (the user was deleted). */
    public function forget(string $user): void
    {
        $this->db->pdo()->prepare('DELETE FROM `' . self::TABLE . '` WHERE `user_id` = ?')->execute([(int) $user]);
    }
}
