<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Wizard;

use FloCMS\Admin\Actions\{Action, ActionContext, BulkAction, BulkActionContext, DeleteAction, EditAction};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{TextInput, Textarea, TimePicker};
use FloCMS\Admin\Form\Layout\{Step, Wizard};
use FloCMS\Admin\Table\Table;

/** The same wizard in a dialog (0.39), plus an action and a bulk action whose forms are wizards. */
class RentalDialogResource extends RentalResource
{
    protected static string $slug = 'rental-dialogs';
    protected static string $formMode = 'modal';
    protected static string $label = 'Rental dialog';
    protected static string $pluralLabel = 'Rental dialogs';

    public function table(Table $table): Table
    {
        return parent::table($table)->actions([
            EditAction::make(), DeleteAction::make(),
            Action::make('schedule')->label('Schedule a viewing')->form(static fn (Form $form): Form => $form->schema([
                Wizard::make('viewing')->schema([
                    Step::make('visitor')->label('Visitor')->schema([TextInput::make('visitor')->required()]),
                    Step::make('time')->label('Date and time')->schema([TimePicker::make('at')->label('Time')->required(), Textarea::make('message')]),
                ]),
            ]))->action(static function (ActionContext $context, array $data): void {
                $context->update(['notes' => 'Viewing with ' . $data['visitor'] . ' at ' . $data['at']]);
            }),
        ])->bulkActions([
            BulkAction::make('reprice')->label('Change prices')->form(static fn (Form $form): Form => $form->schema([
                Wizard::make('reprice')->schema([
                    Step::make('amount')->label('Amount')->schema([TextInput::make('percent')->numeric()->required()]),
                    Step::make('why')->label('Reason')->schema([TextInput::make('reason')->required()]),
                ]),
            ]))->action(static fn (BulkActionContext $context, array $data) => $context->updateAll(['notes' => 'Repriced by ' . $data['percent'] . '%: ' . $data['reason']])),
        ]);
    }
}
