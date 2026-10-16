<?php
declare(strict_types=1);

namespace FloCMS\Admin\Settings;

use FloCMS\Core\Database;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * FloCMS's own settings table (param, value, lang), shared with core
 * (Config::getSetting(), offline_mode). Each admin setting is one row:
 *
 *     param = 'site.title'   lang = ''   value = '{"en":"Acme","ar":"أكمي"}'
 *
 * The value is always JSON, so plain and translatable values need no type
 * column, and the empty lang marks a row that holds every language. Core's
 * own per-language rows are never read or changed here.
 */
final class SettingsStore
{
    /** Admin keys are "<page>.<field>"; rows of other shapes belong to core or the site. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}\.[A-Za-z_][A-Za-z0-9_]{0,120}$/D';

    /** The one settings table of a FloCMS site (core reads it too). */
    public const TABLE = 'settings';

    private readonly string $table;

    public function __construct(private readonly Database $db)
    {
        $this->table = self::TABLE;
    }

    public function table(): string
    {
        return $this->table;
    }

    /** Whether the table exists and has the columns param, value and lang. */
    public function exists(): bool
    {
        try {
            $this->db->pdo()->query('SELECT `param`, `value`, `lang` FROM `' . $this->table . '` WHERE 1 = 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every admin setting, decoded. Rows that are not admin settings (another
     * language, another key shape, a value that is not JSON) are skipped.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];
        $statement = $this->db->pdo()->query("SELECT `param`, `value` FROM `" . $this->table . "` WHERE `lang` = ''");
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$key, $value]) {
            if (!is_string($value) || preg_match(self::KEY_PATTERN, (string) $key) !== 1) { continue; }
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
        $this->db->transaction(function () use ($pdo, $table, $values): void {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . " WHERE `param` = ? AND `lang` = ''");
            $update = $pdo->prepare('UPDATE ' . $table . " SET `value` = ? WHERE `param` = ? AND `lang` = ''");
            $insert = $pdo->prepare('INSERT INTO ' . $table . " (`param`, `value`, `lang`) VALUES (?, ?, '')");
            $delete = $pdo->prepare('DELETE FROM ' . $table . " WHERE `param` = ? AND `lang` = ''");
            foreach ($values as $key => $value) {
                $key = (string) $key;
                if (preg_match(self::KEY_PATTERN, $key) !== 1) { throw new InvalidArgumentException('Invalid settings key: ' . $key); }
                if ($value === null) {
                    $delete->execute([$key]);
                    continue;
                }
                $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $exists->execute([$key]);
                $found = (int) $exists->fetchColumn() > 0;
                $exists->closeCursor();
                if ($found) {
                    $update->execute([$json, $key]);
                } else {
                    $insert->execute([$key, $json]);
                }
            }
        });
    }
}
