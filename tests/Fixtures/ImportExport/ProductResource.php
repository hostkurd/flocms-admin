<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\ImportExport;

use FloCMS\Admin\Form\Fields\{BelongsTo, BelongsToMany, Date, Select, TextInput, Textarea, Toggle};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\ImportExport\{Export, Import, ImportRun};
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\{RelationColumn, TextColumn};
use FloCMS\Admin\Table\Filters\SelectFilter;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Tests\Fixtures\Relations\{CityResource, FeatureResource};

/** CSV export and import (0.25): every kind of field the importer reads, found again by its code. */
final class ProductResource extends Resource
{
    protected static string $table = 'products';
    protected static string $slug = 'products';
    protected static string $formMode = 'page';
    protected static ?string $icon = 'box';
    protected static string $label = 'Product';
    protected static string $pluralLabel = 'Products';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->required()->unique()->rules('max:40'),
            TextInput::make('title')->translatable()->required()->rules('max:120'),
            Textarea::make('summary')->nullable(),
            Select::make('category')->options(['tools' => 'Tools', 'garden' => 'Garden', 'kitchen' => 'Kitchen'])->required(),
            TextInput::make('price')->numeric()->nullable()->rules('min:0')->importAliases(['Preis', 'السعر']),
            Toggle::make('in_stock')->label('In stock'),
            BelongsTo::make('city_id')->label('City')->resource(CityResource::class)->nullable(),
            BelongsToMany::make('features')->resource(FeatureResource::class)->pivot('product_feature', 'product_id', 'feature_id'),
            Date::make('released_on')->label('Released')->nullable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('title')->searchable(),
            TextColumn::make('category'),
            TextColumn::make('price')->sortable(),
            RelationColumn::make('city_id')->label('City')->resource(CityResource::class),
        ])->filters([
            SelectFilter::make('category')->options(['tools' => 'Tools', 'garden' => 'Garden', 'kitchen' => 'Kitchen']),
        ])->defaultSort('id', 'asc');
    }

    /** @var list<int> the runs afterImport() saw (tests) */
    public static array $finished = [];

    public function export(Export $export): Export
    {
        return $export->except(['released_on'])->column('label', static fn (object $row): string => 'Code ' . $row->code, 'Label');
    }

    public function import(Import $import): Import
    {
        return $import->key('code')
            ->defaults(['summary' => 'Imported'])
            ->beforeRow(static fn (array $row): array => ['code' => strtoupper(trim((string) ($row['code'] ?? '')))] + $row)
            ->afterImport(static function (ImportRun $run): void { self::$finished[] = $run->id; });
    }
}
