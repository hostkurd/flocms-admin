<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Admin\Table\Columns\BadgeColumn;

/** A page's status as people read it (0.30): a scheduled page whose time came reads Published. */
final class PageStatusColumn extends BadgeColumn
{
    public function value(object $record): mixed
    {
        return Pages::status($record);
    }
}
