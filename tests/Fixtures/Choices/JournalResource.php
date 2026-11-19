<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Choices;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{TagsInput, TextInput};
use FloCMS\Admin\Table\{Table, Columns\TagsColumn, Columns\TextColumn};
use FloCMS\Admin\Table\Filters\TagsFilter;

/** Tags per language and an ability of its own (0.44). */
class JournalResource extends Resource
{
    protected static string $table = 'journal';
    protected static string $slug = 'journal';
    protected static string $label = 'Entry';
    protected static string $pluralLabel = 'Journal';
    protected static string $translationMode = 'page';
    protected static array $abilities = ['publish' => 'Publish', 'view' => 'not a new one', 'edit' => 'is update', 'Bad-Name' => 'skipped', 'feature'];

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->translatable()->required(),
            TagsInput::make('tags')->translatable()->suggestions(['stone', 'courtyard'])->max(3),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            TagsColumn::make('tags'),
        ])->filters([TagsFilter::make('tags')]);
    }
}
