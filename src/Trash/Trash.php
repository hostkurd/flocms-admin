<?php
declare(strict_types=1);

namespace FloCMS\Admin\Trash;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\BelongsToMany;
use FloCMS\Admin\Form\Fields\FileUpload;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Media\MediaUsage;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Admin\Uploads\Uploads;
use FloCMS\Core\App;
use FloCMS\Core\Database;
use FloCMS\Core\ValidationException;

/**
 * The trash (0.19): a record deleted in the panel leaves its table at once (the
 * website stops showing it) and a copy waits here, with its many-to-many links
 * and upload files, until it is restored with the same id or deleted for good:
 * by hand, or after the configured number of days.
 *
 * On when config/admin.php does not switch it off and its table exists
 * (php flo admin:install writes the migration, php flo migrate creates it).
 * A resource opts out with protected static bool $trash = false.
 */
final class Trash
{
    /** How long an Undo stays possible on the server (the toast shows it for 10 seconds). */
    public const UNDO_SECONDS = 3600;

    private static ?string $status = null;
    private static ?Database $statusFor = null;
    private static ?string $batch = null;

    /** 'off' (config), 'missing' (no table yet: php flo admin:install, php flo migrate) or 'ready'. */
    public static function status(?Database $db = null): string
    {
        if (!Admin::config()->trash()['enabled']) { return 'off'; }
        $db ??= App::db();
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            try {
                $db->query('SELECT `id`, `resource`, `record_id`, `data`, `links`, `files`, `batch`, `deleted_at` FROM `' . self::table() . '` WHERE 1 = 0');
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

    /** Whether deleting a record of this resource puts it in the trash. */
    public static function enabledFor(Resource|string $resource, ?Database $db = null): bool
    {
        $class = is_string($resource) ? $resource : $resource::class;
        return is_subclass_of($class, Resource::class) && $class::usesTrash() && self::enabled($db);
    }

    public static function table(): string { return Admin::config()->trash()['table']; }

    /** Days a record stays in the trash; 0 = until it is emptied by hand. */
    public static function days(): int { return Admin::config()->trash()['days']; }

    /**
     * The delete operation this request belongs to: every record deleted by one
     * request (a bulk delete) shares it, so one Undo brings them all back.
     */
    public static function batch(): string
    {
        return self::$batch ??= bin2hex(random_bytes(16));
    }

    /** Start a new delete operation (commands and tests). */
    public static function newBatch(): void
    {
        self::$batch = null;
    }

    public static function reset(): void
    {
        self::$status = null;
        self::$statusFor = null;
        self::$batch = null;
    }

    /* ---------------------------------------------------------------
     * Deleting
     * ------------------------------------------------------------- */

    /**
     * Copy a record into the trash. Called inside the delete transaction, before
     * its many-to-many links are detached and its row is deleted, so a delete
     * that fails leaves no copy behind.
     */
    public static function capture(Resource $resource, Database $db, object $record): void
    {
        $pk = $resource::getPrimaryKey();
        $id = (string) ($record->{$pk} ?? '');
        if ($id === '') { return; }
        $form = $resource->form(new Form());
        $links = [];
        $files = [];
        foreach ($form->fields() as $name => $field) {
            if ($field instanceof BelongsToMany && ($pivot = $field->getPivot()) !== null) {
                $owner = (string) ($record->{$pivot->parentKey} ?? '');
                if ($owner !== '') { $links[$name] = $pivot->related($db, [$owner])[$owner] ?? []; }
            }
            if ($field instanceof FileUpload && Uploads::managed((string) ($record->{$name} ?? ''), $resource, $field)) {
                $files[] = (string) $record->{$name};
            }
        }
        $db->table(self::table())->insert([
            'resource' => $resource::getSlug(),
            'record_id' => $id,
            'title' => self::titleOf($resource, $record),
            'data' => Snapshot::encode((array) $record),
            'links' => $links === [] ? null : json_encode((object) $links, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'files' => $files === [] ? null : json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'batch' => self::batch(),
            'deleted_by' => Actor::id(),
            'deleted_by_name' => Actor::name(),
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** The raw value of the list's first column (a translatable title stays JSON), at most 2000 characters. */
    public static function titleOf(Resource $resource, object $record): string
    {
        try {
            $primary = $resource->table(new Table())->primaryColumn();
        } catch (\Throwable) {
            $primary = null;
        }
        $value = $primary !== null && isset($record->{$primary}) && is_scalar($record->{$primary}) ? (string) $record->{$primary} : '';
        return mb_substr($value, 0, 2000);
    }

    /* ---------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------- */

    public static function find(int $id, ?Database $db = null): ?TrashEntry
    {
        $db ??= self::connection();
        $row = $db->table(self::table())->where('id', '=', $id)->first();
        return $row !== null ? TrashEntry::fromRow($row) : null;
    }

    /** The newest trash entry of one record, if it is in the trash. */
    public static function findRecord(string $resource, string $recordId, ?Database $db = null): ?TrashEntry
    {
        $db ??= self::connection();
        if (!self::enabled($db)) { return null; }
        $row = $db->table(self::table())->where('resource', '=', $resource)->where('record_id', '=', $recordId)->orderBy('id', 'desc')->first();
        return $row !== null ? TrashEntry::fromRow($row) : null;
    }

    /**
     * One page of entries, newest first.
     *
     * @param list<string> $resources slugs to include
     * @return array{items: list<TrashEntry>, total: int}
     */
    public static function entries(array $resources, string $search = '', ?int $deletedBy = null, int $page = 1, int $perPage = 25, ?Database $db = null): array
    {
        if ($resources === []) { return ['items' => [], 'total' => 0]; }
        $db ??= self::connection();
        $query = self::filtered($db, $resources, $search, $deletedBy);
        $total = $query->count();
        $rows = self::filtered($db, $resources, $search, $deletedBy)->orderBy('deleted_at', 'desc')->orderBy('id', 'desc')
            ->limit($perPage)->offset(max(0, ($page - 1) * $perPage))->get();
        return ['items' => array_map(static fn (object $row): TrashEntry => TrashEntry::fromRow($row), $rows), 'total' => $total];
    }

    /**
     * Every entry of one resource, whoever may see it (for maintenance such as
     * the media usage rebuild; the panel uses entries()).
     *
     * @return list<TrashEntry>
     */
    public static function allOf(string $resource, int $page = 1, int $perPage = 200, ?Database $db = null): array
    {
        $db ??= self::connection();
        $rows = $db->table(self::table())->where('resource', '=', $resource)->orderBy('id', 'asc')
            ->limit($perPage)->offset(max(0, ($page - 1) * $perPage))->get();
        return array_map(static fn (object $row): TrashEntry => TrashEntry::fromRow($row), $rows);
    }

    /** @param list<string> $resources */
    private static function filtered(Database $db, array $resources, string $search, ?int $deletedBy): Database
    {
        $query = self::visibleIn($db, $resources);
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query = $query->whereRaw("(`title` LIKE ? ESCAPE '!' OR `record_id` = ?)", [$like, $search]);
        }
        if ($deletedBy !== null) { $query = $query->where('deleted_by', '=', $deletedBy); }
        return $query;
    }

    /** @param list<string> $resources @return array<string, int> slug => entries */
    public static function counts(array $resources, ?Database $db = null): array
    {
        if ($resources === []) { return []; }
        $db ??= self::connection();
        [$sql, $params] = self::visibleIn($db, $resources, $db->table(self::table())->select(['resource'])->selectRaw('COUNT(*) AS total'))->toSql();
        $rows = $db->query($sql . ' GROUP BY `resource`', $params)->fetchAll(\PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($rows as $row) { $counts[(string) $row['resource']] = (int) $row['total']; }
        return $counts;
    }

    public static function count(string $resource, ?Database $db = null): int
    {
        return self::counts([$resource], $db)[$resource] ?? 0;
    }

    /**
     * People who deleted entries of these resources, for the "Deleted by" filter.
     *
     * @param list<string> $resources
     * @return array<int, string> user id => name
     */
    public static function deleters(array $resources, ?Database $db = null): array
    {
        if ($resources === []) { return []; }
        $db ??= self::connection();
        $rows = self::visibleIn($db, $resources, $db->table(self::table())->select(['deleted_by', 'deleted_by_name']))->whereRaw('`deleted_by` IS NOT NULL', [])
            ->orderBy('deleted_at', 'desc')->limit(500)->get();
        $people = [];
        foreach ($rows as $row) {
            $id = (int) $row->deleted_by;
            if (!isset($people[$id])) { $people[$id] = (string) ($row->deleted_by_name ?? '') !== '' ? (string) $row->deleted_by_name : '#' . $id; }
        }
        asort($people, SORT_NATURAL | SORT_FLAG_CASE);
        return $people;
    }

    /**
     * Whether the current user may see this entry (Resource::canSeeInTrash()).
     * Entries of a resource that is no longer registered are not shown.
     */
    public static function visible(TrashEntry $entry): bool
    {
        $class = $entry->resourceClass();
        if ($class === null) { return false; }
        if (!Resource::hasTrashRule($class)) { return true; }
        return (new $class())->canSeeInTrash((object) $entry->data, $entry->deletedBy);
    }

    /** The most entries of one resource a canSeeInTrash() rule is asked about. */
    private const RULE_SCAN_LIMIT = 5000;

    /**
     * The entries of these resources the current user may see. Resources without a
     * rule are matched by slug; for one with a rule, its entries are checked one by one.
     *
     * @param list<string> $resources
     */
    private static function visibleIn(Database $db, array $resources, ?Database $query = null): Database
    {
        $query ??= $db->table(self::table());
        $open = [];
        $ids = [];
        foreach ($resources as $slug) {
            $class = Admin::registry()->find($slug);
            if ($class === null) { continue; }
            if (!Resource::hasTrashRule($class)) { $open[] = $slug; continue; }
            $rows = $db->table(self::table())->where('resource', '=', $slug)->orderBy('id', 'desc')->limit(self::RULE_SCAN_LIMIT)->get();
            foreach ($rows as $row) {
                $entry = TrashEntry::fromRow($row);
                if (self::visible($entry)) { $ids[] = $entry->id; }
            }
        }
        $parts = [];
        $bindings = [];
        if ($open !== []) { $parts[] = '`resource` IN (' . implode(', ', array_fill(0, count($open), '?')) . ')'; array_push($bindings, ...$open); }
        if ($ids !== []) { $parts[] = '`id` IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'; array_push($bindings, ...$ids); }
        return $query->whereRaw($parts === [] ? '1 = 0' : '(' . implode(' OR ', $parts) . ')', $bindings);
    }

    /* ---------------------------------------------------------------
     * Restoring
     * ------------------------------------------------------------- */

    /**
     * Put a record back with the same id, its many-to-many links and its media
     * usage. Refused with a readable reason (a ValidationException with "_form")
     * when something now blocks it; the entry then stays in the trash.
     */
    public static function restore(TrashEntry $entry, ?Database $db = null): object
    {
        $db ??= self::connection();
        $class = $entry->resourceClass() ?? throw self::refusal(AdminLang::get('trash.type_missing'));
        /** @var Resource $resource */
        $resource = new $class();
        $repository = new RecordRepository($resource, $db, Translations::store());

        return $db->transaction(function () use ($entry, $db, $class, $resource, $repository): object {
            self::lock($db, $entry);
            $pk = $class::getPrimaryKey();
            $table = $class::getTable();
            if ($db->table($table)->where($pk, '=', $entry->recordId)->first() !== null) {
                throw self::refusal(AdminLang::get('trash.id_taken', ['label' => $class::getLabel(), 'id' => $entry->recordId]));
            }
            // Columns removed from the table since the delete are left out.
            $columns = self::columns($db, $table);
            $data = $columns === null ? $entry->data : array_intersect_key($entry->data, array_flip($columns));
            $form = $resource->form(new Form());
            self::checkConflicts($form, $data, $repository, $db, $class);

            $record = (object) $data;
            $resource->beforeRestore($record);
            try {
                $db->table($table)->insert($data);
            } catch (\PDOException $error) {
                throw self::refusal(self::isForeignKey($error) ? AdminLang::get('trash.parent_gone') : AdminLang::get('trash.conflict', ['label' => $class::getLabel()]));
            }
            $restored = $repository->find($entry->recordId) ?? $record;

            foreach ($entry->links as $name => $ids) {
                $field = $form->field($name);
                if (!$field instanceof BelongsToMany || ($pivot = $field->getPivot()) === null || !$field->hasRelation()) { continue; }
                $owner = (string) ($restored->{$pivot->parentKey} ?? '');
                if ($owner === '' || $ids === []) { continue; }
                // Only related records that still exist, in their saved order.
                $existing = $field->relation()->existing($db, $ids);
                $pivot->sync($db, $owner, array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $existing, true))));
            }
            if (MediaLibrary::enabled() && MediaUsage::hasMediaFields($form)) {
                MediaUsage::recordValues($class::getSlug(), $entry->recordId, $form, (array) $restored);
            }
            $db->query('DELETE FROM `' . self::table() . '` WHERE `id` = ?', [$entry->id]);
            $resource->afterRestore($restored);
            Activity::record('restored', $class::getSlug(), $entry->recordId, $entry->title, db: $db);
            return $restored;
        });
    }

    /**
     * Restore every record of one delete operation of the current user (the Undo
     * button). Each record is restored on its own; ones that can't come back stay
     * in the trash and their reasons are returned.
     *
     * @return array{restored: list<TrashEntry>, refused: list<string>}
     */
    public static function undo(string $batch, ?Database $db = null): array
    {
        $db ??= self::connection();
        if (preg_match('/^[a-f0-9]{32}$/D', $batch) !== 1) { return ['restored' => [], 'refused' => []]; }
        $user = Actor::id();
        $query = $db->table(self::table())->where('batch', '=', $batch)->where('deleted_at', '>=', date('Y-m-d H:i:s', time() - self::UNDO_SECONDS));
        $query = $user === null ? $query->whereRaw('`deleted_by` IS NULL', []) : $query->where('deleted_by', '=', $user);
        $restored = [];
        $refused = [];
        foreach ($query->orderBy('id', 'asc')->limit(500)->get() as $row) {
            $entry = TrashEntry::fromRow($row);
            $class = $entry->resourceClass();
            if ($class === null || !\FloCMS\Core\Auth::can($class::permission('create')) || !(new $class())->canCreate()) {
                $refused[] = AdminLang::get('trash.not_allowed', ['title' => $entry->label()]);
                continue;
            }
            try {
                self::restore($entry, $db);
                $restored[] = $entry;
            } catch (ValidationException $error) {
                $refused[] = $entry->label() . ': ' . self::message($error);
            }
        }
        return ['restored' => $restored, 'refused' => $refused];
    }

    /** Unique values now used by another record, and parents that are gone. */
    private static function checkConflicts(Form $form, array $data, RecordRepository $repository, Database $db, string $class): void
    {
        foreach ($form->fields() as $name => $field) {
            if (!$field->storesInColumn() || !array_key_exists($name, $data) || $data[$name] === null || $data[$name] === '') { continue; }
            if ($field->isUnique()) {
                $values = $field->isTranslatable()
                    ? Translations::store()->decode($data[$name], Languages::default())
                    : [null => (string) $data[$name]];
                foreach ($values as $language => $value) {
                    $value = is_scalar($value) ? (string) $value : '';
                    if ($value === '') { continue; }
                    $exists = $repository->exists($name, $value, null, $field->isTranslatable() ? (string) $language : null);
                    if ($exists) {
                        throw self::refusal(AdminLang::get('trash.unique_taken', ['label' => mb_strtolower($class::getLabel()), 'field' => mb_strtolower($field->getLabel()), 'value' => mb_strimwidth($value, 0, 80, '…')]));
                    }
                }
            }
            if ($field instanceof BelongsTo && $field->hasRelation() && is_scalar($data[$name])) {
                if ($field->relation()->existing($db, [(string) $data[$name]]) === []) {
                    throw self::refusal(AdminLang::get('trash.parent_missing', ['field' => $field->getLabel(), 'id' => (string) $data[$name]]));
                }
            }
        }
    }

    /* ---------------------------------------------------------------
     * Deleting for good
     * ------------------------------------------------------------- */

    /** Delete one entry for good: its copy, its upload files and its media usage. */
    public static function purge(TrashEntry $entry, ?Database $db = null, string $reason = 'manual'): void
    {
        $db ??= self::connection();
        $deleted = $db->transaction(function () use ($entry, $db, $reason): bool {
            if ($db->query('DELETE FROM `' . self::table() . '` WHERE `id` = ?', [$entry->id])->rowCount() !== 1) { return false; }
            $class = $entry->resourceClass();
            // Media usage was kept while the record waited; it goes now, unless a record with the
            // same key exists again (a restored copy, or a new record that reused the key).
            if ($class !== null && MediaLibrary::enabled()) {
                $live = $db->table($class::getTable())->where($class::getPrimaryKey(), '=', $entry->recordId)->first();
                if ($live === null) { MediaLibrary::store()->forgetRecord($entry->resource, $entry->recordId); }
            } elseif ($class === null && MediaLibrary::enabled()) {
                MediaLibrary::store()->forgetRecord($entry->resource, $entry->recordId);
            }
            Activity::record('purged', $entry->resource, $entry->recordId, $entry->title, meta: $reason === 'expired' ? ['expired' => true] : [], db: $db);
            return true;
        });
        if (!$deleted) { return; }
        foreach ($entry->files as $path) {
            try { Uploads::storage()->delete($path); } catch (\Throwable $error) { error_log('flocms-admin: could not delete trashed file ' . $path . ': ' . $error->getMessage()); }
        }
    }

    /**
     * Delete for good the entries older than the configured days.
     *
     * @return int how many were deleted
     */
    public static function purgeExpired(?Database $db = null, int $limit = 500): int
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db) || self::days() <= 0) { return 0; }
        $cutoff = date('Y-m-d H:i:s', time() - self::days() * 86400);
        // Logged as "System": not by whoever opened the page that ran the cleanup.
        return Actor::asSystem(function () use ($db, $cutoff, $limit): int {
            $count = 0;
            foreach ($db->table(self::table())->where('deleted_at', '<', $cutoff)->orderBy('id', 'asc')->limit($limit)->get() as $row) {
                self::purge(TrashEntry::fromRow($row), $db, 'expired');
                $count++;
            }
            return $count;
        });
    }

