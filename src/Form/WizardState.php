<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

/** Where a wizard stands (0.39): the step shown, the furthest one reached and the steps shown for the current answers. */
final class WizardState
{
    /** @param list<string> $visible */
    public function __construct(
        public readonly string $step,
        public readonly int $reached,
        public readonly array $visible,
        public readonly bool $isEdit,
        // Shown after Back, Next or a step button: the page moves focus to the new step's heading.
        public readonly bool $moved = false
    ) {}

    public function index(): int { return (int) array_search($this->step, $this->visible, true); }
    public function isFirst(): bool { return $this->index() === 0; }
    public function isLast(): bool { return $this->index() === count($this->visible) - 1; }
    /** Whether the final button (Create, Save) is shown: always when editing, on the last step of a new record. */
    public function isFinal(): bool { return $this->isEdit || $this->isLast(); }
    public function count(): int { return count($this->visible); }
    public function next(): ?string { return $this->visible[$this->index() + 1] ?? null; }
}
