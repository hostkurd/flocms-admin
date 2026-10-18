<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Managers;

use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\RelationColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Filters\SelectFilter;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;

/** Managed inside estates only: no sidebar entry; archived units are outside query(). */
final class UnitResource extends Resource
{
    protected static string $table = 'units';
    protected static string $slug = 'units';
    protected static string $label = 'Unit';
    protected static ?string $icon = 'building';
    protected static bool $navigation = false;

    public function query(Database $query): Database { return $query->where('archived', '=', 0); }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('bedrooms')->rules('integer'),
            TextInput::make('floor')->visibleWhen('estate_id', ['1']),
            BelongsTo::make('estate_id')->resource(EstateResource::class)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('bedrooms'),
            RelationColumn::make('estate_id')->resource(EstateResource::class),
        ])->bulkActions([\FloCMS\Admin\Actions\DeleteBulkAction::make()])->filters([SelectFilter::make('estate_id')->options(['1' => 'One', '2' => 'Two'])]);
    }
}
