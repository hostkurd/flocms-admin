<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\PanelPages;

use FloCMS\Admin\AdminPage;

/** Out of the sidebar and search, with its own permission and a canAccess() rule. */
final class HiddenPage extends AdminPage
{
    protected static string $slug = 'hidden-tools';
    protected static string $label = 'Hidden tools';
    protected static ?string $permission = 'reports.view';
    protected static bool $navigation = false;
    protected static bool $globallySearchable = false;

    public function canAccess(): bool
    {
        return ($_SESSION['user_id'] ?? null) !== 2;
    }

    public function view(): ?string
    {
        return 'admin/getting-started';
    }
}
