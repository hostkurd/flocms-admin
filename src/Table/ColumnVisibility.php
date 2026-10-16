<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table;

use FloCMS\Admin\Preferences\Preferences;

/**
 * Which columns of a list the current user sees. Only columns marked
 * toggleable() can be hidden; the title column never. The user's choice is a
 * personal preference ("columns.<slug>") that records only the changes from
 * the defaults, so a column added later still follows its own default.
 */
final class ColumnVisibility
{
    public function __construct(private readonly Table $table, private readonly string $slug)
    {
    }

    /** @return array<string, Column> columns the user may show or hide */
    public function toggleable(): array
    {
        $primary = $this->table->primaryColumn();
        return array_filter($this->table->getColumns(), static fn (Column $c): bool => $c->isToggleable() && $c->getName() !== $primary);
    }

    /** @return array<string, bool> toggleable column => visible */
    public function choices(): array
    {
        $stored = $this->stored();
        $choices = [];
        foreach ($this->toggleable() as $name => $column) {
            $choices[$name] = is_bool($stored[$name] ?? null) ? $stored[$name] : !$column->isHiddenByDefault();
        }
        return $choices;
    }

    public function isHidden(string $name): bool
    {
        return ($this->choices()[$name] ?? true) === false;
    }

    /** @return array<string, Column> the columns to show, in order */
    public function visibleColumns(): array
    {
        $choices = $this->choices();
        return array_filter($this->table->getColumns(), static fn (Column $c): bool => ($choices[$c->getName()] ?? true) !== false);
    }

    /** Whether any toggleable column differs from its default. */
    public function changed(): bool
    {
        return $this->stored() !== [];
    }

    /** @param list<string> $visible names of the toggleable columns to show */
    public function save(array $visible): void
    {
        $overrides = [];
        foreach ($this->toggleable() as $name => $column) {
            $shown = in_array($name, $visible, true);
            if ($shown === $column->isHiddenByDefault()) { $overrides[$name] = $shown; }
        }
        Preferences::current()?->set($this->key(), $overrides === [] ? null : $overrides);
    }

    public function reset(): void
    {
        Preferences::current()?->set($this->key(), null);
    }

    /** @return array<string, bool> */
    private function stored(): array
    {
        $value = Preferences::current()?->get($this->key());
        return is_array($value) ? array_filter($value, 'is_bool') : [];
    }

    private function key(): string
    {
        return 'columns.' . (strlen($this->slug) <= 90 ? $this->slug : substr(hash('sha256', $this->slug), 0, 32));
    }
}
