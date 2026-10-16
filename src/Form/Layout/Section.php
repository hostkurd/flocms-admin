<?php
declare(strict_types=1);
namespace FloCMS\Admin\Form\Layout;

/** A titled group of fields; collapsible() lets people fold it away (a native <details>). */
final class Section extends Layout
{
    private bool $collapsible = false;
    private bool $collapsed = false;
    public function collapsible(bool $collapsible = true): static { $this->collapsible = $collapsible; return $this; }
    /** Start folded; a section holding an error always opens. */
    public function collapsed(bool $collapsed = true): static { $this->collapsible = $this->collapsible || $collapsed; $this->collapsed = $collapsed; return $this; }
    public function isCollapsible(): bool { return $this->collapsible; }
    public function isCollapsed(): bool { return $this->collapsed; }
}
