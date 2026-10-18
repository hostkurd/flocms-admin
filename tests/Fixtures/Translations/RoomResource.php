<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Translations;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Table\{Table, Columns\TextColumn};

/** A villa's rooms, edited in a dialog with its own language switch. */
final class RoomResource extends Resource
{
    protected static string $table = 'rooms';
    protected static string $slug = 'rooms';
    protected static string $label = 'Room';
    protected static ?string $icon = 'building';
    protected static bool $navigation = false;
    protected static string $formMode = 'modal';
    protected static string $translationMode = 'page';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->translatable()->required()->rules('max:60')->columnSpan('full'),
            TextInput::make('size')->label('Size (m²)')->nullable()->rules('integer'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('size')]);
    }
}
