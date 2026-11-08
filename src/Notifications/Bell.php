<?php
declare(strict_types=1);

namespace FloCMS\Admin\Notifications;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Http\PageView;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Core\TemplateEngine;

/**
 * The bell in the top bar (0.41): a menu like the language menu, so it opens
 * without JavaScript. The count is read on every panel page; with JavaScript
 * the open panel asks again every 'poll' seconds.
 *
 * @internal
 */
final class Bell
{
    /** The whole bell for the signed-in person, or '' when there is none. */
    public static function html(string $back): string
    {
        try {
            if (!Inbox::enabled() || ($user = Actor::id()) === null || !self::reachable()) { return ''; }
            $count = Inbox::unreadCount($user);
            return TemplateEngine::renderFile(PageView::viewPath('bell'), [
                't' => PageView::translator(),
                'label' => self::label($count),
                'badge' => Inbox::badge($count),
                'icon' => Icons::svg('bell'),
                'menu' => self::menu($user, $count, $back),
                'poll' => Inbox::poll(),
                'pollUrl' => Admin::url('notifications/poll'),
            ]);
        } catch (\Throwable $error) {
            \FloCMS\Admin\Support\Log::error('The notification bell could not be shown: ' . $error->getMessage());
            return '';
        }
    }

    /** Whether /admin/notifications is the bell's (a site resource, panel page or controller with that name keeps it). */
    public static function reachable(): bool
    {
        return Admin::registry()->find('notifications') === null
            && Admin::panelPages()->find('notifications') === null
            && !class_exists(\FloCMS\Admin\Support\Str::appControllerClass('notifications'));
    }

    /** The menu's content: header, the latest notifications, See all. */
    public static function menu(int $user, int $count, string $back): string
    {
        return TemplateEngine::renderFile(PageView::viewPath('bell-menu'), [
            't' => PageView::translator(),
            'items' => Inbox::latest($user),
            'count' => $count,
            'countText' => AdminLang::get('notifications.unread_count', ['count' => $count]),
            'clearUrl' => Admin::url('notifications/clear'),
            'allUrl' => Admin::url('notifications'),
            'back' => $back,
            'csrf' => \FloCMS\Core\Csrf::field(),
            'checksIcon' => Icons::svg('check-check', 'fa-icon fa-icon-sm'),
            'emptyIcon' => Icons::svg('check'),
        ]);
    }

    /** "Notifications" or "Notifications, 3 unread", for screen readers. */
    public static function label(int $count): string
    {
        return $count > 0 ? AdminLang::get('notifications.bell_unread', ['count' => $count]) : AdminLang::get('notifications.title');
    }
}
