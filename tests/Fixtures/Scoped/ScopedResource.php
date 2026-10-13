<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Scoped;

use FloCMS\Admin\{Resource, Form\Form, Form\Fields\TextInput, Table\Table};
use FloCMS\Core\Database;

final class ScopedResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'scoped';
    public function form(Form $form): Form { return $form->schema([TextInput::make('slug')->required()]); }
    public function table(Table $table): Table { return $table; }
    public function query(Database $query): Database { return $query->where('status', '=', 'published'); }
    public function canEdit(object $record): bool { return (bool) $record->featured; }
    public function canDelete(object $record): bool { return (bool) $record->featured; }
}
