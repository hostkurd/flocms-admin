<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Trash;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;

/** Narrows query() and has no trash rule: each person sees only what they deleted. */
final class OwnNoteResource extends Resource
{
    protected static string $table = 'notes';
    protected static string $slug = 'own-notes';

    public function query(Database $query): Database { return $query->where('title', '!=', 'hidden'); }
    public function form(Form $form): Form { return $form->schema([TextInput::make('title')->required()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('title')]); }
}
