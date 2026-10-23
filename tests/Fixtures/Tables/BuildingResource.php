<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Tables;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Relations\RelationManager;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Table\Columns\TextColumn;

/** A parent whose rooms are edited in their cells, and a second, read-only view of them (0.38). */
final class BuildingResource extends Resource
{
    protected static string $table = 'buildings';
    protected static string $slug = 'buildings';
    protected static string $label = 'Building';
    protected static ?string $icon = 'building';

    public function form(Form $form): Form { return $form->schema([TextInput::make('name')->required()]); }

    public function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()]); }

    public function relations(): array
    {
        return [
            RelationManager::make(RoomResource::class, 'building_id'),
            RelationManager::make(RoomOverviewResource::class, 'building_id')->label('Overview')->readOnly(),
        ];
    }
}
