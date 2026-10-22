<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Filters;

use FloCMS\Admin\Form\Fields\TimePicker;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Dates;
use FloCMS\Admin\Support\Str;
use FloCMS\Core\Database;

/**
 * Records whose time of day (0.37, a TimePicker column) lies between two
 * times, both included: TimeRangeFilter::make('opens_at'). A range across
 * midnight (22:00–02:00) is not supported.
 */
final class TimeRangeFilter extends Filter
{
    public function emptyFilterValue(): string|array { return ['from' => '', 'until' => '']; }

    public function value(mixed $value): array
    {
        if ($value === '' || $value === []) { return $this->emptyFilterValue(); }
        if (!is_array($value) || array_diff(array_keys($value), ['from', 'until']) !== []) { throw new \InvalidArgumentException('Invalid time range.'); }
        $result = ['from' => '', 'until' => ''];
        foreach ($result as $key => $_) {
            $time = $value[$key] ?? '';
            if (!is_string($time)) { throw new \InvalidArgumentException('Invalid time.'); }
            if ($time === '') { continue; }
            $parsed = strlen($time) <= 8 ? TimePicker::parse($time) : null;
            if ($parsed === null) { throw new \InvalidArgumentException('Invalid time.'); }
            $result[$key] = substr($parsed, 0, 5);
        }
        if ($result['from'] !== '' && $result['until'] !== '' && $result['from'] > $result['until']) { throw new \InvalidArgumentException('Reversed time range.'); }
        return $result;
    }

    public function apply(Database $query, string|array $value): Database
    {
        if (!is_array($value)) { return $query; }
        if (($value['from'] ?? '') !== '') { $query = $query->whereRaw('`' . $this->column . '` >= ?', [$value['from'] . ':00']); }
        if (($value['until'] ?? '') !== '') { $query = $query->whereRaw('`' . $this->column . '` <= ?', [$value['until'] . ':59']); }
        return $query;
    }

    public function renderFilterInput(string $id, string|array $value, string $invalid): ?string
    {
        $value = is_array($value) ? $value : $this->emptyFilterValue();
        $html = '<div class="fa-filter-range">';
        foreach (['from' => $id, 'until' => $id . '-until'] as $key => $inputId) {
            $html .= ($key === 'until' ? '<span class="fa-filter-range-to" aria-hidden="true">–</span><label class="fa-sr" for="' . Str::e($inputId) . '">' . Str::e($this->getLabel() . ' ' . AdminLang::get('filters.until')) . '</label>' : '')
                . '<input class="fa-input fa-time" type="time" id="' . Str::e($inputId) . '" name="filter[' . Str::e($this->getName()) . '][' . $key . ']" data-admin-filter="' . Str::e($this->getName() . '.' . $key) . '"'
                . ' value="' . Str::e((string) ($value[$key] ?? '')) . '"' . ($key === 'from' ? ' aria-describedby="' . Str::e($id . '-from-hint') . '"' : '') . $invalid . '>';
        }
        return $html . '<span class="fa-sr" id="' . Str::e($id . '-from-hint') . '">' . Str::e(AdminLang::get('filters.from')) . '</span></div>';
    }

    public function describeFilterValue(string|array $value): ?string
    {
        if (!is_array($value)) { return null; }
        $from = ($value['from'] ?? '') !== '' ? Dates::timeOfDay($value['from']) : '';
        $until = ($value['until'] ?? '') !== '' ? Dates::timeOfDay($value['until']) : '';
        return match (true) {
            $from !== '' && $until !== '' => $from . ' – ' . $until,
            $from !== '' => AdminLang::get('filters.from') . ' ' . $from,
            default => AdminLang::get('filters.until') . ' ' . $until,
        };
    }
}
