<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

class TextColumn extends Column
{
    protected ?int $limit = null;

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
