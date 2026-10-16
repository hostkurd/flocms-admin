<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Resources;
use FloCMS\Admin\{Resource, Form\Form, Form\Fields\TextInput, Form\Fields\FileUpload, Form\Fields\ImageUpload, Form\Fields\Select, Table\Table, Table\Columns\TextColumn};
final class AssetResource extends Resource
{
    protected static string $table = 'assets';
    protected static string $slug = 'assets';
    // The browser tests drive these records in the list dialog.
    protected static string $formMode = 'modal';
    protected static ?string $icon = 'image';
    protected static string $label = 'Asset';
    protected static string $pluralLabel = 'Assets';
    protected static bool $timestamps = false;
    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required(),
            Select::make('kind')->options(['normal'=>'Normal', 'extra'=>'Extra'])->default('normal'),
            ImageUpload::make('cover')->label('Cover image')->maxSize(1024)->dimensions(1600, 1600),
            FileUpload::make('attachment')->label('Document')->acceptedFileTypes(['application/pdf', 'text/plain'])->maxSize(128),
            FileUpload::make('internal')->disabled(),
            FileUpload::make('extra')->visibleWhen('kind', 'extra'),
        ]);
    }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('title')->searchable()])->bulkActions([\FloCMS\Admin\Actions\DeleteBulkAction::make()]); }
    public function afterSave(object $record): void { if ($record->title === 'After rollback') { throw new \RuntimeException('Fixture rollback'); } }
    public function canEdit(object $record): bool { return $record->title !== 'Denied'; }
    public function beforeSave(array $data, ?object $record): array
    {
        if (($data['title'] ?? '') === 'Force rollback') { throw new \RuntimeException('Fixture rollback'); }
        return $data;
    }
}
