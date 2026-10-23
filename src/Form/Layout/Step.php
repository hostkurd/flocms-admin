<?php
declare(strict_types=1);
namespace FloCMS\Admin\Form\Layout;

/**
 * One step of a Wizard (0.39): its fields are shown on their own page of the
 * form, checked by Next before the following step opens.
 */
final class Step extends Layout
{
    private ?string $icon = null;
    /** @var array{0: string, 1: list<string>}|null */
    private ?array $visibleCondition = null;
    private ?\Closure $afterValidation = null;

    /** An icon (a name from the panel's icon set) shown instead of the step's number until it is done. */
    public function icon(string $icon): static { $this->icon = $icon; return $this; }
    public function getIcon(): ?string { return $this->icon; }

    /**
     * Show the step only while a field of an earlier step has one of these values,
     * like Field::visibleWhen(). A hidden step is skipped: its fields are not shown,
     * not checked and not saved.
     */
    public function visibleWhen(string $field, string|array $values): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) !== 1) { throw new \InvalidArgumentException('Invalid dependency field name.'); }
        foreach ((array) $values as $value) { if (!is_string($value)) { throw new \InvalidArgumentException('Condition values must be strings.'); } }
        $this->visibleCondition = [$field, array_values((array) $values)];
        return $this;
    }
    /** @return array{0: string, 1: list<string>}|null */
    public function getVisibleCondition(): ?array { return $this->visibleCondition; }

    /**
     * Extra checks once the step's own rules pass: fn (array $state, ?object $record): array,
     * returning "field" => message errors (an empty array when all is well).
     */
    public function afterValidation(\Closure $check): static { $this->afterValidation = $check; return $this; }

    /** @return array<string, string> */
    public function runAfterValidation(array $state, ?object $record): array
    {
        if ($this->afterValidation === null) { return []; }
        $errors = ($this->afterValidation)($state, $record);
        if (!is_array($errors)) { throw new \LogicException('Step::afterValidation() must return an array of "field" => message.'); }
        $clean = [];
        foreach ($errors as $key => $message) {
            if (is_string($message) && $message !== '') { $clean[(string) $key] = $message; }
        }
        return $clean;
    }
}
