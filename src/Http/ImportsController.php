<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\ImportExport\{ImportRun, ImportStore, ListTools};
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\{Actor, AdminLang};
use FloCMS\Core\{HttpException, TemplateEngine};

/**
 * Site → Imports (/admin/imports, 0.25): the imports of the last 30 days into
 * the resources the user may import into, with their state; a paused run of the
 * user's own can be continued, a finished one opened and undone.
 */
final class ImportsController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null];

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $resources = self::allowedResources();
        if ($resources === []) { throw new HttpException(ImportStore::status() === 'ready' ? 403 : 404); }
        $page = max(1, (int) ($this->request->queryValue('page', '1') ?? 1));
        $result = ImportStore::make()->runs(array_keys($resources), $page, 25);
        $me = Actor::id();
        $rows = array_map(static function (ImportRun $run) use ($resources, $me): array {
            $class = $resources[$run->resource];
            $status = $run->isPaused() ? 'paused' : $run->status;
            $mine = $me !== null && $run->userId === $me;
            $base = Admin::url($run->resource . '/import/' . $run->id);
            return [
                'file' => $run->fileName,
                'detail' => in_array($run->status, ['checking', 'importing', 'undoing'], true)
                    ? AdminLang::get('imports.progress', ['done' => number_format($run->position), 'total' => number_format($run->rowsTotal)])
                    : AdminLang::get('imports.counts', ['created' => number_format($run->count('created')), 'updated' => number_format($run->count('updated')), 'skipped' => number_format($run->count('errors') + $run->count('skipped'))]),
                'into' => $class::getPluralLabel(), 'by' => $run->userName, 'when' => date('Y-m-d H:i', strtotime($run->createdAt) ?: time()),
                'rows' => number_format($run->rowsTotal), 'status' => $status,
                'tone' => match ($status) { 'finished' => 'green', 'paused', 'checked', 'uploaded' => 'yellow', 'failed', 'undone' => 'red', 'importing', 'checking', 'undoing' => 'blue', default => 'gray' },
                'url' => $mine || in_array($run->status, ['finished', 'cancelled', 'failed', 'undone'], true) ? $base : null,
                'action' => $mine && in_array($status, ['paused', 'uploaded', 'checked'], true) ? 'continue' : ($run->canUndo() ? 'undo' : null),
            ];
        }, $result['data']);
        $html = TemplateEngine::renderFile(PageView::viewPath('imports'), [
            't' => PageView::translator(), 'rows' => $rows, 'total' => $result['total'], 'page' => $page,
            'description' => AdminLang::get('imports.description', ['days' => Admin::config()->import()['undo_days']]),
            'prevUrl' => $page > 1 ? Admin::url('imports', ['page' => $page - 1 > 1 ? $page - 1 : null]) : null,
            'nextUrl' => $page * 25 < $result['total'] ? Admin::url('imports', ['page' => $page + 1]) : null,
        ]);
        return $this->panel(AdminLang::get('imports.title'), $html, 'imports');
    }

    /**
     * Resources the current user may import into, slug => class.
     *
     * @return array<string, class-string<Resource>>
     */
    public static function allowedResources(): array
    {
        if (ImportStore::status() !== 'ready') { return []; }
        $allowed = [];
        foreach (Admin::registry()->all() as $slug => $class) {
            try { if (ListTools::canImport(new $class())) { $allowed[$slug] = $class; } } catch (\Throwable) { continue; }
        }
        return $allowed;
    }
}
