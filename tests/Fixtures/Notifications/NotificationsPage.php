<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Notifications;

use FloCMS\Admin\AdminPage;

/** A site's own panel page at /admin/notifications: it keeps the address, so the bell hides (0.41). */
final class NotificationsPage extends AdminPage
{
    protected static string $slug = 'notifications';
}
