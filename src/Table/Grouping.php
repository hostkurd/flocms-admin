<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table;

use FloCMS\Admin\Preferences\Preferences;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
use FloCMS\Core\Database;

/**
 * The Group by choice of one list and its heading rows (0.38). The choice is
 * a personal preference ("groups.<slug>", like the Columns button) and goes
 * in the address as ?group=city_id or ?group=city_id:desc ("none" for no
 * grouping), so a shared link keeps it.
 *
 * @internal
 */
final class Grouping
{
    private ?Group $active = null;
    private string $direction = 'asc';
    private bool $resolved = false;

    public function __construct(private readonly Table $table, private readonly string $key)
    {
    }

    public function has(): bool
    {
        return $this->table->getGroups() !== [];
    }

    /**
     * Pick the group: $requested from the address or the Reactive state
     * ('' = what the person chose before, else the list's default). A request
     * is remembered.
     */
    public function resolve(string $requested = ''): ?Group
    {
        $this->resolved = true;
        $this->active = null;
        if (!$this->has()) { return null; }
        // Only a group of this list (or "none") is remembered: a stale or made-up link changes nothing.
        $known = $requested === 'none' || isset($this->table->getGroups()[explode(':', $requested, 2)[0]]);
        if ($requested !== '' && !$known) { $requested = ''; }
        $choice = $requested !== '' ? $requested : $this->stored();
        if ($requested !== '' && $requested !== $this->stored()) { $this->remember($requested); }
        if ($choice === null) {
            $default = $this->table->getDefaultGroup();
            $choice = $default ?? ($this->table->isGroupingRequired() ? (string) array_key_first($this->table->getGroups()) : 'none');
        }
        if ($choice === 'none' && $this->table->isGroupingRequired()) { $choice = (string) ($this->table->getDefaultGroup() ?? array_key_first($this->table->getGroups())); }
        [$name, $direction] = array_pad(explode(':', $choice, 2), 2, null);
        $group = $this->table->getGroups()[(string) $name] ?? null;
        if ($group === null) { return null; }
        $this->active = $group;
        $this->direction = $direction === 'desc' || $direction === 'asc' ? $direction : $group->getDirection();
        return $group;
    }

