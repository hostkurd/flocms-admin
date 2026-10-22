<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Choices;

/** The same houses, edited in a dialog. */
final class HouseDialogResource extends HouseResource
{
    protected static string $slug = 'house-dialogs';
    protected static string $label = 'House dialog';
    protected static string $pluralLabel = 'House dialogs';
    protected static string $formMode = 'modal';
}
