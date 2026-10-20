<?php
declare(strict_types=1);
namespace FloCMS\Admin\Table;
use FloCMS\Admin\Support\{AdminLang, Str};
use FloCMS\Admin\Table\Filters\{DateRangeFilter, SelectFilter};
use FloCMS\Core\{Database, HttpException};

/** One allowlist, validation and query contract for both list paths. */
final class FilterSet
{
    public readonly array $values;
    public readonly array $errors;
    public function __construct(private readonly Table $table, string $json = '{}')
    {
        if (strlen($json) > 2000) { throw new HttpException(400); }
        $object = json_decode($json);
        if (!$object instanceof \stdClass) { throw new HttpException(400); }
        $input = json_decode($json, true);
        if (array_diff_key($input, $table->getFilters()) !== []) { throw new HttpException(400); }
        $values = []; $errors = [];
        foreach ($table->getFilters() as $name => $filter) {
            try { $values[$name] = $filter->value($input[$name] ?? ($filter instanceof DateRangeFilter ? [] : $filter->emptyFilterValue())); }
            catch (\InvalidArgumentException) { $values[$name] = $filter instanceof DateRangeFilter ? array_map(static fn ($date): string => is_string($date) && strlen($date) <= 10 ? $date : '', array_intersect_key(is_array($input[$name] ?? null) ? $input[$name] : [], ['from' => '', 'until' => '']) + ['from' => '', 'until' => '']) : $filter->emptyFilterValue(); $errors[$name] = AdminLang::get('filters.invalid', ['label' => $filter->getLabel()]); }
        }
        $this->values = $values; $this->errors = $errors;
    }
    public function apply(Database $query): Database
    {
        if ($this->errors !== []) { return $query; }
        foreach ($this->table->getFilters() as $name => $filter) { $query = $filter->apply($query, $this->values[$name]); }
        return $query;
    }
    public function active(): array
    {
        return array_filter($this->values, static fn ($value): bool => is_array($value) ? implode('', $value) !== '' : $value !== '');
    }
    /** How many filters of the Filters menu are in use (tabs left out, 0.28). */
    public function panelCount(): int
    {
        $tabs = $this->table->getTabs();
        return count(array_diff_key($this->active(), $tabs !== null ? [$tabs->getName() => true] : []));
    }
    /** The active filters with the tab set to $value ('' = All): for the tabs' links (0.28). */
    public function withTab(string $value): array
    {
        $tabs = $this->table->getTabs();
        $active = $this->active();
        if ($tabs === null) { return $active; }
        unset($active[$tabs->getName()]);
        return $value === '' ? $active : [$tabs->getName() => $value] + $active;
    }
    /** The query with every active filter but the tabs (for the tabs' counts). */
    public function applyExceptTabs(Database $query): Database
    {
        if ($this->errors !== []) { return $query; }
        foreach ($this->table->getFilters() as $name => $filter) { if (!$filter->isTabs()) { $query = $filter->apply($query, $this->values[$name]); } }
        return $query;
    }
    public function json(): string { return json_encode((object) $this->active(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    /**
     * Active filters as removable chips: label, readable value, and the filter
     * state (JSON) and query values that remain when that one filter is removed.
     *
     * @return list<array{name: string, label: string, value: string, without: string, query: array<string, mixed>}>
     */
    public function chips(): array
    {
        if ($this->errors !== []) { return []; }
        $chips = [];
        $active = $this->active();
        foreach ($this->table->getFilters() as $name => $filter) {
            if (!array_key_exists($name, $active) || $filter->isTabs()) { continue; }
            $value = $active[$name];
            if (($described = $filter->describeFilterValue($value)) !== null) {
                $text = $described;
            } elseif ($filter instanceof SelectFilter) {
                $options = $filter->getOptions();
                $text = AdminLang::label((string) ($options[$value] ?? $value));
            } else {
                $from = (string) ($value['from'] ?? ''); $until = (string) ($value['until'] ?? '');
                $text = match (true) {
                    $from !== '' && $until !== '' => $from . ' – ' . $until,
                    $from !== '' => AdminLang::get('filters.from') . ' ' . $from,
                    default => AdminLang::get('filters.until') . ' ' . $until,
                };
            }
            $remaining = $active; unset($remaining[$name]);
            $chips[] = ['name' => $name, 'label' => $filter->getLabel(), 'value' => $text,
                'without' => json_encode((object) $remaining, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'query' => $remaining];
        }
        return $chips;
    }
    public function render(string $prefix): string
    {
        $html = ''; $hidden = '';
        foreach ($this->table->getFilters() as $name => $filter) {
            // The tab in use rides along when the Filters menu is applied (0.28).
            if ($filter->isTabs()) { $hidden .= '<input type="hidden" name="filter[' . Str::e($name) . ']" value="' . Str::e((string) $this->values[$name]) . '" data-admin-filter="' . Str::e($name) . '">'; continue; }
            $id = $prefix . '-filter-' . $name;
            $invalid = isset($this->errors[$name]) ? ' aria-invalid="true" aria-describedby="' . Str::e($id . '-error') . '"' : '';
            $html .= '<div class="fa-filter"><label class="fa-label" for="' . Str::e($filter instanceof DateRangeFilter ? $id . '-from' : $id) . '">' . Str::e($filter->getLabel()) . '</label>';
            if (($custom = $filter->renderFilterInput($id, $this->values[$name], $invalid)) !== null) {
                $html .= $custom;
            } elseif ($filter instanceof SelectFilter) {
                $html .= '<select class="fa-input" id="' . Str::e($id) . '" name="filter[' . Str::e($name) . ']" data-admin-filter="' . Str::e($name) . '"' . $invalid . '><option value="">' . Str::e(AdminLang::get('filters.all')) . '</option>';
                foreach ($filter->getOptions() as $key => $label) { $html .= '<option value="' . Str::e((string) $key) . '"' . ($this->values[$name] === (string) $key ? ' selected' : '') . '>' . Str::e(AdminLang::label($label)) . '</option>'; }
                $html .= '</select>';
            } else {
                foreach (['from', 'until'] as $key) {
                    $html .= '<label class="fa-sr" for="' . Str::e($id . '-' . $key) . '">' . Str::e($filter->getLabel() . ' ' . AdminLang::get('filters.' . $key)) . '</label><input class="fa-input" type="date" id="' . Str::e($id . '-' . $key) . '" name="filter[' . Str::e($name) . '][' . $key . ']" data-admin-filter="' . Str::e($name . '.' . $key) . '" value="' . Str::e($this->values[$name][$key]) . '"' . $invalid . '>';
                }
            }
            if (isset($this->errors[$name])) { $html .= '<p class="fa-error" id="' . Str::e($id . '-error') . '" role="alert">' . Str::e($this->errors[$name]) . '</p>'; }
            $html .= '</div>';
        }
        return $html === '' ? '' : $hidden . $html;
    }
}
