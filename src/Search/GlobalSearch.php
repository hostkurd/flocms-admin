<?php
declare(strict_types=1);

namespace FloCMS\Admin\Search;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Http\PanelView;
use FloCMS\Admin\Http\SettingsController;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\App;
use FloCMS\Core\Auth;

/**
 * Ctrl+K search (0.20): asks each source in order and returns groups of results
 * the current person may open.
 *
 * Order: pages and actions, resources in sidebar order, settings pages, media,
 * then the site's own sources. A source starts only while the time budget lasts,
 * so one slow table never blocks the box.
 */
final class GlobalSearch
{
    public const MAX_LENGTH = 100;
    public const MAX_RESOURCE_GROUPS = 8;
    public const BUDGET_SECONDS = 1.5;

    private float $started = 0.0;
    private bool $partial = false;

    public function __construct(private readonly ?int $perGroup = null, private readonly ?float $budget = null)
    {
    }

    /**
     * @return array{query: string, groups: list<array{label: string, more: string, items: list<array<string, string>>}>, total: int, partial: bool}
     */
    public function run(string $query): array
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if (mb_strlen($query) > self::MAX_LENGTH) { $query = mb_substr($query, 0, self::MAX_LENGTH); }
        $this->started = microtime(true);
        $this->partial = false;
        $groups = $query === '' ? $this->blank() : $this->search($query);

