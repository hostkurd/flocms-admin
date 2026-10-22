<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\ListValues;
use FloCMS\Admin\Support\Str;
use InvalidArgumentException;

/**
 * Several choices from a fixed list (0.37): a checkbox per option. The chosen
 * option keys are stored as a JSON list in one column, in the order of the
 * options:
 *
 *     CheckboxList::make('features')
 *         ->options(['pool' => 'Pool', 'parking' => 'Parking'])
 *         ->columns(3)->selectAll()->max(5)
 *
 * Plain checkboxes without JavaScript; Select all, Clear and the filter box come
 * with it. A stored key that is no longer an option stays saved (with a notice)
 * until it is unticked.
 */
class CheckboxList extends Field implements ListField
{
    /** Options above which the filter box appears unless searchable() says otherwise. */
    public const SEARCH_FROM = 12;
    /** Inputs are named features[o0], features[o1] … ("[]" names don't survive the dialog drafts). */
    private const MARKER = '_';

    /** @var array<int|string, string>|Closure */
    private array|Closure $options = [];
    /** @var array<string, string>|null */
    private ?array $resolved = null;
    private int $columns = 1;
    private bool $selectAll = false;
    private ?bool $searchable = null;
    private ?int $min = null;
    private ?int $max = null;
    private ?string $missing = null;
    /**
     * Inside a repeater item or a block: the keys no longer among the options
     * that the form sent back signed, per form state (shared with resolve()'s clones).
     */
    private \ArrayObject $kept;
    /** A record's own field (set by the form's submission): the keys it already has; signatures are not needed then. */
    private ?array $stored = null;

    protected function setUp(): void
    {
        $this->nullable = true;
        $this->kept = new \ArrayObject();
    }

    /**
     * value => label, or a closure returning that array (called once per request).
     * Labels may be lang keys.
     *
     * @param array<int|string, string>|Closure(): array<int|string, string> $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;
        $this->resolved = null;
        return $this;
    }

    /** @return array<string, string> key => translated label */
    public function getOptions(): array
    {
        if ($this->resolved === null) {
            $options = $this->options instanceof Closure ? ($this->options)() : $this->options;
            $this->resolved = [];
            foreach (is_array($options) ? $options : [] as $value => $label) {
                if ((string) $value === '') { continue; }
                $this->resolved[(string) $value] = AdminLang::label((string) $label);
            }
        }
        return $this->resolved;
    }

    /** Options side by side on wide screens (1–4); one column on phones. */
    public function columns(int $columns): static
    {
        if ($columns < 1 || $columns > 4) { throw new InvalidArgumentException('CheckboxList columns must be 1–4.'); }
        $this->columns = $columns;
        return $this;
    }

    /** "Select all" and "Clear" links above the options (with JavaScript). */
    public function selectAll(bool $selectAll = true): static
    {
        $this->selectAll = $selectAll;
        return $this;
    }

