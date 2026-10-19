<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use Closure;

/**
 * How a resource's CSV import works (0.25), returned by Resource::import():
 *
 *     return $import
 *         ->key('reference')                            // existing records are found by this unique column
 *         ->defaults(['status' => 'draft'])             // values for fields the file does not carry
 *         ->beforeRow(fn (array $row): array => $row)   // clean a row before the checks
 *         ->afterImport(fn (ImportRun $run) => …);      // after a run finished
 *
 * Existing records can always be found by `id`; key() adds one more column,
 * which must be unique in the table.
 */
final class Import
{
    private ?string $key = null;
    /** @var array<string, string|array<string, string>> */
    private array $defaults = [];
    private ?Closure $beforeRow = null;
    private ?Closure $afterImport = null;

    public function key(string $column): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) { throw new \InvalidArgumentException('Invalid import key column "' . $column . '".'); }
        $this->key = $column;
        return $this;
    }

    /** @param array<string, string|array<string, string>> $values field => value (a translatable field: language => text) */
    public function defaults(array $values): self
    {
        foreach ($values as $field => $value) {
            if (!is_string($field) || !(is_scalar($value) || is_array($value))) { throw new \InvalidArgumentException('Import defaults are field => value.'); }
            $this->defaults[$field] = is_array($value) ? array_map('strval', $value) : (string) $value;
        }
        return $this;
    }

    /**
     * Change a row before it is checked: receives and returns field => value,
     * as matched from the file (a translatable field: language => text).
     *
     * @param Closure(array<string, string|array<string, string>>): array<string, string|array<string, string>> $callback
     */
    public function beforeRow(Closure $callback): self
    {
        $this->beforeRow = $callback;
        return $this;
    }

    /** @param Closure(ImportRun): void $callback */
    public function afterImport(Closure $callback): self
    {
        $this->afterImport = $callback;
        return $this;
    }

    public function getKey(): ?string { return $this->key; }
    /** @return array<string, string|array<string, string>> */
    public function getDefaults(): array { return $this->defaults; }
    public function getBeforeRow(): ?Closure { return $this->beforeRow; }
    public function getAfterImport(): ?Closure { return $this->afterImport; }
}
