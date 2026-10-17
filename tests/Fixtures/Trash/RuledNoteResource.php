<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Trash;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** Its own trash rule: only notes whose title starts with "team". */
final class RuledNoteResource extends Resource
{
    protected static string $table = 'notes';
    protected static string $slug = 'ruled-notes';

    public function canSeeInTrash(object $record, ?int $deletedBy): bool { return str_starts_with((string) $record->title, 'team'); }
    public function form(Form $form): Form { return $form->schema([TextInput::make('title')->required()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('title')]); }
}
