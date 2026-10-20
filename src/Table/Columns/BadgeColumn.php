<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

/**
 * The value as a coloured badge:
 *
 *     BadgeColumn::make('type')
 *         ->colors(['sale' => 'green', 'rent' => 'blue'])
 *         ->labels(['sale' => 'For sale', 'rent' => 'For rent'])
 */
class BadgeColumn extends Column
{
    public const COLORS = ['gray', 'green', 'blue', 'yellow', 'red', 'purple'];

    /** @var array<string, string> */
    protected array $colors = [];
    /** @var array<string, string> */
    protected array $labels = [];
    protected bool $dot = false;

    /** A coloured dot before the word instead of a filled badge (0.28), for statuses. */
    public function dot(bool $dot = true): static
    {
        $this->dot = $dot;
        return $this;
    }

    /** @param array<int|string, string> $colors value => gray|green|blue|yellow|red|purple */
    public function colors(array $colors): static
    {
        foreach ($colors as $value => $color) {
            $this->colors[(string) $value] = in_array($color, self::COLORS, true) ? $color : 'gray';
        }

        return $this;
    }

    /** @param array<int|string, string> $labels value => label shown in the badge */
    public function labels(array $labels): static
    {
        foreach ($labels as $value => $label) {
            $this->labels[(string) $value] = (string) $label;
        }

        return $this;
    }

    protected function renderValue(mixed $value): string
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return parent::renderValue(null);
        }

        $key = (string) $value;
        $text = isset($this->labels[$key]) ? AdminLang::label($this->labels[$key]) : $key;

        if ($this->dot) {
            return '<span class="fa-status fa-status-' . ($this->colors[$key] ?? 'gray') . '"><span class="fa-status-dot" aria-hidden="true"></span>' . Str::e($text) . '</span>';
        }
        return '<span class="fa-badge fa-badge-' . ($this->colors[$key] ?? 'gray') . '">' . Str::e($text) . '</span>';
    }
}
