<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Search;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** No searchable column: Ctrl+K finds its records by #id only. */
final class IdOnlyPostResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'id-posts';
    protected static string $pluralLabel = 'Id posts';

    public function form(Form $form): Form { return $form->schema([TextInput::make('title')->translatable()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('title')]); }
}
