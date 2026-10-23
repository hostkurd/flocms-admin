<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Tables;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{BelongsTo, TextInput, Toggle};
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Table\Columns\{TextColumn, TextInputColumn, ToggleColumn};
use FloCMS\Admin\Table\Summaries\{Average, Count, Sum};

/** The same rooms in a read-only relation manager: values, no forms (0.38). */
final class RoomOverviewResource extends Resource
{
    protected static string $table = 'suites';
    protected static string $slug = 'suite-overview';
    protected static string $label = 'Room';
    protected static ?string $icon = 'box';
    protected static bool $navigation = false;

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('rent')->numeric()->rules('min:0'),
            Toggle::make('available'),
            BelongsTo::make('building_id')->resource(BuildingResource::class)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextInputColumn::make('rent')->numeric(0)->prefix('€ ')->summarize([Sum::make()->prefix('€ '), Average::make()->decimals(0)->prefix('€ ')]),
            ToggleColumn::make('available')->summarize([Count::make()->where(1)->label('Available')]),
        ])->defaultSort('name');
    }
}
