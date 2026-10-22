<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Choices;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{CheckboxList, Repeater, TagsInput, TextInput, TimePicker};
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Table\{Table, Columns\TagsColumn, Columns\TextColumn, Columns\TimeColumn};
use FloCMS\Admin\Table\Filters\{CheckboxListFilter, TagsFilter, TimeRangeFilter};

/** CheckboxList, TagsInput and TimePicker (0.37) on one resource. */
class HouseResource extends Resource
{
    protected static string $table = 'houses';
    protected static string $slug = 'houses';
    protected static ?string $icon = 'home';
    protected static string $label = 'House';
    protected static string $pluralLabel = 'Houses';

    public const FEATURES = ['pool' => 'Pool', 'parking' => 'Parking', 'sea_view' => 'Sea view', 'garden' => 'Garden', 'lift' => 'Lift',
        'air' => 'Air conditioning', 'fireplace' => 'Fireplace', 'balcony' => 'Balcony', 'furnished' => 'Furnished'];

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('details')->label('Features and viewings')->schema([
                TextInput::make('title')->required()->columnSpan('full'),
                CheckboxList::make('features')->options(self::FEATURES)->columns(3)->selectAll()->searchable()->max(5)->columnSpan('full')
                    ->help('Choose up to 5.'),
                TagsInput::make('keywords')->suggestions(['villa', 'sea view', 'parking', 'park nearby', 'garden'])->max(4)->columnSpan('full'),
                TimePicker::make('opens_at')->label('Viewings from')->minTime('08:00')->maxTime('20:00')->step(15),
                TimePicker::make('closes_at')->label('Viewings until')->minTime('08:00')->maxTime('20:00')->step(15),
                Repeater::make('slots')->label('Open days')->schema([
                    CheckboxList::make('days')->options(['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday']),
                    TimePicker::make('at')->label('At'),
                    TagsInput::make('notes'),
                ])->addLabel('Add day')->columnSpan('full'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            TagsColumn::make('features')->options(self::FEATURES),
            TagsColumn::make('keywords')->searchable(),
            TimeColumn::make('opens_at')->label('Viewings from')->sortable(),
        ])->filters([
            CheckboxListFilter::make('features')->options(self::FEATURES),
            CheckboxListFilter::make('features_any')->column('features')->label('Any of')->options(self::FEATURES)->multiple(),
            TagsFilter::make('keywords')->label('Keyword')->suggestions(['villa', 'sea view']),
            TimeRangeFilter::make('opens_at')->label('Viewings start'),
        ]);
    }
}
