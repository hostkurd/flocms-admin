<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Tables;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{BelongsTo, Select, TextInput, Toggle};
use FloCMS\Admin\Table\{Table, Group};
use FloCMS\Admin\Table\Columns\{RelationColumn, SelectColumn, TextColumn, TextInputColumn, ToggleColumn, CountColumn, DateColumn};
use FloCMS\Admin\Table\Summaries\{Average, Count, Max, Min, Sum};
use FloCMS\Admin\Table\Filters\SelectFilter;

/** Editable cells, totals and groups (0.38). */
class OfferResource extends Resource
{
    protected static string $table = 'offers';
    protected static string $slug = 'offers';
    protected static ?string $icon = 'home';
    protected static string $label = 'Offer';
    protected static string $pluralLabel = 'Offers';
    protected static bool $timestamps = true;

    public const STATUSES = ['published' => 'Published', 'draft' => 'Draft', 'sold' => 'Sold'];

    /** Records ids passed to afterSave(), to check the hooks run (written to a file by the request process). */
    public static ?string $hookLog = null;

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required()->maxLength(120)->unique(),
            BelongsTo::make('city_id')->label('City')->table('cities', 'name', 'id', true),
            Select::make('status')->options(self::STATUSES)->required(),
            TextInput::make('price')->numeric()->rules('min:0'),
            TextInput::make('stock')->numeric()->rules('integer|min:0'),
            Toggle::make('featured'),
            TextInput::make('note')->requiredWhen('status', 'sold'),
        ]);
    }

    public function beforeSave(array $data, ?object $record): array
    {
        if (($data['status'] ?? null) === 'sold' && $record !== null) { $data['note'] = $data['note'] ?? ($record->note ?? null); }
        return $data;
    }

    public function afterSave(object $record): void
    {
        $file = getenv('FLO_TEST_HOOK_LOG');
        if (is_string($file) && $file !== '') { file_put_contents($file, 'after:' . $record->id . "\n", FILE_APPEND); }
    }

    public function canEdit(object $record): bool
    {
        return ($record->title ?? '') !== 'Locked flat';
    }

    public function table(Table $table): Table
    {
        // The browser tests page the list (set by tests/Browser/router.php)
        if (($perPage = (int) getenv('FLO_OFFERS_PER_PAGE')) > 0) { $table->perPage($perPage); }
        return $table->columns([
            TextInputColumn::make('title')->searchable()->sortable(),
            RelationColumn::make('city_id')->label('City')->table('cities', 'name', 'id', true),
            SelectColumn::make('status')->colors(['published' => 'green', 'draft' => 'yellow']),
            TextInputColumn::make('price')->numeric(2)->prefix('€ ')->sortable()
                ->summarize([Sum::make()->label('Total')->decimals(2)->prefix('€ '), Average::make()->decimals(0)->prefix('€ ')]),
            TextInputColumn::make('stock')->numeric(0)->summarize([Sum::make(), Min::make(), Max::make()]),
            ToggleColumn::make('featured')->summarize([Count::make()->where(1)->label('Featured')]),
            ToggleColumn::make('published')->label('Live')->values('published', 'draft')
                ->requiresConfirmation(fn (object $r): string => 'Unpublish ' . $r->title . '?', 'Visitors will no longer see it.', 'Unpublish', true)
                ->beforeUpdate(fn (mixed $value, object $r): array => ['published_at' => $value === 'published' ? '2026-10-23 10:00:00' : null]),
            DateColumn::make('created_at')->label('Added')->sortable(),
        ])->defaultSort('id')->groups([
            Group::make('city_id')->label('City'),
            Group::make('status'),
            Group::make('created_at')->date('month')->label('Month added'),
        ])->filters([
            SelectFilter::make('status')->options(self::STATUSES),
        ])->bulkActions([\FloCMS\Admin\Actions\DeleteBulkAction::make()]);
    }
}