        $out = [];
        $total = 0;
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $result) {
                $item = $result->toArray();
                if ($item !== null) { $items[] = $item; }
            }
            if ($items === []) { continue; }
            $total += count($items);
            $out[] = ['label' => SearchResult::clean($group['label'], 80), 'more' => $group['more'] ?? '', 'items' => $items];
        }
        return ['query' => $query, 'groups' => $out, 'total' => $total, 'partial' => $this->partial];
    }

    private function limit(): int
    {
        return $this->perGroup ?? Admin::config()->search()['per_group'];
    }

    /** Before typing: the last records opened, then the panel's pages. */
    private function blank(): array
    {
        return [
            ['label' => AdminLang::get('search.recent'), 'items' => RecentRecords::results()],
            ['label' => AdminLang::get('search.pages'), 'items' => array_values(array_filter($this->pages(), static fn (SearchResult $r): bool => $r->getType() === AdminLang::get('search.type_page')))],
        ];
    }

    private function search(string $query): array
    {
        $groups = [];
        $pages = array_values(array_filter($this->pages(), static fn (SearchResult $r): bool => self::contains($r->getTitle(), $query)));
        $groups[] = ['label' => AdminLang::get('search.pages_actions'), 'items' => array_slice($pages, 0, $this->limit())];

        $found = 0;
        foreach (Admin::registry()->all() as $slug => $class) {
            if ($found >= self::MAX_RESOURCE_GROUPS) { $this->partial = true; break; }
            if (!$this->inBudget()) { break; }
            $group = $this->resource($slug, $class, $query);
            if ($group !== null && $group['items'] !== []) { $groups[] = $group; $found++; }
        }
        if ($this->inBudget()) { $groups[] = ['label' => AdminLang::get('nav.settings'), 'items' => $this->settings($query)]; }
        if ($this->inBudget()) { $groups[] = ['label' => AdminLang::get('roles.title'), 'items' => $this->roles($query)]; }
        if ($this->inBudget()) { $groups[] = ['label' => AdminLang::get('media.title'), 'items' => $this->media($query)]; }
        foreach (Admin::config()->search()['sources'] as $source) {
            if (!$this->inBudget()) { break; }
            $groups[] = $this->custom($source, $query);
        }
        return $groups;
    }

    private function inBudget(): bool
    {
        $ok = microtime(true) - $this->started < ($this->budget ?? self::BUDGET_SECONDS);
        if (!$ok) { $this->partial = true; }
        return $ok;
    }

    /**
     * The panel's pages the person may open, then "New …" for each resource they may add to.
     *
     * @return list<SearchResult>
     */
    private function pages(): array
    {
        $page = AdminLang::get('search.type_page');
        $pages = [SearchResult::make(AdminLang::get('nav.dashboard'), Admin::url('dashboard'))->icon('dashboard')->type($page)];
        $urls = PanelView::pageUrls();
        foreach ([['media', 'media.title', 'image'], ['users', 'nav.users', 'users'], ['roles', 'nav.roles', 'shield'], ['settings', 'nav.settings', 'settings'], ['activity', 'activity.title', 'clock'], ['trash', 'trash.title', 'trash'], ['imports', 'nav.imports', 'upload'], ['profile', 'nav.profile', 'user']] as [$key, $label, $icon]) {
            if ($urls[$key] !== null) { $pages[] = SearchResult::make(AdminLang::get($label), $urls[$key])->icon($icon)->type($page); }
        }

        $action = AdminLang::get('search.type_action');
        foreach (Admin::registry()->all() as $slug => $class) {
            // A resource kept out of the sidebar is added inside its parent (0.22).
            if (!$class::showsInNavigation() || !Auth::can($class::permission('create'))) { continue; }
            try { if (!(new $class())->canCreate()) { continue; } } catch (\Throwable) { continue; }
            $pages[] = SearchResult::make(AdminLang::get('actions.create_label', ['label' => $class::getLabel()]), Admin::url($slug . '/create'))
                ->icon('plus')->type($action)->detail($class::getPluralLabel());
        }
        return $pages;
    }

    /**
     * Records of one resource: its view permission, its query() and its searchable columns.
     *
     * @param class-string<Resource> $class
     * @return array{label: string, more: string, items: list<SearchResult>}|null
     */
    private function resource(string $slug, string $class, string $query): ?array
    {
        if (!$class::isGloballySearchable() || !Auth::can($class::permission('view'))) { return null; }
        $db = App::db();
        if ($db === null) { return null; }
        try {
            /** @var Resource $resource */
            $resource = new $class();
            $table = $resource->table(new Table());
            $table->prepare($resource->form(new \FloCMS\Admin\Form\Form()));
            if ($table->searchableColumns() === [] && preg_match('/^#?\d{1,18}$/D', $query) !== 1) { return null; }
            $repository = new RecordRepository($resource, $db, Translations::store());
            $rows = $repository->search($table, $query, $this->limit() + 1, AdminLang::language());
        } catch (\Throwable $error) {
            error_log('flocms-admin: search in ' . $slug . ' failed: ' . $error->getMessage());
            return null;
        }
        $more = count($rows) > $this->limit();
        $mayEdit = Auth::can($class::permission('update'));
        $items = [];
        $primary = $table->primaryColumn();
        foreach (array_slice($rows, 0, $this->limit()) as $row) {
            $id = (string) ($row->{$class::getPrimaryKey()} ?? '');
            $title = self::title($row, $primary, $id);
            $url = $resource->searchUrl($row)
                ?? ($mayEdit && $id !== '' && $resource->canEdit($row) ? Admin::url($slug . '/edit/' . rawurlencode($id)) : Admin::url($slug, ['q' => $query]));
            $items[] = SearchResult::make($title, $url)->detail($resource->searchDetails($row) ?? self::details($table, $row))
                ->icon($class::getIcon())->type($class::getLabel());
        }
        return [
            'label' => $class::getPluralLabel(),
            'more' => $more ? Admin::url($slug, ['q' => $query]) : '',
            'items' => $items,
        ];
    }

    /** A record's title as the list shows it (its first searchable column), in the panel's language; else "#id". */
    public static function title(object $row, ?string $primary, string $id): string
    {
        $value = $primary !== null && isset($row->{$primary}) && is_scalar($row->{$primary}) ? (string) $row->{$primary} : '';
        $text = SearchResult::clean(Translations::get($value, AdminLang::language()), 200);
        return $text !== '' ? $text : '#' . $id;
    }

    /** The default detail line: the next searchable column after the title, as text. */
    private static function details(Table $table, object $row): ?string
    {
        $title = $table->primaryColumn();
        foreach ($table->searchableColumns() as $name => $column) {
            if ($name === $title) { continue; }
            $value = $row->{$name} ?? null;
            if ($value === null || is_array($value) || is_object($value)) { continue; }
            $text = $column->isTranslatable() ? Translations::get($value, AdminLang::language()) : (string) $value;
            $text = SearchResult::clean($text, 120);
            if ($text !== '') { return $text; }
        }
        return null;
    }

    /** @return list<SearchResult> */
    private function settings(string $query): array
    {
        if (PanelView::pageUrls()['settings'] === null) { return []; }
        $results = [];
        foreach (SettingsController::allowedPages() as $name => $page) {
            $detail = $page->getDescription();
            $hit = self::contains($page->getLabel(), $query) || ($detail !== null && self::contains($detail, $query));
            if (!$hit) {
                foreach ($page->form()->fields() as $field) {
                    $label = $field->getLabel();
                    if ($label !== '' && self::contains($label, $query)) { $hit = true; $detail = AdminLang::get('search.field', ['label' => $label]); break; }
                }
            }
            if ($hit) {
                $results[] = SearchResult::make($page->getLabel(), Admin::url('settings/edit/' . $name))->detail($detail)
                    ->icon($page->getIcon())->type(AdminLang::get('nav.settings'));
            }
            if (count($results) >= $this->limit()) { break; }
        }
        return $results;
    }

    /**
     * Roles by name (0.21), for people who may open the Roles page.
     *
     * @return list<SearchResult>
     */
    private function roles(string $query): array
    {
        if (PanelView::pageUrls()['roles'] === null || !\FloCMS\Admin\Roles\Roles::enabled()) { return []; }
        $results = [];
        foreach (\FloCMS\Admin\Roles\Roles::all() as $role) {
            if (!self::contains($role->label(), $query)) { continue; }
            $count = count($role->permissions);
            $permissions = $role->holdsEverything() ? AdminLang::get('roles.everything') : AdminLang::get($count === 1 ? 'roles.count_one' : 'roles.count_many', ['count' => $count]);
            $results[] = SearchResult::make($role->label(), Admin::url('roles/edit/' . $role->number))
                ->detail(AdminLang::get('roles.search_detail', ['level' => $role->level, 'count' => $permissions]))
                ->icon('shield')->type(AdminLang::get('roles.details'));
            if (count($results) >= $this->limit()) { break; }
        }
        return $results;
    }

    /** @return list<SearchResult> */
    private function media(string $query): array
    {
        if (!MediaLibrary::enabled() || !MediaLibrary::can('view') || class_exists(\FloCMS\Admin\Support\Str::appControllerClass('media'))) { return []; }
        try {
            $items = MediaLibrary::store()->findMatching($query, $this->limit());
        } catch (\Throwable $error) {
            error_log('flocms-admin: media search failed: ' . $error->getMessage());
            return [];
        }
        $results = [];
        foreach ($items as $item) {
            $parts = [];
            if ($item->title !== '' && $item->fileName !== '') { $parts[] = $item->fileName; }
            if ($item->width !== null && $item->height !== null) { $parts[] = $item->width . ' × ' . $item->height; }
            $results[] = SearchResult::make($item->label(), Admin::url('media/edit/' . $item->id))->detail(implode(' · ', $parts))
                ->icon($item->kind === 'video' ? 'video' : 'image')->type(AdminLang::get('media.title'))
                ->thumbnail($item->kind === 'image' ? $item->url('thumb') : null);
        }
        return $results;
    }

    /** @return array{label: string, items: list<SearchResult>} */
    private function custom(string $class, string $query): array
    {
        try {
            $source = new $class();
            if (!$source instanceof SearchSource) { return ['label' => '', 'items' => []]; }
            $label = $source->label();
            $items = array_values(array_filter($source->search($query, $this->limit()), static fn ($r): bool => $r instanceof SearchResult));
            foreach ($items as $item) { if ($item->getType() === null) { $item->type($label); } }
            return ['label' => $label, 'items' => array_slice($items, 0, $this->limit())];
        } catch (\Throwable $error) {
            error_log('flocms-admin: search source ' . $class . ' failed: ' . $error->getMessage());
            return ['label' => '', 'items' => []];
        }
    }

    private static function contains(string $text, string $query): bool
    {
        return mb_stripos($text, $query) !== false;
    }
}
