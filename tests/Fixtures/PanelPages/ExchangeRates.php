<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\PanelPages;

use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Core\ValidationException;

/** A tool page: a form with Save. */
final class ExchangeRates extends AdminPage
{
    protected static string $label = 'Exchange rates';
    protected static ?string $icon = 'settings';
    protected static ?string $group = 'Tools';
    protected static int $sort = 20;
    protected static string $width = 'narrow';
    protected static string $submitLabel = 'Save rates';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('rate')->numeric()->required(),
            TextInput::make('note')->translatable(),
        ]);
    }

    public function fill(): array
    {
        return ['rate' => $_SESSION['rate'] ?? '1460', 'note' => ['en' => 'Daily']];
    }

    public function submit(array $data): ?string
    {
        if ((float) $data['rate'] <= 0) {
            throw new ValidationException(['rate' => ['Enter a rate above zero.']]);
        }
        $_SESSION['rate'] = $data['rate'];
        $_SESSION['note'] = $data['note'];
        return 'Rates saved.';
    }

    public function badge(): string|int|null
    {
        return 'new';
    }
}
