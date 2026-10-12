<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table;

use Closure;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Translations;
use InvalidArgumentException;

/**
 * Base class of the list page's columns.
 */
abstract class Column
{
    protected string $name;
    protected ?string $label = null;
    protected bool $sortable = false;
    protected bool $searchable = false;
    protected ?bool $translatable = null;
    protected ?Closure $formatter = null;

    final public function __construct(string $name)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid column name "' . $name . '".');
        }

        $this->name = $name;
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;
        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;
        return $this;
    }

    /**
     * Read the column as a translatable (JSON) value. Columns whose form field
     * is translatable are detected automatically; set this for the others.
     */
    public function translatable(bool $translatable = true): static
    {
        $this->translatable = $translatable;
        return $this;
    }

    /** Custom display text: fn (mixed $value, object $record): string (escaped). */
    public function format(Closure $formatter): static
    {
        $this->formatter = $formatter;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return AdminLang::label($this->label ?? Str::headline($this->name));
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable === true;
    }

    /** Used by the table to mark columns whose form field is translatable (an explicit choice wins). */
    public function detectTranslatable(bool $translatable): void
    {
        $this->translatable ??= $translatable;
    }

    /** Raw value of the record (translatable values in the admin's language). */
    public function value(object $record): mixed
    {
        $value = property_exists($record, $this->name) ? $record->{$this->name} : null;

        return $this->isTranslatable() ? Translations::get($value, AdminLang::language()) : $value;
    }

    /** Cell HTML. */
    public function render(object $record): string
    {
        $value = $this->value($record);

        if ($this->formatter !== null) {
            return Str::e(($this->formatter)($value, $record));
        }

        return $this->renderValue($value);
    }

    protected function renderValue(mixed $value): string
    {
        return $value === null || $value === '' ? '<span class="fa-muted">&ndash;</span>' : Str::e($value);
    }

    public function cellClass(): string
    {
        return '';
    }
}
