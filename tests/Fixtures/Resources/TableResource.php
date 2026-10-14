<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Resources;
use FloCMS\Admin\Actions\{BulkAction, BulkActionContext, DeleteBulkAction};
use FloCMS\Admin\Form\{Form, Fields\TextInput};
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Table\{Table, Filters\SelectFilter, Filters\ToggleFilter, Filters\DateRangeFilter};
use FloCMS\Core\{Config, Database};

final class TableResource extends PostResource
{
    public function query(Database $query): Database
    {
        return Config::get('test.exclude_first') ? $query->where('id', '!=', 1) : $query;
    }
    public function table(Table $table): Table
    {
        return parent::table($table)->filters([
            SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            ToggleFilter::make('featured'), DateRangeFilter::make('published_on'),
        ])->bulkActions([
            DeleteBulkAction::make(),
            BulkAction::make('publish')->form(static fn (Form $form): Form => $form->schema([TextInput::make('reason')->required()]))
                ->action(static fn (BulkActionContext $context) => $context->updateAll(['status' => 'published'])),
            BulkAction::make('restricted')->authorize(static fn (?object $record): bool => (bool) $record?->featured)
                ->action(static fn (BulkActionContext $context) => $context->updateAll(['status' => 'draft'])),
            BulkAction::make('fail')->action(static function (BulkActionContext $context): void {
                $context->updateAll(['status' => 'draft']); Notification::make()->title('Must be discarded')->send();
                throw new \RuntimeException('Bulk callback failed');
            }),
        ]);
    }
}
