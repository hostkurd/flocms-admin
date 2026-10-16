<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\Str;

/**
 * A colour as #rrggbb: a text input (#rgb is accepted and expanded) with a
 * colour swatch beside it. Empty stays empty, so a colour can be optional.
 */
final class ColorPicker extends Field
{
    public function typeError(string $value): ?array
    {
        return $value === '' || preg_match('/^#[0-9a-f]{6}$/D', $value) === 1 ? null : ['validation.color', []];
    }

    protected function normalizeInput(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/D', $value, $m) === 1) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        if (preg_match('/^[0-9a-f]{6}$/D', $value) === 1) {
            return '#' . $value;
        }
        return $value;
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $attrs = $this->baseAttributes($id, $name, $attributes) + [
            'type' => 'text', 'value' => $value, 'class' => 'fa-input fa-color-text', 'maxlength' => '7', 'dir' => 'ltr',
            'spellcheck' => 'false', 'autocomplete' => 'off', 'inputmode' => 'text', 'pattern' => '#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})',
            'data-color-text' => true,
        ];
        $attrs['placeholder'] ??= '#0b6e91';
        $swatch = preg_match('/^#[0-9a-f]{6}$/D', $value) === 1 ? $value : '#ffffff';

        return '<div class="fa-color" data-admin-color>'
            . '<input type="color" class="fa-color-swatch" value="' . Str::e($swatch) . '" data-color-swatch tabindex="-1" aria-hidden="true"' . ($this->disabled ? ' disabled' : '') . '>'
            . '<input' . self::attributes($attrs) . '></div>';
    }
}
