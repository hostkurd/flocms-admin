<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Actions;

use FloCMS\Admin\Actions\{Action, ActionContext, CreateAction, EditAction, DeleteAction};
use FloCMS\Admin\Form\{Form, Fields\TextInput};
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Table\Filters\{SelectFilter, ToggleFilter, DateRangeFilter};
use FloCMS\Admin\Actions\{BulkAction, BulkActionContext, DeleteBulkAction};
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;

/** Working publish/unpublish example, using only public Admin APIs. */
class PostActionResource extends PostResource
{
    protected static ?string $description = 'Articles shown on the website, in English, Arabic and Kurdish.';
    public function headerActions(): array
    {
        return [CreateAction::make(), Action::make('announce')->label('demo.announce')->icon('mail')
            ->action(static function (ActionContext $context, array $data): void {
                Notification::make()->title('demo.ready')->warning()->send();
            })];
    }
    public function table(Table $table): Table
    {
        return parent::table($table)->actions([
            EditAction::make(), DeleteAction::make(),
            Action::make('publish')->label('demo.publish')->icon('file')->color('success')
                ->visible(static fn (?object $record): bool => $record?->status === 'draft')
                ->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
                ->form(static fn (Form $form): Form => $form->schema([TextInput::make('reason')->label('demo.reason')->rules('required|max:60')]))
                ->normalizeState(static function (array $state, ?object $record): array { $state['reason'] = trim($state['reason']); return $state; })
                ->action(static function (ActionContext $context, array $data): void {
                    $context->update(['status' => 'published']);
                    // Same-connection audit work is transactional with the update.
                    \FloCMS\Core\App::db()->table('tags')->insert(['name' => 'Published: ' . $data['reason']]);
                    Notification::make()->title('demo.published')->body($data['reason'])->success()->persistent()->send();
                }),
            Action::make('unpublish')->label('demo.unpublish')->color('warning')
                ->visible(static fn (?object $record): bool => $record?->status === 'published')
                ->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
                ->action(static function (ActionContext $context, array $data): void {
                    $context->update(['status' => 'draft']);
                    Notification::make()->title('demo.unpublished')->warning()->send();
                }),
        ])->headerActions([
            Action::make('help')->label('demo.help')->icon('file')->requiresConfirmation(false)
                ->action(static function (ActionContext $context, array $data): void {
                    Notification::make()->title('demo.help_title')->body('demo.help_body')->info()->send();
                }),
        ])->filters([
            SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            ToggleFilter::make('featured'), DateRangeFilter::make('published_on'),
        ])->bulkActions([
            DeleteBulkAction::make(),
            BulkAction::make('publish')->label('demo.publish')->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
                ->form(static fn (Form $form): Form => $form->schema([TextInput::make('reason')->label('demo.reason')->rules('required|max:60')]))
                ->action(static fn (BulkActionContext $context) => $context->updateAll(['status' => 'published'])),
            BulkAction::make('unpublish')->label('demo.unpublish')->color('warning')
                ->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
                ->action(static fn (BulkActionContext $context) => $context->updateAll(['status' => 'draft'])),
        ]);
    }
}
