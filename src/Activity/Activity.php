<?php
declare(strict_types=1);

namespace FloCMS\Admin\Activity;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Trash\Trash;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Database;

/**
 * The activity log (0.19): who created, changed, deleted or restored what in the
 * panel, and when, with the fields that changed (before → after). Shown as
 * History on each record and on the Activity page (permission activity.view).
 *
 * On when config/admin.php does not switch it off and its table exists. Writing
 * an entry never breaks the change it describes: a failure is only logged.
 *
 * Your own entries:
 *
 *     Activity::log('published', $record, ['reason' => 'Scheduled'], $this);
 */
final class Activity
{
    /** The permission of the Activity page. */
    public const PERMISSION = 'activity.view';

    private static ?string $status = null;
    private static ?Database $statusFor = null;

    /** 'off' (config), 'missing' (no table yet) or 'ready'. */
    public static function status(?Database $db = null): string
    {
        if (!Admin::config()->activity()['enabled']) { return 'off'; }
        $db ??= App::db();
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            try {
                $db->query('SELECT `id`, `action`, `resource`, `record_id`, `changes`, `meta`, `created_at` FROM `' . self::table() . '` WHERE 1 = 0');
                self::$status = 'ready';
            } catch (\Throwable) {
                self::$status = 'missing';
            }
        }
        return self::$status;
    }

    public static function enabled(?Database $db = null): bool
    {
        return self::status($db) === 'ready';
    }

    public static function table(): string { return Admin::config()->activity()['table']; }

    /** Days entries are kept; 0 = forever. */
    public static function days(): int { return Admin::config()->activity()['days']; }

    public static function reset(): void
    {
        self::$status = null;
        self::$statusFor = null;
    }

    /** Whether the current user may open the Activity page. */
    public static function canView(): bool
    {
        return self::enabled() && Auth::can(self::PERMISSION);
    }

    /**
     * Write your own entry, e.g. from an action or a resource hook. $action is a
     * short name (letters, digits, "_", "-" or "."); a site label
     * admin.activity.badge.<action> names it, else it reads as "Published".
     *
     * @param array<string, scalar|null> $meta details shown with the entry
     */
    public static function log(string $action, ?object $record = null, array $meta = [], Resource|string|null $resource = null): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $action) !== 1) { throw new \InvalidArgumentException('An activity action is 1–40 letters, digits, "_", "-" or ".".'); }
        $class = $resource instanceof Resource ? $resource::class : $resource;
        if (is_string($class) && !is_subclass_of($class, Resource::class)) {
            $class = Admin::registry()->find($class);
        }
        $slug = is_string($class) ? $class::getSlug() : null;
        $id = null;
        $title = null;
        if ($record !== null && is_string($class)) {
            $id = (string) ($record->{$class::getPrimaryKey()} ?? '');
            $title = Trash::titleOf(new $class(), $record);
        }
        $clean = [];
        foreach ($meta as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) { $clean[mb_substr($key, 0, 40)] = $value === null ? null : mb_substr((string) $value, 0, 1000); }
        }
        self::record($action, $slug, $id, $title, meta: $clean);
    }

    /**
     * The parent note of a child record's entry (0.22): its parents' History
     * lists it too ("Units · created Flat 3"). [] for a record without parents.
     *
     * @return array{parents?: list<string>}
     */
    public static function parents(Resource $resource, object $record): array
    {
        $parents = \FloCMS\Admin\Relations\RelationManager::parentsOf($resource, $record);
        return $parents === [] ? [] : ['parents' => $parents];
    }

    /**
     * Write an entry (the panel's own changes).
     *
     * @param list<array<string, mixed>> $changes
     * @param array<string, mixed> $meta
     */
    public static function record(string $action, ?string $resource = null, ?string $recordId = null, ?string $title = null, array $changes = [], array $meta = [], ?Database $db = null): void
    {
        // An import (0.25) writes one entry for the whole run, not one per record.
        if (self::$quiet && in_array($action, ['created', 'updated', 'deleted', 'restored'], true)) { return; }
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return; }
        try {
            $db->table(self::table())->insert([
                'action' => mb_substr($action, 0, 40),
                'resource' => $resource !== null ? mb_substr($resource, 0, 64) : null,
                'record_id' => $recordId !== null && $recordId !== '' ? mb_substr($recordId, 0, 191) : null,
                'title' => $title !== null && $title !== '' ? mb_substr($title, 0, 2000) : null,
                'user_id' => Actor::id(),
                'user_name' => Actor::name(),
                'changes' => $changes === [] ? null : json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'meta' => $meta === [] ? null : json_encode((object) $meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'batch' => in_array($action, ['deleted', 'restored', 'purged'], true) ? Trash::batch() : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $error) {
            error_log('flocms-admin: could not write the activity log: ' . $error->getMessage());
        }
    }

    /* ---------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------- */

    private static bool $quiet = false;

    /**
     * Run $callback without one entry per created, updated, deleted or restored
     * record (0.25: an import and its Undo log one entry for the whole run).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function quietly(callable $callback): mixed
    {
        $was = self::$quiet;
        self::$quiet = true;
        try { return $callback(); } finally { self::$quiet = $was; }
    }

    public static function find(int $id, ?Database $db = null): ?ActivityEntry
    {
        $db ??= self::connection();
        $row = $db->table(self::table())->where('id', '=', $id)->first();
        return $row !== null ? ActivityEntry::fromRow($row) : null;
    }

    /**
     * The history of one record, newest first.
     *
     * @return list<ActivityEntry>
     */
    public static function forRecord(string $resource, string $recordId, int $limit = 100, ?Database $db = null): array
    {
        $db ??= self::connection();
        $own = static fn (): Database => $db->table(self::table())->where('resource', '=', $resource)->where('record_id', '=', $recordId);
        $entries = array_map(static fn (object $row): ActivityEntry => ActivityEntry::fromRow($row), $own()->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($limit)->get());
        $class = Admin::registry()->find($resource);
        if ($class === null || \FloCMS\Admin\Relations\RelationManager::of(new $class()) === []) { return $entries; }
        // A parent's History also lists its related records' entries (0.22), found by their parent note:
        // asked for apart, so the parent's own entries are never pushed out by its children's.
        $note = $resource . ':' . $recordId;
        $rows = $db->table(self::table())->where('resource', '!=', $resource)
            ->whereRaw("`meta` LIKE ? ESCAPE '!'", ['%"' . strtr($note, ['!' => '!!', '%' => '!%', '_' => '!_']) . '"%'])
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($limit)->get();
        foreach ($rows as $row) {
            $entry = ActivityEntry::fromRow($row);
            // The LIKE only narrows: keep entries whose note really names this record, for people who may view them.
            if (in_array($note, $entry->parents(), true) && ($child = $entry->resourceClass()) !== null && Auth::can($child::permission('view'))) { $entries[] = $entry; }
        }
        usort($entries, static fn (ActivityEntry $a, ActivityEntry $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);
        return array_slice($entries, 0, $limit);
    }

    /**
     * One page of the Activity page.
     *
     * @param array{user?: ?int, resource?: ?string, group?: ?string, since?: ?string, search?: string} $filters
     * @return array{items: list<ActivityEntry>, total: int}
     */
    public static function entries(array $filters, int $page = 1, int $perPage = 50, ?Database $db = null): array
    {
        $db ??= self::connection();
        $total = self::filtered($db, $filters)->count();
        $rows = self::filtered($db, $filters)->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->limit($perPage)->offset(max(0, ($page - 1) * $perPage))->get();
        return ['items' => array_map(static fn (object $row): ActivityEntry => ActivityEntry::fromRow($row), $rows), 'total' => $total];
    }

    /** Actions of each group of the Action switch. */
    public const GROUP_ACTIONS = [
        'created' => ['created', 'media_uploaded', 'role_created'],
        'edited' => ['updated', 'settings_saved', 'page_saved', 'media_updated', 'media_replaced', 'profile_updated', 'role_updated', 'role_reset'],
        'deleted' => ['deleted', 'purged', 'media_deleted', 'role_deleted'],
        'restored' => ['restored'],
    ];

    private static function filtered(Database $db, array $filters): Database
    {
        $query = $db->table(self::table());
        if (isset($filters['user']) && is_int($filters['user'])) { $query = $query->where('user_id', '=', $filters['user']); }
        if (isset($filters['resource']) && is_string($filters['resource']) && $filters['resource'] !== '') { $query = $query->where('resource', '=', $filters['resource']); }
        $group = $filters['group'] ?? null;
        if (is_string($group) && isset(self::GROUP_ACTIONS[$group])) {
            $query = $query->whereIn('action', self::GROUP_ACTIONS[$group]);
        } elseif ($group === 'other') {
            $known = array_merge(...array_values(self::GROUP_ACTIONS));
            $query = $query->whereRaw('`action` NOT IN (' . implode(', ', array_fill(0, count($known), '?')) . ')', $known);
        }
        if (isset($filters['since']) && is_string($filters['since']) && $filters['since'] !== '') { $query = $query->where('created_at', '>=', $filters['since']); }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query = $query->whereRaw("(`title` LIKE ? ESCAPE '!' OR `changes` LIKE ? ESCAPE '!' OR `user_name` LIKE ? ESCAPE '!')", [$like, $like, $like]);
        }
        return $query;
    }

    /**
     * People who appear in the log, for the "Who" filter.
     *
     * @return array<int, string> user id => name
     */
    public static function people(?Database $db = null): array
    {
        $db ??= self::connection();
        $rows = $db->query('SELECT `user_id`, MAX(`user_name`) AS `name` FROM `' . self::table() . '` WHERE `user_id` IS NOT NULL GROUP BY `user_id`')->fetchAll(\PDO::FETCH_ASSOC);
        $people = [];
        foreach ($rows as $row) { $people[(int) $row['user_id']] = (string) ($row['name'] ?? '') !== '' ? (string) $row['name'] : '#' . $row['user_id']; }
        asort($people, SORT_NATURAL | SORT_FLAG_CASE);
        return $people;
    }

    /** @return list<string> resource values that appear in the log */
    public static function resources(?Database $db = null): array
    {
        $db ??= self::connection();
        $rows = $db->query('SELECT DISTINCT `resource` FROM `' . self::table() . '` WHERE `resource` IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_map('strval', $rows));
    }

    /**
     * Delete entries older than the configured days.
     *
     * @return int how many were deleted
     */
    public static function purgeExpired(?Database $db = null): int
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db) || self::days() <= 0) { return 0; }
        return $db->query('DELETE FROM `' . self::table() . '` WHERE `created_at` < ?', [date('Y-m-d H:i:s', time() - self::days() * 86400)])->rowCount();
    }

    private static function connection(): Database
    {
        return App::db() ?? throw new \RuntimeException('The activity log needs a database connection.');
    }
}
