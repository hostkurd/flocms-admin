<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Notifications\Bell;
use FloCMS\Admin\Notifications\Inbox;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Notifications\Target;
use FloCMS\Admin\Support\Actor;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Core\HttpException;
use FloCMS\Core\Http\Response;
use FloCMS\Core\TemplateEngine;

/**
 * The notification bell's page and links (0.41), for every signed-in panel
 * user, each seeing only their own notifications:
 *
 * - /admin/notifications (?show=unread, ?page=2): the full list;
 * - /admin/notifications/open/<id>: marks it read, then opens its page;
 * - POST read/<id>, delete/<id>, clear (mark all read): back to where they were;
 * - /admin/notifications/poll: the count and the menu, as JSON, for the open panel.
 */
final class NotificationsController extends PanelController
{
    public const PER_PAGE = 25;

    protected array $actionPermissions = ['*' => null];

    public function admin_index(): string|Response
    {
        $user = $this->user();
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $unreadOnly = ($_GET['show'] ?? '') === 'unread';
        $page = max(1, min(100000, (int) (is_string($_GET['page'] ?? null) ? $_GET['page'] : '1')));
        $result = Inbox::page($user, $unreadOnly, $page, self::PER_PAGE);
        $last = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        $unread = Inbox::unreadCount($user);
        $all = $unreadOnly ? Inbox::page($user, false, 1, 1)['total'] : $result['total'];
        $query = $unreadOnly ? ['show' => 'unread'] : [];
        $total = $result['total'];
        $from = $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1;
        $html = TemplateEngine::renderFile(PageView::viewPath('inbox'), [
            't' => PageView::translator(),
            'title' => AdminLang::get('notifications.title'),
            'items' => $result['items'],
            'unreadOnly' => $unreadOnly,
            'tabs' => [
                ['label' => AdminLang::get('notifications.tab_all'), 'count' => $all, 'url' => Admin::url('notifications'), 'current' => !$unreadOnly],
                ['label' => AdminLang::get('notifications.tab_unread'), 'count' => $unread, 'url' => Admin::url('notifications', ['show' => 'unread']), 'current' => $unreadOnly],
            ],
            'unread' => $unread,
            'clearUrl' => Admin::url('notifications/clear'),
            'back' => Admin::url('notifications', $query + ['page' => $page > 1 ? $page : null]),
            'csrf' => \FloCMS\Core\Csrf::field(),
            'summary' => $total === 0 ? '' : AdminLang::get('table.summary', ['from' => $from, 'to' => min($total, $page * self::PER_PAGE), 'total' => $total]),
            'pages' => $last <= 1 ? [] : array_map(static fn (?int $n): array => $n === null
                ? ['label' => '…', 'url' => null, 'current' => false]
                : ['label' => (string) $n, 'url' => $n === $page ? null : Admin::url('notifications', $query + ['page' => $n > 1 ? $n : null]), 'current' => $n === $page], PageView::pageNumbers($page, $last)),
            'kept' => Inbox::days() > 0 ? AdminLang::get('notifications.kept_days', ['days' => Inbox::days()]) : AdminLang::get('notifications.kept_forever'),
            'checksIcon' => Icons::svg('check-check'),
            'checkIcon' => Icons::svg('check'),
            'deleteIcon' => Icons::svg('trash'),
            'emptyIcon' => Icons::svg('bell'),
        ]);
        return $this->panel(AdminLang::get('notifications.title'), $html, 'notifications');
    }

    /** Open a notification: it becomes read, then its page opens (or the list, when it has none). */
    public function admin_open(): never
    {
        $user = $this->user();
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $row = Inbox::find($this->id(), $user) ?? throw new HttpException(404);
        if ($this->request->method() === 'GET') { Inbox::markRead((int) $row->id, $user); }
        $this->redirect(Target::resolve(is_string($row->url) ? $row->url : null) ?? Admin::url('notifications'));
    }

    public function admin_read(): never
    {
        $user = $this->postUser();
        if (!Inbox::markRead($this->id(), $user)) { throw new HttpException(404); }
        $this->redirect($this->back());
    }

    public function admin_delete(): never
    {
        $user = $this->postUser();
        if (!Inbox::delete($this->id(), $user)) { throw new HttpException(404); }
        Notification::make()->title(AdminLang::get('notifications.deleted'))->success()->send();
        $this->redirect($this->back());
    }

    /** Mark all as read. */
    public function admin_clear(): never
    {
        $user = $this->postUser();
        if (Inbox::markAllRead($user) > 0) {
            Notification::make()->title(AdminLang::get('notifications.all_read'))->success()->send();
        }
        $this->redirect($this->back());
    }

    /** The count and the bell's menu for an open panel (JSON); never cached. */
    public function admin_poll(): Response
    {
        $user = $this->user();
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $count = Inbox::unreadCount($user);
        return Response::json([
            'count' => $count,
            'badge' => Inbox::badge($count),
            'label' => Bell::label($count),
            'menu' => Bell::menu($user, $count, ''),
        ])->header('Cache-Control', 'no-store');
    }

    /** The signed-in person; 404 while the bell is off or its table is missing. */
    private function user(): int
    {
        if (!Inbox::enabled()) { throw new HttpException(404); }
        $id = Actor::id();
        if ($id === null) { throw new HttpException(403); }
        return $id;
    }

    private function postUser(): int
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        return $this->user();
    }

    private function id(): int
    {
        $id = (string) ($this->params[0] ?? '');
        if (!ctype_digit($id) || strlen($id) > 18) { throw new HttpException(404); }
        return (int) $id;
    }

    /** Back to the panel page the person was on (only a panel URL of this site), else the list. */
    private function back(): string
    {
        $back = $this->request->input('_back');
        $base = Admin::url('');
        if (is_string($back) && $back !== '') {
            $path = (string) parse_url($back, PHP_URL_PATH);
            $query = parse_url($back, PHP_URL_QUERY);
            $basePath = (string) parse_url($base, PHP_URL_PATH);
            $query = is_string($query) && preg_match('/^[^\x00-\x20\x7F"<>\\\\]*$/D', $query) === 1 ? $query : null;
            if (($path === $basePath || str_starts_with($path, $basePath . '/')) && !str_contains($path, '..') && preg_match('~^[A-Za-z0-9/_\-.%]*$~D', $path) === 1) {
                return (preg_match('~^https?://[^/]+~i', $base, $origin) === 1 ? $origin[0] : '') . $path . (is_string($query) && $query !== '' ? '?' . $query : '');
            }
        }
        return Admin::url('notifications');
    }
}
