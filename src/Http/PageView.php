<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use Closure;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Csrf;
use FloCMS\Core\TemplateEngine;

/**
 * Builds the data of the list and form pages and renders their templates
 * (resources/views/*.html, FloCMS template syntax).
 *
 * @internal
 */
final class PageView
{
    /** @param class-string<Resource> $resource */
    public function __construct(private readonly string $resource)
    {
    }

    public static function viewPath(string $name): string
    {
        return dirname(__DIR__, 2) . '/resources/views/' . $name . '.html';
    }

    /**
     * @param array{data: list<object>, pagination: array<string, mixed>, sort: ?string, dir: string} $result
     * @param Closure(object): bool $canEditRecord
     * @param Closure(object): bool $canDeleteRecord
     */
    public function index(Table $table, array $result, string $search, bool $canCreate, bool $canUpdate, bool $canDelete, Closure $canEditRecord, Closure $canDeleteRecord, ?\FloCMS\Admin\Table\FilterSet $filters = null): string
    {
        $class = $this->resource;
        $slug = $class::getSlug();
        $pk = $class::getPrimaryKey();
        $definition = new $class();
        $actions = new \FloCMS\Admin\Actions\ActionRenderer($definition, new \FloCMS\Admin\Actions\ActionRegistry($definition, $table));
        $bulkActions = array_filter((new \FloCMS\Admin\Actions\ActionRegistry($definition, $table))->all('bulk'), fn ($action): bool => $action->available($definition));
        $pagination = $result['pagination'];
        $page = (int) $pagination['cur_page'];
        $filters ??= new \FloCMS\Admin\Table\FilterSet($table);
        foreach ($table->getFilters() as $filter) {
            if ($filter instanceof \FloCMS\Admin\Table\Filters\RelationFilter) { $filter->lookupFrom(Admin::url($slug . '/lookup')); }
        }
        $state = ['static' => Admin::config()->reactiveEnabled() ? 1 : null, 'filter' => $filters->active(), 'q' => $search, 'sort' => $result['sort'], 'dir' => $result['dir']];

        $visibility = new \FloCMS\Admin\Table\ColumnVisibility($table, $slug);
        $visible = $visibility->visibleColumns();
        foreach ($visible as $column) { $column->prepare($result['data']); }
        $columns = [];
        foreach ($visible as $name => $column) {
            $sortUrl = null;
            $sorted = null;
            if ($column->isSortable()) {
                $active = $result['sort'] === $name;
                $sorted = $active ? $result['dir'] : null;
                $next = $active && $result['dir'] === 'asc' ? 'desc' : 'asc';
                $sortUrl = Admin::url($slug, array_replace($state, ['sort' => $name, 'dir' => $next]));
            }
            $columns[] = [
                'label' => $column->getLabel(),
                'sortUrl' => $sortUrl,
                'sorted' => $sorted,
                'aria' => $sorted === null ? 'none' : ($sorted === 'asc' ? 'ascending' : 'descending'),
                'class' => $column->cellClass(),
            ];
        }

        $backUrl = Admin::url($slug, $state + ['page' => $page > 1 ? $page : null]);
        $rows = [];
        foreach ($result['data'] as $record) {
            $id = property_exists($record, $pk) ? (string) $record->{$pk} : '';
            $cells = [];
            foreach ($visible as $name => $column) {
                $cells[] = ['html' => $column->render($record), 'class' => $column->cellClass() . ($name === $table->primaryColumn() ? ' fa-cell-primary' : ''), 'label' => $column->getLabel()];
            }
            $rows[] = [
                'id' => $id,
                'cells' => $cells,
                'actions' => $actions->render('row', $record, false, backUrl: $backUrl),
                'editUrl' => $canUpdate && $id !== '' && $canEditRecord($record) ? Admin::url($slug . '/edit/' . $id) : null,
                'deleteUrl' => $canDelete && $id !== '' && $canDeleteRecord($record) ? Admin::url($slug . '/delete/' . $id) : null,
            ];
        }

        $total = (int) $pagination['items_count'];
        $perPage = (int) $pagination['per_page'];
        $from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
        $to = min($total, $page * $perPage);

        return TemplateEngine::renderFile(self::viewPath('index'), [
            't' => self::translator(),
            'title' => $class::getPluralLabel(),
            'createUrl' => $canCreate ? Admin::url($slug . '/create') : null,
            'pageActions' => $actions->render('page', reactive: false, backUrl: $backUrl),
            'tableActions' => $actions->render('table', reactive: false),
            'createLabel' => AdminLang::get('actions.create_label', ['label' => $class::getLabel()]),
            'listUrl' => Admin::url($slug, ['static' => $state['static']]),
            'static' => $state['static'],
            'filterFields' => $filters->render('fa-list'), 'filterState' => $filters->active(),
            'hasActiveFilters' => $filters->active() !== [],
            'search' => $search,
            'hasSearch' => $table->searchableColumns() !== [],
            'sort' => $result['sort'],
            'dir' => $result['dir'],
            'columns' => $columns,
            'rows' => $rows,
            'hasActions' => $table->getActions() !== [],
            'bulkActions' => $bulkActions, 'resource' => $definition, 'bulkUrl' => Admin::url($slug . '/bulk'),
            'summary' => $total === 0 ? '' : AdminLang::get('table.summary', ['from' => $from, 'to' => $to, 'total' => $total]),
            'pages' => self::pageLinks($slug, $state, $page, (int) $pagination['last_page']),
            'prevUrl' => $page > 1 ? Admin::url($slug, $state + ['page' => $page - 1 > 1 ? $page - 1 : null]) : null,
            'nextUrl' => !empty($pagination['has_next']) ? Admin::url($slug, $state + ['page' => $page + 1]) : null,
            'csrf' => Csrf::field(),
            'backUrl' => $backUrl,
            'searchIcon' => Icons::svg('search'),
            'plusIcon' => Icons::svg('plus'),
            'filterIcon' => Icons::svg('filter'),
            'removeIcon' => Icons::svg('x'),
            'prevIcon' => Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'),
            'nextIcon' => Icons::svg('chevron-right', 'fa-icon fa-flip-rtl'),
            'columnChoices' => array_map(static fn (string $name, bool $shown): array => ['name' => $name, 'label' => $table->getColumns()[$name]->getLabel(), 'visible' => $shown], array_keys($visibility->choices()), $visibility->choices()),
            'columnsChanged' => $visibility->changed(),
            'columnsIcon' => Icons::svg('columns'),
            'filterErrors' => $filters->errors !== [],
            'filterCount' => count($filters->active()),
            'chips' => array_map(static fn (array $chip): array => $chip + ['url' => Admin::url($slug, array_replace($state, ['filter' => $chip['query']]))], $filters->chips()),
        ]);
    }

