<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Reorder;

use FloCMS\Admin\Table\Table;

/** At most three rows in Reorder mode. */
final class SmallLimitFaqResource extends FaqResource
{
    public function table(Table $table): Table
    {
        return parent::table($table)->reorderLimit(3);
    }
}
