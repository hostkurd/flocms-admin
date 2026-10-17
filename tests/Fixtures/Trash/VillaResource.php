<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Trash;

use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\BelongsToMany;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** A record with every kind of value the trash restores and the activity log describes. */
final class VillaResource extends Resource
{
    protected static string $table = 'villas';
    protected static string $slug = 'villas';
    protected static string $label = 'Villa';
    protected static array $activityExcept = ['notes'];

    /** @var list<string> */
    public static array $restored = [];

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->translatable()->required(),
            TextInput::make('slug')->unique()->required(),
            BelongsTo::make('city_id')->label('City')->table('cities', 'name'),
            BelongsToMany::make('tags')->table('tags', 'name')->pivot('villa_tag', 'villa_id', 'tag_id'),
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            Toggle::make('featured'),
            RichEditor::make('body'),
            TextInput::make('owner_phone')->hiddenFromActivity(),
            TextInput::make('notes'),
            TextInput::make('secret')->password(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title'), TextColumn::make('slug')]);
    }

    public function afterRestore(object $record): void
    {
        self::$restored[] = (string) $record->id;
    }
}
