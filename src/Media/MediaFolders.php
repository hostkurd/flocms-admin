<?php
declare(strict_types=1);

namespace FloCMS\Admin\Media;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Core\Database;
use FloCMS\Core\ValidationException;

/**
 * Folders of the media library (0.18): categories kept in the database and shown
 * as a tree. They never exist on disk, so moving an item never changes its
 * address. Each item is in one folder, or in none ("Unfiled").
 */
final class MediaFolders
{
    /** Folders inside folders, at most this deep (Listings / Villas / Sea view / 2026). */
    public const MAX_DEPTH = 4;
    public const NAME_MAX = 120;

    /** @var array<int, array{id: int, parent: ?int, name: string}>|null */
    private ?array $all = null;

    public function __construct(
        private readonly Database $db,
        private readonly string $table,
        private readonly string $mediaTable
    ) {
    }

    public function uses(Database $db): bool { return $this->db === $db; }

    /** @return array<int, array{id: int, parent: ?int, name: string}> Every folder by id. */
    public function all(): array
    {
        if ($this->all === null) {
            $this->all = [];
            foreach ($this->db->table($this->table)->get() as $row) {
                $id = (int) $row->id;
                $parent = $row->parent_id === null ? null : (int) $row->parent_id;
                $this->all[$id] = ['id' => $id, 'parent' => $parent, 'name' => (string) $row->name];
            }
        }
        return $this->all;
    }

