<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

class TextColumn extends Column
{
    protected ?int $limit = null;
    protected ?string $avatarColumn = null;
    protected string|\Closure|null $description = null;
    protected ?\Closure $descriptionMark = null;
    protected string $descriptionMarkLabel = '';

    /**
     * A person's avatar before the text (0.28): the photo in $imageColumn
     * (Avatar::url()), else the first letter of the text in a colour picked
     * from the record's id.
     */
    public function avatar(string $imageColumn = 'image'): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $imageColumn) !== 1) { throw new \InvalidArgumentException('avatar() takes a column name.'); }
        $this->avatarColumn = $imageColumn;
        return $this;
    }

    /** A second, smaller line under the text (0.28): a column's value, or fn (object $record): string. */
    public function description(string|\Closure $description): static
    {
        if (is_string($description) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $description) !== 1) { throw new \InvalidArgumentException('description() takes a column name or a closure.'); }
        $this->description = $description;
        return $this;
    }

    /** A check mark after the second line when fn (object $record): bool says so, with $label for screen readers (e.g. "Email verified"). */
    public function descriptionMark(\Closure $when, string $label): static
    {
        $this->descriptionMark = $when;
        $this->descriptionMarkLabel = $label;
        return $this;
    }

    public function render(object $record): string
    {
        $main = parent::render($record);
        if ($this->avatarColumn === null && $this->description === null) { return $main; }
        $html = '<span class="fa-cell-person">';
        if ($this->avatarColumn !== null) {
            $name = html_entity_decode(strip_tags($main), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $image = $record->{$this->avatarColumn} ?? null;
            $seed = $record->id ?? $name;
            $html .= \FloCMS\Admin\Support\Avatar::html($name, is_scalar($image) ? (string) $image : null, is_scalar($seed) ? (string) $seed : $name);
        }
        // The row link (0.27) goes on the title only, between these markers.
        $html .= '<span class="fa-cell-lines"><span class="fa-cell-title"><!--fa-title-->' . $main . '<!--/fa-title--></span>';
        if ($this->description !== null) {
            $text = $this->description instanceof \Closure ? ($this->description)($record) : ($record->{$this->description} ?? '');
            $text = is_scalar($text) ? trim((string) $text) : '';
            if ($text !== '') {
                $mark = $this->descriptionMark !== null && ($this->descriptionMark)($record)
                    ? \FloCMS\Admin\Support\Icons::svg('check', 'fa-icon fa-cell-mark') . '<span class="fa-sr">' . Str::e(\FloCMS\Admin\Support\AdminLang::label($this->descriptionMarkLabel)) . '</span>' : '';
                $html .= '<span class="fa-cell-sub"><bdi>' . Str::e($text) . '</bdi>' . $mark . '</span>';
            }
        }
        return $html . '</span></span>';
    }

    /** Cut the text after $characters, with an ellipsis. */
    public function limit(int $characters): static
    {
        $this->limit = max(1, $characters);
        return $this;
    }

    protected function renderValue(mixed $value): string
    {
        if ($value === null || $value === '' || is_array($value) || is_object($value)) {
            return parent::renderValue(is_scalar($value) ? $value : null);
        }

        $text = (string) $value;
        if ($this->limit !== null && mb_strlen($text) > $this->limit) {
            return '<span title="' . Str::e($text) . '">' . Str::e(rtrim(mb_substr($text, 0, $this->limit))) . '&hellip;</span>';
        }

        return Str::e($text);
    }
}
