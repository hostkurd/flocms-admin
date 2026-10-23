<?php
declare(strict_types=1);
namespace FloCMS\Admin\Form\Layout;

/**
 * A form shown as steps (0.39): Back and Next move between them, each step is
 * checked on the server before the next one opens, and the record is saved once,
 * at the end. A wizard is the whole form (aside() fields may sit beside it).
 */
final class Wizard extends Layout
{
    private bool $summary = false;
    private ?string $startOn = null;
    private ?string $submitLabel = null;

    /** Adds a last "Review" step that lists the answers before the final button. */
    public function summary(bool $summary = true): static { $this->summary = $summary; return $this; }
    public function hasSummary(): bool { return $this->summary; }
    /** The step a new record's form opens on (default: the first). */
    public function startOn(string $step): static { $this->startOn = $step; return $this; }
    public function getStartOn(): ?string { return $this->startOn; }
    /** The final button's text (default "Create" on a new record, "Save" when editing). */
    public function submitLabel(string $label): static { $this->submitLabel = $label; return $this; }
    public function getSubmitLabel(): ?string { return $this->submitLabel === null ? null : \FloCMS\Admin\Support\AdminLang::label($this->submitLabel); }

    /** @return list<Step> */
    public function steps(): array { return array_values(array_filter($this->children(), static fn ($child): bool => $child instanceof Step)); }
}
