<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\Str;

/**
 * A URL slug. With from('title') it is filled from that field (its default
 * language) while the slug is empty, in the browser and again on save.
 */
class Slug extends Field
{
    protected ?string $from = null;

    public function from(string $field): static
    {
        $this->from = $field;
        return $this;
    }

    public function getFrom(): ?string
    {
        return $this->from;
    }

    protected function normalizeInput(string $value): string
    {
        return Str::slug($value);
    }

    public function prepare(array $state, string $defaultLang): array
    {
        if (($state[$this->name] ?? '') !== '' || $this->from === null || !array_key_exists($this->from, $state)) {
            return $state;
        }

        $source = $state[$this->from];
        if (is_array($source)) {
            $source = $source[$defaultLang] ?? '';
        }
        $state[$this->name] = Str::slug((string) $source);

        return $state;
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $attrs = $this->baseAttributes($id, $name, $attributes) + [
            'type' => 'text',
            'value' => $value,
            'class' => 'fa-input fa-slug',
            'dir' => 'ltr',
            'data-slug-from' => $this->from === null ? null : 'f-' . $this->from,
            'spellcheck' => 'false',
            'autocomplete' => 'off',
        ];

        return '<input' . self::attributes($attrs) . '>';
    }
}