    /* ---------------------------------------------------------------
     * Notices
     * ------------------------------------------------------------- */

    /** Seconds the Undo toast stays on screen (hovering or focusing it pauses it). */
    public const UNDO_TOAST_SECONDS = 10;

    /**
     * The notice after a delete: "Listing deleted." with an Undo button when the
     * records went to the trash, else the plain "Listing deleted.".
     *
     * @param list<string> $titles labels of the deleted records (the first is named)
     */
    public static function notifyDeleted(Resource|string $resource, int $count, array $titles = []): void
    {
        $class = is_string($resource) ? $resource : $resource::class;
        if ($count < 1) { return; }
        if (!self::enabledFor($class)) {
            \FloCMS\Admin\Notifications\Notification::make()->title($count === 1
                ? AdminLang::get('flash.deleted', ['label' => $class::getLabel()])
                : AdminLang::get('trash.deleted_many', ['count' => $count, 'label' => mb_strtolower($class::getPluralLabel())]))->success()->send();
            return;
        }
        $days = self::days();
        $title = $titles[0] ?? '';
        $body = $count === 1 && $title !== ''
            ? AdminLang::get($days > 0 ? 'trash.in_trash_one' : 'trash.in_trash_one_kept', ['title' => mb_strimwidth($title, 0, 80, '…'), 'days' => $days])
            : AdminLang::get($days > 0 ? 'trash.in_trash_many' : 'trash.in_trash_many_kept', ['days' => $days]);
        \FloCMS\Admin\Notifications\Notification::make()
            ->title($count === 1 ? AdminLang::get('flash.deleted', ['label' => $class::getLabel()]) : AdminLang::get('trash.deleted_many', ['count' => $count, 'label' => mb_strtolower($class::getPluralLabel())]))
            ->body($body)->neutral()->icon('trash')->duration(self::UNDO_TOAST_SECONDS)
            ->action(AdminLang::get('trash.undo'), Admin::url('trash/undo/' . self::batch()))
            ->send();
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private static function lock(Database $db, TrashEntry $entry): void
    {
        $sql = 'SELECT `id` FROM `' . self::table() . '` WHERE `id` = ?';
        if ($db->driver() === 'sqlite') {
            // SQLite has no SELECT FOR UPDATE: take the write lock first.
            $db->query('UPDATE `' . self::table() . '` SET `id` = `id` WHERE 0');
        } else {
            $sql .= ' FOR UPDATE';
        }
        if ($db->query($sql, [$entry->id])->fetchColumn() === false) {
            throw self::refusal(AdminLang::get('trash.missing'));
        }
    }

    /** @return list<string>|null the table's columns, or null when they can't be read */
    private static function columns(Database $db, string $table): ?array
    {
        try {
            if ($db->driver() === 'sqlite') {
                $names = array_map(static fn (array $row): string => (string) $row['name'], $db->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(\PDO::FETCH_ASSOC));
            } else {
                $names = array_map(static fn (array $row): string => (string) $row['Field'], $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC));
            }
            return $names === [] ? null : $names;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isForeignKey(\PDOException $error): bool
    {
        $code = (int) ($error->errorInfo[1] ?? 0);
        return in_array($code, [1451, 1452, 1216, 1217], true) || stripos($error->getMessage(), 'foreign key') !== false;
    }

    private static function connection(): Database
    {
        return App::db() ?? throw new \RuntimeException('The trash needs a database connection.');
    }

    public static function refusal(string $message): ValidationException
    {
        if (!class_exists(ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
        return new ValidationException(['_form' => $message]);
    }

    /** The readable text of a refusal. */
    public static function message(ValidationException $error): string
    {
        return implode(' ', array_map(static fn ($messages): string => implode(' ', (array) $messages), $error->getErrors()));
    }
}
