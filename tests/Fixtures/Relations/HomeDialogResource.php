<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Relations;

use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Table\Table;

/** The same homes, edited in a Reactive dialog. */
final class HomeDialogResource extends \FloCMS\Admin\Resource
{
    protected static string $table = 'homes';
    protected static string $slug = 'home-dialogs';
    protected static string $formMode = 'modal';

    public function form(Form $form): Form { return (new HomeResource())->form($form); }
    public function table(Table $table): Table { return (new HomeResource())->table($table); }
}
