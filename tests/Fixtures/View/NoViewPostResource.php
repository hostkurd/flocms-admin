<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\View;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** The posts table without a view page ($viewPage = false). */
final class NoViewPostResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'drafts';
    protected static string $label = 'Draft';
    protected static string $pluralLabel = 'Drafts';
    protected static bool $viewPage = false;

    public function form(Form $form): Form { return $form->schema([TextInput::make('title')->translatable()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('title')->searchable()]); }
}
