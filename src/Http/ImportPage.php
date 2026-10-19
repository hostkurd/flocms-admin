<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\ImportExport\{CsvReader, CsvWriter, ImportException, ImportFiles, ImportRun, ImportStore, Importer, ListTools};
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\{Actor, AdminLang, Icons};
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\Http\Response;
use FloCMS\Core\{Csrf, HttpException, Session, TemplateEngine};

/**
 * The import pages of a resource (0.25), /admin/<slug>/import…:
 *
 *   GET  import                      upload (step 1); POST with a file
 *   POST import/upload/<start|chunk|complete|abort>   the same file in parts (the browser)
 *   GET  import/<run>                the page of the run's step
 *   GET|POST import/<run>/match      match columns (step 2)
 *   GET  import/<run>/preview        checking and the preview (step 3)
 *   POST import/<run>/start          import the rows that passed (step 4)
 *   GET  import/<run>/progress       the import running
 *   GET  import/<run>/result         the result; POST import/<run>/undo
 *   POST import/<run>/step           one batch (JSON for the browser, a redirect without)
 *   POST import/<run>/cancel
 *   GET  import/<run>/problems       the lines with errors as CSV
 *
 * A run belongs to the user who uploaded it: only they continue it or download
 * its lines; anyone who may import into the resource sees its result and can undo it.
 *
 * @internal
 */
final class ImportPage
{
    private ImportStore $store;
    private Importer $importer;

    public function __construct(private readonly Resource $resource)
    {
        $this->store = ImportStore::make();
        $this->importer = new Importer($resource, $this->store);
    }

    /** @param list<string> $params @param array<string, mixed> $input */
    public function handle(array $params, bool $post, array $input, bool $json): string|Response
    {
        if (($params[0] ?? '') === 'upload') {
            // Every upload step changes something: POST only (and so CSRF-checked).
            if (!$post) { throw new HttpException(405); }
            return $this->chunk((string) ($params[1] ?? ''), $input);
        }
        if ($params === []) { return $post ? $this->receive() : $this->upload(); }
        if (preg_match('/^\d{1,10}$/D', (string) $params[0]) !== 1 || count($params) > 2) { throw new HttpException(404); }
        $run = $this->store->find((int) $params[0]);
        if ($run === null || $run->resource !== $this->resource::getSlug()) { throw new HttpException(404); }
        $step = (string) ($params[1] ?? '');
        if ($step === '') { return self::redirect($this->pageOf($run)); }

        $owner = $run->userId !== null && (string) $run->userId === (string) Session::get('user_id', '');
        $ownerOnly = ['match', 'preview', 'start', 'progress', 'step', 'cancel', 'problems'];
        // Anyone may undo (result page), so anyone may follow and drive a running Undo.
        $othersUndo = in_array($step, ['step', 'progress'], true) && $run->status === 'undoing';
        if (in_array($step, $ownerOnly, true) && !$owner && !$othersUndo) { throw new HttpException(403); }
        if ($post && !in_array($step, ['match', 'start', 'step', 'cancel', 'undo'], true)) { throw new HttpException(405); }
        if (!$post && in_array($step, ['start', 'step', 'cancel', 'undo'], true)) { throw new HttpException(405); }

        try {
            return match ($step) {
                'match' => $this->match($run, $post, $input),
                'preview' => $run->status === 'uploaded' ? self::redirect($this->url($run, 'match')) : $this->preview($run),
                'start' => $this->start($run),
                'progress' => in_array($run->status, ['importing', 'undoing'], true) ? $this->progress($run) : self::redirect($this->pageOf($run)),
                'result' => $this->result($run, $owner),
                'step' => $this->step($run, $json),
                'cancel' => $this->cancel($run),
                'undo' => $this->undo($run),
                'problems' => $this->problems($run),
                default => throw new HttpException(404),
            };
        } catch (ImportException $error) {
            Session::setFlash($error->getMessage(), 'danger');
            if ($json) { return Response::json(['error' => $error->getMessage(), 'url' => $this->pageOf($this->store->find($run->id) ?? $run)], 409)->header('Cache-Control', 'no-store'); }
            return self::redirect($this->pageOf($this->store->find($run->id) ?? $run));
        }
    }

