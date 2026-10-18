<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Repeaters;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{KeyValue, MediaPicker, Repeater, Select, TextInput};
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Table\{Table, Columns\TextColumn};

/** Repeater and key-value fields (0.24): rooms as cards, opening hours as a table, specs. */
class ApartmentResource extends Resource
{
    protected static string $table = 'apartments';
    protected static string $slug = 'apartments';
    protected static ?string $icon = 'building';
    protected static string $label = 'Apartment';
    protected static string $pluralLabel = 'Apartments';

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('details')->label('Details')->schema([
                TextInput::make('title')->translatable()->required()->columnSpan('full'),
                Repeater::make('rooms')->schema([
                    TextInput::make('name')->translatable()->required()->rules('max:60'),
                    TextInput::make('size')->label('Size (m²)')->rules('numeric'),
                    Select::make('floor')->options(['0' => 'Ground', '1' => '1st', '2' => '2nd']),
                    MediaPicker::make('photo'),
                ])->itemLabel('name')->addLabel('Add room')->maxItems(6)->columns(3)->distinct('name'),
                KeyValue::make('specs')->keyLabel('Feature')->valueLabel('Details')->translatableValues(),
                Repeater::make('hours')->label('Opening hours')->schema([
                    Select::make('day')->options(['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday'])->required(),
                    TextInput::make('opens')->placeholder('09:00'),
                    TextInput::make('closes')->placeholder('17:00'),
                ])->table()->addLabel('Add day'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable()]);
    }
}
