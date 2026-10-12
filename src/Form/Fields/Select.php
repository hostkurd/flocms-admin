<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

class Select extends Field
{
    /** @var array<int|string, string>|Closure */
    protected array|Closure $options = [];
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
}
