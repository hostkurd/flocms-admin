<?php
declare(strict_types=1);

namespace FloCMS\Admin\Media;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Core\Database;
use FloCMS\Core\ValidationException;

/**
 * Rows of the media library and where they are used. Files are written by
 * MediaFiles; this class never touches the disk except through it after a
 * commit (deleting replaced or removed files).
 */
final class MediaStore
{
    public const KINDS = ['image', 'video'];

    /** @var array<int, ?MediaItem> Loaded once per request */
    private array $cache = [];

    public function __construct(
        private readonly Database $db,
        private readonly string $table,
        private readonly string $usageTable,
        private readonly string $baseUrl
    ) {
    }

    public function uses(Database $db): bool { return $this->db === $db; }
    public function baseUrl(): string { return $this->baseUrl; }
    public function connection(): Database { return $this->db; }

    public function find(int $id): ?MediaItem
    {
        if ($id < 1) { return null; }
        if (!array_key_exists($id, $this->cache)) { $this->preload([$id]); }
        return $this->cache[$id] ?? null;
    }

    /**
     * Items for these ids, in the given order, without missing ones.
     *
     * @param list<int|string> $ids
     * @return list<MediaItem>
     */
    public function findMany(array $ids): array
    {
        $ids = self::ids($ids);
        $this->preload($ids);
        return array_values(array_filter(array_map(fn (int $id): ?MediaItem => $this->cache[$id] ?? null, $ids)));
    }

    /** Load these ids in one query (website lists call media_preload()). @param list<int|string> $ids */
    public function preload(array $ids): void
    {
        $missing = array_values(array_filter(self::ids($ids), fn (int $id): bool => !array_key_exists($id, $this->cache)));
        foreach (array_chunk($missing, 500) as $chunk) {
            foreach ($chunk as $id) { $this->cache[$id] = null; }
            foreach ($this->db->table($this->table)->whereIn('id', $chunk)->get() as $row) {
                $item = MediaItem::fromRow($row, $this->baseUrl);
                $this->cache[$item->id] = $item;
            }
        }
    }

