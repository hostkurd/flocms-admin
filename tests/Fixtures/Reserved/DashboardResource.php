<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Reserved;

use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Table;

/** Uses a URL that belongs to the panel's own pages. */
final class DashboardResource extends Resource
{
    protected static string $table = 'dashboards';
    protected static string $slug = 'dashboard';

    public function form(Form $form): Form
    {
        return $form;
    }

    public function table(Table $table): Table
    {
        return $table;
    }
}
