<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Repeaters;

/** The same apartments, edited in a dialog with page-level translation. */
final class ApartmentDialogResource extends ApartmentResource
{
    protected static string $slug = 'apartment-dialogs';
    protected static string $label = 'Apartment dialog';
    protected static string $pluralLabel = 'Apartment dialogs';
    protected static string $formMode = 'modal';
    protected static string $translationMode = 'page';
}
