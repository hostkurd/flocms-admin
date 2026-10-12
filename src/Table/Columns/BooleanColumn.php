<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

class BooleanColumn extends Column
{
    protected function renderValue(mixed $value): string
    {
        $on = in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
        $text = AdminLang::get($on ? 'table.yes' : 'table.no');

        return '<span class="fa-badge ' . ($on ? 'fa-badge-green' : 'fa-badge-gray') . '">' . Str::e($text) . '</span>';
    }
}