    public function title(): string
    {
        return AdminLang::get('import.title', ['label' => $this->resource::getPluralLabel()]);
    }

    /* ---------------------------------------------------------------
     * Step 1: upload
     * ------------------------------------------------------------- */

    private function upload(?string $error = null): string
    {
        $config = Admin::config()->import();
        $unfinished = $this->store->unfinished($this->resource::getSlug(), Actor::id());
        return $this->view('upload', [
            'error' => $error,
            'action' => $this->base(),
            'uploadUrl' => $this->base() . '/upload',
            'templateUrl' => Admin::url($this->resource::getSlug() . '/export', ['template' => 1]),
            'templateName' => preg_replace('/[^a-z0-9-]+/', '-', $this->resource::getSlug()) . '-template.csv',
            'limits' => AdminLang::get('import.limits', ['size' => self::megabytes($config['max_bytes']), 'rows' => number_format($config['max_rows'])]),
            'maxBytes' => $config['max_bytes'], 'chunkBytes' => ImportFiles::CHUNK_BYTES,
            'unfinished' => $unfinished === null ? null : ['url' => $this->pageOf($unfinished), 'name' => $unfinished->fileName, 'cancelUrl' => $unfinished->status === 'importing' ? null : $this->url($unfinished, 'cancel')],
        ]);
    }

