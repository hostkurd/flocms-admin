<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Resources;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** Its slug collides with the application controller FloCMS\Controllers\NewsController. */
final class NewsResource extends Resource
{
    protected static string $table = 'news';
    protected static string $slug = 'news';

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('title')]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }
}
