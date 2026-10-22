<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\PanelPages;

use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/** A table that isn't in the database, and a view reading a column that isn't there. */
final class MissingTable extends AdminPage
{
    public function table(Table $table): Table
    {
        return $table->query(static fn (Database $db): Database => $db->table('bookings'))->columns([TextColumn::make('guest')])->defaultSort('id');
    }

    public function view(): ?string
    {
        return 'admin/sales-report';
    }

    public function data(array $filters): array
    {
        App::db()->table('users')->select(['nickname'])->get();
        return [];
    }
}
