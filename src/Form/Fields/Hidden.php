<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;

/** <input type="hidden">: submitted and written, not shown. */
class Hidden extends Field
{
    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        return '<input' . self::attributes(['type' => 'hidden', 'id' => $id, 'name' => $name === '' ? null : $name, 'value' => $value]) . '>';
    }
}
