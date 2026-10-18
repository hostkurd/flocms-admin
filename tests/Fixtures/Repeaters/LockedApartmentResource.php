<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Repeaters;

use FloCMS\Admin\Form\Form;

/** The apartment form with the rooms list disabled: shown, never written. */
final class LockedApartmentResource extends ApartmentResource
{
    protected static string $slug = 'locked-apartments';

    public function form(Form $form): Form
    {
        $form = parent::form($form);
        $form->fields()['rooms']->disabled();
        return $form;
    }
}
