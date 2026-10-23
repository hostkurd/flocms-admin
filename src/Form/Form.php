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
        $this->steps = self::wizardSteps($components, $aside, $flat);
        $this->components = $components; $this->aside = $aside; $this->fields = $flat; return $this;
    }

    /** @var array<string, string> field name => the wizard step holding it (0.39) */
    private array $steps = [];

    /**
     * Wizard rules (0.39): a Wizard is the whole schema (aside() fields may sit beside it),
     * passwords only on its last step (their typed value is never printed back, so an
     * earlier step would lose it on Next), and a step's visibleWhen() names a field of an
     * earlier step. Returns which step holds each field.
     *
     * @param array<string, Field> $flat
     * @return array<string, string>
     */
    private static function wizardSteps(array $components, array $aside, array $flat): array
    {
        $wizards = array_values(array_filter($components, static fn ($node): bool => $node instanceof Layout\Wizard));
        foreach ($aside as $node) {
            if ($node instanceof Layout\Wizard) { throw new InvalidArgumentException('A Wizard goes in schema(), not aside().'); }
        }
        if ($wizards === []) { return []; }
        if (count($components) !== 1) { throw new InvalidArgumentException('A Wizard is the whole form: put every field of schema() in its steps (aside() fields may sit beside it).'); }
        $steps = $wizards[0]->steps();
        if ($steps === []) { throw new InvalidArgumentException('A Wizard needs at least one Step.'); }
        $map = []; $names = [];
        $collect = function (array $nodes, string $step) use (&$collect, &$map): void {
            foreach ($nodes as $node) {
                if ($node instanceof Layout\Layout) { $collect($node->children(), $step); }
                elseif ($node instanceof Field) { $map[$node->getName()] = $step; }
            }
        };
        foreach ($steps as $index => $step) {
            if (isset($names[$step->getName()])) { throw new InvalidArgumentException('Step names must be unique in a wizard: "' . $step->getName() . '".'); }
            $names[$step->getName()] = $index;
            $collect($step->children(), $step->getName());
        }
        $last = $steps[count($steps) - 1]->getName();
        foreach ($map as $name => $step) {
            $field = $flat[$name];
            if ($field instanceof Fields\TextInput && $field->getType() === 'password' && $step !== $last) {
                throw new InvalidArgumentException('The password field "' . $name . '" must be on the last step of the wizard (or in aside()): a typed password is never shown again, so it would be lost on Next.');
            }
        }
        foreach ($steps as $index => $step) {
            $condition = $step->getVisibleCondition();
            if ($condition !== null) {
                $owner = $map[$condition[0]] ?? null;
                if (!isset($flat[$condition[0]])) { throw new InvalidArgumentException('Step "' . $step->getName() . '" depends on an unknown field "' . $condition[0] . '".'); }
                if ($owner !== null && $names[$owner] >= $index) { throw new InvalidArgumentException('Step "' . $step->getName() . '" depends on "' . $condition[0] . '": use a field of an earlier step.'); }
            }
            foreach ($map as $name => $owner) {
                if ($owner === $step->getName()) { $flat[$name]->inStepShownWhen($condition); }
            }
        }
        if (($start = $wizards[0]->getStartOn()) !== null && !isset($names[$start])) { throw new InvalidArgumentException('Wizard::startOn() names an unknown step "' . $start . '".'); }
        return $map;
    }

    /** The form's wizard (0.39), or null when the form is not shown as steps. */
    public function wizard(): ?Layout\Wizard
    {
        $first = $this->components[0] ?? null;
        return $first instanceof Layout\Wizard ? $first : null;
    }

    /** The wizard step holding a field, or null (no wizard, or an aside() field). */
    public function stepOf(string $field): ?string { return $this->steps[$field] ?? null; }

    /** @return list<string> the fields of one wizard step */
    public function stepFields(string $step): array { return array_keys(array_filter($this->steps, static fn (string $owner): bool => $owner === $step)); }

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
            if ($current->fixedValue() !== null) { $state[$name] = $current->fixedValue(); }
            elseif (!$current->isVisible() || ($current->isDisabled() && $record !== null)) { $state[$name] = $field->stateFromRecord($record, \FloCMS\Admin\Translation\Languages::default()); }
            $value = $state[$name] ?? $field->stateFromRecord($record, \FloCMS\Admin\Translation\Languages::default());
            $effective[$name] = (!$current->isVisible() && $current->fixedValue() === null) || is_array($value) || $current->typeError((string) $value) !== null ? '' : $value;
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
    /**
     * A repeater's no-JavaScript button (0.24): "rooms:add", "rooms:remove:k1", "rooms:up:k1".
     * Changes that list in the state; false when the operation names no repeater of this form.
     */
    public function applyRepeaterOperation(array &$state, mixed $operation): bool
    {
        if (!is_string($operation) || strlen($operation) > 300) { return false; }
        foreach ($this->fields as $name => $field) {
            if (!($field instanceof \FloCMS\Admin\Form\Fields\Repeater || $field instanceof \FloCMS\Admin\Form\Fields\Builder) || !$field->isWritable()) { continue; }
            $changed = $field->applyOperation(is_string($state[$name] ?? null) ? $state[$name] : '', $operation);
            if ($changed !== null) { $state[$name] = $changed; return true; }
        }
        return false;
    }

    public function liveFields(): array
    {
        $names = [];
        foreach ($this->fields as $field) { $names = array_merge($names, $field->dependencies()); }
        return array_values(array_unique($names));
    }

    /**
     * Where the errors are, for the message above the form: "Price (Details), City (Location)".
     * Errors of fields inside a tab name the tab; an error with no field gives its own text.
     *
     * @param array<string, mixed> $errors
     * @param array<string, mixed> $state the values, for the names of blocks with errors (0.29)
     * @param bool $steps name the wizard step of each field (0.39); off for the errors of the step shown
     */
    public function errorSummary(array $errors, array $state = [], bool $steps = true): string
    {
        $tabs = [];
        $walk = function (array $nodes, ?string $tab) use (&$walk, &$tabs, $steps): void {
            foreach ($nodes as $node) {
                if ($node instanceof \FloCMS\Admin\Form\Layout\Tab || ($steps && $node instanceof \FloCMS\Admin\Form\Layout\Step)) { $walk($node->children(), $node->getLabel()); }
                elseif ($node instanceof \FloCMS\Admin\Form\Layout\Layout) { $walk($node->children(), $tab); }
                elseif ($node instanceof Field) { $tabs[$node->getName()] = $tab; }
            }
        };
        $walk($this->components, null);
        $walk($this->aside, null);
        $places = [];
        foreach ($errors as $key => $message) {
            $name = explode('.', (string) $key, 2)[0];
            $field = $this->fields[$name] ?? null;
            if ($field === null) {
                $text = is_array($message) ? implode(' ', array_filter($message, 'is_string')) : (is_string($message) ? $message : '');
                if ($text !== '') { $places[$text] = $text; }
                continue;
            }
            $tab = $tabs[$name] ?? null;
            // Inside a repeater (0.24): "Rooms → item 3: Name (Arabic)".
            if ($field instanceof \FloCMS\Admin\Form\Fields\Repeater && preg_match('/^[^.]+\.(\d+)\.([A-Za-z0-9_]+)(?:\.([a-z0-9-]+))?$/', (string) $key, $match) === 1) {
                $sub = $field->fields()[$match[2]] ?? null;
                $lang = $match[3] ?? null;
                $notes = array_values(array_filter([$tab, $sub !== null && $sub->isTranslatable() && $lang !== null && in_array($lang, \FloCMS\Admin\Translation\Languages::all(), true) ? \FloCMS\Admin\Translation\Languages::name($lang) : null],
                    static fn (?string $part): bool => $part !== null && $part !== ''));
                $places[(string) $key] = \FloCMS\Admin\Support\AdminLang::get('repeater.error_place', ['field' => $field->getLabel(), 'number' => (string) ((int) $match[1] + 1), 'item' => $sub?->getLabel() ?? $match[2]])
                    . ($notes !== [] ? ' (' . implode(\FloCMS\Admin\Support\AdminLang::get('form.list_separator'), $notes) . ')' : '');
                continue;
            }
            // Inside a block (0.29): "Page content → Call to action: Heading (Arabic)".
            if ($field instanceof \FloCMS\Admin\Form\Fields\Builder && ($place = $field->errorPlace((string) $key, is_string($state[$name] ?? null) ? $state[$name] : null)) !== null) {
                $places[(string) $key] = $place . ($tab !== null && $tab !== '' ? ' (' . $tab . ')' : '');
                continue;
            }
            // A translation's error names its language: "Title (Arabic)".
            $lang = explode('.', (string) $key, 2)[1] ?? null;
            $language = $field->isTranslatable() && $lang !== null && in_array($lang, \FloCMS\Admin\Translation\Languages::all(), true)
                ? \FloCMS\Admin\Translation\Languages::name($lang) : null;
            $notes = array_values(array_filter([$tab, $language], static fn (?string $part): bool => $part !== null && $part !== ''));
            $places[$name . ($language !== null ? '.' . $lang : '')] = $field->getLabel() . ($notes !== [] ? ' (' . implode(\FloCMS\Admin\Support\AdminLang::get('form.list_separator'), $notes) . ')' : '');
        }
        return implode(\FloCMS\Admin\Support\AdminLang::get('form.list_separator'), array_values($places));
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
