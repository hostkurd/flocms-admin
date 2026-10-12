<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;

/** <input type="date">, stored as Y-m-d. Empty is stored as NULL. */
class Date extends Field
{
    protected function setUp(): void
    {
        $this->nullable = true;
    }

    protected function formatForInput(mixed $value): string
    {
        $value = parent::formatForInput($value);

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1 && $m[1] !== '0000-00-00' ? $m[1] : '';
    }

    public function typeError(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? null : ['validation.date', []];
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $attrs = $this->baseAttributes($id, $name, $attributes) + ['type' => 'date', 'value' => $value, 'class' => 'fa-input'];

        return '<input' . self::attributes($attrs) . '>';
    }
}
