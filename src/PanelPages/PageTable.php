<?php
declare(strict_types=1);

namespace FloCMS\Admin\PanelPages;

use FloCMS\Admin\Admin;
use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Http\PageView;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;
use FloCMS\Admin\Table\ColumnVisibility;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/**
 * A panel page's read-only table (0.36): rows from the page's own query, with
 * the list's search, sort, pages, column choice and CSV download.
 *
 * @internal
 */
final class PageTable
{
    public function __construct(private readonly AdminPage $page, private readonly Table $table)
    {
    }

    /** The table of a page, or null when table() sets no query. */
    public static function of(AdminPage $page): ?self
    {
        $table = $page->table(new Table());
        return $table->getQuery() !== null && $table->getColumns() !== [] ? new self($page, $table) : null;
    }

    public function table(): Table
    {
        return $this->table;
    }

    public function visibility(): ColumnVisibility
    {
        return new ColumnVisibility($this->table, 'page.' . $this->page::getSlug());
    }

    /** The rows of the filters and the search, sorted, before paging. */
    public function query(string $search, ?string $sort, ?string $dir): array
    {
        $db = App::db() ?? throw new \RuntimeException('A panel page\'s table needs the database.');
        $query = ($this->table->getQuery())($db, $this->page->filterValues());
        if (!$query instanceof Database) {
            throw new \LogicException('table()->query() of ' . $this->page::class . ' must return a query ($db->table(...)).');
        }
        $query = $this->search($query, $search);
        $column = $sort !== null ? $this->table->sortableColumn($sort) : null;
        if ($column === null && $this->table->getDefaultSort() !== null) {
            $column = $this->table->column((string) $this->table->getDefaultSort());
            $dir = $this->table->getDefaultDirection();
        }
        $dir = strtolower((string) $dir) === 'desc' ? 'desc' : 'asc';
        // defaultSort() on a column that isn't shown (the key, "id"): still the order of the rows.
        if ($column === null && $this->table->getDefaultSort() !== null && $this->table->column((string) $this->table->getDefaultSort()) === null) {
            return [$query->orderBy((string) $this->table->getDefaultSort(), $this->table->getDefaultDirection()), null, $this->table->getDefaultDirection()];
        }
        if ($column !== null && $column->isDatabaseColumn()) {
            $query = $column->isTranslatable()
                ? $query->orderByRaw(Translations::store()->sortExpression($query, $column->getName(), in_array(AdminLang::language(), Languages::all(), true) ? AdminLang::language() : Languages::default(), Languages::default()) . ' ' . strtoupper($dir))
                : $query->orderBy($column->getName(), $dir);
            // Rows with equal values keep one order: the default sort decides between them.
            $default = $this->table->getDefaultSort();
            if ($default !== null && $default !== $column->getName() && ($this->table->column($default) === null || !$this->table->column($default)->isTranslatable())) {
                $query = $query->orderBy($default, $this->table->getDefaultDirection());
            }
        }
        return [$query, $column?->getName(), $dir];
    }

    /**
     * @return array{data: list<object>, pagination: array<string, mixed>, sort: ?string, dir: string}
     */
    public function paginate(string $search, ?string $sort, ?string $dir, int $page): array
    {
        if ($sort !== null && $this->visibility()->isHidden($sort)) { $sort = null; $dir = null; }
        [$query, $sorted, $dir] = $this->query($search, $sort, $dir);
        $result = $query->paginate(max(1, $page), $this->table->getPerPage() ?? Admin::config()->perPage());
        return ['data' => $result['data'], 'pagination' => $result['pagination'], 'sort' => $sorted, 'dir' => $dir];
    }

    private function search(Database $query, string $search): Database
    {
        $search = trim($search);
        $columns = array_filter($this->table->searchableColumns(), static fn (Column $c): bool => $c->isDatabaseColumn());
        if ($search === '' || $columns === []) { return $query; }
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        return $query->where(static function (Database $group) use ($columns, $like, $search): void {
            foreach ($columns as $column) {
                if ($column->isTranslatable()) {
                    Translations::store()->whereContains($group, $column->getName(), Languages::all(), $search, Languages::default());
                } else {
                    $group->orWhereRaw('`' . $column->getName() . "` LIKE ? ESCAPE '!'", [$like]);
                }
                foreach ($column->getAlsoSearched() as $other) {
                    $group->orWhereRaw('`' . $other . "` LIKE ? ESCAPE '!'", [$like]);
                }
            }
        });
    }

