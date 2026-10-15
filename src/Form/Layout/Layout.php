<?php
declare(strict_types=1);
namespace FloCMS\Admin\Form\Layout;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\{AdminLang, Str};

/** Layouts organize presentation only; fields keep flat names and persistence. */
abstract class Layout
{
    private array $children = [];
    private ?string $label = null;
    private ?string $description = null;
    private int $columns = 2;
    final public function __construct(private readonly string $name)
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $name) !== 1) { throw new \InvalidArgumentException('Use a stable layout name.'); }
    }
    public static function make(string $name): static { return new static($name); }
    public function schema(array $children): static
    {
        foreach ($children as $child) {
            if (!$child instanceof Field && !$child instanceof Layout) { throw new \InvalidArgumentException('Layouts accept fields and layouts.'); }
            if ($this instanceof Tabs && !$child instanceof Tab) { throw new \InvalidArgumentException('Tabs accepts Tab layouts only.'); }
        }
        $this->children = $children; return $this;
    }
    public function label(string $label): static { $this->label = $label; return $this; }
    public function description(string $description): static { $this->description = $description; return $this; }
    public function columns(int $columns): static
    {
        if ($columns < 1 || $columns > 4) { throw new \InvalidArgumentException('Layouts support 1–4 columns.'); }
        $this->columns = $columns; return $this;
    }
    public function getName(): string { return $this->name; }
    public function getLabel(): string { return AdminLang::label($this->label ?? Str::headline($this->name)); }
    public function getDescription(): ?string { return $this->description === null ? null : AdminLang::label($this->description); }
    public function getColumns(): int { return $this->columns; }
    public function children(): array { return $this->children; }
}
