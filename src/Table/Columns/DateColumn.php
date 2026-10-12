<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

class DateColumn extends Column
{
    protected string $dateFormat = 'Y-m-d';

    /** PHP date() format, default 'Y-m-d'; use 'Y-m-d H:i' for date and time. */
    public function dateFormat(string $format): static
    {
        $this->dateFormat = $format;
        return $this;
    }

    protected function renderValue(mixed $value): string
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return parent::renderValue(null);
        }

        $time = strtotime($value);

        return $time === false ? Str::e($value) : '<time datetime="' . Str::e($value) . '">' . Str::e(date($this->dateFormat, $time)) . '</time>';
    }

    public function cellClass(): string
    {
        return 'fa-nowrap';
    }
}
