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
        private readonly string $baseUrl,
        private readonly ?string $foldersTable = null
    ) {
    }

    /** Sorting the library: newest (default), oldest, name, largest. */
    public const SORTS = ['newest', 'oldest', 'name', 'largest'];

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
     * $more (0.18): 'folder' => folder id, or 'unfiled' (no folder or a folder that is gone);
     * 'no_alt' => true (images without alt text); 'sort' => one of SORTS; 'uploaded_by' => user id.
     *
     * @param array{folder?: int|string|null, no_alt?: bool, sort?: string, uploaded_by?: ?int} $more
     * @return array{items: list<MediaItem>, total: int, page: int, pages: int}
     */
    public function search(string $term = '', ?string $kind = null, bool $unused = false, int $page = 1, int $perPage = 40, array $more = []): array
    {
        $query = $this->filtered($term, $kind, $unused, $more);
        $total = $this->filtered($term, $kind, $unused, $more)->count();
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $pages));
        $query = match ($more['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at', 'asc')->orderBy('id', 'asc'),
            'name' => $query->orderByRaw("LOWER(CASE WHEN `title` <> '' THEN `title` ELSE `file_name` END) ASC")->orderBy('id', 'asc'),
            'largest' => $query->orderBy('bytes', 'desc')->orderBy('id', 'desc'),
            default => $query->orderBy('created_at', 'desc')->orderBy('id', 'desc'),
        };
        $rows = $query->limit($perPage)->offset(($page - 1) * $perPage)->get();
        $items = [];
        foreach ($rows as $row) { $item = MediaItem::fromRow($row, $this->baseUrl); $this->cache[$item->id] = $item; $items[] = $item; }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The newest items whose file name, title or alt text contains $term, without
     * counting the rest (Ctrl+K search, 0.20).
     *
     * @return list<MediaItem>
     */
    public function findMatching(string $term, int $limit): array
    {
        $rows = $this->filtered($term, null, false)->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(max(1, $limit))->get();
        $items = [];
        foreach ($rows as $row) { $items[] = MediaItem::fromRow($row, $this->baseUrl); }
        return $items;
    }

    /** @param array{folder?: int|string|null, no_alt?: bool, sort?: string, uploaded_by?: ?int} $more */
    private function filtered(string $term, ?string $kind, bool $unused, array $more = []): Database
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
        $folder = $more['folder'] ?? null;
        if ($folder === 'unfiled') {
            $query = $this->foldersTable === null ? $query->whereRaw('`folder_id` IS NULL', [])
                : $query->whereRaw('(`folder_id` IS NULL OR `folder_id` NOT IN (SELECT `id` FROM `' . $this->foldersTable . '`))', []);
        } elseif (is_int($folder)) {
            $query = $query->where('folder_id', '=', $folder);
        }
        if (($more['no_alt'] ?? false) === true) {
            $query = $query->where('kind', '=', 'image')->whereRaw("(`alt` IS NULL OR `alt` = '' OR `alt` = '{}' OR `alt` = '[]')", []);
        }
        if (is_int($more['uploaded_by'] ?? null)) {
            $query = $query->where('uploaded_by', '=', $more['uploaded_by']);
        }
        return $query;
    }

    /** @param array{kind: string, path: string, thumb_path: ?string, mime: string, bytes: int, width: ?int, height: ?int, file_name: string, hash?: ?string} $file */
    public function create(array $file, ?int $uploadedBy = null, string $title = '', ?int $folderId = null): MediaItem
    {
        if (!in_array($file['kind'], self::KINDS, true)) { throw new \InvalidArgumentException('Unknown media kind.'); }
        $now = date('Y-m-d H:i:s');
        $row = $file + ['title' => mb_substr($title, 0, 255), 'alt' => null, 'uploaded_by' => $uploadedBy, 'created_at' => $now, 'updated_at' => $now];
        if ($folderId !== null) { $row['folder_id'] = $folderId; }
        if (!isset($row['hash']) || !is_string($row['hash']) || preg_match('/^[0-9a-f]{64}$/D', $row['hash']) !== 1) { unset($row['hash']); }
        $id = $this->db->table($this->table)->insert($row);
        unset($this->cache[$id]);
        $item = $this->find($id) ?? throw new \RuntimeException('The media item was not saved.');
        $this->log('media_uploaded', $item, meta: ['file' => $item->fileName]);
        return $item;
    }

    /** An activity entry about one item (0.19). */
    private function log(string $action, MediaItem $item, array $changes = [], array $meta = []): void
    {
        \FloCMS\Admin\Activity\Activity::record($action, 'media', (string) $item->id, $item->label(), $changes, $meta, $this->db);
    }

    /** @param array<string, string> $alt Alt text per language */
    public function updateDetails(int $id, string $title, array $alt): void
    {
        $before = $this->find($id);
        $alt = array_filter(array_map('trim', $alt), static fn (string $text): bool => $text !== '');
        $this->db->table($this->table)->where('id', '=', $id)->update([
            'title' => mb_substr(trim($title), 0, 255),
            'alt' => $alt === [] ? null : json_encode($alt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        unset($this->cache[$id]);
        $after = $this->find($id);
        if ($before !== null && $after !== null) {
            $changes = [];
            if ($before->title !== $after->title) {
                $changes[] = ['field' => 'title', 'label' => AdminLang::get('media.item_title'), 'lang' => null, 'type' => 'value', 'before' => $before->title, 'after' => $after->title];
            }
            $old = $before->altValues();
            $new = $after->altValues();
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $language) {
                if (($old[$language] ?? '') !== ($new[$language] ?? '')) {
                    $changes[] = ['field' => 'alt', 'label' => AdminLang::get('media.alt'), 'lang' => (string) $language, 'type' => 'value', 'before' => mb_strimwidth($old[$language] ?? '', 0, 300, '…'), 'after' => mb_strimwidth($new[$language] ?? '', 0, 300, '…')];
                }
            }
            if ($changes !== []) { $this->log('media_updated', $after, $changes); }
        }
        // Read back for the log only: later reads must see changes made by other code paths.
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
        $this->log('media_replaced', $item, [['field' => 'file', 'label' => AdminLang::get('activity.file'), 'lang' => null, 'type' => 'value', 'before' => $item->fileName, 'after' => (string) $file['file_name']]]);
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
        $this->log('media_deleted', $item, meta: ['file' => $item->fileName]);
        return array_values(array_filter([$item->path, $item->thumbPath]));
    }

    /**
     * Put items in a folder (null: Unfiled). Returns how many rows changed.
     *
     * @param list<int|string> $ids
     */
    public function moveToFolder(array $ids, ?int $folderId): int
    {
        $ids = self::ids($ids);
        if ($ids === []) { return 0; }
        $moved = 0;
        $changed = 0;
        $different = $this->db->driver() === 'sqlite' ? '`folder_id` IS NOT ?' : 'NOT (`folder_id` <=> ?)';
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $changed += (int) $this->db->query('SELECT COUNT(*) FROM `' . $this->table . '` WHERE `id` IN (' . $in . ') AND ' . $different, [...$chunk, $folderId])->fetchColumn();
            $moved += $this->db->query('UPDATE `' . $this->table . '` SET `folder_id` = ?, `updated_at` = ? WHERE `id` IN (' . $in . ')', [$folderId, date('Y-m-d H:i:s'), ...$chunk])->rowCount();
            foreach ($chunk as $id) { unset($this->cache[$id]); }
        }
        if ($changed > 0) {
            $folder = AdminLang::get('media.unfiled');
            if ($folderId !== null) {
                try { $folder = MediaLibrary::folders()->label($folderId); } catch (\Throwable) { $folder = '#' . $folderId; }
            }
            $first = $changed === 1 && count($ids) === 1 ? $this->find($ids[0]) : null;
            \FloCMS\Admin\Activity\Activity::record('media_moved', 'media', $first !== null ? (string) $first->id : null, $first?->label(), meta: ['count' => $changed, 'folder' => $folder], db: $this->db);
            if ($first !== null) { unset($this->cache[$first->id]); }
        }
        return $moved;
    }

    /** The spot to keep in view when the image is cropped, in percent from the top-left corner; null removes it. */
    public function setFocal(int $id, ?int $x, ?int $y): void
    {
        if ($x === null || $y === null) { $x = $y = null; } else { $x = max(0, min(100, $x)); $y = max(0, min(100, $y)); }
        $this->db->table($this->table)->where('id', '=', $id)->update(['focal_x' => $x, 'focal_y' => $y, 'updated_at' => date('Y-m-d H:i:s')]);
        unset($this->cache[$id]);
    }

    /** An item whose uploaded file had exactly this content (SHA-256), if any. */
    public function findByHash(string $hash, ?int $except = null): ?MediaItem
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) { return null; }
        $query = $this->db->table($this->table)->where('hash', '=', $hash);
        if ($except !== null) { $query = $query->where('id', '<>', $except); }
        $row = $query->orderBy('id', 'asc')->first();
        if ($row === null) { return null; }
        $item = MediaItem::fromRow($row, $this->baseUrl);
        $this->cache[$item->id] = $item;
        return $item;
    }

    /** Store the fingerprint of an item's file (imports and older items). */
    public function setHash(int $id, string $hash): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) { return; }
        $this->db->table($this->table)->where('id', '=', $id)->update(['hash' => $hash]);
        unset($this->cache[$id]);
    }

    /**
     * Numbers for the Media page's header.
     *
     * @return array{items: int, images: int, videos: int, bytes: int, no_alt: int}
     */
    public function stats(): array
    {
        $row = $this->db->query("SELECT COUNT(*), SUM(CASE WHEN `kind` = 'image' THEN 1 ELSE 0 END), SUM(`bytes`),
            SUM(CASE WHEN `kind` = 'image' AND (`alt` IS NULL OR `alt` = '' OR `alt` = '{}' OR `alt` = '[]') THEN 1 ELSE 0 END) FROM `" . $this->table . '`')->fetch(\PDO::FETCH_NUM) ?: [0, 0, 0, 0];
        $items = (int) $row[0];
        $images = (int) $row[1];
        return ['items' => $items, 'images' => $images, 'videos' => $items - $images, 'bytes' => (int) $row[2], 'no_alt' => (int) $row[3]];
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
