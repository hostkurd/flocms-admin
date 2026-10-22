<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Filters;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Core\Database;

/**
 * Records whose CheckboxList column holds a choice (0.37):
 *
 *     CheckboxListFilter::make('features')->options(['pool' => 'Pool', 'parking' => 'Parking'])
 *
 * One option at a time ("Has: Pool"); with multiple() the options are
 * checkboxes and a record matches when it has any of the ticked ones.
 */
final class CheckboxListFilter extends Filter
{
    /** @var array<string, string> */
    private array $options = [];
    private bool $multiple = false;

    /** @param array<int|string, string> $options key => label (lang keys work) */
    public function options(array $options): static
    {
        if (count($options) > 100) { throw new \InvalidArgumentException('Too many filter options.'); }
        $this->options = [];
        foreach ($options as $key => $label) {
            if ((string) $key === '' || strlen((string) $key) > 190 || !is_string($label)) { throw new \InvalidArgumentException('Invalid filter option.'); }
            $this->options[(string) $key] = $label;
        }
        return $this;
    }

    /** @return array<string, string> */
    public function getOptions(): array { return $this->options; }

    /** Tick several options: records with any of them. */
    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;
        return $this;
    }

    public function isMultiple(): bool { return $this->multiple; }

    public function emptyFilterValue(): string|array { return $this->multiple ? [] : ''; }

    public function value(mixed $value): string|array
    {
        if (!$this->multiple) {
            if (!is_string($value) || ($value !== '' && !array_key_exists($value, $this->options))) { throw new \InvalidArgumentException('Invalid filter option.'); }
            return $value;
        }
        if ($value === '' || $value === []) { return []; }
        if (is_string($value)) { $value = [$value]; }
        if (!is_array($value) || count($value) > 100) { throw new \InvalidArgumentException('Invalid filter option.'); }
        $keys = [];
        foreach ($value as $item) {
            if (!is_string($item)) { throw new \InvalidArgumentException('Invalid filter option.'); }
            if ($item === '') { continue; }
            if (!array_key_exists($item, $this->options)) { throw new \InvalidArgumentException('Invalid filter option.'); }
            $keys[$item] = $item;
        }
        // In the order of the options, keyed by themselves (a canonical JSON object)
        $ordered = [];
        foreach (array_keys($this->options) as $key) { if (isset($keys[$key])) { $ordered[$key] = $key; } }
        return $ordered;
    }

    public function apply(Database $query, string|array $value): Database
    {
        $keys = is_array($value) ? array_values($value) : ($value === '' ? [] : [$value]);
        return ListMatch::any($query, $this->column, $keys);
    }

    public function renderFilterInput(string $id, string|array $value, string $invalid): ?string
    {
        if (!$this->multiple) {
            $html = '<select class="fa-input" id="' . Str::e($id) . '" name="filter[' . Str::e($this->getName()) . ']" data-admin-filter="' . Str::e($this->getName()) . '"' . $invalid . '><option value="">' . Str::e(AdminLang::get('filters.all')) . '</option>';
            foreach ($this->options as $key => $label) {
                $html .= '<option value="' . Str::e($key) . '"' . ($value === $key ? ' selected' : '') . '>' . Str::e(AdminLang::label($label)) . '</option>';
            }
            return $html . '</select>';
        }
        $chosen = is_array($value) ? $value : [];
        $html = '<div class="fa-filter-checks" id="' . Str::e($id) . '" role="group" aria-label="' . Str::e($this->getLabel()) . '"' . $invalid . '>';
        $index = 0;
        foreach ($this->options as $key => $label) {
            $optionId = $id . '-' . $index;
            $html .= '<label class="fa-check" for="' . Str::e($optionId) . '"><input type="checkbox" id="' . Str::e($optionId) . '" name="filter[' . Str::e($this->getName()) . '][o' . $index . ']"'
                . ' data-admin-filter="' . Str::e($this->getName() . '.o' . $index) . '" value="' . Str::e($key) . '"' . (in_array($key, $chosen, true) ? ' checked' : '') . '>'
                . '<span>' . Str::e(AdminLang::label($label)) . '</span></label>';
            $index++;
        }
        return $html . '</div>';
    }

    public function describeFilterValue(string|array $value): ?string
    {
        $keys = is_array($value) ? array_values($value) : [$value];
        return implode(', ', array_map(fn (string $key): string => AdminLang::label($this->options[$key] ?? $key), $keys));
    }
}
