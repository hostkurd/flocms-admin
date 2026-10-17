<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Search;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** Posts nobody may edit, with their own detail line: Ctrl+K links to the list instead. */
final class ReadOnlyPostResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'archive';
    protected static string $label = 'Archived post';
    protected static string $pluralLabel = 'Archive';

    public function form(Form $form): Form { return $form->schema([TextInput::make('title')->translatable()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('title')->searchable()]); }
    public function canEdit(object $record): bool { return false; }
    public function searchDetails(object $record): ?string { return 'Status: ' . $record->status; }
}
