<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use Closure;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
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
    /**
     * @param class-string<Resource> $resource
     * @param Resource|null $definition the resource object when it is limited to a parent (0.22)
     */
    public function __construct(private readonly string $resource, private readonly ?Resource $definition = null)
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
    /**
     * @param (Closure(): \FloCMS\Core\Database)|null $rowsQuery the list's rows on every page, unordered (totals, 0.38)
     */
    public function index(Table $table, array $result, string $search, bool $canCreate, bool $canUpdate, bool $canDelete, Closure $canEditRecord, Closure $canDeleteRecord, ?\FloCMS\Admin\Table\FilterSet $filters = null, string $tabs = '', ?Closure $rowsQuery = null, ?\FloCMS\Admin\Table\Grouping $grouping = null): string
    {
        $class = $this->resource;
        $slug = $class::getSlug();
        $pk = $class::getPrimaryKey();
        $definition = $this->definition ?? new $class();
        $scope = $definition->parentScope();
        $actions = new \FloCMS\Admin\Actions\ActionRenderer($definition, new \FloCMS\Admin\Actions\ActionRegistry($definition, $table));
        $bulkActions = array_filter((new \FloCMS\Admin\Actions\ActionRegistry($definition, $table))->all('bulk'), fn ($action): bool => $action->available($definition));
        $pagination = $result['pagination'];
        $page = (int) $pagination['cur_page'];
        $filters ??= new \FloCMS\Admin\Table\FilterSet($table);
        foreach ($table->getFilters() as $filter) {
            if ($filter instanceof \FloCMS\Admin\Table\Filters\RelationFilter) { $filter->lookupFrom(Admin::url($slug . '/lookup')); }
        }
        $state = ['static' => Admin::config()->reactiveEnabled() ? 1 : null, 'parent' => $scope?->token(), 'filter' => $filters->active(), 'q' => $search, 'sort' => $result['sort'], 'dir' => $result['dir'],
            'group' => $grouping !== null && $grouping->has() ? $grouping->value() : null];

        $visibility = new \FloCMS\Admin\Table\ColumnVisibility($table, $scope?->columnsKey() ?? $slug);
        $visible = $visibility->visibleColumns();
        foreach ($visible as $column) { $column->prepareRecords($result['data']); }
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
        // Editable cells (0.38) post here and come back to this page of the list.
        $cellContext = new \FloCMS\Admin\Table\Cells\CellContext($definition, $backUrl, 'fa-list-cell');
        // Group headings with their counts and subtotals (0.38)
        $groups = $grouping?->active() !== null
            ? $grouping->headings($result['data'], $visible, $rowsQuery, ($result['previous'] ?? null) !== null, $result['previous']['key'] ?? null, count($bulkActions) > 0, $table->getActions() !== [], 'fa-list')
            : ['heads' => [], 'keys' => []];
        $rows = [];
        foreach ($result['data'] as $index => $record) {
            $id = property_exists($record, $pk) ? (string) $record->{$pk} : '';
            $cells = [];
            foreach ($visible as $name => $column) {
                $html = $column->renderCell($record, $cellContext);
                if ($name === $table->primaryColumn()) { $html = self::rowLink($definition, $record, $html, ['parent' => $scope?->token()]); }
                $cells[] = ['html' => $html, 'class' => $column->cellClass() . ($name === $table->primaryColumn() ? ' fa-cell-primary' : ''), 'label' => $column->getLabel()];
            }
            $rows[] = [
                'id' => $id,
                'head' => $groups['heads'][$index] ?? '',
                'group' => $groups['keys'][$index] ?? null,
                'cells' => $cells,
                'actions' => $actions->render('row', $record, false, backUrl: $backUrl),
                'editUrl' => $canUpdate && $id !== '' && $canEditRecord($record) ? Admin::url($slug . '/edit/' . $id, ['parent' => $scope?->token()]) : null,
                'deleteUrl' => $canDelete && $id !== '' && $canDeleteRecord($record) ? Admin::url($slug . '/delete/' . $id, ['parent' => $scope?->token()]) : null,
            ];
        }

        $total = (int) $pagination['items_count'];
        $perPage = (int) $pagination['per_page'];
        $totals = self::totals($visible, $result, $rowsQuery, $search !== '' || $filters->active() !== [], $table->getSummaryMode());
        $from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
        $to = min($total, $page * $perPage);

        return TemplateEngine::renderFile(self::viewPath('index'), [
            't' => self::translator(),
            'title' => $scope !== null ? AdminLang::get('relations.list_title', ['label' => $class::getPluralLabel(), 'parent' => $scope->parentTitle()]) : $class::getPluralLabel(),
            'parentLink' => $scope !== null ? ['url' => $scope->parentUrl(), 'label' => AdminLang::get('relations.back_to', ['title' => $scope->parentTitle()])] : null,
            'backIcon' => Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'), 'parentToken' => $scope?->token(),
            'createUrl' => $canCreate && ($scope === null || $scope->manager->allowsCreate()) ? Admin::url($slug . '/create', ['parent' => $scope?->token(), 'back' => $scope !== null ? Admin::url($slug, $state) : null]) : null,
            'pageActions' => $actions->render('page', reactive: false, backUrl: $backUrl),
            'tableActions' => $actions->render('table', reactive: false),
            'createLabel' => AdminLang::get('actions.create_label', ['label' => $class::getLabel()]),
            'listUrl' => Admin::url($slug, ['static' => $state['static'], 'parent' => $state['parent']]),
            'static' => $state['static'],
            'filterFields' => $filters->render('fa-list'), 'filterState' => $filters->active(), 'tabs' => $tabs,
            'hasActiveFilters' => $filters->active() !== [],
            'search' => $search,
            'hasSearch' => $table->searchableColumns() !== [],
            'sort' => $result['sort'],
            'dir' => $result['dir'],
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals, 'refreshCells' => $totals !== [] || $grouping?->active() !== null,
            'groupMenu' => self::groupMenu($grouping, static fn (string $value): string => Admin::url($slug, array_replace($state, ['group' => $value, 'page' => null]))),
            'grouped' => $grouping?->active() !== null,
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
            'reorderUrl' => $scope !== null ? null : self::reorderUrl($definition, $table, $filters, $canUpdate),
            'trash' => $scope !== null ? null : TrashPage::toolbarLink($class),
            'trashIcon' => Icons::svg('trash'),
            // CSV export and import (0.25)
            'export' => \FloCMS\Admin\ImportExport\ListTools::export($definition, $filters, $search, $result['sort'], $result['dir'], $total),
            'importUrl' => \FloCMS\Admin\ImportExport\ListTools::importUrl($definition), 'importIcon' => Icons::svg('upload'),
            'reorderIcon' => Icons::svg('arrows-up-down'), 'groupIcon' => Icons::svg('layers'),
            'filterErrors' => $filters->errors !== [],
            'filterCount' => $filters->panelCount(),
            'chips' => array_map(static fn (array $chip): array => $chip + ['url' => Admin::url($slug, array_replace($state, ['filter' => $chip['query']]))], $filters->chips()),
        ]);
    }

    /**
     * The totals lines under a list (0.38), or [] when no visible column has totals.
     *
     * @param array<string, \FloCMS\Admin\Table\Column> $visible
     * @param array{data: list<object>, pagination: array<string, mixed>} $result
     * @param (Closure(): \FloCMS\Core\Database)|null $rowsQuery
     * @return list<array{kind: string, cells: list<array{html: string, class: string, label: string}>}>
     */
    public static function totals(array $visible, array $result, ?Closure $rowsQuery, bool $filtered, string $mode = 'both'): array
    {
        $totals = new \FloCMS\Admin\Table\Totals($visible);
        if (!$totals->has()) { return []; }
        $last = (int) ($result['pagination']['last_page'] ?? 1);
        $db = \FloCMS\Core\App::db();
        return $totals->lines($result['data'], $db, $last > 1 && $rowsQuery !== null ? $rowsQuery() : null, (int) $result['pagination']['items_count'], $last, $filtered, $mode);
    }

    /**
     * The Group by menu (0.38), or null when the list has no groups. $url
     * makes a choice's link (page lists) or its value (Reactive lists).
     *
     * @return array{label: string, current: ?string, choices: list<array{value: string, label: string, active: bool, url: string}>, directions: list<array{value: string, label: string, active: bool, url: string}>, collapsible: bool}|null
     */
    public static function groupMenu(?\FloCMS\Admin\Table\Grouping $grouping, Closure $url): ?array
    {
        if ($grouping === null || !$grouping->has()) { return null; }
        $active = $grouping->active();
        $link = static fn (array $choice): array => $choice + ['url' => $url($choice['value'])];
        return [
            'label' => $active === null ? AdminLang::get('groups.title') : AdminLang::get('groups.title_active', ['label' => $active->getLabel()]),
            'current' => $active?->getLabel(),
            'choices' => array_map($link, $grouping->choices()),
            'directions' => array_map($link, $grouping->directions()),
            'collapsible' => $active !== null && $active->isCollapsible(),
        ];
    }

    /** The Reorder button's link, or null when the list is not reorderable or the user may not reorder. */
    public static function reorderUrl(\FloCMS\Admin\Resource $resource, Table $table, \FloCMS\Admin\Table\FilterSet $filters, bool $canUpdate): ?string
    {
        if (!$table->isReorderable() || !$canUpdate || $filters->errors !== [] || !$resource->canReorder()) { return null; }
        return Admin::url($resource::getSlug() . '/reorder', ['filter' => $filters->active() ?: null]);
    }

    /**
     * Reorder mode: the filtered rows in their saved order, each with a drag
     * handle and the ↑/↓ buttons used without JavaScript.
     *
     * @param array{total: int, data: list<object>} $result
     */
    public function reorder(Table $table, \FloCMS\Admin\Table\FilterSet $filters, array $result, string $moved = '', string $step = ''): string
    {
        $class = $this->resource;
        $slug = $class::getSlug();
        $pk = $class::getPrimaryKey();
        $visibility = new \FloCMS\Admin\Table\ColumnVisibility($table, $slug);
        $visible = $visibility->visibleColumns();
        foreach ($visible as $column) { $column->prepareRecords($result['data']); }
        $primary = $table->primaryColumn();
        $rows = [];
        $count = count($result['data']);
        foreach (array_values($result['data']) as $index => $record) {
            $id = property_exists($record, $pk) ? (string) $record->{$pk} : '';
            $title = '';
            $cells = [];
            foreach ($visible as $name => $column) {
                $html = $column->render($record);
                if ($name === $primary) {
                    $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                    array_unshift($cells, ['html' => $html, 'class' => 'fa-reorder-title', 'label' => $column->getLabel()]);
                } else {
                    $cells[] = ['html' => $html, 'class' => 'fa-reorder-cell', 'label' => $column->getLabel()];
                }
            }
            $title = $title !== '' ? $title : '#' . $id;
            $rows[] = ['id' => $id, 'title' => $title, 'cells' => $cells, 'position' => $index + 1,
                'first' => $index === 0, 'last' => $index === $count - 1,
                // After a move without JavaScript, focus returns to the same button (or its partner at an end).
                'focus' => $moved !== '' && $moved === $id ? ($step === 'up' && $index === 0 ? 'down' : ($step === 'down' && $index === $count - 1 ? 'up' : $step)) : ''];
        }
        $active = $filters->active();
        $reactive = Admin::config()->reactiveEnabled();
        $doneUrl = $reactive
            ? Admin::url($slug, ['admin-resource_filters' => $active !== [] ? $filters->json() : null])
            : Admin::url($slug, ['filter' => $active ?: null]);

        return TemplateEngine::renderFile(self::viewPath('reorder'), [
            't' => self::translator(),
            'title' => AdminLang::get('reorder.title', ['label' => $class::getPluralLabel()]),
            'rows' => $rows,
            'total' => $result['total'],
            'limit' => $table->getReorderLimit(),
            'tooMany' => $result['total'] > $table->getReorderLimit(),
            'filterErrors' => $filters->errors !== [],
            'chips' => $filters->chips(),
            'filtersJson' => $filters->json(),
            'action' => Admin::url($slug . '/reorder'),
            'doneUrl' => $doneUrl,
            'csrf' => Csrf::field(),
            'csrfToken' => Csrf::token(),
            'handleIcon' => Icons::svg('grip', 'fa-icon fa-grip'),
            'upIcon' => Icons::svg('chevron-up'),
            'downIcon' => Icons::svg('chevron-down'),
        ]);
    }

    public function form(string $title, string $action, string $fields, ?string $deleteUrl, bool $isEdit, bool $canContinue, bool $hasErrors,
        ?string $backUrl = null, bool $canAnother = false, bool $dirty = false, bool $hasAside = false, ?array $history = null,
        string $managers = '', ?string $relatedText = null, ?array $parent = null, ?array $translation = null): string
    {
        $class = $this->resource;
        $trashed = $deleteUrl !== null && \FloCMS\Admin\Trash\Trash::enabledFor($class);

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
            'deleteText' => self::deleteText($class) . ($relatedText !== null ? ' ' . $relatedText : ''),
            'managers' => $managers,
            'parent' => $parent,
            'backIcon' => Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'),
            'trashed' => $trashed,
            'history' => $history,
            'historyIcon' => Icons::svg('history'),
            'closeIcon' => Icons::svg('x'),
            // Page-level translation (0.23): the switch, the note and the language shown first.
            'translation' => $translation,
        ]);
    }

    /**
     * The row's title as a link to its record (0.27): Edit or the view page, as
     * Resource::recordUrl() decides; left as it is when it holds a link already.
     *
     * @param array<string, string|null> $query
     */
    public static function rowLink(\FloCMS\Admin\Resource $resource, object $record, string $html, array $query = []): string
    {
        // A cell with a title part (0.28, avatar and second line): only the title is the link.
        if (preg_match('~<!--fa-title-->(.*?)<!--/fa-title-->~s', $html, $title) === 1) {
            if (preg_match('/<(a|button)\b/i', $title[1]) === 1) { return $html; }
            $url = $resource->recordUrl($record, $query);
            return str_replace($title[0], $url === null ? $title[1] : '<a class="fa-row-link" href="' . Str::e($url) . '">' . $title[1] . '</a>', $html);
        }
        if ($html === '' || preg_match('/<(a|button)\b/i', $html) === 1) { return $html; }
        $url = $resource->recordUrl($record, $query);
        return $url === null ? $html : '<a class="fa-row-link" href="' . Str::e($url) . '">' . $html . '</a>';
    }

    /** The delete confirmation's text: "It goes to the trash for 30 days…" or "This cannot be undone." */
    public static function deleteText(string $class): string
    {
        if (!\FloCMS\Admin\Trash\Trash::enabledFor($class)) { return AdminLang::get('delete.text'); }
        $days = \FloCMS\Admin\Trash\Trash::days();
        return $days > 0 ? AdminLang::get('delete.text_trash', ['days' => $days]) : AdminLang::get('delete.text_trash_kept');
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
