<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Managers;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Relations\RelationManager;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\CountColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** A parent with two relation managers (0.22); estate 3 is locked (canEdit false). */
final class EstateResource extends Resource
{
    protected static string $table = 'estates';
    protected static string $slug = 'estates';
    protected static string $label = 'Estate';
    protected static ?string $icon = 'home';

    public function form(Form $form): Form { return $form->schema([TextInput::make('title')->required()]); }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            CountColumn::make('units')->relation(UnitResource::class, 'estate_id'),
        ]);
    }

    public function canEdit(object $record): bool { return (int) $record->id !== 3; }

    public function relations(): array
    {
        return [
            RelationManager::make(UnitResource::class, 'estate_id'),
            RelationManager::make(EnquiryResource::class, 'estate_id')->label('Enquiries')->readOnly()->columns(['name']),
        ];
    }
}
