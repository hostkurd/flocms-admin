<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Relations;

use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** A tree: a category is never its own parent. */
final class CategoryResource extends Resource
{
    protected static string $table = 'categories';
    protected static string $slug = 'categories';

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required(), BelongsTo::make('parent_id')->label('Parent')->resource(self::class)]);
    }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()]); }
}