    public function form(string $title, string $action, string $fields, ?string $deleteUrl, bool $isEdit, bool $canContinue, bool $hasErrors,
        ?string $backUrl = null, bool $canAnother = false, bool $dirty = false, bool $hasAside = false): string
    {
        $class = $this->resource;

        return TemplateEngine::renderFile(self::viewPath('form'), [
            't' => self::translator(),
            'title' => $title,
            'action' => $action,
            'fields' => $fields,
            'csrf' => Csrf::field(),
            'listUrl' => Admin::url($class::getSlug()),
            'listLabel' => $class::getPluralLabel(),
            'deleteUrl' => $deleteUrl,
            'isEdit' => $isEdit,
            'canContinue' => $canContinue,
            'hasErrors' => $hasErrors,
            'backUrl' => $backUrl ?? Admin::url($class::getSlug()),
            'canAnother' => $canAnother,
            // A re-shown form (errors, refreshed fields) holds unsaved input.
            'dirty' => $dirty,
            'hasAside' => $hasAside,
            'description' => $class::getDescription(),
            'trashIcon' => Icons::svg('trash'),
        ]);
    }

    /** @return Closure(string, array<string, string|int>=): string */
    public static function translator(): Closure
    {
        return static fn (string $key, array $replace = []): string => AdminLang::get($key, $replace);
    }

    /**
     * Page numbers with gaps: 1 … 4 5 [6] 7 8 … 20
     *
     * @param array<string, string|null> $state
     * @return list<array{label: string, url: ?string, current: bool}>
     */
    private static function pageLinks(string $slug, array $state, int $page, int $last): array
    {
        $links = [];
        foreach (self::pageNumbers($page, $last) as $n) {
            $links[] = $n === null
                ? ['label' => '…', 'url' => null, 'current' => false]
                : ['label' => (string) $n, 'url' => $n === $page ? null : Admin::url($slug, $state + ['page' => $n > 1 ? $n : null]), 'current' => $n === $page];
        }

        return $links;
    }

    /**
     * Page numbers around the current page, null for a gap: 1 … 4 5 [6] 7 8 … 20.
     * Shared by the standard and Reactive lists. Empty for a single page.
     *
     * @return list<int|null>
     */
    public static function pageNumbers(int $page, int $last): array
    {
        if ($last <= 1) {
            return [];
        }
        $numbers = array_unique(array_filter(
            [1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $last],
            static fn (int $n): bool => $n >= 1 && $n <= $last
        ));
        sort($numbers);
        $result = [];
        $previous = 0;
        foreach ($numbers as $n) {
            if ($n - $previous > 1) { $result[] = null; }
            $result[] = $n;
            $previous = $n;
        }

        return $result;
    }
}
