<?php
declare(strict_types=1);
namespace FloCMS\Admin\Notifications;

use FloCMS\Admin\Http\PageView;
use FloCMS\Core\TemplateEngine;

final class NotificationView
{
    public static function pending(string $key = 'panel'): string
    {
        return TemplateEngine::renderFile(PageView::viewPath('notifications'), ['notifications' => Notifications::pull(), 'key' => $key]);
    }
}
