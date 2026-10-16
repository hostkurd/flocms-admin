<?php
declare(strict_types=1);

namespace FloCMS\Admin\Settings;

use FloCMS\Core\Database;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * The settings table: one row per key, the value always JSON encoded
 * ("Acme", {"en":"Acme","ar":"أكمي"}, "1"), so plain and translatable values
 * need no type column.
 *
 *     CREATE TABLE settings (`key` VARCHAR(190) PRIMARY KEY, `value` TEXT NULL, `updated_at` DATETIME NULL)
 */
final class SettingsStore
{
    private readonly string $table;

    public function __construct(private readonly Database $db, string $table = 'settings')
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
            throw new InvalidArgumentException('Invalid settings table name "' . $table . '".');
        }
        $this->table = $table;
    }

    public function table(): string
    {
        return $this->table;
    }

    /** Whether the table exists and has the expected columns. */
    public function exists(): bool
    {
        try {
            $this->db->pdo()->query('SELECT `key`, `value`, `updated_at` FROM `' . $this->table . '` WHERE 1 = 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every stored value, decoded. Rows whose value is not valid JSON are skipped.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];
        $statement = $this->db->pdo()->query('SELECT `key`, `value` FROM `' . $this->table . '`');
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$key, $value]) {
            if (!is_string($value)) { continue; }
            try {
                $values[(string) $key] = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
        }
        return $values;
    }

    /**
     * Store values in one transaction; null removes the key.
     *
     * @param array<string, mixed> $values
     */
    public function put(array $values): void
    {
        $pdo = $this->db->pdo();
        $table = '`' . $this->table . '`';
        $now = date('Y-m-d H:i:s');
        $this->db->transaction(function () use ($pdo, $table, $values, $now): void {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE `key` = ?');
            $update = $pdo->prepare('UPDATE ' . $table . ' SET `value` = ?, `updated_at` = ? WHERE `key` = ?');
            $insert = $pdo->prepare('INSERT INTO ' . $table . ' (`key`, `value`, `updated_at`) VALUES (?, ?, ?)');
            $delete = $pdo->prepare('DELETE FROM ' . $table . ' WHERE `key` = ?');
            foreach ($values as $key => $value) {
                $key = (string) $key;
                if (strlen($key) > 190) { throw new InvalidArgumentException('Settings key too long: ' . $key); }
                if ($value === null) {
                    $delete->execute([$key]);
                    continue;
                }
                $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $exists->execute([$key]);
                $found = (int) $exists->fetchColumn() > 0;
                $exists->closeCursor();
                if ($found) {
                    $update->execute([$json, $now, $key]);
                } else {
                    $insert->execute([$key, $json, $now]);
                }
            }
        });
    }
}
