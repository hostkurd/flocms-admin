<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Wizard;

use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Form\Fields\{Select, TextInput};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Layout\{Step, Wizard};

/** A custom page whose form is a wizard (0.39). */
final class MovingIn extends AdminPage
{
    protected static string $label = 'Moving in';
    protected static string $slug = 'moving-in';
    protected static string $submitLabel = 'Send';

    public function form(Form $form): Form
    {
        return $form->schema([
            Wizard::make('moving')->schema([
                Step::make('who')->label('Who')->schema([TextInput::make('tenant')->required()]),
                Step::make('when')->label('When')->schema([Select::make('month')->options(['jan' => 'January', 'feb' => 'February'])->required()]),
            ]),
        ]);
    }

    public function submit(array $data): ?string
    {
        $_SESSION['moving_in'] = $data['tenant'] . '/' . $data['month'];
        return 'Sent.';
    }
}
