<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Wizard;

/** The rental wizard with page-level translation (0.23 + 0.39). */
class RentalPageModeResource extends RentalResource
{
    protected static string $slug = 'rental-pages';
    protected static string $translationMode = 'page';
}
