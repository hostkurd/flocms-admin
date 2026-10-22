<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use Closure;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\ListValues;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

/**
 * A list kept in one column (0.37: TagsInput, CheckboxList) as small chips,
 * the first few and "+2" for the rest:
 *
 *     TagsColumn::make('keywords')
 *     TagsColumn::make('features')->options(['pool' => 'Pool'])->limit(3)
 *
 * With options() the chips show the labels instead of the stored keys.
 */
class TagsColumn extends Column
{
    /** @var array<int|string, string>|Closure */
    private array|Closure $options = [];
    /** @var array<string, string>|null */
    private ?array $resolved = null;
    private int $limit = 3;

    /**
     * key => label (lang keys work), or a closure returning that array.
     *
     * @param array<int|string, string>|Closure(): array<int|string, string> $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;
        $this->resolved = null;
        return $this;
    }

    /** Chips shown before "+N" (1–20, default 3). */
    public function limit(int $limit): static
    {
        $this->limit = max(1, min(20, $limit));
        return $this;
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        if ($this->resolved === null) {
            $options = $this->options instanceof Closure ? ($this->options)() : $this->options;
            $this->resolved = [];
            foreach (is_array($options) ? $options : [] as $key => $label) { $this->resolved[(string) $key] = AdminLang::label((string) $label); }
        }
        return $this->resolved;
    }

    protected function renderValue(mixed $value): string
    {
        $items = ListValues::decode($value);
        if ($items === []) { return parent::renderValue(null); }
        $labels = $this->labels();
        $items = array_map(static fn (string $item): string => $labels[$item] ?? $item, $items);
        $shown = array_slice($items, 0, $this->limit);
        $rest = array_slice($items, $this->limit);
        $html = '<span class="fa-tags-cell">';
        foreach ($shown as $item) { $html .= '<span class="fa-tag">' . Str::e($item) . '</span>'; }
        if ($rest !== []) {
            $count = (string) count($rest);
            $html .= '<span class="fa-tag-more" title="' . Str::e(implode(', ', $rest)) . '"><span aria-hidden="true">+' . $count . '</span><span class="fa-sr">'
                . Str::e(AdminLang::get('table.more_items', ['count' => $count]) . ': ' . implode(', ', $rest)) . '</span></span>';
        }
        return $html . '</span>';
    }
}
