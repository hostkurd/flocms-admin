<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use FloCMS\Admin\Form\Layout\Step;
use FloCMS\Admin\Form\Layout\Wizard;

/**
 * Moving through a wizard (0.39). Every Back, Next and step button is a normal
 * form submit: "_wizard" names the move (next, back, go:<step>), "_wizard_step"
 * the step shown and "_wizard_reached" the furthest step reached (only used to
 * draw the header: the final submit checks every step anyway). A submit without
 * a move saves; on a new record before the last step it means Next, so Enter
 * moves on rather than creating a half-filled record.
 */
final class WizardFlow
{
    public const REVIEW = '_review';

    private function __construct(private readonly Form $form, private readonly Wizard $wizard, private readonly bool $isEdit) {}

    public static function for(Form $form, bool $isEdit): ?self
    {
        $wizard = $form->wizard();
        return $wizard === null ? null : new self($form, $wizard, $isEdit);
    }

    public function wizard(): Wizard { return $this->wizard; }
    public function isEdit(): bool { return $this->isEdit; }

    /**
     * The steps shown for these values, in order (a step's visibleWhen() decides),
     * plus "_review" when the wizard has a summary.
     *
     * @return list<string>
     */
    public function visibleSteps(array $state, ?object $record = null): array
    {
        $resolved = $this->form->evaluate($state, $record);
        $names = [];
        foreach ($this->wizard->steps() as $step) {
            if (self::isShown($step, $resolved, $state)) { $names[] = $step->getName(); }
        }
        if ($this->wizard->hasSummary()) { $names[] = self::REVIEW; }
        return $names;
    }

    private static function isShown(Step $step, Form $resolved, array $state): bool
    {
        $condition = $step->getVisibleCondition();
        if ($condition === null) { return true; }
        [$field, $values] = $condition;
        $value = $state[$field] ?? '';
        return ($resolved->field($field)?->isVisible() ?? false) && is_string($value) && in_array($value, $values, true);
    }

    public function step(string $name): ?Step
    {
        foreach ($this->wizard->steps() as $step) { if ($step->getName() === $name) { return $step; } }
        return null;
    }

    /** The state of a form shown for the first time: the start step on a new record, the first one when editing. */
    public function initial(array $state, ?object $record = null): WizardState
    {
        $visible = $this->visibleSteps($state, $record);
        $start = !$this->isEdit && $this->wizard->getStartOn() !== null && in_array($this->wizard->getStartOn(), $visible, true) ? $this->wizard->getStartOn() : $visible[0];
        $index = (int) array_search($start, $visible, true);
        return new WizardState($start, $this->isEdit ? count($visible) - 1 : $index, $visible, $this->isEdit);
    }

    /**
     * Handles one submit of the form.
     *
     * @param mixed $move the posted "_wizard" value
     * @param \Closure(): array<string, string> $validate every error of the whole form, as on save
     * @param bool $refreshing a live refresh or a repeater button: the step stays, nothing is checked
     * @return array{state: WizardState, save: bool, errors: array<string, string>}
     */
    public function handle(array $input, array $state, ?object $record, \Closure $validate, bool $refreshing = false): array
    {
        $visible = $this->visibleSteps($state, $record);
        $requested = is_string($input['_wizard_step'] ?? null) ? $input['_wizard_step'] : '';
        // A step that is gone (hidden by a changed answer) gives way to the next one still shown.
        $current = in_array($requested, $visible, true) ? $requested : $this->nearest($requested, $visible);
        $index = (int) array_search($current, $visible, true);
        $reachedName = is_string($input['_wizard_reached'] ?? null) ? $input['_wizard_reached'] : '';
        $reached = $this->isEdit ? count($visible) - 1 : max($index, in_array($reachedName, $visible, true) ? (int) array_search($reachedName, $visible, true) : $index);
        $move = $input['_wizard'] ?? null;
        $last = count($visible) - 1;

        if ($refreshing) {
            return ['state' => new WizardState($current, $reached, $visible, $this->isEdit), 'save' => false, 'errors' => []];
        }
        if ($move === null && !$this->isEdit && $index < $last) { $move = 'next'; }
        if ($move === null) {
            [$errors, $failed] = $this->allErrors($validate(), $state, $record, $visible);
            $step = $errors === [] ? $current : ($failed ?? $current);
            return ['state' => new WizardState($step, $reached, $visible, $this->isEdit, $errors !== []), 'save' => true, 'errors' => $errors];
        }
        if (!is_string($move)) { $move = ''; }

        if ($move === 'next') {
            $errors = $this->stepErrors($current, $validate(), $state, $record);
            if ($errors === [] && $index < $last) { $index++; $reached = max($reached, $index); }
            return ['state' => new WizardState($visible[$index], $reached, $visible, $this->isEdit, true), 'save' => false, 'errors' => $errors];
        }
        if ($move === 'back') {
            return ['state' => new WizardState($visible[max(0, $index - 1)], $reached, $visible, $this->isEdit, true), 'save' => false, 'errors' => []];
        }
        if (str_starts_with($move, 'go:')) {
            $target = substr($move, 3);
            $position = array_search($target, $visible, true);
            if ($position !== false && ($this->isEdit || $position <= $reached)) { $current = $target; }
        }
        // An unknown move only shows the form again, never saves.
        return ['state' => new WizardState($current, $reached, $visible, $this->isEdit, true), 'save' => false, 'errors' => []];
    }

    /** @param list<string> $visible */
    private function nearest(string $requested, array $visible): string
    {
        $order = array_map(static fn (Step $step): string => $step->getName(), $this->wizard->steps());
        $position = array_search($requested, $order, true);
        if ($position === false) { return $visible[0]; }
        foreach ($visible as $name) {
            $at = array_search($name, $order, true);
            if ($at !== false && $at >= $position) { return $name; }
        }
        return $visible[count($visible) - 1];
    }

    /**
     * The errors of one step's fields, then its afterValidation() when they pass.
     *
     * @param array<string, string> $all
     * @return array<string, string>
     */
    public function stepErrors(string $step, array $all, array $state, ?object $record): array
    {
        if ($step === self::REVIEW) { return []; }
        $fields = array_flip($this->form->stepFields($step));
        $errors = array_filter($all, static fn ($key): bool => isset($fields[explode('.', (string) $key, 2)[0]]), ARRAY_FILTER_USE_KEY);
        if ($errors === []) { $errors = $this->step($step)?->runAfterValidation($state, $record) ?? []; }
        return $errors;
    }

    /**
     * Every error of the form on the final submit: the form's own, plus each shown
     * step's afterValidation() where that step's fields passed.
     *
     * @param array<string, string> $all
     * @param list<string> $visible
     * @return array{0: array<string, string>, 1: ?string} the errors and the first step holding one
     */
    private function allErrors(array $all, array $state, ?object $record, array $visible): array
    {
        $errors = $all; $first = null;
        foreach ($visible as $name) {
            if ($name === self::REVIEW) { continue; }
            $own = $this->stepErrors($name, $all, $state, $record);
            if ($own !== [] && $first === null) { $first = $name; }
            $errors += $own;
        }
        return [$errors, $first];
    }

    /** @param list<string> $visible */
    public function firstWithErrors(array $errors, array $visible): ?string
    {
        foreach ($visible as $name) {
            if ($name === self::REVIEW) { continue; }
            $fields = array_flip($this->form->stepFields($name));
            foreach (array_keys($errors) as $key) {
                if (isset($fields[explode('.', (string) $key, 2)[0]])) { return $name; }
            }
        }
        return null;
    }
}