    /**
     * One page of the library, newest first.
     *
     * @return array{items: list<MediaItem>, total: int, page: int, pages: int}
     */
    public function search(string $term = '', ?string $kind = null, bool $unused = false, int $page = 1, int $perPage = 40): array
    {
        $query = $this->filtered($term, $kind, $unused);
        $total = $this->filtered($term, $kind, $unused)->count();
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $pages));
        $rows = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($perPage)->offset(($page - 1) * $perPage)->get();
        $items = [];
        foreach ($rows as $row) { $item = MediaItem::fromRow($row, $this->baseUrl); $this->cache[$item->id] = $item; $items[] = $item; }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    private function filtered(string $term, ?string $kind, bool $unused): Database
    {
        $query = $this->db->table($this->table);
        if ($kind !== null && in_array($kind, self::KINDS, true)) { $query = $query->where('kind', '=', $kind); }
        $term = trim($term);
        if ($term !== '') {
            $like = '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query = $query->where(function (Database $group) use ($like): void {
                foreach (['file_name', 'title', 'alt'] as $column) { $group->orWhereRaw('`' . $column . "` LIKE ? ESCAPE '!'", [$like]); }
            });
        }
        if ($unused) {
            $query = $query->whereRaw('NOT EXISTS (SELECT 1 FROM `' . $this->usageTable . '` u WHERE u.`media_id` = `' . $this->table . '`.`id`)', []);
        }
        return $query;
    }

    /** @param array{kind: string, path: string, thumb_path: ?string, mime: string, bytes: int, width: ?int, height: ?int, file_name: string} $file */
    public function create(array $file, ?int $uploadedBy = null, string $title = ''): MediaItem
    {
        if (!in_array($file['kind'], self::KINDS, true)) { throw new \InvalidArgumentException('Unknown media kind.'); }
        $now = date('Y-m-d H:i:s');
        $id = $this->db->table($this->table)->insert($file + ['title' => mb_substr($title, 0, 255), 'alt' => null, 'uploaded_by' => $uploadedBy, 'created_at' => $now, 'updated_at' => $now]);
        unset($this->cache[$id]);
        return $this->find($id) ?? throw new \RuntimeException('The media item was not saved.');
    }

    /** @param array<string, string> $alt Alt text per language */
    public function updateDetails(int $id, string $title, array $alt): void
    {
        $alt = array_filter(array_map('trim', $alt), static fn (string $text): bool => $text !== '');
        $this->db->table($this->table)->where('id', '=', $id)->update([
            'title' => mb_substr(trim($title), 0, 255),
            'alt' => $alt === [] ? null : json_encode($alt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        unset($this->cache[$id]);
    }

    /**
     * New files for an item; the id, title, alt text and picker usages stay.
     * Refused while the item is used inside rich text (its address is in that HTML).
     *
     * @param array{kind: string, path: string, thumb_path: ?string, mime: string, bytes: int, width: ?int, height: ?int, file_name: string} $file
     * @return list<string> The old files, to delete after the commit.
     */
    public function replace(int $id, array $file): array
    {
        $item = $this->find($id) ?? throw self::refusal(['_form' => AdminLang::get('media.missing')]);
        if ($file['kind'] !== $item->kind) { throw self::refusal(['file' => AdminLang::get('media.replace_kind')]); }
        if ($this->usedInText($id) !== []) { throw self::refusal(['_form' => AdminLang::get('media.replace_in_text')]); }
        $this->db->table($this->table)->where('id', '=', $id)->update($file + ['updated_at' => date('Y-m-d H:i:s')]);
        unset($this->cache[$id]);
        return array_values(array_filter([$item->path, $item->thumbPath]));
    }

    /**
     * Remove an unused item. Refused while it is used anywhere.
     *
     * @return list<string> Its files, to delete after the commit.
     */
    public function delete(int $id): array
    {
        $item = $this->find($id) ?? throw self::refusal(['_form' => AdminLang::get('media.missing')]);
        if ($this->usage($id) !== []) { throw self::refusal(['_form' => AdminLang::get('media.in_use')]); }
        // One statement, so a record saved meanwhile can't start using the item between the check and the delete.
        $deleted = $this->db->query('DELETE FROM ' . $this->table . ' WHERE id = ? AND NOT EXISTS (SELECT 1 FROM ' . $this->usageTable . ' WHERE media_id = ?)', [$id, $id])->rowCount();
        if ($deleted === 0) { throw self::refusal(['_form' => AdminLang::get('media.in_use')]); }
        $this->cache[$id] = null;
        return array_values(array_filter([$item->path, $item->thumbPath]));
    }

    /** How many places use each item. @param list<int> $ids @return array<int, int> */
    public function usageCounts(array $ids): array
    {
        $ids = self::ids($ids);
        if ($ids === []) { return []; }
        $rows = $this->db->query('SELECT `media_id`, COUNT(*) AS c FROM `' . $this->usageTable . '` WHERE `media_id` IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') GROUP BY `media_id`', $ids)->fetchAll(\PDO::FETCH_NUM);
        $counts = array_fill_keys($ids, 0);
        foreach ($rows as [$id, $count]) { $counts[(int) $id] = (int) $count; }
        return $counts;
    }

    /** @return list<array{resource: string, record_id: string, field: string}> */
    public function usage(int $id): array
    {
        $rows = $this->db->table($this->usageTable)->where('media_id', '=', $id)->orderBy('resource')->orderBy('record_id')->get();
        return array_map(static fn (object $row): array => ['resource' => (string) $row->resource, 'record_id' => (string) $row->record_id, 'field' => (string) $row->field], $rows);
    }

    /** @return list<array{resource: string, record_id: string, field: string}> Places where the item is inside rich text. */
    public function usedInText(int $id): array
    {
        return array_values(array_filter($this->usage($id), fn (array $row): bool => MediaUsage::isTextField($row['resource'], $row['field'], $row['record_id'])));
    }

    /** Items whose web or thumb file is one of these paths. @param list<string> $paths @return list<int> */
    public function idsForPaths(array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
        $ids = [];
        foreach (array_chunk($paths, 200) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $rows = $this->db->query('SELECT `id` FROM `' . $this->table . '` WHERE `path` IN (' . $marks . ') OR `thumb_path` IN (' . $marks . ')', [...$chunk, ...$chunk])->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $id) { $ids[] = (int) $id; }
        }
        return array_values(array_unique($ids));
    }

    /** Whether a row exists for this path (imports skip known files). */
    public function hasPath(string $path): bool
    {
        return $this->idsForPaths([$path]) !== [];
    }

    /** Which of these ids exist, as kinds. @param list<int|string> $ids @return array<int, string> */
    public function kinds(array $ids): array
    {
        $ids = self::ids($ids);
        $this->preload($ids);
        $kinds = [];
        foreach ($ids as $id) { if (($this->cache[$id] ?? null) !== null) { $kinds[$id] = $this->cache[$id]->kind; } }
        return $kinds;
    }

    /** Replace one record field's usage rows. @param list<int> $ids */
    public function syncUsage(string $resource, string $recordId, string $field, array $ids, bool $strict = false): void
    {
        $this->db->table($this->usageTable)->where('resource', '=', $resource)->where('record_id', '=', $recordId)->where('field', '=', $field)->delete();
        $ids = self::ids($ids);
        $existing = array_keys($this->kinds($ids));
        foreach ($existing as $id) {
            $this->db->table($this->usageTable)->insert(['media_id' => $id, 'resource' => $resource, 'record_id' => $recordId, 'field' => $field]);
        }
        if (!$strict || $ids === []) { return; }
        // A save: an item deleted by someone else since the form was checked refuses it (the
        // caller's transaction rolls back). Read fresh, not from the cache the form check filled;
        // MySQL/MariaDB with a lock, past this transaction's snapshot; SQLite runs one writer at a time.
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $lock = $this->db->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $found = $this->db->query('SELECT id FROM ' . $this->table . ' WHERE id IN (' . $marks . ')' . $lock, $ids)->fetchAll(\PDO::FETCH_COLUMN);
        if (count($found) !== count($ids)) {
            foreach ($ids as $id) { if (!in_array((string) $id, array_map('strval', $found), true)) { $this->cache[$id] = null; } }
            throw self::refusal([$field => AdminLang::get('media.gone')]);
        }
    }

    /** Forget every usage row of a record (it was deleted). */
    public function forgetRecord(string $resource, string $recordId): void
    {
        $this->db->table($this->usageTable)->where('resource', '=', $resource)->where('record_id', '=', $recordId)->delete();
    }

    /** Remove every usage row (a rebuild starts from nothing). */
    public function forgetAll(): void
    {
        $this->db->query('DELETE FROM ' . $this->usageTable);
    }

    /** The newest items (the picker's list without JavaScript). @return list<MediaItem> */
    public function newest(int $limit = 200, ?string $kind = null): array
    {
        return $this->search('', $kind, false, 1, $limit)['items'];
    }

    /** @param array<string, string> $errors */
    private static function refusal(array $errors): ValidationException
    {
        // FloCMS declares ValidationException next to its Validator.
        if (!class_exists(ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
        return new ValidationException($errors);
    }

    /** @param list<int|string> $ids @return list<int> */
    public static function ids(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/D', $id) === 1)) {
                $value = (int) $id;
                if ($value > 0 && $value <= 4294967295 && !in_array($value, $clean, true)) { $clean[] = $value; }
            }
        }
        return $clean;
    }
}