    private function receive(): string|Response
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return $this->upload(AdminLang::get('import.choose_file')); }
        if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { return $this->upload(AdminLang::get('import.too_large', ['size' => self::megabytes(Admin::config()->import()['max_bytes'])])); }
        try {
            $result = ImportFiles::upload($file);
        } catch (\FloCMS\Uploader\Exceptions\UploadException|HttpException) {
            return $this->upload(AdminLang::get('import.not_csv', ['size' => self::megabytes(Admin::config()->import()['max_bytes'])]));
        }
        try {
            $run = $this->importer->start((string) $result->path(), (string) $result->originalName(), (int) $result->size(), Actor::id(), (string) Actor::name());
        } catch (ImportException $error) {
            @unlink((string) $result->absolutePath());
            @unlink((string) $result->absolutePath() . '.utf8');
            return $this->upload($error->getMessage());
        }
        return self::redirect($this->url($run, 'match'));
    }

    /** @param array<string, mixed> $input */
    private function chunk(string $step, array $input): Response
    {
        $json = static fn (array $data, int $status = 200): Response => Response::json($data, $status)->header('Cache-Control', 'no-store');
        $chunks = ImportFiles::chunks();
        $id = $input['upload_id'] ?? null;
        try {
            switch ($step) {
                case 'start':
                    $name = $input['name'] ?? null;
                    $bytes = $input['bytes'] ?? null;
                    if (!is_string($name) || !is_scalar($bytes) || !ctype_digit((string) $bytes)) { throw new HttpException(400); }
                    $status = $chunks->start($name, (int) $bytes, ImportFiles::owner());
                    Session::set('import_upload_' . $status['upload_id'], mb_substr($name, 0, 190));
                    return $json(['upload_id' => $status['upload_id'], 'chunk_size' => ImportFiles::CHUNK_BYTES, 'received' => $status['received_bytes']]);
                case 'chunk':
                    $offset = $input['offset'] ?? null;
                    if (!is_string($id) || !is_scalar($offset) || !ctype_digit((string) $offset) || !is_array($_FILES['chunk'] ?? null)) { throw new HttpException(400); }
                    $status = $chunks->append($id, (int) $offset, $_FILES['chunk'], ImportFiles::owner());
                    return $json(['received' => $status['received_bytes'], 'complete' => $status['complete']]);
                case 'complete':
                    if (!is_string($id)) { throw new HttpException(400); }
                    $result = $chunks->complete($id, ImportFiles::owner());
                    $name = (string) Session::get('import_upload_' . $id, (string) $result->originalName());
                    Session::delete('import_upload_' . $id);
                    @chmod((string) $result->absolutePath(), 0600);
                    try {
                        $run = $this->importer->start((string) $result->path(), $name, (int) $result->size(), Actor::id(), (string) Actor::name());
                    } catch (ImportException $error) {
                        @unlink((string) $result->absolutePath());
                        @unlink((string) $result->absolutePath() . '.utf8');
                        return $json(['error' => $error->getMessage()], 422);
                    }
                    return $json(['url' => $this->url($run, 'match')]);
                case 'abort':
                    if (!is_string($id)) { throw new HttpException(400); }
                    $chunks->abort($id, ImportFiles::owner());
                    return $json(['ok' => true]);
            }
        } catch (\FloCMS\Uploader\Exceptions\ChunkOffsetException $error) {
            return $json(['error' => 'offset', 'received' => $error->expectedOffset()], 409);
        } catch (\FloCMS\Uploader\Exceptions\ValidationException) {
            return $json(['error' => AdminLang::get('import.not_csv', ['size' => self::megabytes(Admin::config()->import()['max_bytes'])])], 422);
        } catch (\FloCMS\Uploader\Exceptions\UploadException) {
            return $json(['error' => AdminLang::get('import.upload_failed')], 500);
        }
        throw new HttpException(404);
    }

    /* ---------------------------------------------------------------
     * Step 2: match columns
     * ------------------------------------------------------------- */

    /** @param array<string, mixed> $input */
    private function match(ImportRun $run, bool $post, array $input): string|Response
    {
        if (!in_array($run->status, ['uploaded', 'checking', 'checked'], true)) { return self::redirect($this->pageOf($run)); }
        $errors = [];
        $mapping = $run->mapping;
        if ($post) {
            $encoding = $input['encoding'] ?? null;
            if (($input['_reread'] ?? null) !== null && is_string($encoding) && $encoding !== $run->encoding) {
                $run = $this->importer->reencode($run, $encoding);
                return self::redirect($this->url($run, 'match'));
            }
            $columns = is_array($input['columns'] ?? null) ? $input['columns'] : [];
            $errors = $this->importer->saveMatch($run, $columns, (string) ($input['mode'] ?? 'create'), (string) ($input['key'] ?? ''), (string) ($input['date_order'] ?? 'dmy'));
            if ($errors === []) { return self::redirect($this->url($run, 'preview')); }
            $run = $this->store->find($run->id) ?? $run;
            $mapping = [];
            foreach ($columns as $index => $target) { if (is_numeric($index) && is_string($target) && $target !== '') { $mapping[(int) $index] = $target; } }
        }
        $targets = $this->importer->targets();
        $auto = $this->importer->schema()->match($run->headings);
        $rows = [];
        foreach ($run->headings as $index => $heading) {
            $samples = array_values(array_filter(array_map(static fn (array $row): string => CsvReader::clean((string) ($row[$index] ?? '')), $run->samples), static fn (string $v): bool => $v !== ''));
            $chosen = $mapping[$index] ?? '';
            $rows[] = [
                'index' => $index, 'heading' => $heading, 'samples' => implode(' · ', $samples), 'chosen' => $chosen,
                'state' => $chosen === '' ? 'skipped' : (($auto[$index] ?? null) === $chosen ? 'matched' : 'chosen'),
                'error' => $errors['columns.' . $index] ?? null,
            ];
        }
        $hasDates = false;
        foreach ($mapping as $target) {
            $field = $targets[$target]['field'] ?? null;
            if ($field instanceof \FloCMS\Admin\Form\Fields\Date) { $hasDates = true; }
        }
        $keys = array_map(fn (string $key): array => ['value' => $key, 'label' => $targets[$key]['label'] ?? $key], $this->importer->keyColumns());
        return $this->view('match', [
            'run' => $run, 'rows' => $rows, 'errors' => $errors,
            'targets' => array_map(static fn (array $t): array => ['value' => $t['key'], 'label' => $t['label'] . ($t['required'] ? ' *' : '')], array_values($targets)),
            'action' => $this->url($run, 'match'),
            'canUpdate' => $this->resource::canImportUpdates(),
            'keys' => $keys, 'hasDates' => $hasDates,
            'encodings' => array_map(static fn (string $e): array => ['value' => $e, 'label' => AdminLang::get('import.encoding_' . str_replace('-', '_', $e))], CsvReader::ENCODINGS),
            'separator' => match ($run->separator) { ';' => ';', "\t" => AdminLang::get('import.tab'), '|' => '|', default => ',' },
            'backUrl' => $this->base(),
            'missing' => $run->mode === 'update' ? '' : implode(AdminLang::get('form.list_separator'), $this->importer->missingRequired($mapping)),
        ]);
    }

    /* ---------------------------------------------------------------
     * Step 3: preview
     * ------------------------------------------------------------- */

    private function preview(ImportRun $run): string|Response
    {
        if (!in_array($run->status, ['checking', 'checked'], true)) { return self::redirect($this->pageOf($run)); }
        if ($run->status === 'checking') {
            return $this->view('working', ['run' => $run, 'phase' => 'checking', 'stepUrl' => $this->url($run, 'step'), 'cancelUrl' => $this->url($run, 'cancel'),
                'done' => $run->position, 'total' => $run->rowsTotal, 'paused' => $run->isPaused(), 'step' => 3]);
        }
        $problems = $this->importer->problems($run, 100);
        return $this->view('preview', [
            'run' => $run, 'ready' => $run->count('new') + $run->count('update'), 'new' => $run->count('new'), 'update' => $run->count('update'),
            'errorsCount' => $run->count('errors'), 'emptied' => $run->count('emptied'), 'mediaDropped' => $run->count('media_dropped'), 'problems' => $problems,
            'problemsUrl' => $this->url($run, 'problems'), 'backUrl' => $this->url($run, 'match'), 'startUrl' => $this->url($run, 'start'),
            'keyLabel' => $this->importer->targets()[$run->keyColumn ?? '']['label'] ?? '',
        ]);
    }

    /* ---------------------------------------------------------------
     * Step 4: import, result, undo
     * ------------------------------------------------------------- */

    private function start(ImportRun $run): Response
    {
        $run = $this->importer->begin($run);
        return self::redirect($this->url($run, 'progress'));
    }

    private function progress(ImportRun $run): string
    {
        $undoing = $run->status === 'undoing';
        return $this->view('working', ['run' => $run, 'phase' => $undoing ? 'undoing' : 'importing', 'stepUrl' => $this->url($run, 'step'),
            'cancelUrl' => $undoing ? null : $this->url($run, 'cancel'),
            'done' => $run->position, 'total' => $undoing ? $run->count('created') + $run->count('updated') : $run->rowsTotal, 'paused' => $run->isPaused(), 'step' => 4]);
    }

    private function result(ImportRun $run, bool $owner): string|Response
    {
        if (in_array($run->status, ['uploaded', 'checking', 'checked', 'importing'], true)) { return self::redirect($this->pageOf($run)); }
        if ($run->status === 'undoing') { return $this->progress($run); }
        $canUndo = $run->canUndo() && ListTools::canImport($this->resource) && $this->resource::canUndoImport($run->count('created'), $run->count('updated'));
        return $this->view('result', [
            'run' => $run, 'owner' => $owner, 'canUndo' => $canUndo, 'undoUrl' => $this->url($run, 'undo'),
            'listUrl' => Admin::url($this->resource::getSlug()), 'againUrl' => $this->base(),
            'problemsUrl' => $owner && ($run->count('errors') + $run->count('skipped')) > 0 && is_file(ImportStore::path($run)) ? $this->url($run, 'problems') : null,
            'when' => self::when($run->finishedAt ?? $run->updatedAt),
            'undoDays' => Admin::config()->import()['undo_days'],
        ]);
    }

    private function step(ImportRun $run, bool $json): Response
    {
        $run = $this->importer->step($run);
        $page = $this->pageOf($run);
        if (!$json) { return self::redirect(in_array($run->status, ['checking', 'importing', 'undoing'], true) ? $this->current($run) : $page); }
        $working = in_array($run->status, ['checking', 'importing', 'undoing'], true);
        return Response::json([
            'status' => $run->status, 'done' => $run->position,
            'total' => $run->status === 'undoing' ? $run->count('created') + $run->count('updated') : $run->rowsTotal,
            'counts' => $run->counts, 'finished' => !$working, 'url' => $page,
            'text' => AdminLang::get('import.progress', ['done' => number_format($run->position), 'total' => number_format($run->status === 'undoing' ? $run->count('created') + $run->count('updated') : $run->rowsTotal)]),
        ])->header('Cache-Control', 'no-store');
    }

    private function cancel(ImportRun $run): Response
    {
        $run = $this->importer->cancel($run);
        if (in_array($run->status, ['cancelled'], true) && $run->count('created') + $run->count('updated') === 0) {
            Session::setFlash(AdminLang::get('import.cancelled'), 'success');
            return self::redirect($this->base());
        }
        return self::redirect($this->url($run, 'result'));
    }

    private function undo(ImportRun $run): Response
    {
        if (!ListTools::canImport($this->resource) || !$this->resource::canUndoImport($run->count('created'), $run->count('updated'))) { throw new HttpException(403); }
        $run = $this->importer->undo($run);
        return self::redirect($this->url($run, 'progress'));
    }

    private function problems(ImportRun $run): Response
    {
        if (!is_file(ImportStore::path($run))) { throw new HttpException(404); }
        $name = preg_replace('/\.(csv|tsv|txt)$/i', '', $run->fileName) ?: 'import';
        $download = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '-to-fix.csv';
        $headers = self::csvHeaders($download);
        foreach ($headers as $header => $value) { header($header . ': ' . $value); }
        $this->importer->writeProblems($run, new CsvWriter());
        $response = Response::make('');
        foreach ($headers as $header => $value) { $response = $response->header($header, $value); }
        return $response;
    }

    /** @return array<string, string> */
    public static function csvHeaders(string $fileName): array
    {
        return [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $fileName) . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /* ---------------------------------------------------------------
     * Addresses and the view
     * ------------------------------------------------------------- */

    private function base(): string { return Admin::url($this->resource::getSlug() . '/import'); }
    public function url(ImportRun $run, string $step): string { return $this->base() . '/' . $run->id . '/' . $step; }

    /** The page that shows the run as it is now. */
    public function pageOf(ImportRun $run): string
    {
        return match ($run->status) {
            'uploaded' => $this->url($run, 'match'),
            'checking', 'checked' => $this->url($run, 'preview'),
            'importing', 'undoing' => $this->url($run, 'progress'),
            default => $this->url($run, 'result'),
        };
    }

    /** Where a step without JavaScript goes on. */
    private function current(ImportRun $run): string
    {
        return $run->status === 'checking' ? $this->url($run, 'preview') : $this->url($run, 'progress');
    }

    private static function redirect(string $url): Response
    {
        return Response::make('', 303)->header('Location', $url)->header('Cache-Control', 'no-store');
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    }

    private static function when(string $date): string
    {
        $time = strtotime($date);
        return $time === false ? $date : date('Y-m-d H:i', $time);
    }

    /** @param array<string, mixed> $data */
    private function view(string $step, array $data): string
    {
        $steps = ['upload' => 1, 'match' => 2, 'preview' => 3, 'working' => (int) ($data['step'] ?? 3), 'result' => 4];
        return TemplateEngine::renderFile(PageView::viewPath('import'), $data + [
            't' => PageView::translator(), 'screen' => $step, 'current' => $steps[$step] ?? 1, 'title' => $this->title(),
            'resourceUrl' => Admin::url($this->resource::getSlug()), 'resourceLabel' => $this->resource::getPluralLabel(), 'resourceSingular' => $this->resource::getLabel(),
            'csrf' => Csrf::field(), 'csrfToken' => Csrf::token(),
            'stepNames' => [1 => AdminLang::get('import.step_upload'), 2 => AdminLang::get('import.step_match'), 3 => AdminLang::get('import.step_preview'), 4 => AdminLang::get('import.step_import')],
            'icons' => ['file' => Icons::svg('file'), 'upload' => Icons::svg('upload'), 'download' => Icons::svg('download'), 'check' => Icons::svg('check'), 'info' => Icons::svg('info'), 'x' => Icons::svg('x')],
            'run' => $data['run'] ?? null, 'language' => Languages::default(),
            'deleted' => !\FloCMS\Admin\Trash\Trash::enabledFor($this->resource),
        ]);
    }
}
