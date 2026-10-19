<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\View;

use FloCMS\Admin\Actions\DeleteAction;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** The posts table whose list offers no Edit action: records open on the view page only. */
final class NoEditPostResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'entries';
    protected static string $label = 'Entry';
    protected static string $pluralLabel = 'Entries';

    public function form(Form $form): Form { return $form->schema([TextInput::make('title')->translatable()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('title')->searchable()])->actions([DeleteAction::make()]); }
}