    /** @return array{id: int, parent: ?int, name: string}|null */
    public function find(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * Folders in reading order (each followed by its subfolders, names in natural
     * order), with how many items are directly inside each.
     *
     * @return list<array{id: int, parent: ?int, name: string, depth: int, count: int, children: int}>
     */
    public function tree(): array
    {
        $counts = $this->counts();
        $children = [];
        foreach ($this->all() as $folder) { $children[$folder['parent'] ?? 0][] = $folder; }
        foreach ($children as &$list) { usort($list, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']); }
        unset($list);
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $children, $counts): void {
            foreach ($children[$parent] ?? [] as $folder) {
                // A parent that points at a missing folder (edited by hand) is shown at the top.
                $out[] = $folder + ['depth' => $depth, 'count' => $counts[$folder['id']] ?? 0, 'children' => count($children[$folder['id']] ?? [])];
                $walk($folder['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        $shown = array_column($out, 'id');
        foreach ($this->all() as $folder) {
            if (!in_array($folder['id'], $shown, true)) {
                $out[] = $folder + ['depth' => 0, 'count' => $counts[$folder['id']] ?? 0, 'children' => 0];
            }
        }
        return $out;
    }

    /**
     * Items directly in each folder, plus 'all' and 'unfiled' (no folder or a folder that is gone).
     *
     * @return array<int|string, int>
     */
    public function counts(): array
    {
        $counts = ['all' => 0, 'unfiled' => 0];
        $rows = $this->db->query('SELECT `folder_id`, COUNT(*) AS c FROM `' . $this->mediaTable . '` GROUP BY `folder_id`')->fetchAll(\PDO::FETCH_NUM);
        $known = $this->all();
        foreach ($rows as [$folder, $count]) {
            $count = (int) $count;
            $counts['all'] += $count;
            if ($folder === null || !isset($known[(int) $folder])) { $counts['unfiled'] += $count; } else { $counts[(int) $folder] = $count; }
        }
        return $counts;
    }

    /** @return list<string> Folder names from the top down to this one. */
    public function path(?int $id): array
    {
        $names = [];
        $seen = [];
        while ($id !== null && ($folder = $this->find($id)) !== null && !isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($names, $folder['name']);
            $id = $folder['parent'];
        }
        return $names;
    }

    /** "Listings / Villas", or '' for no folder. */
    public function label(?int $id): string
    {
        return implode(' / ', $this->path($id));
    }

    /** @return list<int> This folder and every folder inside it. */
    public function descendants(int $id): array
    {
        $ids = [$id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($this->all() as $folder) {
                if ($folder['parent'] === $ids[$i] && !in_array($folder['id'], $ids, true)) { $ids[] = $folder['id']; }
            }
        }
        return $ids;
    }

    /**
     * A folder by its names from the top ("Listings/Villas"), or by a name that only one
     * folder has ("Homepage slider"). Case does not matter.
     */
    public function resolve(string $path): ?int
    {
        $parts = array_values(array_filter(array_map(static fn (string $p): string => self::clean($p), explode('/', $path)), static fn (string $p): bool => $p !== ''));
        if ($parts === []) { return null; }
        $parent = null;
        foreach ($parts as $index => $name) {
            $match = $this->child($parent, $name);
            if ($match === null) {
                if ($index === 0 && count($parts) === 1) {
                    $same = array_values(array_filter($this->all(), static fn (array $f): bool => mb_strtolower($f['name']) === mb_strtolower($name)));
                    return count($same) === 1 ? $same[0]['id'] : null;
                }
                return null;
            }
            $parent = $match;
        }
        return $parent;
    }

    /**
     * The folder at this path from the top ("Listings/Villas"), made (with any missing parent)
     * when it does not exist yet. A media field's ->folder() uses it for its uploads.
     */
    public function ensure(string $path): int
    {
        $parent = null;
        $parts = array_values(array_filter(array_map(static fn (string $p): string => self::clean($p), explode('/', $path)), static fn (string $p): bool => $p !== ''));
        if ($parts === []) { throw self::refusal('folder', 'media.folder_name_required'); }
        // Check the whole path first, so a refused one leaves no folders half made.
        if (count($parts) > self::MAX_DEPTH) { throw self::refusal('folder', 'media.folder_too_deep', ['max' => (string) self::MAX_DEPTH]); }
        foreach ($parts as $name) { $this->validName($name); }
        return (int) $this->db->transaction(function () use ($parts, $parent): int {
            foreach ($parts as $name) {
                $parent = $this->child($parent, $name) ?? $this->create($name, $parent);
            }
            return (int) $parent;
        });
    }

    public function create(string $name, ?int $parentId): int
    {
        $name = $this->validName($name);
        if ($parentId !== null && $this->find($parentId) === null) { throw self::refusal('parent', 'media.folder_missing'); }
        if (count($this->path($parentId)) + 1 > self::MAX_DEPTH) { throw self::refusal('parent', 'media.folder_too_deep', ['max' => (string) self::MAX_DEPTH]); }
        if ($this->child($parentId, $name) !== null) { throw self::refusal('name', 'media.folder_taken'); }
        $now = date('Y-m-d H:i:s');
        $id = (int) $this->db->table($this->table)->insert(['parent_id' => $parentId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        $this->all = null;
        return $id;
    }

    public function rename(int $id, string $name): void
    {
        $folder = $this->find($id) ?? throw self::refusal('_form', 'media.folder_missing');
        $name = $this->validName($name);
        $other = $this->child($folder['parent'], $name);
        if ($other !== null && $other !== $id) { throw self::refusal('name', 'media.folder_taken'); }
        $this->db->table($this->table)->where('id', '=', $id)->update(['name' => $name, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->all = null;
    }

    /** Put a folder (with everything in it) inside another folder, or at the top (null). */
    public function move(int $id, ?int $parentId): void
    {
        $folder = $this->find($id) ?? throw self::refusal('_form', 'media.folder_missing');
        if ($parentId === $folder['parent']) { return; }
        if ($parentId !== null) {
            if ($this->find($parentId) === null) { throw self::refusal('parent', 'media.folder_missing'); }
            if (in_array($parentId, $this->descendants($id), true)) { throw self::refusal('parent', 'media.folder_into_itself'); }
        }
        if (count($this->path($parentId)) + $this->height($id) > self::MAX_DEPTH) { throw self::refusal('parent', 'media.folder_too_deep', ['max' => (string) self::MAX_DEPTH]); }
        if ($this->child($parentId, $folder['name']) !== null) { throw self::refusal('name', 'media.folder_taken'); }
        $this->db->table($this->table)->where('id', '=', $id)->update(['parent_id' => $parentId, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->all = null;
    }

    /**
     * Delete a folder. Its items and subfolders move up one level (to its parent,
     * or to Unfiled and the top); a subfolder whose name is taken there gets " (2)".
     */
    public function delete(int $id): void
    {
        $folder = $this->find($id) ?? throw self::refusal('_form', 'media.folder_missing');
        $parent = $folder['parent'];
        $this->db->transaction(function () use ($id, $parent): void {
            $this->db->table($this->mediaTable)->where('folder_id', '=', $id)->update(['folder_id' => $parent]);
            foreach ($this->all() as $child) {
                if ($child['parent'] !== $id) { continue; }
                $name = $child['name'];
                for ($n = 2; ($taken = $this->child($parent, $name)) !== null && $taken !== $child['id'] && $taken !== $id; $n++) {
                    $name = mb_substr($child['name'], 0, self::NAME_MAX - 6) . ' (' . $n . ')';
                }
                $this->db->table($this->table)->where('id', '=', $child['id'])->update(['parent_id' => $parent, 'name' => $name, 'updated_at' => date('Y-m-d H:i:s')]);
                $this->all[$child['id']] = ['id' => $child['id'], 'parent' => $parent, 'name' => $name];
            }
            $this->db->table($this->table)->where('id', '=', $id)->delete();
        });
        $this->all = null;
    }

    /** The id of the folder called $name directly inside $parent (case-insensitive). */
    private function child(?int $parent, string $name): ?int
    {
        foreach ($this->all() as $folder) {
            if ($folder['parent'] === $parent && mb_strtolower($folder['name']) === mb_strtolower($name)) { return $folder['id']; }
        }
        return null;
    }

    /** Levels in this folder's subtree, itself included (a folder alone: 1). */
    private function height(int $id): int
    {
        $height = 1;
        foreach ($this->all() as $folder) {
            if ($folder['parent'] === $id) { $height = max($height, 1 + $this->height($folder['id'])); }
        }
        return $height;
    }

    private function validName(string $name): string
    {
        $name = self::clean($name);
        if ($name === '') { throw self::refusal('name', 'media.folder_name_required'); }
        if (str_contains($name, '/')) { throw self::refusal('name', 'media.folder_name_slash'); }
        if (mb_strlen($name) > self::NAME_MAX) { throw self::refusal('name', 'media.folder_name_long', ['max' => (string) self::NAME_MAX]); }
        return $name;
    }

    /** Trimmed, control characters removed, runs of spaces made one. */
    private static function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name)));
    }

    /** @param array<string, string> $replace */
    private static function refusal(string $field, string $key, array $replace = []): ValidationException
    {
        if (!class_exists(ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
        return new ValidationException([$field => AdminLang::get($key, $replace)]);
    }
}
