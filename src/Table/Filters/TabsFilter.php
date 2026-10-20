<?php
declare(strict_types=1);
namespace FloCMS\Admin\Table\Filters;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Core\Database;

/**
 * Tabs above a list (0.28), each a value of one column with its count:
 *
 *     $table->tabs(TabsFilter::make('status')->options([1 => 'Active', 2 => 'Pending', 3 => 'Suspended']))
 *
 * The first tab, All, shows every record. A tab is a filter like any other
 * (search, export and selection see it), but it is shown as tabs, not in the
 * Filters menu or as a chip.
 */
final class TabsFilter extends SelectFilter
{
    private ?string $allLabel = null;

    /** The first tab's label (default "All"). */
    public function allLabel(string $label): static { $this->allLabel = $label; return $this; }
    public function getAllLabel(): string { return $this->allLabel !== null ? AdminLang::label($this->allLabel) : AdminLang::get('filters.all'); }

    public function isTabs(): bool { return true; }

    /**
     * How many records each tab holds in $query (the list's other filters and
     * search applied): '' => all, value => count. One GROUP BY query.
     *
     * @return array<string, int>
     */
    public function counts(Database $query): array
    {
        $counts = array_fill_keys(array_map('strval', array_keys($this->options)), 0);
        $total = 0;
        // A resource query with ORDER BY would fail a GROUP BY under ONLY_FULL_GROUP_BY (MariaDB): counts need no order.
        $query = clone $query;
        \Closure::bind(function (): void { $this->order = []; }, $query, Database::class)();
        foreach ($query->selectRaw('`' . $this->column . '` AS tab_value, COUNT(*) AS tab_count')->groupBy($this->column)->get() as $row) {
            $row = (array) $row;
            $count = (int) ($row['tab_count'] ?? 0);
            $total += $count;
            $key = (string) ($row['tab_value'] ?? '');
            if (array_key_exists($key, $counts)) { $counts[$key] = $count; }
        }
        return ['' => $total] + $counts;
    }
}
