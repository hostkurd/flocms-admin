<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Console\Stub;
use FloCMS\Admin\Notifications\Inbox;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Notifications\Recipients;
use FloCMS\Core\App;
use FloCMS\Core\Config;
use FloCMS\Core\Database;

/**
 * The notification bell (0.41) through whole requests: sendTo() stores one row
 * per person, the bell and its menu, opening, marking read, deleting, the page,
 * the poll answer, and a resource's sidebar badge.
 */
final class NotificationsRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_notifications');
        $file = self::$root . '/notifications-migration.php';
        file_put_contents($file, Stub::render('notifications-migration', ['table' => 'admin_notifications']));
        (require $file)->up(new Database($pdo));

        // This process sends the notifications, as a site's form handler would.
        App::$db = new Database($pdo);
        Admin::useConfig(['resources' => [\FloCMS\Admin\Tests\Fixtures\Resources\PostResource::class]], true, self::$root);
        Config::set('roles', [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin']);
        Config::set('permissions', self::PERMISSIONS);
        Config::set('admin_access_roles', ['1', '2', '3']);
    }

    protected function tearDown(): void
    {
        // Other tests share the MySQL database: without this table the bell is off for them.
        self::pdo()->exec('DROP TABLE IF EXISTS admin_notifications');
        Admin::reset();
        App::$db = null;
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return self::pdo()->query('SELECT * FROM admin_notifications ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function sendInquiry(string $name = 'Maria'): int
    {
        return Notification::make()
            ->title('New inquiry from :name', ['name' => $name])
            ->body('Property · :ref', ['ref' => 'FH-2026-0143'])
            ->icon('mail')
            ->url('posts/edit/1')
            ->sendTo(Recipients::permission('content.posts.view'));
    }

    public function testSendToStoresOneRowForEachPersonWhoMayView(): void
    {
        self::assertSame(3, self::sendInquiry(), 'Sara, Ali and Root; not Dara, whose role has no permissions');
        $rows = self::rows();
        self::assertSame(['1', '2', '3'], array_map('strval', array_column($rows, 'user_id')));
        self::assertSame('New inquiry from :name', $rows[0]['title'], 'kept as written, translated when read');
        self::assertSame(['name' => 'Maria', 'ref' => 'FH-2026-0143'], json_decode((string) $rows[0]['params'], true));
        self::assertSame('posts/edit/1', $rows[0]['url']);
        self::assertSame('mail', $rows[0]['icon']);
        self::assertNull($rows[0]['read_at']);

        // A role, chosen people, everyone; suspended people never
        self::assertSame(1, Notification::make()->title('Admins only')->sendTo(Recipients::role(2)));
        self::assertSame(2, Notification::make()->title('Two people')->sendTo([1, 3, 4]), 'Dara (role 0) is not a panel user');
        self::pdo()->exec('UPDATE users SET status = 3 WHERE id = 2');
        self::assertSame(2, Notification::make()->title('Everyone')->sendTo(Recipients::everyone()));
        self::assertSame(0, Notification::make()->title('Nobody')->sendTo([]));
    }

    public function testTheBellShowsTheCountAndTheLatestNotifications(): void
    {
        self::sendInquiry('Maria');
        self::sendInquiry('James');
        $page = self::request('GET', '/en/admin/dashboard');
        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertStringContainsString('id="fa-bell"', $body);
        self::assertStringContainsString('aria-label="Notifications, 2 unread"', $body);
        self::assertMatchesRegularExpression('~data-admin-bell-badge aria-hidden="true">2</span>~', $body);
        self::assertStringContainsString('New inquiry from James', $body);
        self::assertStringContainsString('Property · FH-2026-0143', $body);
        self::assertStringContainsString('2 unread', $body);
        self::assertStringContainsString('Mark all as read', $body);
        self::assertStringContainsString('data-admin-bell-poll="60"', $body);
        // Newest first
        self::assertLessThan(strpos($body, 'New inquiry from Maria'), strpos($body, 'New inquiry from James'));

        // Polling off, and nothing unread: no badge
        self::pdo()->exec('UPDATE admin_notifications SET read_at = created_at');
        $quiet = self::request('GET', '/en/admin/dashboard', ['admin_config' => ['notifications' => ['poll' => 0]]])['body'];
        self::assertStringContainsString('data-admin-bell-poll="0"', $quiet);
        self::assertStringContainsString('aria-label="Notifications"', $quiet);
        self::assertMatchesRegularExpression('~data-admin-bell-badge aria-hidden="true" hidden\s*></span>~', $quiet);
    }

    public function testEachPersonSeesOnlyTheirOwnNotifications(): void
    {
        self::sendInquiry();
        $ids = array_map('intval', array_column(self::rows(), 'id', 'user_id'));

        // Sara opens hers: it becomes read and its page opens
        $open = self::request('GET', '/en/admin/notifications/open/' . $ids[1]);
        self::assertTrue($open['redirected']);
        self::assertNotNull(self::row('admin_notifications', $ids[1])['read_at']);
        self::assertNull(self::row('admin_notifications', $ids[2])['read_at'], 'Ali\'s copy stays unread');

        // Ali's notification is not Sara's to open, mark read or delete
        self::assertSame(404, self::request('GET', '/en/admin/notifications/open/' . $ids[2])['status']);
        self::assertSame(404, self::post('/en/admin/notifications/read/' . $ids[2], [])['status']);
        self::assertSame(404, self::post('/en/admin/notifications/delete/' . $ids[2], [])['status']);
        self::assertNull(self::row('admin_notifications', $ids[2])['read_at']);
        self::assertNotNull(self::row('admin_notifications', $ids[2]));

        // Changes are POST only
        self::assertSame(405, self::request('GET', '/en/admin/notifications/delete/' . $ids[1])['status']);
        self::assertSame(405, self::request('GET', '/en/admin/notifications/clear')['status']);
    }

    public function testOpeningThePageAnotherWayMarksItRead(): void
    {
        self::sendInquiry();
        $ids = array_map('intval', array_column(self::rows(), 'id', 'user_id'));
        $edit = self::request('GET', '/en/admin/posts/edit/1?back=list');
        self::assertSame(200, $edit['status']);
        self::assertNotNull(self::row('admin_notifications', $ids[1])['read_at'], 'Sara opened the post from its list');
        self::assertNull(self::row('admin_notifications', $ids[2])['read_at'], 'Ali has not');
        self::assertStringNotContainsString('data-admin-bell-badge aria-hidden="true">1<', $edit['body'], 'the badge already counts it as read');
    }

    public function testMarkReadMarkAllAndDelete(): void
    {
        self::sendInquiry('Maria');
        self::sendInquiry('James');
        $mine = array_values(array_filter(self::rows(), static fn (array $row): bool => (int) $row['user_id'] === 1));

        $read = self::post('/en/admin/notifications/read/' . $mine[0]['id'], ['_back' => '/en/admin/posts?page=2']);
        self::assertTrue($read['redirected']);
        self::assertNotNull(self::row('admin_notifications', (int) $mine[0]['id'])['read_at']);

        $clear = self::post('/en/admin/notifications/clear', ['_back' => 'https://evil.example/']);
        self::assertTrue($clear['redirected']);
        self::assertSame('All notifications marked as read.', $clear['session']['_flo_admin_notifications'][0]['title']);
        self::assertSame(0, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_notifications WHERE user_id = 1 AND read_at IS NULL')->fetchColumn());
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_notifications WHERE user_id = 2 AND read_at IS NULL')->fetchColumn(), 'only Sara\'s');

        $delete = self::post('/en/admin/notifications/delete/' . $mine[1]['id'], []);
        self::assertTrue($delete['redirected']);
        self::assertNull(self::row('admin_notifications', (int) $mine[1]['id']));
        self::assertSame('Notification deleted.', $delete['session']['_flo_admin_notifications'][0]['title']);

        // Without the CSRF token nothing changes
        $forged = self::request('POST', '/en/admin/notifications/delete/' . $mine[0]['id'], ['post' => ['_token' => 'wrong'], 'expect_error' => true]);
        self::assertNotSame(200, $forged['status']);
        self::assertNotNull(self::row('admin_notifications', (int) $mine[0]['id']));
    }

    public function testTheNotificationsPageAndThePollAnswer(): void
    {
        self::sendInquiry('Maria');
        self::sendInquiry('James');
        $first = (int) array_values(array_filter(self::rows(), static fn (array $row): bool => (int) $row['user_id'] === 1))[0]['id'];
        self::pdo()->exec('UPDATE admin_notifications SET read_at = created_at WHERE id = ' . $first);

        $page = self::request('GET', '/en/admin/notifications');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<h1 class="fa-page-title">Notifications</h1>', $page['body']);
        self::assertStringContainsString('New inquiry from Maria', $page['body']);
        self::assertStringContainsString('New inquiry from James', $page['body']);
        self::assertStringContainsString('Notifications are kept for 90 days.', $page['body']);
        self::assertMatchesRegularExpression('~All<span class="fa-inbox-tab-count">2</span>~', $page['body']);
        self::assertMatchesRegularExpression('~Unread<span class="fa-inbox-tab-count">1</span>~', $page['body']);

        $unread = self::request('GET', '/en/admin/notifications?show=unread')['body'];
        // The page's list (the bell's menu above it shows the latest, read or not)
        $unread = substr($unread, (int) strpos($unread, 'class="fa-card fa-inbox"'));
        self::assertStringContainsString('New inquiry from James', $unread);
        self::assertStringNotContainsString('New inquiry from Maria', $unread);

        $poll = self::request('GET', '/en/admin/notifications/poll');
        $answer = json_decode($poll['body'], true);
        self::assertIsArray($answer, $poll['body']);
        self::assertSame(1, $answer['count']);
        self::assertSame('1', $answer['badge']);
        self::assertSame('Notifications, 1 unread', $answer['label']);
        self::assertStringContainsString('New inquiry from James', $answer['menu']);
        self::assertStringNotContainsString('<script', $answer['menu']);
    }

    public function testTitlesAreReadInEachPersonsLanguageAndEscaped(): void
    {
        file_put_contents(self::$root . '/lang/ku.php', "<?php\nreturn ['lng.dir' => 'rtl', 'lng.en' => 'ئینگلیزی', 'site.new_inquiry' => 'پرسیاری نوێ لە :name'];\n");
        file_put_contents(self::$root . '/lang/en.php', "<?php\nreturn ['lng.dir' => 'ltr', 'site.new_inquiry' => 'New inquiry from :name'];\n");
        Notification::make()->title('site.new_inquiry', ['name' => '<b>Maria</b>'])->sendTo([1]);

        $english = self::request('GET', '/en/admin/notifications')['body'];
        self::assertStringContainsString('New inquiry from &lt;b&gt;Maria&lt;/b&gt;', $english);
        self::assertStringNotContainsString('<b>Maria</b>', $english);
        $kurdish = self::request('GET', '/ku/admin/notifications')['body'];
        self::assertStringContainsString('پرسیاری نوێ لە &lt;b&gt;Maria&lt;/b&gt;', $kurdish);
        file_put_contents(self::$root . '/lang/en.php', "<?php\nreturn ['lng.dir' => 'ltr'];\n");
        file_put_contents(self::$root . '/lang/ku.php', "<?php\nreturn ['lng.dir' => 'rtl', 'lng.en' => 'ئینگلیزی'];\n");
    }

    public function testWithoutTheTableThereIsNoBell(): void
    {
        self::pdo()->exec('DROP TABLE admin_notifications');
        \FloCMS\Admin\Notifications\Inbox::reset();
        self::assertSame(0, self::sendInquiry(), 'stores nothing and never throws');
        $page = self::request('GET', '/en/admin/dashboard');
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('id="fa-bell"', $page['body']);
        self::assertSame(404, self::request('GET', '/en/admin/notifications')['status']);
        // Switched off in config/admin.php: no page either
        $file = self::$root . '/notifications-migration.php';
        (require $file)->up(new Database(self::pdo()));
        $off = self::request('GET', '/en/admin/dashboard', ['admin_config' => ['notifications' => false]]);
        self::assertStringNotContainsString('id="fa-bell"', $off['body']);
    }

    public function testAPanelPageNamedNotificationsKeepsItsAddressAndHidesTheBell(): void
    {
        self::sendInquiry();
        $body = self::request('GET', '/en/admin/dashboard', ['admin_config' => ['admin_pages' => [\FloCMS\Admin\Tests\Fixtures\Notifications\NotificationsPage::class]]])['body'];
        self::assertStringNotContainsString('id="fa-bell"', $body);
    }

    public function testAResourceCanShowABadgeInTheSidebar(): void
    {
        $config = ['resources' => [\FloCMS\Admin\Tests\Fixtures\Resources\PostResource::class, \FloCMS\Admin\Tests\Fixtures\Notifications\DraftResource::class]];
        $body = self::request('GET', '/en/admin/dashboard', ['admin_config' => $config])['body'];
        self::assertMatchesRegularExpression('~<span>Drafts</span><span class="fa-nav-badge">1</span>~', $body, 'one post is a draft');
        self::assertMatchesRegularExpression('~<span>Posts</span></a>~', $body, 'no badge without navigationBadge()');
        self::pdo()->exec("UPDATE posts SET status = 'published'");
        $none = self::request('GET', '/en/admin/dashboard', ['admin_config' => $config])['body'];
        self::assertMatchesRegularExpression('~<span>Drafts</span></a>~', $none, '0 shows nothing');
    }
}
