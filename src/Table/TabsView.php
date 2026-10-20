<?php
declare(strict_types=1);
namespace FloCMS\Admin\Table;

use Closure;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Core\Database;

/**
 * The tabs above a list (0.28): All, then one per value, each with its count.
 * Links without JavaScript; in a live list, buttons that set the filters.
 *
 * @internal
 */
final class TabsView
{
    /**
     * @param Database $query the list's records with its search applied (not yet its filters)
     * @param Closure(array<string, mixed>): string $link the URL of the list with these filters (static lists)
     */
    public static function render(Table $table, FilterSet $filters, Database $query, ?Closure $link = null): string
    {
        $tabs = $table->getTabs();
        if ($tabs === null) { return ''; }
        try { $counts = $tabs->counts($filters->applyExceptTabs($query)); }
        catch (\Throwable $error) { error_log('flocms-admin: tab counts failed: ' . $error->getMessage()); $counts = []; }
        $current = (string) ($filters->values[$tabs->getName()] ?? '');
        $html = '<div class="fa-tabs-bar" role="group" aria-label="' . Str::e($tabs->getLabel()) . '">';
        $items = ['' => $tabs->getAllLabel()] + array_map(static fn ($label): string => AdminLang::label((string) $label), $tabs->getOptions());
        foreach ($items as $value => $label) {
            $value = (string) $value;
            $on = $value === $current;
            $count = array_key_exists($value, $counts) ? '<span class="fa-tab-count">' . number_format($counts[$value]) . '</span>' : '';
            $inner = '<span>' . Str::e($label) . '</span>' . $count;
            $state = $filters->withTab($value);
            if ($link !== null) {
                $html .= '<a class="fa-list-tab' . ($on ? ' is-current' : '') . '" href="' . Str::e($link($state)) . '"' . ($on ? ' aria-current="true"' : '') . ' data-admin-tab-value="' . Str::e($value) . '">' . $inner . '</a>';
            } else {
                $html .= '<button type="button" class="fa-list-tab' . ($on ? ' is-current' : '') . '" aria-pressed="' . ($on ? 'true' : 'false') . '" data-admin-tab-value="' . Str::e($value) . '" data-rx-set="filters" data-rx-value="' . Str::e(json_encode((object) $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) . '" data-rx-action="applyFilters">' . $inner . '</button>';
            }
        }
        return $html . '</div>';
    }
}
