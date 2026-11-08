<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Duplicate;

use FloCMS\Admin\Actions\CreateAction;
use FloCMS\Admin\Actions\DeleteAction;
use FloCMS\Admin\Actions\DuplicateAction;
use FloCMS\Admin\Actions\EditAction;
use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\BelongsToMany;
use FloCMS\Admin\Form\Fields\Date;
use FloCMS\Admin\Form\Fields\FileUpload;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\Slug;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Relations\RelationManager;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/**
 * Duplicate (0.40): a lot with every kind of field, plans as relation-manager
 * children, and LOT_DUPLICATE in the environment choosing the action's options.
 * A lot titled "Secret" can't be viewed; LOT_NO_CREATE=1 refuses creating;
 * LOT_CREATE changes the Create action.
 */
final class LotResource extends Resource
{
    protected static string $table = 'lots';
    protected static string $slug = 'lots';
    protected static string $label = 'Lot';
    protected static string $pluralLabel = 'Lots';
    protected static ?string $icon = 'home';
    protected static string $formMode = 'modal';
    protected static bool $viewPage = true;
    protected static bool $trash = false;

    public function form(Form $form): Form
    {
        return $form->schema([
            // LOT_ID=1 shows the primary key; LOT_SLUG=translatable one slug per language
            ...(getenv('LOT_ID') === '1' ? [TextInput::make('id')->label('ID')] : []),
            TextInput::make('title')->translatable()->required(),
            getenv('LOT_SLUG') === 'translatable' ? TranslatableSlug::make('slug')->from('title')->translatable()->unique() : Slug::make('slug')->from('title')->unique(),
            TextInput::make('code')->label('Code')->unique(),
            TextInput::make('price')->label('Price')->rules('numeric'),
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->default('draft'),
            Toggle::make('featured'),
            Date::make('listed_on'),
            BelongsTo::make('city_id')->label('City')->table('lot_cities', 'name'),
            BelongsToMany::make('features')->table('lot_features', 'name')->pivot('lot_feature', 'lot_id', 'feature_id'),
            MediaPicker::make('cover_id')->label('Cover'),
            Repeater::make('rooms')->schema([TextInput::make('name')]),
            RichEditor::make('body')->translatable(),
            FileUpload::make('brochure')->label('Brochure')->acceptedFileTypes(['text/plain'])->maxSize(64),
            FileUpload::make('plan')->label('Plan')->acceptedFileTypes(['text/plain'])->maxSize(1),
            TextInput::make('secret')->label('Secret')->password(false),
            TextInput::make('note')->label('Note')->disabled()->default('fresh'),
        ]);
    }

    public function table(Table $table): Table
    {
        $duplicate = DuplicateAction::make();
        match ((string) getenv('LOT_DUPLICATE')) {
            'except' => $duplicate->except(['price', 'features']),
            'only' => $duplicate->only(['title', 'status']),
            'fill' => $duplicate->fill(static fn (array $state, object $source): array => ['price' => '999', 'title' => ['en' => 'Copy of ' . json_decode($source->title, true)['en']]] + $state),
            default => null,
        };
        return $table->columns([TextColumn::make('title')->searchable()])
            ->actions(getenv('LOT_DUPLICATE') === 'none' ? [EditAction::make(), DeleteAction::make()] : [EditAction::make(), $duplicate, DeleteAction::make()]);
    }

    /** LOT_CREATE: none (no Create), hidden (visible() false), disabled, or permission (its own lots.publish). */
    public function headerActions(): array
    {
        $create = CreateAction::make();
        return match ((string) getenv('LOT_CREATE')) {
            'none' => [],
            'hidden' => [$create->visible(static fn (): bool => false)],
            'disabled' => [$create->disabled(static fn (): string => 'Closed')],
            'permission' => [$create->permission('lots.publish')],
            default => [$create],
        };
    }

    public function canCreate(): bool { return getenv('LOT_NO_CREATE') !== '1'; }
    public function canView(object $record): bool { return !str_contains((string) $record->title, 'Secret'); }

    public function relations(): array
    {
        return [RelationManager::make(LotPlanResource::class, 'lot_id')];
    }
}
