<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormSubmission;
use FloCMS\Admin\Resource;
use FloCMS\Core\Database;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;

/**
 * Imports a CSV file into a resource (0.25), in steps a browser (or the
 * command) drives one short request at a time:
 *
 *  1. start(): the uploaded file becomes a run; its columns are matched by heading.
 *  2. saveMatch(): the editor's column matches and mode; checking starts.
 *  3. step() while checking: every row goes through the form's own rules, nothing
 *     is saved; each line gets a status (new, update, error).
 *  4. step() while importing: the rows that passed are saved, a batch per
 *     transaction, through FormSubmission (beforeSave, unique, scopes all apply).
 *  5. undo() + step() while undoing: created records go to the trash, updated ones
 *     get their values from before the import back.
 */
final class Importer
{
    private Schema $schema;
    private Import $import;
    private Form $form;
    private RecordRepository $repository;
    private FormSubmission $submission;
    /** @var array<string, array{key: string, label: string, field: ?Field, lang: ?string, names: list<string>, required: bool}> */
    private array $targets;
    /** Run from the command line: no signed-in user, so no permission to check. */
    private bool $console = false;

    public function fromConsole(): self
    {
        $this->console = true;
        return $this;
    }

    private function mayUpdate(): bool
    {
        return $this->console || $this->resource::canImportUpdates();
    }

    public function __construct(private readonly Resource $resource, private readonly ImportStore $store)
    {
        $db = $store->db();
        $this->schema = Schema::for($resource, $db);
        $this->form = $this->schema->form();
        $this->import = $resource->import(new Import());
        $this->repository = new RecordRepository($resource, $db, Translations::store());
        $this->submission = new FormSubmission($resource, $this->repository);
        $this->targets = $this->schema->importTargets();
    }

    public function schema(): Schema { return $this->schema; }
    public function settings(): Import { return $this->import; }

    /** @return array<string, array{key: string, label: string, field: ?Field, lang: ?string, names: list<string>, required: bool}> */
    public function targets(): array { return $this->targets; }

    /** The columns that can find existing records: the id, and the resource's key(). @return list<string> */
    public function keyColumns(): array
    {
        $keys = [$this->resource::getPrimaryKey()];
        if (($key = $this->import->getKey()) !== null && $key !== $keys[0]) { $keys[] = $key; }
        return $keys;
    }

    /* ---------------------------------------------------------------
     * 1. Upload
     * ------------------------------------------------------------- */

    /**
     * A run for a file stored in the imports folder.
     *
     * @throws ImportException when the file is not a CSV this import can read
     */
    public function start(string $storedName, string $originalName, int $size, ?int $userId, string $userName): ImportRun
    {
        $original = ImportStore::folder() . '/' . basename($storedName);
        $fallback = array_intersect(['ar', 'ku', 'fa', 'ckb'], Languages::all()) !== [] ? 'windows-1256' : 'windows-1252';
        $encoding = CsvReader::detectEncoding($original, $fallback);
        try {
            $facts = $this->read($original, $original . '.utf8', $encoding);
        } catch (ImportException $error) {
            // UTF-8 in the first 64 KB, another encoding further on (an "é" late in a Windows file).
            if ($encoding !== 'utf-8' || $error->getMessage() !== AdminLang::get('import.not_text')) { throw $error; }
            $facts = $this->read($original, $original . '.utf8', $encoding = $fallback);
        }
        return $this->store->create([
            'resource' => $this->resource::getSlug(), 'user_id' => $userId, 'user_name' => mb_substr($userName, 0, 190),
            'file_name' => mb_substr($originalName, 0, 190), 'file_path' => basename($storedName), 'file_size' => $size,
            'encoding' => $encoding, 'status' => 'uploaded',
        ] + $facts + $this->defaultsFor($facts['mapping']));
    }

    /** Read the file again as another encoding (the Match step's encoding choice). */
    public function reencode(ImportRun $run, string $encoding): ImportRun
    {
        if (!in_array($encoding, CsvReader::ENCODINGS, true)) { throw new ImportException(AdminLang::get('import.not_text')); }
        $facts = $this->read(ImportStore::original($run), ImportStore::path($run), $encoding);
        return $this->store->update($run, ['encoding' => $encoding, 'status' => 'uploaded'] + $facts + $this->defaultsFor($facts['mapping']));
    }

