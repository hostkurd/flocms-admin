<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\Str;

class Textarea extends Field
{
    protected int $rows = 4;

    public function rows(int $rows): static
    {
        $this->rows = max(2, $rows);
        return $this;
    }

    public function supportsTranslation(): bool
    {
        return true;
    }

    public function isLong(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        $this->columnSpan = 2;
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $rows = (string) ($attributes['data-rows'] ?? $this->rows);
        unset($attributes['data-rows']);

        $attrs = $this->baseAttributes($id, $name, $attributes) + [
            'class' => 'fa-input fa-textarea',
            'rows' => $rows,
        ];

        return '<textarea' . self::attributes($attrs) . '>' . Str::e($value) . '</textarea>';
    }
}
