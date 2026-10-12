<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;

/**
 * A checkbox stored as 1 / 0. A hidden "0" input is sent first, so an
 * unchecked box is saved as 0 rather than ignored.
 */
class Toggle extends Field
{
    protected function setUp(): void
    {
        $this->default = false;
    }

    protected function formatForInput(mixed $value): string
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true) ? '1' : '0';
    }

    public function stateFromInput(array $input, array $languages): string|array
    {
        $value = $input[$this->name] ?? '0';

        return is_scalar($value) && $this->formatForInput((string) $value) === '1' ? '1' : '0';
    }

    public function dehydrate(string|array $state): mixed
    {
        return $state === '1' ? 1 : 0;
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $attrs = $this->baseAttributes($id, $name, $attributes);
        unset($attrs['placeholder']);
        $attrs += ['type' => 'checkbox', 'value' => '1', 'class' => 'fa-switch', 'checked' => $value === '1'];

        $hidden = $name === '' || $this->disabled ? '' : '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="0">';

        return $hidden . '<input' . self::attributes($attrs) . '>';
    }
}
