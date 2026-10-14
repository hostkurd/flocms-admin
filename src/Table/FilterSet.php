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
            try { $values[$name] = $filter->value($input[$name] ?? ($filter instanceof DateRangeFilter ? [] : '')); }
            catch (\InvalidArgumentException) { $values[$name] = $filter instanceof DateRangeFilter ? array_map(static fn ($date): string => is_string($date) && strlen($date) <= 10 ? $date : '', array_intersect_key(is_array($input[$name] ?? null) ? $input[$name] : [], ['from' => '', 'until' => '']) + ['from' => '', 'until' => '']) : ''; $errors[$name] = AdminLang::get('filters.invalid', ['label' => $filter->getLabel()]); }
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
    public function json(): string { return json_encode((object) $this->active(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    public function render(string $prefix): string
    {
        $html = '';
        foreach ($this->table->getFilters() as $name => $filter) {
            $id = $prefix . '-filter-' . $name;
            $invalid = isset($this->errors[$name]) ? ' aria-invalid="true" aria-describedby="' . Str::e($id . '-error') . '"' : '';
            $html .= '<div class="fa-filter"><label class="fa-label" for="' . Str::e($filter instanceof DateRangeFilter ? $id . '-from' : $id) . '">' . Str::e($filter->getLabel()) . '</label>';
            if ($filter instanceof SelectFilter) {
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
        return $html;
    }
}