    /**
     * Headings, first rows, row count and matched columns of a file.
     *
     * @return array{csv_separator: string, headings: list<string>, samples: list<list<string>>, rows_total: int, data_start: int, mapping: array<int, string>}
     */
    private function read(string $original, string $copy, string $encoding): array
    {
        if (!CsvReader::normalize($original, $copy, $encoding)) { throw new ImportException(AdminLang::get('import.not_text')); }
        $separator = CsvReader::detectSeparator($copy);
        $reader = new CsvReader($copy, $separator);
        $headings = $reader->header();
        if ($headings === [] || count($headings) > 200) { throw new ImportException(AdminLang::get('import.no_header')); }
        $headings = array_map(static fn (string $h, int $i): string => $h !== '' ? mb_substr($h, 0, 120) : AdminLang::get('import.column_n', ['number' => (string) ($i + 1)]), $headings, array_keys($headings));
        $total = $reader->count();
        $max = Admin::config()->import()['max_rows'];
        if ($total === 0) { throw new ImportException(AdminLang::get('import.no_rows')); }
        if ($total > $max) { throw new ImportException(AdminLang::get('import.too_many_rows', ['count' => number_format($total), 'max' => number_format($max)])); }
        $start = $reader->dataStart();
        $samples = array_values(array_map(static fn (array $cells): array => array_map(static fn (string $c): string => mb_substr($c, 0, 80), $cells), $reader->rows($start, 2, 3)['rows']));
        return ['csv_separator' => $separator, 'headings' => $headings, 'samples' => $samples, 'rows_total' => $total, 'data_start' => $start, 'mapping' => $this->schema->match($headings)];
    }

    /** Mode and key from the matched columns: a matched id or key column updates. @param array<int, string> $mapping */
    private function defaultsFor(array $mapping): array
    {
        $key = null;
        foreach ($this->keyColumns() as $column) {
            if (in_array($column, $mapping, true)) { $key = $column; break; }
        }
        return ['mode' => $key !== null && $this->mayUpdate() ? 'both' : 'create', 'key_column' => $key ?? $this->keyColumns()[0]];
    }

    /* ---------------------------------------------------------------
     * 2. Match columns
     * ------------------------------------------------------------- */

    /**
     * Save the matches and start checking; errors per input name, or [].
     *
     * @param array<int|string, mixed> $columns column index => target key ('' = skip)
     * @return array<string, string>
     */
    public function saveMatch(ImportRun $run, array $columns, string $mode, string $key, string $dateOrder): array
    {
        $errors = [];
        $mapping = [];
        foreach ($columns as $index => $target) {
            if (!is_numeric($index) || (int) $index < 0 || (int) $index >= count($run->headings) || !is_string($target) || $target === '') { continue; }
            if (!isset($this->targets[$target])) { $errors['columns.' . $index] = AdminLang::get('import.unknown_target'); continue; }
            if (in_array($target, $mapping, true)) { $errors['columns.' . $index] = AdminLang::get('import.target_twice', ['label' => $this->targets[$target]['label']]); continue; }
            $mapping[(int) $index] = $target;
        }
        if (!in_array($mode, ImportRun::MODES, true)) { $mode = 'create'; }
        if ($mode !== 'create' && !$this->mayUpdate()) { $mode = 'create'; }
        if (!in_array($key, $this->keyColumns(), true)) { $key = $this->keyColumns()[0]; }
        if ($mode !== 'create' && !in_array($key, $mapping, true)) {
            $errors['key'] = AdminLang::get('import.key_unmatched', ['label' => $this->targets[$key]['label'] ?? $key]);
        }
        if ($mapping === []) { $errors['columns'] = AdminLang::get('import.nothing_matched'); }
        if ($mode !== 'update' && ($missing = $this->missingRequired($mapping)) !== []) {
            $errors['required'] = AdminLang::get('import.required_unmatched', ['fields' => implode(AdminLang::get('form.list_separator'), $missing)]);
        }
        if ($errors !== []) {
            $this->store->update($run, ['mapping' => $mapping, 'mode' => $mode, 'key_column' => $key, 'date_order' => $dateOrder === 'mdy' ? 'mdy' : 'dmy']);
            return $errors;
        }
        $this->store->deleteRows($run);
        $this->store->update($run, ['mapping' => $mapping, 'mode' => $mode, 'key_column' => $key, 'date_order' => $dateOrder === 'mdy' ? 'mdy' : 'dmy',
            'status' => 'checking', 'position' => 0, 'next_byte' => $run->dataStart, 'counts' => [], 'message' => null]);
        return [];
    }