    public function active(): ?Group
    {
        if (!$this->resolved) { $this->resolve(); }
        return $this->active;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    /** The address value of the current choice ("city_id:desc", or "none"). */
    public function value(): string
    {
        $group = $this->active();
        return $group === null ? 'none' : $group->getName() . ($this->direction !== $group->getDirection() ? ':' . $this->direction : '');
    }

    private function preferenceKey(): string
    {
        return 'groups.' . (strlen($this->key) <= 90 ? $this->key : substr(hash('sha256', $this->key), 0, 32));
    }

    private function stored(): ?string
    {
        $value = Preferences::current()?->get($this->preferenceKey());
        return is_string($value) && preg_match('/^(none|[A-Za-z_][A-Za-z0-9_]{0,63}(:(asc|desc))?)$/D', $value) === 1 ? $value : null;
    }

    private function remember(string $choice): void
    {
        if (preg_match('/^(none|[A-Za-z_][A-Za-z0-9_]{0,63}(:(asc|desc))?)$/D', $choice) !== 1) { return; }
        Preferences::current()?->set($this->preferenceKey(), $choice);
    }

    /**
     * The choices of the Group by menu.
     *
     * @return list<array{value: string, label: string, active: bool}>
     */
    public function choices(): array
    {
        $current = $this->active();
        $choices = [];
        if (!$this->table->isGroupingRequired()) { $choices[] = ['value' => 'none', 'label' => AdminLang::get('groups.none'), 'active' => $current === null]; }
        foreach ($this->table->getGroups() as $name => $group) {
            $choices[] = ['value' => $name, 'label' => $group->getLabel(), 'active' => $current?->getName() === $name];
        }
        return $choices;
    }

    /** The two direction choices of the active group: [value, label, active]. @return list<array{value: string, label: string, active: bool}> */
    public function directions(): array
    {
        $group = $this->active();
        if ($group === null) { return []; }
        return [
            ['value' => $group->getName() . ':asc', 'label' => AdminLang::get($group->getDate() !== null ? 'groups.oldest' : 'groups.ascending'), 'active' => $this->direction === 'asc'],
            ['value' => $group->getName() . ':desc', 'label' => AdminLang::get($group->getDate() !== null ? 'groups.newest' : 'groups.descending'), 'active' => $this->direction === 'desc'],
        ];
    }

    /**
     * Heading rows of this page: row index => heading <tr>, and each row's
     * group key (for collapsing).
     *
     * @param list<object> $rows
     * @param array<string, Column> $visible
     * @param (\Closure(): Database)|null $rowsQuery the list's rows on every page, unordered
     * @param ?string $previousKey the group key of the row just before this page (null on page 1)
     * @return array{heads: array<int, string>, keys: array<int, string>}
     */
    public function headings(array $rows, array $visible, ?\Closure $rowsQuery, bool $previous, ?string $previousKey, bool $selectable, bool $actions, string $prefix): array
    {
        $group = $this->active();
        if ($group === null || $rows === []) { return ['heads' => [], 'keys' => []]; }
        $keys = array_map(static fn (object $row): ?string => $group->keyOf($row), $rows);
        $group->prepareTitles(array_values(array_filter($keys, static fn (?string $k): bool => $k !== null)));
        $totals = new Totals($visible);
        try {
            $sub = $rowsQuery !== null ? $this->subtotals($rowsQuery(), $group, $keys, $totals) : [];
        } catch (\PDOException $error) {
            // A query() the subtotals cannot wrap (a join with clashing columns): headings count this page only.
            \FloCMS\Admin\Support\Log::error('Group subtotals skipped: ' . $error->getMessage());
            $sub = [];
        }
        $heads = [];
        $ids = [];
        $last = "\1";
        foreach ($rows as $index => $row) {
            $key = $keys[$index];
            $token = $key ?? "\0";
            $ids[$index] = $prefix . '-g' . substr(hash('sha256', $token), 0, 10);
            if ($token === $last) { continue; }
            $last = $token;
            $continued = $index === 0 && $previous && $previousKey === $key;
            $count = $sub[$token]['count'] ?? count(array_filter($keys, static fn (?string $k): bool => ($k ?? "\0") === $token));
            $onPage = count(array_filter($keys, static fn (?string $k): bool => ($k ?? "\0") === $token));
            $heads[$index] = $this->heading($group, $key, $row, $ids[$index], $count, $onPage, $continued, $sub[$token]['values'] ?? $totals->page(array_values(array_filter($rows, static fn (object $r, int $i): bool => ($keys[$i] ?? "\0") === $token, ARRAY_FILTER_USE_BOTH))), $totals, $visible, $selectable, $actions);
        }
        return ['heads' => $heads, 'keys' => $ids];
    }

    /**
     * Row counts and subtotals of the groups on this page, over all their rows
     * (one grouped query).
     *
     * @param list<?string> $keys
     * @return array<string, array{count: int, values: array<string, list<int|float|string|null>>}>
     */
    private function subtotals(Database $query, Group $group, array $keys, Totals $totals): array
    {
        $db = \FloCMS\Core\App::db();
        if ($db === null) { return []; }
        $key = $group->keySql('flo_t.`' . $group->getName() . '`', $db->driver());
        $present = array_values(array_unique(array_filter($keys, static fn (?string $k): bool => $k !== null)));
        $hasNull = in_array(null, $keys, true);
        [$select, $bindings, $map] = $totals->selectList();
        [$from, $params] = SqlRows::derived($query);
        $where = [];
        if ($present !== []) { $where[] = $key . ' IN (' . implode(', ', array_fill(0, count($present), '?')) . ')'; }
        if ($hasNull) { $where[] = $key . ' IS NULL'; }
        if ($where === []) { return []; }
        $sql = 'SELECT ' . $key . ' AS flo_g, COUNT(*) AS flo_n' . ($select !== [] ? ', ' . implode(', ', $select) : '')
            . ' FROM ' . $from . ' WHERE ' . implode(' OR ', $where) . ' GROUP BY flo_g';
        $result = [];
        foreach (SqlRows::select($db, $sql, [...$bindings, ...$params, ...$present]) as $row) {
            $token = $row->flo_g === null ? "\0" : (string) $row->flo_g;
            $result[$token] = ['count' => (int) $row->flo_n, 'values' => $totals->read($row, $map)];
        }
        return $result;
    }

    /** @param array<string, Column> $visible @param array<string, list<int|float|string|null>> $values */
    private function heading(Group $group, ?string $key, object $row, string $id, int $count, int $onPage, bool $continued, array $values, Totals $totals, array $visible, bool $selectable, bool $actions): string
    {
        $title = $group->title($key, $row);
        $color = $group->color($key);
        $toggle = $group->isCollapsible()
            ? '<button type="button" class="fa-group-toggle" data-admin-group-toggle aria-expanded="true" aria-label="' . Str::e(AdminLang::get('groups.collapse', ['title' => $title])) . '"'
                . ' data-expand-label="' . Str::e(AdminLang::get('groups.expand', ['title' => $title])) . '" data-collapse-label="' . Str::e(AdminLang::get('groups.collapse', ['title' => $title])) . '">'
                . Icons::svg('chevron-down', 'fa-icon') . '</button>'
            : '';
        $label = '<span class="fa-group-head">' . $toggle
            . '<span class="fa-group-title' . ($color !== null ? ' fa-badge fa-badge-' . $color : '') . '" dir="auto">' . Str::e($title) . '</span>'
            . ($continued ? '<span class="fa-group-continued">' . Str::e(AdminLang::get('groups.continued')) . '</span>' : '')
            . '<span class="fa-group-count">' . Str::e(AdminLang::get($count === 1 ? 'groups.count_one' : 'groups.count', ['count' => $count])) . '</span>'
            . '<span class="fa-group-hidden" data-admin-group-hidden hidden>' . Str::e(AdminLang::get('groups.hidden', ['count' => $onPage])) . '</span>'
            . '</span>';
        $html = '<tr class="fa-group-row" id="' . Str::e($id) . '" data-admin-group="' . Str::e($id) . '">';
        if ($selectable) {
            $html .= '<td class="fa-selection-col"><input type="checkbox" data-admin-select-group="' . Str::e($id) . '" aria-label="' . Str::e(AdminLang::get('groups.select', ['title' => $title])) . '"></td>';
        }
        $first = true;
        foreach ($visible as $name => $column) {
            $cell = $first ? $label : '';
            $first = false;
            foreach ($column->getSummaries() as $index => $summary) { $cell .= $summary->html($values[$name][$index] ?? null, $column->getLabel()); }
            $html .= '<td class="' . Str::e(trim($column->cellClass() . ' fa-group-cell' . ($column->getSummaries() !== [] ? ' has-totals' : ''))) . '" data-label="' . Str::e($column->getLabel()) . '">' . $cell . '</td>';
        }
        if ($actions) { $html .= '<td class="fa-actions"></td>'; }
        return $html . '</tr>';
    }
}