    /**
     * The table's card: toolbar, rows and pages.
     *
     * @param array<string, mixed> $state the page's address: filter, q, sort, dir
     */
    public function render(array $result, string $search, array $state): string
    {
        $slug = $this->page::getSlug();
        $visibility = $this->visibility();
        $visible = $visibility->visibleColumns();
        foreach ($visible as $column) { $column->prepareRecords($result['data']); }
        $primary = $this->table->primaryColumn();
        $columns = [];
        foreach ($visible as $name => $column) {
            $sortUrl = null;
            $sorted = null;
            if ($column->isSortable()) {
                $active = $result['sort'] === $name;
                $sorted = $active ? $result['dir'] : null;
                $sortUrl = Admin::url($slug, array_replace($state, ['sort' => $name, 'dir' => $active && $result['dir'] === 'asc' ? 'desc' : 'asc', 'page' => null]));
            }
            $columns[] = ['label' => $column->getLabel(), 'sortUrl' => $sortUrl, 'sorted' => $sorted,
                'aria' => $sorted === null ? 'none' : ($sorted === 'asc' ? 'ascending' : 'descending'), 'class' => $column->cellClass()];
        }
        $link = $this->table->getRowUrl();
        $rows = [];
        foreach ($result['data'] as $row) {
            $url = null;
            if ($link !== null) {
                $candidate = $link($row);
                $url = is_string($candidate) && self::safeUrl($candidate) ? $candidate : null;
            }
            $cells = [];
            foreach ($visible as $name => $column) {
                $html = $column->render($row);
                if ($name === $primary && $url !== null) { $html = self::link($html, $url); }
                $cells[] = ['html' => $html, 'class' => $column->cellClass() . ($name === $primary ? ' fa-cell-primary' : ''), 'label' => $column->getLabel()];
            }
            $rows[] = ['cells' => $cells];
        }
        $pagination = $result['pagination'];
        $current = (int) $pagination['cur_page'];
        $total = (int) $pagination['items_count'];
        $perPage = (int) $pagination['per_page'];
        $pages = [];
        foreach (PageView::pageNumbers($current, (int) $pagination['last_page']) as $n) {
            $pages[] = $n === null ? ['label' => '…', 'url' => null, 'current' => false]
                : ['label' => (string) $n, 'url' => $n === $current ? null : Admin::url($slug, $state + ['page' => $n > 1 ? $n : null]), 'current' => $n === $current];
        }
        $listUrl = Admin::url($slug);
        $exportable = $this->table->isExportable() && Admin::config()->export()['enabled'];

        return \FloCMS\Core\TemplateEngine::renderFile(PageView::viewPath('panel-page-table'), [
            't' => PageView::translator(),
            'listUrl' => $listUrl,
            'backUrl' => Admin::url($slug, $state + ['page' => $current > 1 ? $current : null]),
            'filterState' => is_array($state['filter'] ?? null) ? $state['filter'] : [],
            'search' => $search,
            'hasSearch' => $this->table->searchableColumns() !== [],
            'clearSearchUrl' => Admin::url($slug, array_replace($state, ['q' => null, 'page' => null])),
            'sort' => $result['sort'], 'dir' => $result['dir'],
            'columns' => $columns, 'rows' => $rows,
            'summary' => $total === 0 ? '' : AdminLang::get('table.summary', ['from' => ($current - 1) * $perPage + 1, 'to' => min($total, $current * $perPage), 'total' => $total]),
            'pages' => $pages,
            'prevUrl' => $current > 1 ? Admin::url($slug, $state + ['page' => $current - 1 > 1 ? $current - 1 : null]) : null,
            'nextUrl' => !empty($pagination['has_next']) ? Admin::url($slug, $state + ['page' => $current + 1]) : null,
            'csrf' => \FloCMS\Core\Csrf::field(),
            'columnChoices' => array_map(fn (string $name, bool $shown): array => ['name' => $name, 'label' => $this->table->getColumns()[$name]->getLabel(), 'visible' => $shown], array_keys($visibility->choices()), $visibility->choices()),
            'columnsChanged' => $visibility->changed(),
            'exportUrl' => $exportable ? Admin::url($slug . '/csv', array_intersect_key($state, array_flip(['filter', 'q', 'sort', 'dir']))) : null,
            'filtered' => $search !== '' || ($state['filter'] ?? []) !== [],
            'searchIcon' => Icons::svg('search'), 'removeIcon' => Icons::svg('x'), 'columnsIcon' => Icons::svg('columns'), 'downloadIcon' => Icons::svg('download'),
            'prevIcon' => Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'), 'nextIcon' => Icons::svg('chevron-right', 'fa-icon fa-flip-rtl'),
        ]);
    }

    /** Write the rows (all pages, at most the export limit) as CSV: the visible columns' text. */
    public function csv(\FloCMS\Admin\ImportExport\CsvWriter $writer, string $search, ?string $sort, ?string $dir): int
    {
        $visible = $this->visibility()->visibleColumns();
        $writer->row(array_map(static fn (Column $c): string => html_entity_decode(strip_tags($c->getLabel()), ENT_QUOTES | ENT_HTML5, 'UTF-8'), array_values($visible)));
        if ($sort !== null && $this->visibility()->isHidden($sort)) { $sort = null; $dir = null; }
        $limit = Admin::config()->export()['limit'];
        // One query, so rows can't repeat or go missing between chunks.
        [$query] = $this->query($search, $sort, $dir);
        $rows = $query->limit($limit)->get();
        foreach ($visible as $column) { $column->prepareRecords($rows); }
        $written = 0;
        foreach ($rows as $row) {
            $writer->row(array_map(static fn (Column $c): string => self::text($c->render($row)), array_values($visible)));
            $written++;
        }
        return $written;
    }

    /** A cell's HTML as plain text. */
    private static function text(string $html): string
    {
        $html = (string) preg_replace('~<span class="fa-sr">.*?</span>~s', '', $html);
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return $text === '–' ? '' : $text;
    }

    private static function link(string $html, string $url): string
    {
        if (preg_match('~<!--fa-title-->(.*?)<!--/fa-title-->~s', $html, $title) === 1) {
            if (preg_match('/<(a|button)\b/i', $title[1]) === 1) { return $html; }
            return str_replace($title[0], '<a class="fa-row-link" href="' . Str::e($url) . '">' . $title[1] . '</a>', $html);
        }
        if ($html === '' || preg_match('/<(a|button)\b/i', $html) === 1) { return $html; }
        return '<a class="fa-row-link" href="' . Str::e($url) . '">' . $html . '</a>';
    }

    /** http(s) and site-relative addresses only: never javascript: or data:. */
    public static function safeUrl(string $url): bool
    {
        // No control characters or backslashes ("/\evil.test" is read as "//evil.test").
        if (preg_match('/[\x00-\x1F\\\\]/', $url) === 1) { return false; }
        return preg_match('~^(https?://|/(?!/))~i', $url) === 1;
    }
}
