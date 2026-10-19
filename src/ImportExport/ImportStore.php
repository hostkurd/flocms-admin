<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use FloCMS\Admin\Admin;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/**
 * The imports and import rows tables (0.25), and the folder of uploaded files
 * (storage/admin-imports/, private).
 */
final class ImportStore
{
    /** @var array<int, string> run id => the lock this request holds */
    private array $claims = [];

    /** @var array<string, string> status() for this request */
    private static array $status = [];

    public function __construct(private readonly Database $db)
    {
    }

    public static function make(?Database $db = null): self
    {
        $db ??= App::db();
        if ($db === null) { throw new \RuntimeException('No database connection.'); }
        return new self($db);
    }

    public function db(): Database { return $this->db; }

    public static function table(): string { return Admin::config()->import()['table']; }
    public static function rowsTable(): string { return Admin::config()->import()['rows_table']; }
    public static function folder(): string { return Admin::config()->root() . '/storage/admin-imports'; }

    /** 'off' (import => false), 'missing' (run php flo admin:install and migrate) or 'ready'. */
    public static function status(?Database $db = null): string
    {
        if (!Admin::config()->import()['enabled']) { return 'off'; }
        $db ??= App::db();
        if ($db === null) { return 'missing'; }
        // Asked once per resource on every panel page: remembered for the request.
        $key = spl_object_id($db) . ':' . self::table();
        if (isset(self::$status[$key])) { return self::$status[$key]; }
        try {
            $db->query('SELECT `id`, `status`, `csv_separator` FROM `' . self::table() . '` WHERE 1 = 0');
            $db->query('SELECT `id`, `before_values` FROM `' . self::rowsTable() . '` WHERE 1 = 0');
            return self::$status[$key] = 'ready';
        } catch (\Throwable) {
            // Not remembered: admin:install and migrate may run in this process (tests).
            return 'missing';
        }
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): ImportRun
    {
        $now = date('Y-m-d H:i:s');
        $id = $this->db->table(self::table())->insert(self::encode($data) + ['created_at' => $now, 'updated_at' => $now]);
        return $this->find($id) ?? throw new \RuntimeException('The import was not saved.');
    }

    public function find(int $id): ?ImportRun
    {
        $row = $this->db->table(self::table())->where('id', '=', $id)->first();
        return $row === null ? null : ImportRun::fromRow((array) $row);
    }

    /** @param array<string, mixed> $data */
    public function update(ImportRun $run, array $data): ImportRun
    {
        $data = self::encode($data);
        $this->db->table(self::table())->where('id', '=', $run->id)->update($data + ['updated_at' => date('Y-m-d H:i:s')]);
        return $this->find($run->id) ?? $run;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function encode(array $data): array
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        foreach (['mapping', 'counts'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) { $data[$key] = json_encode((object) $data[$key], $flags); }
        }
        foreach (['headings', 'samples'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) { $data[$key] = json_encode(array_values($data[$key]), $flags); }
        }
        return $data;
    }

    /**
     * Take the run for one batch: false while another request works on it, so a
     * retried or doubled request never processes the same rows twice.
     */
    public function claim(ImportRun $run, int $seconds = 60): bool
    {
        $now = date('Y-m-d H:i:s');
        $until = date('Y-m-d H:i:s', time() + $seconds);
        $statement = $this->db->pdo()->prepare('UPDATE `' . self::table() . '` SET `locked_until` = ? WHERE `id` = ? AND (`locked_until` IS NULL OR `locked_until` < ?)');
        $statement->execute([$until, $run->id, $now]);
        if ($statement->rowCount() !== 1) { return false; }
        $this->claims[$run->id] = $until;
        return true;
    }

    /** Give the lock back, only if it is still this request's (a slow request may have lost it). */
    public function release(ImportRun $run): void
    {
        $until = $this->claims[$run->id] ?? null;
        unset($this->claims[$run->id]);
        if ($until === null) { return; }
        $this->db->table(self::table())->where('id', '=', $run->id)->where('locked_until', '=', $until)->update(['locked_until' => null]);
    }

    /** Another run of the same resource that is checking or importing right now. */
    public function busy(string $resource, int $except): ?ImportRun
    {
        foreach ($this->db->table(self::table())->where('resource', '=', $resource)->whereIn('status', ['importing', 'undoing'])->where('id', '!=', $except)->get() as $row) {
            $run = ImportRun::fromRow((array) $row);
            if ($run->isWorking()) { return $run; }
        }
        return null;
    }

    /**
     * Runs of these resources, newest first.
     *
     * @param list<string> $resources
     * @return array{data: list<ImportRun>, total: int}
     */
    public function runs(array $resources, int $page = 1, int $perPage = 25, int $days = 30): array
    {
        if ($resources === []) { return ['data' => [], 'total' => 0]; }
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $query = fn (): Database => $this->db->table(self::table())->whereIn('resource', $resources)->where('created_at', '>=', $since);
        $total = $query()->count();
        $rows = $query()->orderBy('id', 'desc')->limit($perPage)->offset(($page - 1) * $perPage)->get();
        return ['data' => array_map(static fn (object $r): ImportRun => ImportRun::fromRow((array) $r), $rows), 'total' => $total];
    }

