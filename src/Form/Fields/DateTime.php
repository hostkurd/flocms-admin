<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;

/** <input type="datetime-local">, stored as Y-m-d H:i:s. Empty is stored as NULL. */
class DateTime extends Field
{
    protected function setUp(): void
    {
        $this->nullable = true;
    }

    protected function formatForInput(mixed $value): string
    {
        $value = parent::formatForInput($value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $value, $m) === 1 && $m[1] !== '0000-00-00') {
            return $m[1] . 'T' . $m[2];
        }

        return '';
    }

    public function typeError(string $value): ?array
    {
        return $value === '' || self::parse($value) !== null ? null : ['validation.date', []];
    }

    public function dehydrate(string|array $state): mixed
    {
        if (!is_string($state) || $state === '') {
            return parent::dehydrate($state);
        }

        return self::parse($state)?->format('Y-m-d H:i:s') ?? $state;
    }

    private static function parse(string $value): ?\DateTimeImmutable
    {
        foreach (['!Y-m-d\TH:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            // PHP 8.2+ returns false when there were no errors, 8.1 an array of zero counts
            $errors = \DateTimeImmutable::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
            if ($date !== false && $clean) {
                return $date;
            }
        }

        return null;
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $attrs = $this->baseAttributes($id, $name, $attributes) + ['type' => 'datetime-local', 'value' => $value, 'class' => 'fa-input'];

        return '<input' . self::attributes($attrs) . '>';
    }
}
