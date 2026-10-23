<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Wizard;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{FileUpload, Select, TextInput, Textarea, Toggle};
use FloCMS\Admin\Form\Layout\{Step, Wizard};
use FloCMS\Admin\Table\{Table, Columns\TextColumn};

/** A wizard form (0.39): a step shown only for rentals, a check after a step, an upload, a review step and an aside field. */
class RentalResource extends Resource
{
    protected static string $table = 'rentals';
    protected static string $slug = 'rentals';
    protected static ?string $icon = 'home';
    protected static string $label = 'Rental';
    protected static string $pluralLabel = 'Rentals';

    public function form(Form $form): Form
    {
        return $form->schema([
            Wizard::make('listing')->schema([
                Step::make('details')->label('Details')->description('Name and type')->schema([
                    TextInput::make('title')->translatable()->required()->rules('max:120'),
                    Select::make('type')->options(['sale' => 'For sale', 'rent' => 'For rent'])->required(),
                ]),
                Step::make('location')->label('Location')->description('City and address')->schema([
                    TextInput::make('city')->required(),
                    TextInput::make('address')->label('Street address'),
                ]),
                Step::make('terms')->label('Rental terms')->visibleWhen('type', 'rent')->schema([
                    TextInput::make('lease_months')->label('Lease (months)')->numeric()->required(),
                ]),
                Step::make('pricing')->label('Pricing')->schema([
                    TextInput::make('price')->numeric()->required(),
                    FileUpload::make('brochure'),
                    Textarea::make('notes'),
                ])->afterValidation(static fn (array $state): array => ($state['type'] ?? '') === 'rent' && (float) ($state['price'] ?? 0) > 50000
                    ? ['price' => 'A monthly rent above 50,000 looks like a sale price.'] : []),
            ])->summary(),
        ])->aside([
            Toggle::make('featured'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title'), TextColumn::make('city'), TextColumn::make('price')]);
    }
}
