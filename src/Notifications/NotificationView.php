<?php
declare(strict_types=1);
namespace FloCMS\Admin\Notifications;

use FloCMS\Admin\Http\PageView;
use FloCMS\Core\TemplateEngine;

final class NotificationView
{
    public static function pending(string $key = 'panel'): string
    {
        $notifications = array_map(static function (array $notification): array {
            // Entries queued before 0.15.2 have no duration
            $notification['duration'] = is_int($notification['duration'] ?? null) ? $notification['duration'] : Notification::defaultDuration((string) ($notification['level'] ?? 'info'));
            return $notification;
        }, Notifications::pull());
        return TemplateEngine::renderFile(PageView::viewPath('notifications'), ['notifications' => $notifications, 'key' => $key]);
    }
}