    /** A filter box over the options; by default shown above SEARCH_FROM options. */
    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;
        return $this;
    }

    /** At least this many choices (when any is made; add required() to ask for one). */
    public function min(int $min): static
    {
        if ($min < 1) { throw new InvalidArgumentException('CheckboxList min must be 1 or more.'); }
        $this->min = $min;
        return $this;
    }

    /** At most this many choices. */
    public function max(int $max): static
    {
        if ($max < 1) { throw new InvalidArgumentException('CheckboxList max must be 1 or more.'); }
        $this->max = $max;
        return $this;
    }

    /** The notice for a stored key that is no longer an option (":value" is the key). */
    public function whenMissing(string $message): static
    {
        $this->missing = $message;
        return $this;
    }

    public function getColumns(): int { return $this->columns; }
    public function hasSelectAll(): bool { return $this->selectAll; }
    public function getMin(): ?int { return $this->min; }
    public function getMax(): ?int { return $this->max; }
    public function isSearchable(): bool { return $this->searchable ?? count($this->getOptions()) > self::SEARCH_FROM; }

    /* ---------------------------------------------------------------
     * Values
     * ------------------------------------------------------------- */

    public function values(mixed $state): array
    {
        return ListValues::decode($state);
    }

    public function readable(array $values): array
    {
        $options = $this->getOptions();
        return array_map(static fn (string $key): string => $options[$key] ?? $key, $values);
    }

    protected function formatForInput(mixed $value): string
    {
        return ListValues::encode($this->ordered(ListValues::decode($value)));
    }

    public function stateFromInput(array $input, array $languages): string|array
    {
        $value = $input[$this->name] ?? null;
        if (!is_array($value)) {
            // The JSON state (a dialog's draft, a repeater item, an import) or legacy text
            return is_string($value) ? ListValues::encode($this->ordered(ListValues::decode($value))) : '';
        }
        $keys = [];
        $kept = [];
        foreach ($value as $index => $item) {
            if ($index === self::MARKER || !is_string($item) || $item === '') { continue; }
            if (str_starts_with($item, '~') && !array_key_exists($item, $this->getOptions())) {
                // A key no longer among the options, kept: only as the form showed it (signed).
                $key = Builder::unsign(substr($item, 1), 'kept|' . $this->name);
                if ($key === null) { continue; }
                $kept[] = $key;
                $item = $key;
            }
            $keys[] = $item;
            if (count($keys) >= ListValues::LIMIT) { break; }
        }
        $state = ListValues::encode($this->ordered($keys));
        if ($kept !== []) { $this->kept[$state] = $kept; }
        return $state;
    }

    /**
     * The keys the record has (0.37). Set before a save is checked: a key that
     * is no longer an option passes only when the record already has it, whether
     * the form showed it, an import sent it or the field was not in the input.
     *
     * @param list<string> $stored
     */
    public function allowStored(array $stored): void
    {
        $this->stored = $stored;
    }

    /** The field is in the input (a hidden features[_] comes with every form that shows it). */
    public function submitted(array $input): bool
    {
        return array_key_exists($this->name, $input);
    }

    /**
     * Unique keys in the order of the options, keys no longer among them last.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private function ordered(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        $known = array_values(array_filter(array_keys($this->getOptions()), static fn (string $key): bool => in_array($key, $keys, true)));
        return array_merge($known, array_values(array_diff($keys, $known)));
    }

    public function dehydrate(string|array $state): mixed
    {
        $keys = is_string($state) ? ListValues::decode($state) : [];
        if ($keys === []) { return $this->isNullable() ? null : '[]'; }
        return ListValues::encode($keys);
    }

    public function typeError(string $value): ?array
    {
        $keys = ListValues::decode($value);
        $options = $this->getOptions();
        // A record's field: only keys it already has; an item's: only keys the form showed (signed) in this state
        $allowed = $this->stored ?? ($this->kept[$value] ?? []);
        $chosen = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $options)) { $chosen++; continue; }
            if (!in_array($key, $allowed, true)) { return ['validation.in', []]; }
        }
        // Old keys kept until unticked don't count towards min and max
        if ($this->min !== null && $chosen > 0 && $chosen < $this->min) { return ['validation.list_min', ['min' => (string) $this->min]]; }
        if ($this->max !== null && $chosen > $this->max) { return ['validation.list_max', ['max' => (string) $this->max]]; }
        return null;
    }

    /* ---------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------- */

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $chosen = ListValues::decode($value);
        $options = $this->getOptions();
        $disabled = $this->isDisabled() || !empty($attributes['disabled']);
        $describedBy = isset($attributes['aria-describedby']) ? ' aria-describedby="' . Str::e((string) $attributes['aria-describedby']) . '"' : '';
        $label = $this->getLabel();
        $inputName = static fn (string $part): ?string => $name === '' ? null : $name . '[' . $part . ']';

        $html = '<div class="fa-checks" id="' . Str::e($id) . '" role="group" aria-labelledby="' . Str::e($id) . '-label"' . $describedBy
            . ' data-admin-checks data-checks-total="' . count($options) . '"' . ($this->max !== null ? ' data-checks-max="' . $this->max . '"' : '')
            . ' data-checks-count-text="' . Str::e(AdminLang::get('checks.count')) . '">';
        if ($name !== '') {
            $html .= '<input type="hidden" name="' . Str::e((string) $inputName(self::MARKER)) . '" value=""' . ($disabled ? ' disabled' : '') . '>';
        }
        $tools = '';
        if ($this->selectAll && !$disabled) {
            // Select all only when every option may be chosen
            if ($this->max === null || $this->max >= count($options)) {
                $tools .= '<button type="button" class="fa-checks-link" data-checks-all>' . Str::e(AdminLang::get('checks.select_all')) . '</button>';
            }
            $tools .= '<button type="button" class="fa-checks-link" data-checks-none>' . Str::e(AdminLang::get('checks.clear')) . '</button>';
        }
        if ($this->selectAll || $this->isSearchable()) {
            $html .= '<div class="fa-checks-tools" data-checks-tools hidden><span class="fa-checks-count" data-checks-count aria-live="polite"></span>' . $tools . '</div>';
        }
        $html .= '<div class="fa-checks-box">';
        if ($this->isSearchable()) {
            $html .= '<label class="fa-checks-search" data-checks-search-wrap hidden>' . Icons::svg('search', 'fa-icon') . '<input type="search" class="fa-checks-filter" data-checks-search'
                . ' placeholder="' . Str::e(AdminLang::get('checks.filter')) . '" aria-label="' . Str::e(AdminLang::get('checks.filter_label', ['label' => $label])) . '"></label>';
        }
        $html .= '<div class="fa-checks-grid fa-checks-cols-' . $this->columns . '">';
        $index = 0;
        foreach ($options as $key => $optionLabel) {
            $optionId = $id . '-' . $index;
            $checked = in_array((string) $key, $chosen, true);
            $html .= '<label class="fa-check" for="' . Str::e($optionId) . '"><input type="checkbox" id="' . Str::e($optionId) . '"'
                . ($name !== '' ? ' name="' . Str::e((string) $inputName('o' . $index)) . '"' : '')
                . ' value="' . Str::e((string) $key) . '"' . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>'
                . '<span>' . Str::e($optionLabel) . '</span></label>';
            $index++;
        }
        $html .= '</div>';
        $html .= '<p class="fa-checks-empty" data-checks-empty hidden>' . Str::e(AdminLang::get('checks.no_match')) . '</p>';
        // Keys no longer among the options: kept (ticked) until unticked
        $gone = 0;
        foreach ($chosen as $key) {
            if (array_key_exists($key, $options)) { continue; }
            $optionId = $id . '-gone-' . $gone;
            $message = $this->missing !== null ? str_replace(':value', $key, AdminLang::label($this->missing)) : AdminLang::get('checks.gone', ['value' => $key]);
            $html .= '<p class="fa-choice-gone fa-check-gone">' . Icons::svg('alert-triangle', 'fa-icon')
                . '<label class="fa-check" for="' . Str::e($optionId) . '"><input type="checkbox" id="' . Str::e($optionId) . '"'
                . ($name !== '' ? ' name="' . Str::e((string) $inputName('k' . $gone)) . '"' : '')
                . ' value="~' . Str::e(Builder::sign($key, 'kept|' . $this->name)) . '" checked' . ($disabled ? ' disabled' : '') . ' data-checks-kept>'
                . '<span>' . Str::e($message) . '</span></label></p>';
            $gone++;
        }
        return $html . '</div></div>';
    }
}
