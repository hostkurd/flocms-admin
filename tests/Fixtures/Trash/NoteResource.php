<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Trash;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** A resource that opts out of the trash: deleting is final. */
final class NoteResource extends Resource
{
    protected static string $table = 'notes';
    protected static string $slug = 'notes';
    protected static bool $trash = false;

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('title')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }
}
