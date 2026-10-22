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
    private bool $group = false;
    /** A light group inside a tab (0.33): a small heading with a rule instead of a card. */
    public function group(bool $group = true): static { $this->group = $group; return $this; }
    public function isGroup(): bool { return $this->group; }
    public function isCollapsible(): bool { return $this->collapsible; }
    public function isCollapsed(): bool { return $this->collapsed; }
}