    /**
     * Required fields no column fills (and no default): new records would fail.
     *
     * @param array<int, string> $mapping
     * @return list<string> their labels
     */
    public function missingRequired(array $mapping): array
    {
        $missing = [];
        foreach ($this->targets as $target => $info) {
            if (!$info['required'] || in_array($target, $mapping, true) || $info['field'] === null) { continue; }
            if (array_key_exists($info['field']->getName(), $this->import->getDefaults())) { continue; }
            $default = $info['field']->stateFromRecord(null, Languages::default());
            if ((is_array($default) ? trim((string) ($default[Languages::default()] ?? '')) : trim($default)) !== '') { continue; }
            // A slug is filled from its source field.
            if ($info['field'] instanceof Fields\Slug) { continue; }
            $missing[] = $info['label'];
        }
        return $missing;
    }

    /* ---------------------------------------------------------------
     * 3–5. Steps
     * ------------------------------------------------------------- */

    /** One batch of whatever the run is doing; the run afterwards. */
    public function step(ImportRun $run, ?int $size = null): ImportRun
    {
        if (!in_array($run->status, ['checking', 'importing', 'undoing'], true)) { return $run; }
        if (!$this->store->claim($run)) { return $run; }
        $size ??= Admin::config()->import()['batch_size'];
        try {
            // The file was removed (housekeeping after keep_hours): stop, keeping what was saved.
            if ($run->status !== 'undoing' && !is_file(ImportStore::path($run))) {
                $stopped = $this->store->update($run, ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s'), 'message' => AdminLang::get('import.file_gone')]);
                if ($run->status === 'importing') { $this->log($stopped); } else { $this->store->deleteRows($stopped); }
                return $stopped;
            }
            return match ($run->status) {
                'checking' => $this->checkBatch($run, $size),
                'importing' => $this->importBatch($run, $size),
                'undoing' => $this->undoBatch($run, $size),
            };
        } finally {
            $this->store->release($run);
        }
    }

    private function checkBatch(ImportRun $run, int $size): ImportRun
    {
        $reader = new CsvReader(ImportStore::path($run), $run->separator);
        $batch = $reader->rows($run->nextByte, $run->position + 2, $size);
        $values = new Values($this->store->db(), $run->dateOrder, $run->decimalComma());
        // One transaction: a batch cut short (a timeout) leaves nothing, and is checked again whole.
        return $this->store->db()->transaction(function () use ($run, $batch, $values): ImportRun {
            $this->store->deleteRowsFrom($run, $run->position + 2);
            $counts = $run->counts;
            foreach ($batch['rows'] as $line => $cells) {
                $result = $this->evaluate($run, $line, $cells, $values, true);
                $name = $result['status'] === 'error' ? 'errors' : $result['status'];
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $counts['emptied'] = ($counts['emptied'] ?? 0) + $result['emptied'];
                if ($result['status'] !== 'error' && $result['dropped'] > 0) { $counts['media_dropped'] = ($counts['media_dropped'] ?? 0) + $result['dropped']; }
                $this->store->insertRows([[
                    'import_id' => $run->id, 'line' => $line, 'status' => $result['status'],
                    'record_id' => $result['record'] !== null ? (string) $result['record']->{$this->resource::getPrimaryKey()} : null,
                    'key_value' => $result['key'] !== '' ? mb_substr($result['key'], 0, 191) : null,
                    // At most 20 problems per row are kept.
                    'errors' => $result['errors'] === [] ? null : json_encode(array_slice($result['errors'], 0, 20), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ]]);
            }
            $position = $run->position + count($batch['rows']);
            return $this->store->update($run, ['position' => $position, 'next_byte' => $batch['next'], 'counts' => $counts]
                + ($batch['end'] || $batch['rows'] === [] ? ['status' => 'checked', 'next_byte' => $run->dataStart] : []));
        });
    }

    /** Start importing the rows that passed. */
    public function begin(ImportRun $run): ImportRun
    {
        if ($run->status !== 'checked') { throw new ImportException(AdminLang::get('import.not_checked')); }
        if (($run->count('new') + $run->count('update')) === 0) { throw new ImportException(AdminLang::get('import.nothing_ready')); }
        if (($busy = $this->store->busy($run->resource, $run->id)) !== null) {
            throw new ImportException(AdminLang::get('import.busy', ['name' => $busy->userName]));
        }
        return $this->store->update($run, ['status' => 'importing', 'position' => 0, 'next_byte' => $run->dataStart,
            'counts' => array_intersect_key($run->counts, array_flip(['new', 'update', 'errors', 'emptied', 'media_dropped'])) + ['created' => 0, 'updated' => 0, 'skipped' => 0]]);
    }

    private function importBatch(ImportRun $run, int $size): ImportRun
    {
        $reader = new CsvReader(ImportStore::path($run), $run->separator);
        $batch = $reader->rows($run->nextByte, $run->position + 2, $size);
        $lines = array_keys($batch['rows']);
        $statuses = $lines === [] ? [] : $this->store->rowsByLine($run, min($lines), max($lines));
        $values = new Values($this->store->db(), $run->dateOrder, $run->decimalComma());
        $counts = $run->counts;
        $uploads = new \FloCMS\Admin\Uploads\UploadBatch();
        $pk = $this->resource::getPrimaryKey();
        $line = 0;
        try {
            Activity::quietly(function () use ($batch, $statuses, $values, $run, &$counts, $uploads, $pk, &$line): void {
                $this->repository->transaction(function () use ($batch, $statuses, $values, $run, &$counts, $uploads, $pk, &$line): void {
                    foreach ($batch['rows'] as $line => $cells) {
                        $row = $statuses[$line] ?? null;
                        if ($row === null || !in_array($row->status, ['new', 'update'], true)) { continue; }
                        $result = $this->evaluate($run, $line, $cells, $values, false);
                        if ($result['status'] === 'error') {
                            $counts['skipped'] = ($counts['skipped'] ?? 0) + 1;
                            $this->store->updateRow((int) $row->id, ['status' => 'failed', 'errors' => json_encode(array_slice($result['errors'], 0, 20), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
                            continue;
                        }
                        $record = $result['record'];
                        $before = $record === null ? null : $this->snapshot($record);
                        $id = $this->submission->save($this->form, $result['state'], $record, false, $uploads);
                        $saved = $this->repository->find($id);
                        $created = $record === null;
                        $counts[$created ? 'created' : 'updated'] = ($counts[$created ? 'created' : 'updated'] ?? 0) + 1;
                        $this->store->updateRow((int) $row->id, ['status' => $created ? 'created' : 'updated', 'record_id' => $id,
                            'before_values' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                            'after_hash' => $saved !== null ? RecordRepository::fingerprint($saved) : null]);
                    }
                });
            });
        } catch (\Throwable $error) {
            $uploads->rollback();
            $message = $error instanceof \FloCMS\Core\ValidationException ? implode(' ', array_map(static fn ($m): string => implode(' ', (array) $m), $error->getErrors())) : $error->getMessage();
            error_log('flocms-admin: import ' . $run->id . ' stopped at row ' . $line . ': ' . $error->getMessage());
            $failed = $this->store->update($run, ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s'),
                'message' => AdminLang::get('import.stopped_at', ['line' => (string) $line, 'message' => mb_substr($message, 0, 300)])]);
            $this->log($failed);
            return $failed;
        }
        $uploads->commit();
        $position = $run->position + count($batch['rows']);
        $run = $this->store->update($run, ['position' => $position, 'next_byte' => $batch['next'], 'counts' => $counts]);
        // Cancelled while this batch ran: the cancel stands.
        if ($run->status !== 'importing') { return $run; }
        if ($batch['end'] || $batch['rows'] === []) {
            $run = $this->store->update($run, ['status' => 'finished', 'finished_at' => date('Y-m-d H:i:s')]);
            $this->log($run);
            if (($after = $this->import->getAfterImport()) !== null) { $after($run); }
        }
        return $run;
    }

    /** Stop where it is: what was written stays (and can be undone). */
    public function cancel(ImportRun $run): ImportRun
    {
        if (in_array($run->status, ['finished', 'cancelled', 'failed', 'undone'], true)) { return $run; }
        // An Undo is finished, not dropped halfway: its rows are what makes it possible.
        if ($run->status === 'undoing') { throw new ImportException(AdminLang::get('import.undo_running')); }
        $importing = $run->status === 'importing';
        $run = $this->store->update($run, ['status' => 'cancelled', 'finished_at' => date('Y-m-d H:i:s')]);
        if ($importing) { $this->log($run); } else { ImportStore::deleteFiles($run); $this->store->deleteRows($run); }
        return $run;
    }

    /** The record's row and its many-to-many keys, to put back on Undo. @return array{row: array<string, mixed>, links: array<string, list<string>>} */
    private function snapshot(object $record): array
    {
        $links = [];
        foreach ($this->form->fields() as $name => $field) {
            if ($field instanceof Fields\BelongsToMany && ($pivot = $field->getPivot()) !== null) {
                $owner = (string) ($record->{$pivot->parentKey} ?? '');
                $links[$name] = $owner === '' ? [] : ($pivot->related($this->store->db(), [$owner])[$owner] ?? []);
            }
        }
        return ['row' => (array) $record, 'links' => $links];
    }

    private function log(ImportRun $run): void
    {
        Activity::record('imported', $this->resource::getSlug(), null, $run->fileName, [], [
            'import' => $run->id, 'created' => $run->count('created'), 'updated' => $run->count('updated'),
            'skipped' => $run->count('skipped') + $run->count('errors'), 'status' => $run->status,
        ], $this->store->db());
    }

    /* ---------------------------------------------------------------
     * Undo
     * ------------------------------------------------------------- */

    /**
     * Records the run created or updated that were changed since (or deleted, for
     * updated ones): an Undo would lose those changes, so it is refused.
     *
     * @return list<string> their titles
     */
    public function changedSince(ImportRun $run): array
    {
        $changed = [];
        $after = 0;
        $pk = $this->resource::getPrimaryKey();
        while (($rows = $this->store->rowsWithStatus($run, ['created', 'updated'], 500, $after)) !== []) {
            $after = (int) end($rows)->id;
            $ids = array_values(array_filter(array_map(static fn (object $r): string => (string) $r->record_id, $rows), static fn (string $id): bool => $id !== ''));
            $current = [];
            foreach ($ids === [] ? [] : $this->repository->newQuery()->whereIn($pk, $ids)->get() as $record) { $current[(string) $record->{$pk}] = $record; }
            foreach ($rows as $row) {
                $record = $current[(string) $row->record_id] ?? null;
                if ($record === null) {
                    if ($row->status === 'updated') { $changed[] = '#' . $row->record_id; }
                    continue;
                }
                if ($row->after_hash !== null && RecordRepository::fingerprint($record) !== $row->after_hash) {
                    $changed[] = \FloCMS\Admin\Actions\ActionExecutor::label($this->resource, $record);
                }
            }
        }
        return $changed;
    }

    public function undo(ImportRun $run): ImportRun
    {
        if (!$run->canUndo()) { throw new ImportException(AdminLang::get('import.undo_unavailable')); }
        $changed = $this->changedSince($run);
        if ($changed !== []) {
            $shown = array_slice($changed, 0, 5);
            throw new ImportException(AdminLang::get('import.undo_changed', ['count' => (string) count($changed), 'titles' => implode(AdminLang::get('form.list_separator'), $shown) . (count($changed) > 5 ? '…' : '')]));
        }
        return $this->store->update($run, ['status' => 'undoing', 'position' => 0, 'next_byte' => 0, 'counts' => $run->counts + ['removed' => 0, 'restored' => 0, 'kept' => 0]]);
    }

    /** One trash batch for the whole Undo, though it runs over several requests. */
    private static function trashBatch(ImportRun $run): string
    {
        return substr(hash('sha256', 'flo-admin-import-undo:' . $run->id . ':' . $run->createdAt), 0, 32);
    }

    private function undoBatch(ImportRun $run, int $size): ImportRun
    {
        $rows = $this->store->rowsWithStatus($run, ['created', 'updated'], $size, $run->nextByte);
        $counts = $run->counts;
        $db = $this->store->db();
        $pk = $this->resource::getPrimaryKey();
        \FloCMS\Admin\Trash\Trash::useBatch(self::trashBatch($run));
        try {
            Activity::quietly(function () use ($rows, &$counts, $db, $pk): void {
                foreach ($rows as $row) {
                    // Each row on its own: a record that can't go back is kept and counted, the rest carry on.
                    try {
                        $kept = $this->repository->transaction(fn (): ?string => $this->undoRow($row, $db, $pk));
                    } catch (\Throwable $error) {
                        error_log('flocms-admin: undo of import row ' . $row->id . ' failed: ' . $error->getMessage());
                        $kept = $error instanceof \FloCMS\Core\ValidationException ? implode(' ', array_map(static fn ($m): string => implode(' ', (array) $m), $error->getErrors())) : AdminLang::get('import.undo_row_failed');
                    }
                    if ($kept === null) {
                        $counts[$row->status === 'created' ? 'removed' : 'restored'] = ($counts[$row->status === 'created' ? 'removed' : 'restored'] ?? 0) + 1;
                        $this->store->updateRow((int) $row->id, ['status' => 'undone']);
                    } else {
                        $counts['kept'] = ($counts['kept'] ?? 0) + 1;
                        $this->store->updateRow((int) $row->id, ['status' => 'kept', 'errors' => json_encode([['field' => '', 'value' => '', 'message' => mb_substr($kept, 0, 300)]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
                    }
                }
            });
        } finally {
            \FloCMS\Admin\Trash\Trash::useBatch(null);
        }
        $run = $this->store->update($run, ['next_byte' => $rows === [] ? $run->nextByte : (int) end($rows)->id, 'position' => $run->position + count($rows), 'counts' => $counts]);
        if (count($rows) < $size) {
            $run = $this->store->update($run, ['status' => 'undone', 'undone_at' => date('Y-m-d H:i:s')]);
            Activity::record('import_undone', $this->resource::getSlug(), null, $run->fileName, [], [
                'import' => $run->id, 'removed' => $run->count('removed'), 'restored' => $run->count('restored'), 'kept' => $run->count('kept'),
            ], $db);
        }
        return $run;
    }

    /** Whether a stored key is the key from the file (numbers as numbers, text in any case). */
    private static function sameKey(string $stored, string $key): bool
    {
        if ($stored === $key) { return true; }
        if (preg_match('/^\d+$/D', $stored) === 1 && preg_match('/^\d+$/D', $key) === 1) { return ltrim($stored, '0') === ltrim($key, '0'); }
        return mb_strtolower($stored) === mb_strtolower($key);
    }

    /** Put one row back; null when done, else why it was kept. */
    private function undoRow(object $row, Database $db, string $pk): ?string
    {
        $id = (string) $row->record_id;
        $current = $id === '' ? null : $this->repository->find($id);
        // Changed while the Undo runs (changedSince() checked before it started): keep that edit.
        if ($current !== null && $row->after_hash !== null && RecordRepository::fingerprint($current) !== $row->after_hash) {
            return AdminLang::get('import.undo_row_changed');
        }
        if ($row->status === 'created') {
            if ($current === null) { return null; }
            if (!$this->resource->canDelete($current)) { return AdminLang::get('import.undo_row_denied'); }
            $this->repository->delete($id);
            return null;
        }
        $before = json_decode((string) $row->before_values, true);
        if (!is_array($before) || !is_array($before['row'] ?? null)) { return AdminLang::get('import.undo_row_failed'); }
        if ($current === null) { return AdminLang::get('import.undo_row_changed'); }
        // Only the table's own columns: a query() with joins or computed columns adds others.
        $stored = $db->table($this->resource::getTable())->where($pk, '=', $id)->first();
        $data = array_intersect_key($before['row'], $stored === null ? [] : (array) $stored);
        unset($data[$pk]);
        if ($data !== []) { $this->repository->update($id, $data); }
        foreach ($this->form->fields() as $name => $field) {
            if ($field instanceof Fields\BelongsToMany && ($pivot = $field->getPivot()) !== null && isset($before['links'][$name])) {
                $owner = (string) ($before['row'][$pivot->parentKey] ?? '');
                if ($owner !== '') { $pivot->sync($db, $owner, array_map('strval', (array) $before['links'][$name])); }
            }
        }
        if (($saved = $this->repository->find($id)) !== null) { \FloCMS\Admin\Media\MediaUsage::record($this->resource, $this->form, $saved); }
        return null;
    }

    /* ---------------------------------------------------------------
     * One row
     * ------------------------------------------------------------- */

    /**
     * What a line would do, and why not.
     *
     * @param list<string> $cells
     * @return array{status: string, record: ?object, state: array<string, mixed>, errors: list<array{field: string, value: string, message: string}>, key: string, emptied: int, dropped: int}
     */
    public function evaluate(ImportRun $run, int $line, array $cells, Values $values, bool $checking): array
    {
        $errors = [];
        $error = static function (string $field, string $value, string $message) use (&$errors): void {
            $errors[] = ['field' => $field, 'value' => mb_substr($value, 0, 200), 'message' => $message];
        };
        $byTarget = [];
        // The reader has already cleaned the cells (trimmed, formula guard taken off).
        foreach ($run->mapping as $index => $target) { $byTarget[$target] = (string) ($cells[$index] ?? ''); }

        // The file's values per field (a translatable field: language => text), cleaned by beforeRow()
        $fields = [];
        foreach ($byTarget as $target => $cell) {
            $info = $this->targets[$target] ?? null;
            if ($info === null || $info['field'] === null) { continue; }
            $name = $info['field']->getName();
            if ($info['lang'] !== null) { $fields[$name][$info['lang']] = $cell; } else { $fields[$name] = $cell; }
        }
        if (($hook = $this->import->getBeforeRow()) !== null) {
            $changed = $hook($fields);
            $fields = is_array($changed) ? $changed : $fields;
        }

        // Which record the line is about (after beforeRow(), which may clean the key)
        $keyColumn = $run->keyColumn ?? $this->resource::getPrimaryKey();
        $pk = $this->resource::getPrimaryKey();
        $keyValue = $keyColumn === $pk ? ($byTarget[$pk] ?? '') : ($fields[$keyColumn] ?? ($byTarget[$keyColumn] ?? ''));
        $key = trim(is_scalar($keyValue) ? (string) $keyValue : '');
        // 007 and 7 are the same number: one form, so duplicates are found.
        if (preg_match('/^\d{1,18}$/D', $key) === 1) { $key = ltrim($key, '0') === '' ? '0' : ltrim($key, '0'); }
        $record = null;
        if ($key !== '') {
            $found = $this->repository->newQuery()->where($keyColumn, '=', $key)->first();
            // MySQL compares "12abc" with a number column as 12: only the same value counts.
            $record = $found !== null && self::sameKey((string) ($found->{$keyColumn} ?? ''), $key) ? $found : null;
            if ($checking && ($first = $this->store->lineWithKey($run, $key)) !== null) {
                $error($this->targets[$keyColumn]['label'] ?? $keyColumn, $key, AdminLang::get('import.duplicate_key', ['line' => (string) $first]));
            }
        }
        if ($run->mode === 'create' && $record !== null) {
            $error($this->targets[$keyColumn]['label'] ?? $keyColumn, $key, AdminLang::get('import.exists'));
            $record = null;
        }
        if ($run->mode === 'update' && $record === null) {
            $error($this->targets[$keyColumn]['label'] ?? $keyColumn, $key, AdminLang::get($key === '' ? 'import.key_empty' : 'import.not_found'));
            return ['status' => 'error', 'record' => null, 'state' => [], 'errors' => $errors, 'key' => $key, 'emptied' => 0, 'dropped' => 0];
        }
        if ($record !== null && !$this->resource->canEdit($record)) {
            $error($this->targets[$keyColumn]['label'] ?? $keyColumn, $key, AdminLang::get('import.not_editable'));
        }
        // New records: defaults() fill the fields the file does not carry
        if ($record === null) {
            foreach ($this->import->getDefaults() as $name => $value) {
                if (!array_key_exists($name, $fields)) { $fields[$name] = $value; }
            }
        }

        // Into form input: every field gets a value, the file's or the record's (its default on create)
        $input = [];
        $emptied = 0;
        $unread = [];
        $default = Languages::default();
        foreach ($this->form->fields() as $name => $field) {
            $current = $field->stateFromRecord($record, $default);
            // From the file (or defaults() / beforeRow()); uploads never come from a file.
            $given = array_key_exists($name, $fields) && $field->isWritable() && !$field->isOmitted() && !$field instanceof Fields\FileUpload && !$field instanceof Fields\MediaPicker;
            if (!$given) {
                if ($field instanceof Fields\BelongsToMany || $field instanceof Fields\Repeater) { continue; }
                $input[$name] = $current;
                continue;
            }
            $value = $fields[$name];
            if ($field->isTranslatable()) {
                $merged = is_array($current) ? $current : [];
                foreach (is_array($value) ? $value : [$default => (string) $value] as $lang => $text) {
                    if (!in_array($lang, Languages::all(), true)) { continue; }
                    if ($record !== null && trim((string) $text) === '' && trim((string) ($merged[$lang] ?? '')) !== '') { $emptied++; }
                    $merged[$lang] = $field instanceof Fields\RichEditor ? $values->richText((string) $text) : (string) $text;
                }
                $input[$name] = $merged;
                continue;
            }
            $text = is_array($value) ? (string) ($value[$default] ?? '') : (string) $value;
            if ($record !== null && trim($text) === '' && (is_array($current) ? $current !== [] : trim($current) !== '' && !($field instanceof Fields\Toggle && $current === '0'))) { $emptied++; }
            [$converted, $message] = $values->read($field, $text);
            if ($message !== null) { $error(strip_tags($field->getLabel()), $text, $message); $unread[$name] = true; }
            if ($field instanceof Fields\BelongsToMany) {
                $input[$name] = is_array($converted) ? $converted : [];
                $input[$name . Fields\BelongsToMany::PRESENT] = '1';
                continue;
            }
            $input[$name] = $converted;
        }

        $state = $this->submission->state($this->form, $input, $record);
        foreach ($this->submission->errors($this->form, $state, $record) as $errorKey => $message) {
            // A cell that could not be read already says why; the form's rule would repeat it.
            if (isset($unread[(string) preg_replace('/[.\[].*$/', '', (string) $errorKey)])) { continue; }
            [$label, $value] = $this->describe((string) $errorKey, $byTarget);
            $error($label, $value, $message);
        }
        $status = $errors !== [] ? 'error' : ($record === null ? 'new' : 'update');
        return ['status' => $status, 'record' => $record, 'state' => $state, 'errors' => $errors, 'key' => $key, 'emptied' => $status === 'update' ? $emptied : 0,
            'dropped' => $values->droppedMedia()];
    }

    /**
     * The label and file value of an error key ("title.ar", "rooms.2.name").
     *
     * @param array<string, string> $byTarget
     * @return array{0: string, 1: string}
     */
    private function describe(string $key, array $byTarget): array
    {
        if ($key === '_form') { return [AdminLang::get('import.row'), '']; }
        if (isset($this->targets[$key])) { return [$this->targets[$key]['label'], $byTarget[$key] ?? '']; }
        $name = explode('.', $key)[0];
        $field = $this->form->fields()[$name] ?? null;
        $lang = explode('.', $key);
        $lang = end($lang);
        $label = $field !== null ? strip_tags($field->getLabel()) : $name;
        if (in_array($lang, Languages::all(), true) && isset($this->targets[$name . '.' . $lang])) { return [$this->targets[$name . '.' . $lang]['label'], $byTarget[$name . '.' . $lang] ?? '']; }
        return [$label, $byTarget[$name] ?? ''];
    }

    /* ---------------------------------------------------------------
     * Reading the results
     * ------------------------------------------------------------- */

    /**
     * Lines with errors: line, and the errors.
     *
     * @return list<array{line: int, errors: list<array{field: string, value: string, message: string}>}>
     */
    public function problems(ImportRun $run, int $limit): array
    {
        $list = [];
        foreach ($this->store->rowsWithStatus($run, ['error', 'failed'], $limit) as $row) {
            $errors = json_decode((string) $row->errors, true);
            $list[] = ['line' => (int) $row->line, 'errors' => is_array($errors) ? $errors : []];
        }
        return $list;
    }

    /** The file's lines with errors, plus an Errors column, to fix and upload again. */
    public function writeProblems(ImportRun $run, CsvWriter $writer): void
    {
        $reader = new CsvReader(ImportStore::path($run), $run->separator);
        $writer->row([...$run->headings, AdminLang::get('import.errors_column')]);
        $from = $run->dataStart;
        $number = 2;
        do {
            $batch = $reader->rows($from, $number, 1000);
            $lines = array_keys($batch['rows']);
            $rows = $lines === [] ? [] : $this->store->rowsByLine($run, min($lines), max($lines));
            foreach ($batch['rows'] as $line => $cells) {
                $row = $rows[$line] ?? null;
                if ($row === null || !in_array($row->status, ['error', 'failed'], true)) { continue; }
                $errors = json_decode((string) $row->errors, true);
                $text = implode('; ', array_map(static fn (array $e): string => trim(($e['field'] ?? '') . ': ' . ($e['message'] ?? ''), ': '), is_array($errors) ? $errors : []));
                $writer->row([...array_pad($cells, count($run->headings), ''), $text]);
            }
            $from = $batch['next'];
            $number += count($batch['rows']);
        } while (!$batch['end'] && $batch['rows'] !== []);
        $writer->close();
    }
}
