<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\PanelPages;

use FloCMS\Admin\AdminPage;

/** A help page with its own view, under Content (no group), for everyone with panel.* or its permission. */
final class GettingStarted extends AdminPage
{
    protected static ?string $description = 'First steps with the panel';

    public function view(): ?string
    {
        return 'admin/getting-started';
    }

    public function data(array $filters): array
    {
        return ['steps' => ['Add your logo', 'Invite your team']];
    }
}
