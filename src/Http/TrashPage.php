<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Activity\ActivityView;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Dates;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Trash\Trash;
use FloCMS\Admin\Trash\TrashEntry;
use FloCMS\Core\Auth;
use FloCMS\Core\Csrf;
use FloCMS\Core\HttpException;
use FloCMS\Core\TemplateEngine;
use FloCMS\Core\ValidationException;

/**
 * The trash of one resource (/admin/<slug>/trash, with that resource's columns)
 * or of every resource (/admin/trash, Site → Trash): restore, delete for good,
 * bulk selection and Empty trash.
 *
 * Who may do what: seeing the trash of a resource needs its view permission and
 * create or delete; restoring needs create (it adds a record back); deleting for
 * good and emptying need delete.
 *
 * @internal
 */
final class TrashPage
{
    public const PER_PAGE = 25;
    private const SELECTION_LIMIT = 100;

    /** @param class-string<Resource>|null $resource null: every resource (the global page) */
    public function __construct(private readonly ?string $resource = null)
    {
    }

    /**
     * Resources whose trash the current user may open, by slug.
     *
     * @return array<string, class-string<Resource>>
     */
    public static function allowedResources(): array
    {
        $allowed = [];
        foreach (Admin::registry()->all() as $slug => $class) {
            if (self::mayOpen($class)) { $allowed[$slug] = $class; }
        }
        return $allowed;
    }

    /**
     * The "Trash (n)" button of a list's toolbar, or null when the trash is off,
     * empty or not for this user.
     *
     * @param class-string<Resource> $class
     * @return array{url: string, count: int}|null
     */
    public static function toolbarLink(string $class): ?array
    {
        try {
            if (!Trash::enabledFor($class) || !self::mayOpen($class)) { return null; }
            $count = Trash::count($class::getSlug());
        } catch (\Throwable) {
            return null;
        }
        return $count > 0 ? ['url' => Admin::url($class::getSlug() . '/trash'), 'count' => $count] : null;
    }

    /** @param class-string<Resource> $class */
    public static function mayOpen(string $class): bool
    {
        return $class::usesTrash() && Auth::can($class::permission('view')) && (self::mayRestore($class) || self::mayPurge($class));
    }

    /** @param class-string<Resource> $class */
    public static function mayRestore(string $class): bool
    {
        return Auth::can($class::permission('create')) && (new $class())->canCreate();
    }

    /** @param class-string<Resource> $class */
    public static function mayPurge(string $class): bool
    {
        return Auth::can($class::permission('delete'));
    }

    /* ---------------------------------------------------------------
     * Actions (POST)
     * ------------------------------------------------------------- */

