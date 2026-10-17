<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Relations;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

final class CountryResource extends Resource
{
    protected static string $table = 'countries';
    protected static string $slug = 'countries';

    public function form(Form $form): Form { return $form->schema([TextInput::make('name')->required()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()]); }
}
