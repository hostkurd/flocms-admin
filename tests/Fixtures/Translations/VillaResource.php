<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Translations;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{RichEditor, Select, Textarea, TextInput};
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Relations\RelationManager;
use FloCMS\Admin\Table\{Table, Columns\TextColumn, Columns\TranslationsColumn};

/** Page-level translation (0.23): one language at a time, shared fields locked outside English. */
final class VillaResource extends Resource
{
    protected static string $table = 'villas';
    protected static string $slug = 'villas';
    protected static ?string $icon = 'home';
    protected static string $label = 'Villa';
    protected static string $pluralLabel = 'Villas';
    protected static string $translationMode = 'page';

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('details')->label('Details')->schema([
                TextInput::make('title')->translatable()->required()->rules('max:120')->columnSpan('full'),
                Textarea::make('summary')->translatable()->nullable()->columnSpan('full'),
                RichEditor::make('description')->translatable()->nullable(),
                TextInput::make('price')->nullable()->rules('numeric'),
                TextInput::make('city')->nullable(),
                TextInput::make('seo_title')->label('SEO title')->translatable()->nullable()->rules('max:70'),
            ]),
        ])->aside([
            Section::make('publishing')->label('Publishing')->columns(1)->schema([
                Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->default('draft')->required(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('city'),
            TranslationsColumn::make('translations'),
        ]);
    }

    public function relations(): array
    {
        return [RelationManager::make(RoomResource::class, 'villa_id')->label('Rooms')];
    }
}
