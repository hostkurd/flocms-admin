<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Builder;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{Builder, TextInput};
use FloCMS\Admin\Table\{Table, Columns\TextColumn};

/** The Builder field (0.29): a landing page with one list of blocks per language. */
class LandingResource extends Resource
{
    protected static string $table = 'landings';
    protected static string $slug = 'landings';
    protected static ?string $icon = 'file';
    protected static string $label = 'Landing page';
    protected static string $pluralLabel = 'Landing pages';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->translatable()->required()->columnSpan('full'),
            Builder::make('content')->label('Page content')->translatable()->maxBlocks(30),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable()]);
    }
}