    /** The unfinished run of this user and resource (to continue), if any. */
    public function unfinished(string $resource, ?int $userId): ?ImportRun
    {
        $row = $this->db->table(self::table())->where('resource', '=', $resource)->whereIn('status', ['uploaded', 'checking', 'checked', 'importing'])
            ->where('user_id', '=', $userId ?? 0)->orderBy('id', 'desc')->first();
        return $row === null ? null : ImportRun::fromRow((array) $row);
    }

    /* ---------------------------------------------------------------
     * Rows
     * ------------------------------------------------------------- */

    /** @param list<array<string, mixed>> $rows */
    public function insertRows(array $rows): void
    {
        foreach ($rows as $row) { $this->db->table(self::rowsTable())->insert($row); }
    }

    /** @return array<int, object> line => row */
    public function rowsByLine(ImportRun $run, int $from, int $to): array
    {
        $rows = [];
        foreach ($this->db->table(self::rowsTable())->where('import_id', '=', $run->id)->where('line', '>=', $from)->where('line', '<=', $to)->get() as $row) {
            $rows[(int) $row->line] = $row;
        }
        return $rows;
    }

    /** @param array<string, mixed> $data */
    public function updateRow(int $id, array $data): void
    {
        $this->db->table(self::rowsTable())->where('id', '=', $id)->update($data);
    }

    /** The first line holding this key value, if any. */
    public function lineWithKey(ImportRun $run, string $value): ?int
    {
        $row = $this->db->table(self::rowsTable())->select(['line'])->where('import_id', '=', $run->id)->where('key_value', '=', $value)->orderBy('line', 'asc')->first();
        return $row === null ? null : (int) $row->line;
    }

    /** @return list<object> rows with a status, in file order */
    public function rowsWithStatus(ImportRun $run, array $statuses, int $limit, int $afterId = 0): array
    {
        return $this->db->table(self::rowsTable())->where('import_id', '=', $run->id)->whereIn('status', $statuses)->where('id', '>', $afterId)
            ->orderBy('id', 'asc')->limit($limit)->get();
    }

    public function countRows(ImportRun $run, array $statuses): int
    {
        return $this->db->table(self::rowsTable())->where('import_id', '=', $run->id)->whereIn('status', $statuses)->count();
    }

    /** Rows from this line on (a check batch that is run again). */
    public function deleteRowsFrom(ImportRun $run, int $line): void
    {
        $this->db->table(self::rowsTable())->where('import_id', '=', $run->id)->where('line', '>=', $line)->delete();
    }

    public function deleteRows(ImportRun $run): void
    {
        $this->db->table(self::rowsTable())->where('import_id', '=', $run->id)->delete();
    }

    /* ---------------------------------------------------------------
     * Files
     * ------------------------------------------------------------- */

    /** The UTF-8 copy the import reads. */
    public static function path(ImportRun $run): string
    {
        return self::folder() . '/' . basename($run->filePath) . '.utf8';
    }

    public static function original(ImportRun $run): string
    {
        return self::folder() . '/' . basename($run->filePath);
    }

    public static function deleteFiles(ImportRun $run): void
    {
        foreach ([self::original($run), self::path($run)] as $file) {
            if (is_file($file)) { @unlink($file); }
        }
    }

    /**
     * Housekeeping: files of runs that ended (or were left) more than keep_hours
     * ago, the rows of runs past their Undo days, runs older than 30 days.
     * Returns the number of runs whose files were removed.
     */
    public function purge(): int
    {
        $config = Admin::config()->import();
        $removed = 0;
        $before = date('Y-m-d H:i:s', time() - $config['keep_hours'] * 3600);
        foreach ($this->db->table(self::table())->where('updated_at', '<', $before)->get() as $row) {
            $run = ImportRun::fromRow((array) $row);
            if (is_file(self::original($run)) || is_file(self::path($run))) { self::deleteFiles($run); $removed++; }
            if (in_array($run->status, ['uploaded', 'checking', 'checked'], true)) {
                $this->update($run, ['status' => 'cancelled', 'message' => 'expired']);
                $this->deleteRows($run);
            } elseif ($run->status === 'importing') {
                // Paused longer than its file is kept: it ends here, and what it saved can still be undone.
                $this->update($run, ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s'), 'message' => \FloCMS\Admin\Support\AdminLang::get('import.file_gone')]);
            }
        }
        $undoBefore = date('Y-m-d H:i:s', time() - max(1, $config['undo_days']) * 86400);
        foreach ($this->db->table(self::table())->select(['id'])->where('updated_at', '<', $undoBefore)->get() as $row) {
            $this->db->table(self::rowsTable())->where('import_id', '=', (int) $row->id)->delete();
        }
        $old = date('Y-m-d H:i:s', time() - 30 * 86400);
        $this->db->table(self::table())->where('created_at', '<', $old)->where('updated_at', '<', $undoBefore)->delete();
        return $removed;
    }
}
