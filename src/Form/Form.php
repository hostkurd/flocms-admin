<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use InvalidArgumentException;

/**
 * The fields of a resource's create and edit pages, in order.
 */
final class Form
{
    /** @var array<string, Field> */
    private array $fields = [];

    private array $components = [];
    /** @var list<Field|\FloCMS\Admin\Form\Layout\Layout> secondary fields shown in a sidebar on the full page */
    private array $aside = [];
    private int $columns = 2;
    public function columns(int $columns): self
    {
        if ($columns < 1 || $columns > 4) { throw new InvalidArgumentException('Forms support 1–4 columns.'); }
        $this->columns = $columns; return $this;
    }
    public function getColumns(): int { return $this->columns; }
    public function components(): array { return $this->components; }
    public function asideComponents(): array { return $this->aside; }

    /** Fields may be grouped in layouts while retaining flat, unique names. */
    public function schema(array $fields): self
    {
        return $this->index(array_merge($this->components, $fields), $this->aside);
    }

    /**
     * Secondary fields (publishing, relations, dates) in a sidebar beside the
     * main fields on the full-page form; in a dialog they follow the main fields.
     * Same field types and layouts as schema(); names stay unique across both.
     */
    public function aside(array $fields): self
    {
        return $this->index($this->components, array_merge($this->aside, $fields));
    }

    private function index(array $components, array $aside): self
    {
        $flat = []; $count = 0;
        $walk = function (array $nodes, int $depth = 0) use (&$walk, &$flat, &$count): void {
            if ($depth > 8) { throw new InvalidArgumentException('Form layouts are nested too deeply.'); }
            foreach ($nodes as $node) {
                if (++$count > 256) { throw new InvalidArgumentException('Too many form components.'); }
                if ($node instanceof \FloCMS\Admin\Form\Layout\Layout) { $walk($node->children(), $depth + 1); }
                elseif ($node instanceof Field) {
                    if (isset($flat[$node->getName()])) { throw new InvalidArgumentException('The form has two fields named "' . $node->getName() . '".'); }
                    $flat[$node->getName()] = $node;
                } else { throw new InvalidArgumentException('Form::schema() expects fields or layouts.'); }
            }
        };
        $walk($components); $walk($aside);
        $this->components = $components; $this->aside = $aside; $this->fields = $flat; return $this;
    }

    /** Resolve dependencies in order; hidden submitted values never control other fields. */
    public function evaluate(array &$state, ?object $record = null): self
    {
        $result = clone $this; $resolved = []; $effective = []; $visiting = [];
        $visit = function (string $name) use (&$visit, &$resolved, &$effective, &$visiting, &$state, $record): void {
            if (isset($resolved[$name])) { return; }
            if (isset($visiting[$name])) { throw new \LogicException('Cyclic form dependency: ' . $name); }
            $field = $this->fields[$name] ?? throw new \LogicException('Unknown form dependency: ' . $name);
            $visiting[$name] = true;
            foreach ($field->dependencies() as $dependency) {
                if (($this->fields[$dependency] ?? null) instanceof \FloCMS\Admin\Form\Fields\FileUpload) { throw new \LogicException('Uploads cannot control dependencies.'); }
                if (($this->fields[$dependency] ?? null)?->isTranslatable()) { throw new \LogicException('Dependencies must use scalar fields.'); }
                if (($this->fields[$dependency] ?? null) instanceof \FloCMS\Admin\Form\Fields\TextInput && $this->fields[$dependency]->getType() === 'password') { throw new \LogicException('Passwords cannot control dependencies.'); }
                $visit($dependency);
            }
            $current = $field->resolve($effective);
            if (!$current->isVisible() || ($current->isDisabled() && $record !== null)) { $state[$name] = $field->stateFromRecord($record, \FloCMS\Admin\Translation\Languages::default()); }
            $value = $state[$name] ?? $field->stateFromRecord($record, \FloCMS\Admin\Translation\Languages::default());
            $effective[$name] = !$current->isVisible() || is_array($value) || $current->typeError((string) $value) !== null ? '' : $value;
            $resolved[$name] = $current; unset($visiting[$name]);
        };
        foreach (array_keys($this->fields) as $name) { $visit($name); }
        $result->fields = array_replace($this->fields, $resolved); return $result;
    }
    public function refreshState(array &$state, ?object $record = null): void
    {
        $resolved = $this->evaluate($state, $record);
        foreach ($resolved->fields() as $name => $field) {
            if ($field instanceof \FloCMS\Admin\Form\Fields\Select && $field->hasDependentOptions() && $field->typeError((string) ($state[$name] ?? '')) !== null) { $state[$name] = ''; }
            // A related record that does not belong to the new parent is cleared (a city of another country).
            if ($field instanceof \FloCMS\Admin\Form\Fields\BelongsTo && $field->getDependsOn() !== null && is_string($state[$name] ?? null) && $field->typeError($state[$name]) !== null) { $state[$name] = ''; }
            if ($field instanceof \FloCMS\Admin\Form\Fields\BelongsToMany && $field->getDependsOn() !== null && is_string($state[$name] ?? null)) { $state[$name] = $field->onlyValid($state[$name]); }
        }
        $this->evaluate($state, $record);
    }
    public function liveFields(): array
    {
        $names = [];
        foreach ($this->fields as $field) { $names = array_merge($names, $field->dependencies()); }
        return array_values(array_unique($names));
    }

    /** @return array<string, Field> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $name): ?Field
    {
        return $this->fields[$name] ?? null;
    }

    /** @return array<string, Field> fields whose value is written to the table */
    public function writableFields(): array
    {
        return array_filter($this->fields, static fn (Field $f): bool => $f->isWritable());
    }

    /** @return list<string> */
    public function translatableNames(): array
    {
        return array_values(array_keys(array_filter($this->fields, static fn (Field $f): bool => $f->isTranslatable())));
    }
}
