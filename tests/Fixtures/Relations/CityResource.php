<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Relations;

use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;

/** Translatable names; archived cities are outside the scope (never offered or accepted). */
final class CityResource extends Resource
{
    protected static string $table = 'cities';
    protected static string $slug = 'cities';

    public function query(Database $query): Database { return $query->where('archived', '=', 0); }
    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->translatable()->required(), BelongsTo::make('country_id')->resource(CountryResource::class)]);
    }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()]); }
}
