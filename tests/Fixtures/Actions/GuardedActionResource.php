<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Actions;

use FloCMS\Admin\Actions\{Action, ActionContext};
use FloCMS\Admin\Form\{Form, Fields\TextInput, Fields\Placeholder};
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\{App, Database, ValidationException};

/** Security/failure fixtures are intentionally separate from the working example. */
final class GuardedActionResource extends PostActionResource
{
    public function query(Database $query): Database { return $query->whereRaw('COALESCE(views, 0) >= ?', [0]); }
    public function table(Table $table): Table
    {
        $table = parent::table($table);
        return $table->actions([...$table->getActions(),
            Action::make('hidden')->visible(static fn (?object $record): bool => false)
                ->action(static function (): void { throw new \RuntimeException('Hidden callback ran'); }),
            Action::make('restricted')->authorize(static fn (?object $record): bool => (bool) $record?->featured)
                ->action(static function (ActionContext $context): void { $context->update(['slug' => 'restricted']); }),
            Action::make('fail')->action(static function (ActionContext $context): void {
                $context->update(['slug' => 'failed']); App::db()->table('tags')->insert(['name' => 'must roll back']);
                Notification::make()->title('False success')->success()->send(); throw new \RuntimeException('action failed');
            }),
            Action::make('check')->form(static fn (Form $form): Form => $form->schema([
                TextInput::make('note')->rules('required|max:20'), TextInput::make('password')->password(false)->rules('min:8'),
                Placeholder::make('stored')->content(static fn (?object $record): string => 'Stored: ' . ($record?->slug ?? 'MISSING')),
            ]))->validate(static fn (array $state): array => $state['note'] === 'blocked' ? ['note' => 'Business validation failed.'] : [])
                ->action(static function (ActionContext $context): void { $context->update(['slug' => 'checked']); }),
            Action::make('expand')->form(static fn (Form $form): Form => $form->schema([TextInput::make('note')]))
                ->normalizeState(static fn (array $state): array => ['note' => str_repeat('x', 65000)])
                ->action(static function (): void { throw new \RuntimeException('Expanded callback ran'); }),
            Action::make('reject')->action(static function (): void {
                Notification::make()->title('False validation success')->success()->send();
                throw new ValidationException(['_form' => 'Not ready.']);
            }),
        ]);
    }
}
