<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Resources;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** Custom permissions and a hyphenated slug. */
final class TagResource extends Resource
{
    protected static string $table = 'tags';
    protected static string $slug = 'post-tags';
    protected static ?string $icon = 'tag';
    protected static ?string $permissionPrefix = 'taxonomy.tags';
    protected static array $permissions = ['delete' => 'settings.delete'];
    protected static int $navigationSort = 2;

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()]);
    }
}
