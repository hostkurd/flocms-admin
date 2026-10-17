<?php
declare(strict_types=1);
namespace FloCMS\Admin\Table\Filters;

use FloCMS\Admin\Support\{AdminLang, Str};
use FloCMS\Core\Database;

abstract class Filter
{
    protected string $column;
    private ?string $label = null;
    final public function __construct(private readonly string $name)
    {
        $this->column = self::identifier($name);
        $this->setUp();
    }
    protected function setUp(): void {}
    public static function make(string $name): static { return new static($name); }
    public function column(string $column): static { $this->column = self::identifier($column); return $this; }
    public function label(string $label): static { $this->label = $label; return $this; }
    public function getName(): string { return $this->name; }
    public function getColumn(): string { return $this->column; }
    public function getLabel(): string { return AdminLang::label($this->label ?? Str::headline($this->name)); }
    private static function identifier(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $value) !== 1) { throw new \InvalidArgumentException('Invalid filter identifier.'); }
        return $value;
    }
    /** Validate a client value; return a safe, canonical value. */
    abstract public function value(mixed $value): string|array;
    abstract public function apply(Database $query, string|array $value): Database;
    /** The value when the filter is not used. */
    public function emptyValue(): string|array { return ''; }
    /** The control in the Filters menu, or null for the built-in select/date range markup. */
    public function renderInput(string $id, string|array $value, string $invalid, string $prefix): ?string { return null; }
    /** Readable text of an active value for its chip, or null for the built-in text. */
    public function describe(string|array $value): ?string { return null; }
}
