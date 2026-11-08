<?php
declare(strict_types=1);

namespace FloCMS\Admin\Notifications;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Core\App;
use FloCMS\Core\Database;
use FloCMS\Core\Lang;

/**
 * The notifications kept for each person (0.41): the top bar's bell, its unread
 * count and /admin/notifications. Rows are written by Notification::sendTo().
 *
 * On when config/admin.php does not switch it off and its table exists
 * (php flo admin:install, php flo migrate). Reads only ever return the
 * signed-in person's own rows: every method takes their user id.
 */
final class Inbox
{
    /** Notifications in the bell's menu. */
    public const MENU_SIZE = 8;
    /** Rows written by one sendTo() at most (one per person). */
    public const MAX_RECIPIENTS = 5000;

    private static ?string $status = null;
    private static ?Database $statusFor = null;

    /** 'off' (config), 'missing' (no table yet) or 'ready'. */
    public static function status(?Database $db = null): string
    {
        if (!Admin::config()->notifications()['enabled']) { return 'off'; }
        $db ??= App::db();
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            try {
                $db->query('SELECT `id`, `user_id`, `level`, `icon`, `title`, `body`, `params`, `url`, `read_at`, `created_at` FROM `' . self::table() . '` WHERE 1 = 0');
                self::$status = 'ready';
            } catch (\Throwable) {
                self::$status = 'missing';
            }
        }
        return self::$status;
    }

    public static function enabled(?Database $db = null): bool
    {
        return self::status($db) === 'ready';
    }

    public static function reset(): void
    {
        self::$status = null;
        self::$statusFor = null;
    }

    public static function table(): string { return Admin::config()->notifications()['table']; }

    /** Days notifications are kept; 0 = forever. */
    public static function days(): int { return Admin::config()->notifications()['days']; }

    /** Seconds between count checks in an open panel; 0 = none. */
    public static function poll(): int { return Admin::config()->notifications()['poll']; }

    /**
     * Store one notification for each user id; returns how many rows were written.
     *
     * @param list<int> $userIds
     * @param array{level: string, icon: ?string, title: string, body: string, params: array<string, string>, url: ?string} $message
     */
    public static function store(array $userIds, array $message, ?Database $db = null): int
    {
        $db ??= App::db() ?? throw new \RuntimeException('Notifications need a database connection.');
        if (!self::enabled($db)) { throw new \RuntimeException('The notifications table is missing: run php flo admin:install and php flo migrate.'); }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0))), 0, self::MAX_RECIPIENTS);
        if ($ids === []) { return 0; }
        $row = [
            'level' => $message['level'],
            'icon' => $message['icon'],
            'title' => mb_strcut($message['title'], 0, 512, 'UTF-8'),
            'body' => $message['body'] === '' ? null : mb_strcut($message['body'], 0, 4096, 'UTF-8'),
            'params' => $message['params'] === [] ? null : json_encode($message['params'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'url' => $message['url'],
            'read_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $db->transaction(static function () use ($db, $ids, $row): void {
            foreach ($ids as $id) { $db->table(self::table())->insert(['user_id' => $id] + $row); }
        });
        return count($ids);
    }

    /** Unread notifications of a person. */
    public static function unreadCount(int $userId, ?Database $db = null): int
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return 0; }
        return (int) $db->query('SELECT COUNT(*) FROM `' . self::table() . '` WHERE `user_id` = ? AND `read_at` IS NULL', [$userId])->fetchColumn();
    }

    /**
     * The latest notifications of a person, newest first, shaped for the views.
     *
     * @return list<array<string, mixed>>
     */
    public static function latest(int $userId, int $limit = self::MENU_SIZE, ?Database $db = null): array
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return []; }
        $rows = $db->query('SELECT * FROM `' . self::table() . '` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT ' . max(1, min(100, $limit)), [$userId])->fetchAll(\PDO::FETCH_OBJ);
        return array_map([self::class, 'present'], $rows);
    }

    /**
     * One page of a person's notifications, newest first; $unread only the unread ones.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function page(int $userId, bool $unread, int $page, int $perPage, ?Database $db = null): array
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return ['items' => [], 'total' => 0]; }
        $where = '`user_id` = ?' . ($unread ? ' AND `read_at` IS NULL' : '');
        $total = (int) $db->query('SELECT COUNT(*) FROM `' . self::table() . '` WHERE ' . $where, [$userId])->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $db->query('SELECT * FROM `' . self::table() . '` WHERE ' . $where . ' ORDER BY `id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset, [$userId])->fetchAll(\PDO::FETCH_OBJ);
        return ['items' => array_map([self::class, 'present'], $rows), 'total' => $total];
    }

    /** A person's own notification, or null. */
    public static function find(int $id, int $userId, ?Database $db = null): ?object
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return null; }
        $row = $db->query('SELECT * FROM `' . self::table() . '` WHERE `id` = ? AND `user_id` = ?', [$id, $userId])->fetch(\PDO::FETCH_OBJ);
        return $row === false ? null : $row;
    }

    /** Mark one of a person's notifications read; false when it isn't theirs. */
    public static function markRead(int $id, int $userId, ?Database $db = null): bool
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return false; }
        if (self::find($id, $userId, $db) === null) { return false; }
        $db->query('UPDATE `' . self::table() . '` SET `read_at` = ? WHERE `id` = ? AND `user_id` = ? AND `read_at` IS NULL', [date('Y-m-d H:i:s'), $id, $userId]);
        return true;
    }

    /** Mark all of a person's notifications read; returns how many changed. */
    public static function markAllRead(int $userId, ?Database $db = null): int
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return 0; }
        return $db->query('UPDATE `' . self::table() . '` SET `read_at` = ? WHERE `user_id` = ? AND `read_at` IS NULL', [date('Y-m-d H:i:s'), $userId])->rowCount();
    }

    /**
     * Mark read a person's unread notifications that open this panel page
     * (a path such as "inquiries/edit/5", or a URL of the panel), so a record
     * opened from its list counts as read too. Returns how many changed.
     */
    public static function markReadFor(string $url, int $userId, ?Database $db = null): int
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return 0; }
        $target = Target::normalize($url);
        if ($target === null) { return 0; }
        return $db->query('UPDATE `' . self::table() . '` SET `read_at` = ? WHERE `user_id` = ? AND `url` = ? AND `read_at` IS NULL', [date('Y-m-d H:i:s'), $userId, $target])->rowCount();
    }

    /**
     * Mark read the signed-in person's notifications that open the panel page of
     * this request (with or without its query), so a record opened from its
     * list counts as read too. Never breaks the page.
     */
    public static function markCurrentPageRead(): void
    {
        try {
            if (($user = \FloCMS\Admin\Support\Actor::id()) === null || ($db = App::db()) === null || !self::enabled($db)) { return; }
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            // /admin alone may be a panel page set as home, not the dashboard
            if (preg_match('~/admin/?(\?|$)~', $uri) === 1) { return; }
            $target = Target::normalize($uri);
            if ($target === null || str_starts_with($target, '/') || str_starts_with($target, 'notifications')) { return; }
            $targets = array_values(array_unique([$target, explode('?', $target, 2)[0]]));
            $db->query('UPDATE `' . self::table() . '` SET `read_at` = ? WHERE `user_id` = ? AND `read_at` IS NULL AND `url` IN (' . implode(', ', array_fill(0, count($targets), '?')) . ')',
                array_merge([date('Y-m-d H:i:s'), $user], $targets));
        } catch (\Throwable $error) {
            \FloCMS\Admin\Support\Log::error('Notifications for this page could not be marked read: ' . $error->getMessage());
        }
    }

    /** Delete one of a person's notifications; false when it isn't theirs. */
    public static function delete(int $id, int $userId, ?Database $db = null): bool
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db)) { return false; }
        return $db->query('DELETE FROM `' . self::table() . '` WHERE `id` = ? AND `user_id` = ?', [$id, $userId])->rowCount() > 0;
    }

    /**
     * Delete notifications older than the configured days.
     *
     * @return int how many were deleted
     */
    public static function purgeExpired(?Database $db = null): int
    {
        $db ??= App::db();
        if ($db === null || !self::enabled($db) || self::days() <= 0) { return 0; }
        return $db->query('DELETE FROM `' . self::table() . '` WHERE `created_at` < ?', [date('Y-m-d H:i:s', time() - self::days() * 86400)])->rowCount();
    }

    /**
     * A row as the bell and the page show it: title and body in the reader's
     * language (a lang key is translated, :placeholders filled), when, and links.
     *
     * @return array<string, mixed>
     */
    public static function present(object $row): array
    {
        $params = json_decode((string) ($row->params ?? ''), true);
        $params = is_array($params) ? array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $params) : [];
        $text = static function (?string $value) use ($params): string {
            $line = AdminLang::label((string) $value);
            return $params === [] ? $line : Lang::replace($line, $params);
        };
        $created = strtotime((string) $row->created_at) ?: time();
        $level = in_array($row->level, ['info', 'success', 'warning', 'danger', 'neutral'], true) ? $row->level : 'info';
        $icon = is_string($row->icon ?? null) && in_array($row->icon, Icons::names(), true) ? $row->icon : 'bell';
        $id = (int) $row->id;
        return [
            'id' => $id,
            'title' => $text($row->title),
            'body' => $row->body === null ? '' : $text($row->body),
            'level' => $level,
            'iconHtml' => Icons::svg($icon, 'fa-icon'),
            'unread' => $row->read_at === null,
            'time' => self::ago($created),
            'datetime' => date('c', $created),
            'exact' => \FloCMS\Admin\Support\Dates::day($created) . ' ' . \FloCMS\Admin\Support\Dates::time($created),
            'openUrl' => Admin::url('notifications/open/' . $id),
            'hasUrl' => is_string($row->url) && $row->url !== '',
            'readUrl' => Admin::url('notifications/read/' . $id),
            'deleteUrl' => Admin::url('notifications/delete/' . $id),
        ];
    }

    /** "just now", "5 min ago", "3 hours ago", "Yesterday", "4 days ago", then the date. */
    public static function ago(int $time, ?int $now = null): string
    {
        $now ??= time();
        $seconds = max(0, $now - $time);
        if ($seconds < 60) { return AdminLang::get('notifications.just_now'); }
        if ($seconds < 3600) { return AdminLang::get('notifications.minutes_ago', ['count' => intdiv($seconds, 60)]); }
        if ($seconds < 86400 && date('Y-m-d', $time) === date('Y-m-d', $now)) {
            $hours = intdiv($seconds, 3600);
            return $hours === 1 ? AdminLang::get('notifications.hour_ago') : AdminLang::get('notifications.hours_ago', ['count' => $hours]);
        }
        $days = (int) round((strtotime(date('Y-m-d', $now)) - strtotime(date('Y-m-d', $time))) / 86400);
        if ($days <= 1) { return AdminLang::get('notifications.yesterday'); }
        if ($days < 7) { return AdminLang::get('notifications.days_ago', ['count' => $days]); }
        return \FloCMS\Admin\Support\Dates::day($time);
    }

    /** The badge text for a count: '' for none, '99+' above 99. */
    public static function badge(int $count): string
    {
        return $count <= 0 ? '' : ($count > 99 ? '99+' : (string) $count);
    }
}
