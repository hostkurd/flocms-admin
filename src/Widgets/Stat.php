<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Support\AdminLang, Support\Str};

final class Stat
{
    private ?string $description = null;
    private string $color = 'primary';
    private ?string $resource = null;
    private function __construct(private readonly string $label, private readonly string|int|float $value)
    {
        if ((is_float($value) && !is_finite($value)) || strlen((string) $value) > 256 || strlen($label) > 500) { throw new \InvalidArgumentException('Use finite, bounded statistic values and labels.'); }
    }
    public static function make(string $label, string|int|float $value): self { return new self($label, $value); }
    public function description(string $text): self { if (strlen($text) > 1000) { throw new \InvalidArgumentException('Statistic description is too long.'); } $this->description = $text; return $this; }
    public function color(string $color): self { if (!in_array($color, ['primary','success','warning','danger','neutral'], true)) { throw new \InvalidArgumentException('Unknown statistic color.'); } $this->color = $color; return $this; }
    public function resource(string $class): self { $this->resource = $class; return $this; }
    public function render(WidgetContext $context): string
    {
        $tag = $this->resource === null ? 'div' : 'a'; $href = $this->resource === null ? '' : ' href="' . Str::e($context->resourceUrl($this->resource)) . '"';
        $value = is_string($this->value) ? $this->value : number_format($this->value, is_float($this->value) && floor($this->value) !== $this->value ? 2 : 0);
        return '<' . $tag . $href . ' class="fa-widget-stat fa-widget-' . $this->color . '"><span class="fa-stat-label">' . Str::e(AdminLang::label($this->label)) . '</span><span class="fa-stat-value" dir="auto">' . Str::e($value) . '</span>'
            . ($this->description === null ? '' : '<span class="fa-help">' . Str::e(AdminLang::label($this->description)) . '</span>') . '</' . $tag . '>';
    }
}
