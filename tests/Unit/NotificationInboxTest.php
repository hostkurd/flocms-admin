<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Console\Stub;
use FloCMS\Admin\Notifications\Inbox;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Notifications\Target;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/** The notification bell's store, links and settings (0.41). */
final class NotificationInboxTest extends TestCase
{
    protected function tearDown(): void
    {
        Admin::reset();
        App::$db = null;
        parent::tearDown();
    }

    private static function database(array $config = []): Database
    {
        $root = sys_get_temp_dir() . '/flo-inbox-' . bin2hex(random_bytes(4));
        mkdir($root);
        $db = new Database(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        App::$db = $db;
        Admin::useConfig($config, true, $root);
        file_put_contents($root . '/m.php', Stub::render('notifications-migration', ['table' => Admin::config()->notifications()['table']]));
        (require $root . '/m.php')->up($db);
        return $db;
    }

    public function testSettings(): void
    {
        Admin::useConfig([]);
        self::assertSame(['enabled' => true, 'table' => 'admin_notifications', 'days' => 90, 'poll' => 60], Admin::config()->notifications());
        Admin::useConfig(['notifications' => false]);
        self::assertFalse(Admin::config()->notifications()['enabled']);
        Admin::useConfig(['notifications' => ['days' => 0, 'poll' => 5, 'table' => 'site_notes']]);
        self::assertSame(['enabled' => true, 'table' => 'site_notes', 'days' => 0, 'poll' => 15], Admin::config()->notifications(), 'at least 15 seconds');
        Admin::useConfig(['notifications' => ['poll' => 0, 'table' => 'bad name']]);
        self::assertSame(0, Admin::config()->notifications()['poll']);
        self::assertSame('admin_notifications', Admin::config()->notifications()['table']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testTargetsStayOnThisSite(): void
    {
        define('SITE_URI', 'https://example.test');
        define('ACTIVE_LANG', 'ar');
        Admin::useConfig([]);
        // Panel pages are kept without the language
        self::assertSame('inquiries/edit/5', Target::normalize('inquiries/edit/5'));
        self::assertSame('inquiries/edit/5?tab=2', Target::normalize('https://example.test/en/admin/inquiries/edit/5?tab=2'));
        self::assertSame('inquiries', Target::normalize('/en/admin/inquiries'));
        self::assertSame('dashboard', Target::normalize('https://example.test/el/admin'));
        // Website pages keep their path
        self::assertSame('/en/properties/villa-a1', Target::normalize('/en/properties/villa-a1'));
        self::assertSame('/en/contact', Target::normalize('https://EXAMPLE.test/en/contact'));
        // Letters of any language and spaces are kept, encoded
        self::assertSame('/el/properties/%CE%B2%CE%AF%CE%BB%CE%B1-%CE%B11', Target::normalize('/el/properties/βίλα-α1'));
        self::assertSame('properties?search=sea%20view', Target::normalize('properties?search=sea view'));
        self::assertSame('https://example.test/ar/admin/properties?search=sea%20view', Target::resolve(Target::normalize('properties?search=sea view')));
        // Other sites and tricks are refused
        foreach (['https://evil.example/en/admin/x', '//evil.example/x', 'javascript:alert(1)', 'data:text/html,x', '/en/../admin', '/en/admin/%2e%2e/x', 'a/%2E%2E/b',
                  'inquiries/../../etc', "inquiries/\nedit", 'inquiries\\edit', '/en/a#b', 'http://example.test.evil.example/x', ''] as $bad) {
            self::assertNull(Target::normalize($bad), $bad);
        }
        // Opened in the reader's language
        self::assertSame('https://example.test/ar/admin/inquiries/edit/5?tab=2', Target::resolve('inquiries/edit/5?tab=2'));
        self::assertSame('https://example.test/en/contact', Target::resolve('/en/contact'));
        self::assertNull(Target::resolve('//evil.example'));
        self::assertNull(Target::resolve(null));
        self::expectException(\InvalidArgumentException::class);
        Notification::make()->title('x')->url('https://evil.example/');
    }

    public function testStoreCountReadAndPurge(): void
    {
        $db = self::database();
        $message = ['level' => 'info', 'icon' => 'mail', 'title' => 'Hello :name', 'body' => '', 'params' => ['name' => 'Sara'], 'url' => 'posts/edit/1'];
        self::assertSame(2, Inbox::store([5, 6, 5, 0, -1], $message), 'each person once');
        Inbox::store([5], ['url' => 'posts/edit/2'] + $message);
        self::assertSame(2, Inbox::unreadCount(5));
        self::assertSame(1, Inbox::unreadCount(6));

        $latest = Inbox::latest(5);
        self::assertSame('Hello Sara', $latest[0]['title']);
        self::assertTrue($latest[0]['unread']);
        self::assertGreaterThan($latest[1]['id'], $latest[0]['id'], 'newest first');

        // Only the owner can read or delete
        self::assertFalse(Inbox::markRead($latest[0]['id'], 6));
        self::assertTrue(Inbox::markRead($latest[0]['id'], 5));
        self::assertSame(1, Inbox::unreadCount(5));
        self::assertFalse(Inbox::delete($latest[1]['id'], 6));

        // Opening the record from its list marks its notification read too
        self::assertSame(1, Inbox::markReadFor('posts/edit/1', 5));
        self::assertSame(0, Inbox::unreadCount(5));
        self::assertSame(1, Inbox::unreadCount(6), 'only that person\'s');
        self::assertSame(1, Inbox::markAllRead(6));

        // Older than the days are purged
        $db->query("UPDATE admin_notifications SET created_at = '2000-01-01 00:00:00' WHERE user_id = 6");
        self::assertSame(1, Inbox::purgeExpired());
        self::assertSame(['items' => [], 'total' => 0], Inbox::page(6, false, 1, 10));
        self::assertSame(2, Inbox::page(5, false, 1, 10)['total']);
    }

    public function testTimesAndBadges(): void
    {
        $now = strtotime('2026-11-08 12:00:00');
        self::assertSame('Just now', Inbox::ago($now - 20, $now));
        self::assertSame('5 min ago', Inbox::ago($now - 300, $now));
        self::assertSame('1 hour ago', Inbox::ago($now - 3700, $now));
        self::assertSame('3 hours ago', Inbox::ago($now - 3 * 3600 - 10, $now));
        self::assertSame('Yesterday', Inbox::ago(strtotime('2026-11-07 23:00:00'), $now));
        self::assertSame('4 days ago', Inbox::ago(strtotime('2026-11-04 09:00:00'), $now));
        self::assertSame('', Inbox::badge(0));
        self::assertSame('7', Inbox::badge(7));
        self::assertSame('99+', Inbox::badge(100));
    }

    public function testWithoutTheTableNothingIsStoredAndNothingBreaks(): void
    {
        App::$db = new Database(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        Admin::useConfig([]);
        self::assertSame('missing', Inbox::status());
        self::assertSame(0, Notification::make()->title('Lost')->sendTo([1]));
        self::assertSame(0, Inbox::unreadCount(1));
        Admin::useConfig(['notifications' => false]);
        self::assertSame('off', Inbox::status());
    }
}
