<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

class Select extends Field
{
    private ?string $parent = null;
    private ?Closure $dependentOptions = null;
    /** The callback receives only the canonical parent value. */
    public function optionsFor(string $parent, Closure $callback): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $parent) !== 1) { throw new \InvalidArgumentException('Invalid dependency field name.'); }
        $this->parent = $parent; $this->dependentOptions = $callback; return $this;
    }
    public function hasDependentOptions(): bool { return $this->dependentOptions !== null; }
    public function dependencies(): array { return array_values(array_unique(array_merge(parent::dependencies(), $this->parent === null ? [] : [$this->parent]))); }
    public function resolve(array $state): static
    {
        $field = parent::resolve($state);
        if ($this->dependentOptions === null) { $field->resolved = $this->getOptions(); }
        if ($this->dependentOptions !== null) {
            $options = ($this->dependentOptions)((string) ($state[$this->parent] ?? ''));
            if (!is_array($options)) { throw new \LogicException('Dependent options must return an array.'); }
            $field->options($options);
        }
        return $field;
    }
    /** @var array<int|string, string>|Closure */
    protected array|Closure $options = [];
    /** select | segmented | pictures | positions (0.29) */
    private string $display = 'select';
    /** @var array<string, string> option value => inline SVG (pictures) */
    private array $pictures = [];

    /** Show the options as one row of buttons (a few short options: Light / Dark). */
    public function segmented(): static { $this->display = 'segmented'; return $this; }

    /**
     * Show the options as pictures (a layout chooser): value => an SVG you
     * write (decorative; the option's label is shown under it and read out).
     *
     * @param array<int|string, string> $svgs
     */
    public function pictures(array $svgs): static
    {
        $this->display = 'pictures';
        foreach ($svgs as $value => $svg) { $this->pictures[(string) $value] = $svg; }
        return $this;
    }

    /** Nine positions in a 3 × 3 grid (top-left … bottom-right), e.g. where text sits on an image. */
    public function positions(): static
    {
        $this->display = 'positions';
        $this->options = ['top-start' => 'select.top_start', 'top-center' => 'select.top_center', 'top-end' => 'select.top_end',
            'middle-start' => 'select.middle_start', 'middle-center' => 'select.middle_center', 'middle-end' => 'select.middle_end',
            'bottom-start' => 'select.bottom_start', 'bottom-center' => 'select.bottom_center', 'bottom-end' => 'select.bottom_end'];
        $this->resolved = null;
        return $this;
    }

    public function getDisplay(): string { return $this->display; }
    /** @var array<int|string, string>|null */
    private ?array $resolved = null;

    /**
     * value => label, or a closure returning that array (called once per request).
     *
     * @param array<int|string, string>|Closure(): array<int|string, string> $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;
        $this->resolved = null;
        return $this;
    }

    /** @return array<string, string> value => translated label */
    public function getOptions(): array
    {
        if ($this->resolved === null) {
            $options = $this->options instanceof Closure ? ($this->options)() : $this->options;
            $this->resolved = [];
            foreach (is_array($options) ? $options : [] as $value => $label) {
                $this->resolved[(string) $value] = AdminLang::label((string) $label);
            }
        }

        return $this->resolved;
    }

    public function typeError(string $value): ?array
    {
        if ($value === '' || array_key_exists($value, $this->getOptions())) {
            return null;
        }

        return ['validation.in', []];
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        if ($this->display !== 'select') { return $this->renderChoices($id, $name, $value, $attributes); }
        $attrs = $this->baseAttributes($id, $name, $attributes);
        unset($attrs['placeholder']);
        $attrs['class'] = 'fa-input fa-select';

        $html = '<select' . self::attributes($attrs) . '>';
        $html .= '<option value="">' . Str::e($this->getPlaceholder() ?? AdminLang::get('form.choose')) . '</option>';
        foreach ($this->getOptions() as $optionValue => $label) {
            $selected = (string) $optionValue === $value ? ' selected' : '';
            $html .= '<option value="' . Str::e($optionValue) . '"' . $selected . '>' . Str::e($label) . '</option>';
        }

        return $html . '</select>';
    }

    /** Radio buttons (they work without JavaScript): a row, pictures or a 3 × 3 grid. */
    private function renderChoices(string $id, string $name, string $value, array $attributes): string
    {
        $value = $value === '' ? (string) array_key_first($this->getOptions()) : $value;
        $disabled = $this->isDisabled() || !empty($attributes['disabled']);
        $html = '<div class="fa-choices is-' . $this->display . '" id="' . Str::e($id) . '" role="radiogroup"'
            . (isset($attributes['aria-describedby']) ? ' aria-describedby="' . Str::e((string) $attributes['aria-describedby']) . '"' : '')
            . ' aria-labelledby="' . Str::e($id) . '-label">';
        $index = 0;
        foreach ($this->getOptions() as $optionValue => $label) {
            $optionId = $id . '-' . $index++;
            $checked = (string) $optionValue === $value;
            $html .= '<label class="fa-choice' . ($checked ? ' is-checked' : '') . '" for="' . Str::e($optionId) . '"' . ($this->display === 'positions' ? ' title="' . Str::e($label) . '"' : '') . '>'
                . '<input type="radio" id="' . Str::e($optionId) . '" name="' . Str::e($name) . '" value="' . Str::e((string) $optionValue) . '"' . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>';
            if ($this->display === 'pictures') {
                $html .= '<span class="fa-choice-picture" aria-hidden="true">' . ($this->pictures[(string) $optionValue] ?? '') . '</span><span class="fa-choice-label">' . Str::e($label) . '</span>';
            } elseif ($this->display === 'positions') {
                $html .= '<span class="fa-sr">' . Str::e($label) . '</span>';
            } else {
                $html .= '<span class="fa-choice-label">' . Str::e($label) . '</span>';
            }
            $html .= '</label>';
        }
        return $html . '</div>';
    }
}
