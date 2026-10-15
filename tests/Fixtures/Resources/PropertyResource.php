<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Resources;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{TextInput, Textarea, Select, Date};
use FloCMS\Admin\Form\Layout\{Section, Grid, Tabs, Tab};
use FloCMS\Admin\Table\{Table, Columns\TextColumn};

/** Runnable example using only public Forms APIs. */
final class PropertyResource extends Resource
{
    protected static string $table = 'properties';
    protected static string $slug = 'properties';
    protected static string $label = 'Property';
    protected static string $pluralLabel = 'Properties';
    public function tableActionsForm(Form $form): Form
    {
        return $form->schema([Section::make('address')->schema([
            Select::make('country')->options(['iq' => 'Iraq', 'gr' => 'Greece'])->required(),
            Select::make('city')->optionsFor('country', static fn (string $country): array => match ($country) {
                'iq' => ['sulaimani' => 'Sulaymaniyah', 'erbil' => 'Erbil'], 'gr' => ['athens' => 'Athens'], default => [],
            })->required(),
        ])]);
    }
    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('property')->label('Property editor')->schema([
                Tab::make('details')->label('Details')->schema([
                    Section::make('content')->label('Property details')->description('Describe the property in your site languages.')->schema([
                        TextInput::make('title')->translatable()->required()->rules('max:190')->columnSpan('full'),
                        Textarea::make('description')->translatable()->rules('max:100000')->columnSpan('full'),
                    ]),
                ]),
                Tab::make('location')->label('Location')->schema([
                    Grid::make('address')->columns(2)->schema([
                        Select::make('country')->options(['iq' => 'Iraq', 'gr' => 'Greece'])->default('iq')->required(),
                        Select::make('city')->optionsFor('country', static fn (string $country): array => match ($country) {
                            'iq' => ['sulaimani' => 'Sulaymaniyah', 'erbil' => 'Erbil'], 'gr' => ['athens' => 'Athens', 'patras' => 'Patras'], default => [],
                        })->required(),
                    ]),
                ]),
                Tab::make('pricing')->label('Pricing')->columns(3)->schema([
                    Select::make('listing_type')->options(['sale' => 'Sale', 'rent' => 'Rent'])->default('sale')->required(),
                    TextInput::make('price')->numeric()->nullable()->visibleWhen('listing_type', 'sale')->requiredWhen('listing_type', 'sale')->rules('min:0'),
                    TextInput::make('lease_months')->numeric()->nullable()->visibleWhen('listing_type', 'rent')->requiredWhen('listing_type', 'rent')->rules('integer|min:1|max:120'),
                    TextInput::make('access_code')->password()->columnSpan('full'),
                ]),
                Tab::make('publishing')->label('Publishing')->schema([
                    Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->default('draft')->required(),
                    Date::make('published_on')->nullable()->visibleWhen('status', 'published')->requiredWhen('status', 'published'),
                ]),
            ]),
        ]);
    }
    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable()->sortable(), TextColumn::make('country'), TextColumn::make('city')])->defaultSort('id', 'desc')->actions([
            \FloCMS\Admin\Actions\EditAction::make(), \FloCMS\Admin\Actions\DeleteAction::make(),
            \FloCMS\Admin\Actions\Action::make('relocate')->form(fn (Form $form): Form => $this->tableActionsForm($form))
                ->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
                ->action(static fn (\FloCMS\Admin\Actions\ActionContext $context, array $data) => $context->update($data)),
        ])->bulkActions([
            \FloCMS\Admin\Actions\BulkAction::make('relocate')->form(fn (Form $form): Form => $this->tableActionsForm($form))
                ->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
                ->action(static fn (\FloCMS\Admin\Actions\BulkActionContext $context, array $data) => $context->updateAll($data)),
        ]);
    }
}
