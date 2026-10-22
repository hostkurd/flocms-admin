<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Support\Dates;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

/**
 * A time of day (0.37, TimePicker), as config 'time_format' says (24-hour by
 * default): TimeColumn::make('opens_at'), or ->timeFormat('g:i A') for one column.
 */
class TimeColumn extends Column
{
    private ?string $timeFormat = null;
    private bool $seconds = false;

    /** PHP date() format of the time, e.g. 'H:i' or 'g:i A'. */
    public function timeFormat(string $format): static
    {
        $this->timeFormat = $format;
        return $this;
    }

    /** Show seconds too. */
    public function seconds(bool $seconds = true): static
    {
        $this->seconds = $seconds;
        return $this;
    }

    protected function renderValue(mixed $value): string
    {
        if (!is_scalar($value) || trim((string) $value) === '') { return parent::renderValue(null); }
        $text = Dates::timeOfDay((string) $value, $this->seconds, $this->timeFormat);
        if ($text === '') { return Str::e((string) $value); }
        $machine = \FloCMS\Admin\Form\Fields\TimePicker::parse(preg_replace('/^\d{4}-\d{2}-\d{2}[ T]/', '', (string) $value) ?? '') ?? '';
        return '<time datetime="' . Str::e($this->seconds ? $machine : substr($machine, 0, 5)) . '">' . Str::e($text) . '</time>';
    }

    public function cellClass(): string
    {
        return 'fa-nowrap';
    }
}
