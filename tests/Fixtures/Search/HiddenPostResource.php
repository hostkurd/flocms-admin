<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Search;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** Left out of Ctrl+K search. */
final class HiddenPostResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'hidden-posts';
    protected static bool $globallySearchable = false;

    public function form(Form $form): Form { return $form->schema([TextInput::make('title')->translatable()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('title')->searchable()]); }
}
