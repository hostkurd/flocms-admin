<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\Str;

/**
 * Read-only text in the form, never submitted or written:
 *
 *     Placeholder::make('created')->content(fn (?object $record) => $record?->created_at ?? '-')
 */
class Placeholder extends Field
{
    protected ?Closure $content = null;
    private ?object $record = null;

    public function content(Closure $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function stateFromRecord(?object $record, string $defaultLang): string|array
    {
        $this->record = $record;

        if ($this->content !== null) {
            $value = ($this->content)($record);
            return is_scalar($value) ? (string) $value : '';
        }

        return parent::stateFromRecord($record, $defaultLang);
    }

    public function stateFromInput(array $input, array $languages): string|array
    {
        return $this->stateFromRecord($this->record, '');
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        return '<div class="fa-static" id="' . Str::e($id) . '">' . ($value === '' ? '&ndash;' : Str::e($value)) . '</div>';
    }
}
