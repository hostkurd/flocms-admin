<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Relations;

use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\BelongsToMany;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\RelationColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Filters\RelationFilter;
use FloCMS\Admin\Table\Table;

/** A home with a country, a city of that country, an agent (no resource) and features (pivot). */
final class HomeResource extends Resource
{
    protected static string $table = 'homes';
    protected static string $slug = 'homes';
    protected static string $formMode = 'page';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required(),
            BelongsTo::make('country_id')->label('Country')->resource(CountryResource::class)->required(),
            BelongsTo::make('city_id')->label('City')->resource(CityResource::class)->dependsOn('country_id')->required(),
            BelongsTo::make('agent_id')->label('Agent')->table('agents', 'full_name'),
            BelongsToMany::make('features')->resource(FeatureResource::class)->pivot('home_feature', 'home_id', 'feature_id')->orderColumn('sort')->max(3),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            RelationColumn::make('city_id')->label('City')->resource(CityResource::class)->sortable(),
            RelationColumn::make('features')->resource(FeatureResource::class)->pivot('home_feature', 'home_id', 'feature_id')->orderColumn('sort')->limit(2)->toggleable(),
        ])->filters([
            RelationFilter::make('city_id')->label('City')->resource(CityResource::class),
            RelationFilter::make('features')->resource(FeatureResource::class)->pivot('home_feature', 'home_id', 'feature_id'),
        ])->defaultSort('id', 'asc');
    }
}