    /**
     * Restore, delete for good or empty, then return where to go back to.
     *
     * @param array<string, mixed> $input
     */
    public function handle(array $input): string
    {
        $query = $this->query(is_string($input['_query'] ?? null) ? $input['_query'] : '');
        $back = $this->url($query);
        $action = $input['task'] ?? '';
        $scope = $this->scope($query['type'] ?? '');

        if ($action === 'empty') {
            $purgeable = array_keys(array_filter($scope, static fn (string $class): bool => self::mayPurge($class)));
            if ($purgeable === []) { throw new HttpException(403); }
            $count = 0;
            do {
                $batch = Trash::entries($purgeable, '', null, 1, 200)['items'];
                foreach ($batch as $entry) { Trash::purge($entry); $count++; }
            } while (count($batch) === 200);
            Notification::make()->title(AdminLang::get($count === 1 ? 'trash.purged_one' : 'trash.purged_many', ['count' => $count]))->success()->send();
            return $back;
        }
        if (!in_array($action, ['restore', 'purge'], true)) { throw new HttpException(400); }

        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || $ids === [] || count($ids) > self::SELECTION_LIMIT) { throw new HttpException(400); }
        $entries = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !ctype_digit($id)) { throw new HttpException(400); }
            $entry = Trash::find((int) $id);
            // Gone meanwhile (restored or emptied by someone else): nothing to do for it.
            if ($entry === null) { continue; }
            $class = $scope[$entry->resource] ?? null;
            if ($class === null || !Trash::visible($entry)) { throw new HttpException(404); }
            if ($action === 'restore' ? !self::mayRestore($class) : !self::mayPurge($class)) { throw new HttpException(403); }
            $entries[] = $entry;
        }

        if ($action === 'purge') {
            foreach ($entries as $entry) { Trash::purge($entry); }
            if ($entries !== []) {
                Notification::make()->title(count($entries) === 1
                    ? AdminLang::get('trash.purged_named', ['title' => $entries[0]->label()])
                    : AdminLang::get('trash.purged_many', ['count' => count($entries)]))->success()->send();
            }
            return $back;
        }

        $restored = [];
        $refused = [];
        foreach ($entries as $entry) {
            try {
                Trash::restore($entry);
                $restored[] = $entry;
            } catch (ValidationException $error) {
                $refused[] = [$entry, Trash::message($error)];
            }
        }
        if ($restored !== []) {
            Notification::make()->title(count($restored) === 1
                ? AdminLang::get('trash.restored_named', ['label' => self::label($restored[0]), 'title' => $restored[0]->label()])
                : AdminLang::get('trash.restored_many', ['count' => count($restored)]))->success()->send();
        }
        foreach (array_slice($refused, 0, 5) as [$entry, $message]) {
            Notification::make()->title(AdminLang::get('trash.refused', ['title' => $entry->label()]))->body($message)->danger()->send();
        }
        return $back;
    }

    /* ---------------------------------------------------------------
     * The page (GET)
     * ------------------------------------------------------------- */

    /** @param array<string, mixed> $get the query string */
    public function render(array $get): string
    {
        $query = $this->query(http_build_query(array_intersect_key($get, array_flip(['q', 'by', 'type', 'page']))));
        $type = (string) ($query['type'] ?? '');
        $scope = $this->scope($type);
        $slugs = array_keys($scope);
        $search = (string) ($query['q'] ?? '');
        $deletedBy = isset($query['by']) ? (int) $query['by'] : null;
        $page = max(1, (int) ($query['page'] ?? 1));
        $result = Trash::entries($slugs, $search, $deletedBy, $page, self::PER_PAGE);
        $last = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $class = $this->resource;
        $columns = [];
        $cells = [];
        if ($class !== null) {
            [$columns, $cells] = $this->resourceColumns($class, $result['items']);
        } else {
            $columns = [['label' => AdminLang::get('trash.item'), 'class' => ''], ['label' => AdminLang::get('trash.type'), 'class' => '']];
        }

        $rows = [];
        $now = time();
        foreach ($result['items'] as $index => $entry) {
            $entryClass = $scope[$entry->resource] ?? null;
            $left = $entry->daysLeft($now);
            $time = strtotime($entry->deletedAt) ?: $now;
            $rows[] = [
                'id' => (string) $entry->id,
                'label' => $entry->label(),
                'cells' => $class !== null ? $cells[$index] : [
                    ['html' => '<span class="fa-cell-strong">' . htmlspecialchars($entry->label(), ENT_QUOTES) . '</span>' . (($note = self::keptNote($entry)) !== '' ? '<span class="fa-cell-sub">' . htmlspecialchars($note, ENT_QUOTES) . '</span>' : ''), 'class' => 'fa-cell-primary', 'label' => AdminLang::get('trash.item')],
                    ['html' => '<span class="fa-badge fa-badge-' . self::typeColor($entry->resource) . '">' . htmlspecialchars($entryClass !== null ? $entryClass::getLabel() : $entry->resource, ENT_QUOTES) . '</span>', 'class' => '', 'label' => AdminLang::get('trash.type')],
                ],
                'who' => $entry->deletedByName ?? ($entry->deletedBy !== null ? '#' . $entry->deletedBy : AdminLang::get('activity.system')),
                'initial' => mb_strtoupper(mb_substr(ltrim((string) ($entry->deletedByName ?? AdminLang::get('activity.system')), '#'), 0, 1)),
                'avatar' => ActivityView::avatarClass($entry->deletedBy),
                'when' => Dates::relative($time, $now),
                'deletedAt' => date('c', $time),
                'deletedFull' => Dates::full($time),
                'left' => $left === null ? AdminLang::get('trash.kept') : ($left <= 0 ? AdminLang::get('trash.today') : AdminLang::get($left === 1 ? 'trash.day' : 'trash.days', ['count' => $left])),
                'soon' => $left !== null && $left <= 3,
                'canRestore' => $entryClass !== null && self::mayRestore($entryClass),
                'canPurge' => $entryClass !== null && self::mayPurge($entryClass),
                'purgeText' => AdminLang::get('trash.purge_one_text', ['title' => $entry->label()]),
            ];
        }

        $canRestore = array_filter($scope, static fn (string $c): bool => self::mayRestore($c)) !== [];
        $purgeable = array_filter($scope, static fn (string $c): bool => self::mayPurge($c));
        $days = Trash::days();
        $total = $result['total'];
        $from = $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1;
        $people = [];
        foreach (Trash::deleters($slugs) as $id => $name) { $people[] = ['id' => $id, 'name' => $name, 'selected' => $deletedBy === $id]; }

        $emptyCount = array_sum(Trash::counts(array_keys($purgeable)));
        $emptyLabel = AdminLang::get('trash.empty');
        if ($class !== null) {
            $title = AdminLang::get('trash.resource_title', ['label' => $class::getPluralLabel()]);
            $emptyTitle = AdminLang::get('trash.empty_resource_title', ['label' => mb_strtolower($class::getPluralLabel())]);
        } elseif ($type !== '' && isset($scope[$type])) {
            $title = AdminLang::get('trash.title');
            $emptyTitle = AdminLang::get('trash.empty_resource_title', ['label' => mb_strtolower($scope[$type]::getPluralLabel())]);
        } else {
            $title = AdminLang::get('trash.title');
            $emptyTitle = AdminLang::get('trash.empty_all_title');
        }

        return TemplateEngine::renderFile(PageView::viewPath('trash'), [
            't' => PageView::translator(),
            'title' => $title,
            'description' => $class !== null
                ? AdminLang::get('trash.resource_description', ['label' => mb_strtolower($class::getPluralLabel())])
                : AdminLang::get($days > 0 ? 'trash.description' : 'trash.description_kept', ['days' => $days]),
            'backUrl' => $class !== null ? Admin::url($class::getSlug()) : null,
            'backLabel' => $class !== null ? AdminLang::get('trash.back', ['label' => mb_strtolower($class::getPluralLabel())]) : '',
            'canEmpty' => $purgeable !== [] && $emptyCount > 0,
            'emptyLabel' => $emptyLabel,
            'emptyTitle' => $emptyTitle,
            'emptyText' => AdminLang::get($emptyCount === 1 ? 'trash.empty_text_one' : 'trash.empty_text', ['count' => $emptyCount]),
            'tabs' => $class === null ? $this->tabs($type) : [],
            'type' => $class === null ? $type : '',
            'hasSearch' => true,
            'search' => $search,
            'deletedBy' => $deletedBy,
            'people' => $people,
            'listUrl' => $this->url([]),
            'clearUrl' => $this->url(array_diff_key($query, ['q' => 1, 'page' => 1])),
            'postUrl' => $this->url([]),
            'query' => http_build_query($query),
            'columns' => $columns,
            'rows' => $rows,
            'canRestore' => $canRestore,
            'canPurge' => $purgeable !== [],
            'emptyMessage' => AdminLang::get('trash.empty_state'),
            'note' => $days > 0 ? AdminLang::get('trash.note', ['days' => $days]) : AdminLang::get('trash.note_kept'),
            'summary' => $total === 0 ? '' : AdminLang::get('table.summary', ['from' => $from, 'to' => min($total, $page * self::PER_PAGE), 'total' => $total]),
            'pages' => array_map(fn (?int $n): array => $n === null
                ? ['label' => '…', 'url' => null, 'current' => false]
                : ['label' => (string) $n, 'url' => $n === $page ? null : $this->url(['page' => $n > 1 ? $n : null] + $query), 'current' => $n === $page], PageView::pageNumbers($page, $last)),
            'csrf' => Csrf::field(),
            'trashIcon' => Icons::svg('trash'),
            'trashIconLarge' => Icons::svg('trash', 'fa-icon fa-blank-icon'),
            'undoIcon' => Icons::svg('undo', 'fa-icon fa-flip-rtl'),
            'backIcon' => Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'),
            'searchIcon' => Icons::svg('search'),
            'removeIcon' => Icons::svg('x'),
            'infoIcon' => Icons::svg('clock', 'fa-icon fa-icon-sm'),
        ]);
    }

    /** The page title (the panel's tab and breadcrumbs). */
    public function title(): string
    {
        return $this->resource !== null ? AdminLang::get('trash.resource_title', ['label' => $this->resource::getPluralLabel()]) : AdminLang::get('trash.title');
    }

    /**
     * Columns of the resource's list (those the user shows), rendered from the deleted rows.
     *
     * @param class-string<Resource> $class
     * @param list<TrashEntry> $entries
     * @return array{0: list<array{label: string, class: string}>, 1: list<list<array{html: string, class: string, label: string}>>}
     */
    private function resourceColumns(string $class, array $entries): array
    {
        $definition = new $class();
        $table = $definition->table(new Table());
        $translatable = $definition->form(new \FloCMS\Admin\Form\Form())->translatableNames();
        foreach ($table->getColumns() as $name => $column) { $column->detectTranslatable(in_array($name, $translatable, true)); }
        $visible = (new \FloCMS\Admin\Table\ColumnVisibility($table, $class::getSlug()))->visibleColumns();
        $records = array_map(static fn (TrashEntry $entry): object => $entry->record(), $entries);
        foreach ($visible as $column) {
            try { $column->prepareRecords($records); } catch (\Throwable) { /* rendered without preloading */ }
        }
        $columns = [];
        foreach ($visible as $column) { $columns[] = ['label' => $column->getLabel(), 'class' => $column->cellClass()]; }
        $cells = [];
        foreach ($entries as $index => $entry) {
            $row = [];
            foreach ($visible as $name => $column) {
                try { $html = $column->render($records[$index]); } catch (\Throwable) { $html = ''; }
                if ($name === $table->primaryColumn() && ($note = self::keptNote($entry)) !== '') {
                    $html .= '<span class="fa-cell-sub">' . htmlspecialchars($note, ENT_QUOTES) . '</span>';
                }
                $row[] = ['html' => $html, 'class' => $column->cellClass() . ($name === $table->primaryColumn() ? ' fa-cell-primary' : ''), 'label' => $column->getLabel()];
            }
            $cells[] = $row;
        }
        return [$columns, $cells];
    }

    /** "2 files and 3 links kept" under the title. */
    private static function keptNote(TrashEntry $entry): string
    {
        $links = array_sum(array_map('count', $entry->links));
        $files = count($entry->files);
        $parts = [];
        if ($files > 0) { $parts[] = AdminLang::get($files === 1 ? 'trash.file_kept' : 'trash.files_kept', ['count' => $files]); }
        if ($links > 0) { $parts[] = AdminLang::get($links === 1 ? 'trash.link_kept' : 'trash.links_kept', ['count' => $links]); }
        return implode(' · ', $parts);
    }

    /** @return list<array{label: string, count: int, url: string, current: bool}> */
    private function tabs(string $type): array
    {
        $allowed = self::allowedResources();
        $counts = Trash::counts(array_keys($allowed));
        $tabs = [['label' => AdminLang::get('trash.all'), 'count' => array_sum($counts), 'url' => $this->url([]), 'current' => $type === '']];
        foreach ($allowed as $slug => $class) {
            if (($counts[$slug] ?? 0) === 0 && $type !== $slug) { continue; }
            $tabs[] = ['label' => $class::getPluralLabel(), 'count' => $counts[$slug] ?? 0, 'url' => $this->url(['type' => $slug]), 'current' => $type === $slug];
        }
        return count($tabs) > 2 || $type !== '' ? $tabs : [];
    }

    /**
     * The resources this page covers.
     *
     * @return array<string, class-string<Resource>>
     */
    private function scope(string $type): array
    {
        if ($this->resource !== null) {
            if (!self::mayOpen($this->resource)) { throw new HttpException(403); }
            return [$this->resource::getSlug() => $this->resource];
        }
        $allowed = self::allowedResources();
        if ($type !== '') {
            return isset($allowed[$type]) ? [$type => $allowed[$type]] : throw new HttpException(404);
        }
        return $allowed;
    }

    /** @return array<string, string> the page's own query values, validated */
    private function query(string $query): array
    {
        parse_str($query, $values);
        $clean = [];
        $q = $values['q'] ?? '';
        if (is_string($q) && trim($q) !== '') { $clean['q'] = mb_substr(trim($q), 0, 100); }
        $by = $values['by'] ?? '';
        if (is_string($by) && ctype_digit($by) && $by !== '') { $clean['by'] = $by; }
        $type = $values['type'] ?? '';
        if ($this->resource === null && is_string($type) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $type) === 1) { $clean['type'] = $type; }
        $page = $values['page'] ?? '';
        if (is_string($page) && ctype_digit($page) && (int) $page > 1 && (int) $page <= 100000) { $clean['page'] = $page; }
        return $clean;
    }

    /** @param array<string, scalar|null> $query */
    private function url(array $query): string
    {
        return Admin::url($this->resource !== null ? $this->resource::getSlug() . '/trash' : 'trash', $query);
    }

    /** The resource label of an entry ("Listing"). */
    private static function label(TrashEntry $entry): string
    {
        $class = $entry->resourceClass();
        return $class !== null ? $class::getLabel() : $entry->resource;
    }

    /** A stable badge colour per resource type. */
    private static function typeColor(string $slug): string
    {
        return ['blue', 'purple', 'yellow', 'green', 'teal'][abs(crc32($slug)) % 5];
    }
}
