<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Console\Stub;

/**
 * The trash and the activity log through whole requests: Undo after a delete,
 * the Trash pages, the Activity page, History and the Changes dialog.
 */
final class TrashRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_trash');
        $pdo->exec('DROP TABLE IF EXISTS admin_activity');
        $file = self::$root . '/history-migration.php';
        file_put_contents($file, Stub::render('trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']));
        (require $file)->up(new \FloCMS\Core\Database($pdo));
    }

    protected function tearDown(): void
    {
        // Other tests share the MySQL database: without these tables the trash is off for them.
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_trash');
        $pdo->exec('DROP TABLE IF EXISTS admin_activity');
        parent::tearDown();
    }

    /** @return array<string, mixed>|null */
    private static function trashRow(int $postId): ?array
    {
        $row = self::pdo()->query("SELECT * FROM admin_trash WHERE resource = 'posts' AND record_id = '" . $postId . "'")->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private static function activity(): array
    {
        return self::pdo()->query('SELECT * FROM admin_activity ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** The full form of post 1 with some values changed. */
    private static function postForm(array $changes): array
    {
        return $changes + ['title' => ['en' => 'Sea view villa', 'ar' => 'فيلا'], 'slug' => 'sea-view-villa', 'body' => ['en' => ''], 'status' => 'published',
            'views' => '', 'featured' => '1', 'published_on' => '', '_close' => '1'];
    }

    public function testDeleteGoesToTheTrashWithAnUndoButton(): void
    {
        $result = self::post('/admin/posts/delete/2', []);

        self::assertTrue($result['redirected']);
        self::assertNull(self::row('posts', 2), 'the row leaves its table');
        $entry = self::trashRow(2);
        self::assertNotNull($entry);
        self::assertSame('1', (string) $entry['deleted_by']);
        self::assertSame('Sara', $entry['deleted_by_name']);
        $notice = $result['session']['_flo_admin_notifications'][0];
        self::assertSame('Post deleted.', $notice['title']);
        self::assertSame('“Mountain cabin” is in the trash for 30 days.', $notice['body']);
        self::assertSame('neutral', $notice['level']);
        self::assertSame(10, $notice['duration']);
        self::assertSame('https://example.test/en/admin/trash/undo/' . $entry['batch'], $notice['action']['url']);

        // Undo puts it back, with the same id, and returns to the page it was on
        $undo = self::post('/admin/trash/undo/' . $entry['batch'], ['_back' => '/en/admin/posts?page=1']);
        self::assertTrue($undo['redirected']);
        self::assertNotNull(self::row('posts', 2));
        self::assertNull(self::trashRow(2));
        self::assertSame('Post restored.', $undo['session']['_flo_admin_notifications'][0]['title']);
        self::assertSame(['deleted', 'restored'], array_column(self::activity(), 'action'));

        // Someone else can't undo another person's delete
        self::post('/admin/posts/delete/2', []);
        $batch = self::trashRow(2)['batch'];
        $other = self::post('/admin/trash/undo/' . $batch, [], ['session' => self::SUPER]);
        self::assertNull(self::row('posts', 2));
        self::assertSame('warning', $other['session']['_flo_admin_notifications'][0]['level']);
        self::assertSame(405, self::request('GET', '/admin/trash/undo/' . $batch)['status']);
    }

    public function testTheNotificationShowsTheUndoButton(): void
    {
        self::post('/admin/posts/delete/2', []);
        $entry = self::trashRow(2);
        $session = self::EDITOR + ['_flo_admin_notifications' => [[
            'id' => 'n1', 'level' => 'neutral', 'duration' => 10, 'title' => 'Post deleted.', 'body' => '“Mountain cabin” is in the trash for 30 days.',
            'lang' => 'en', 'dir' => 'ltr', 'dismiss' => 'Dismiss', 'issued' => time(), 'icon' => 'trash',
            'action' => ['label' => 'Undo', 'url' => 'https://example.test/en/admin/trash/undo/' . $entry['batch']],
        ]]];
        $body = self::request('GET', '/admin/posts', ['session' => $session])['body'];

        self::assertStringContainsString('action="https://example.test/en/admin/trash/undo/' . $entry['batch'] . '"', $body);
        self::assertStringContainsString('data-admin-notification-action', $body);
        self::assertStringContainsString('name="_token" value="tok"', $body);
        self::assertStringContainsString('fa-notification-timer', $body);
        self::assertStringContainsString('fa-trash-link', $body, 'the list shows its Trash (1) button');
        self::assertStringContainsString('href="https://example.test/en/admin/posts/trash"', $body);
    }

    public function testTheListTrashRestoresAndDeletesForGood(): void
    {
        self::post('/admin/posts/delete/2', []);
        self::post('/admin/posts/delete/1', []);

        $page = self::request('GET', '/admin/posts/trash');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Posts trash', $page['body']);
        self::assertStringContainsString('Mountain cabin', $page['body']);
        self::assertStringContainsString('sea-view-villa', $page['body'], 'the list\'s own columns');
        self::assertStringContainsString('30 days', $page['body']);
        self::assertStringContainsString('Sara', $page['body'], 'who deleted it');

        $id = (string) self::trashRow(2)['id'];
        $restored = self::post('/admin/posts/trash', ['task' => 'restore', 'ids' => [$id], '_query' => 'q=cabin']);
        self::assertTrue($restored['redirected']);
        self::assertNotNull(self::row('posts', 2));
        self::assertSame('Post restored: “Mountain cabin”.', $restored['session']['_flo_admin_notifications'][0]['title']);

        $purge = self::post('/admin/posts/trash', ['task' => 'purge', 'ids' => [(string) self::trashRow(1)['id']]]);
        self::assertTrue($purge['redirected']);
        self::assertNull(self::trashRow(1));
        self::assertNull(self::row('posts', 1), 'gone for good');

        self::post('/admin/posts/delete/2', []);
        self::post('/admin/posts/trash', ['task' => 'empty']);
        self::assertNull(self::trashRow(2));
        self::assertSame(['deleted', 'deleted', 'restored', 'purged', 'deleted', 'purged'], array_column(self::activity(), 'action'));

        self::assertSame(400, self::post('/admin/posts/trash', ['task' => 'restore', 'ids' => ['x']])['status']);
        self::assertSame(400, self::post('/admin/posts/trash', ['task' => 'nope'])['status']);
    }

    public function testATakenSlugRefusesTheRestoreWithAReason(): void
    {
        self::post('/admin/posts/delete/2', []);
        self::pdo()->exec("INSERT INTO posts (title, slug, status) VALUES ('{\"en\":\"New cabin\"}', 'mountain-cabin', 'draft')");

        $result = self::post('/admin/posts/trash', ['task' => 'restore', 'ids' => [(string) self::trashRow(2)['id']]]);
        $notice = $result['session']['_flo_admin_notifications'][0];
        self::assertSame('danger', $notice['level']);
        self::assertSame('Couldn’t restore “Mountain cabin”.', $notice['title']);
        self::assertStringContainsString('Another post now uses the slug “mountain-cabin”', $notice['body']);
        self::assertNotNull(self::trashRow(2), 'it stays in the trash');
    }

    public function testTrashPermissions(): void
    {
        self::post('/admin/posts/delete/2', []);
        $id = (string) self::trashRow(2)['id'];

        // View and update only: no Trash at all
        $viewer = [1 => ['content.posts.view', 'content.posts.update']];
        self::assertSame(403, self::request('GET', '/admin/posts/trash', ['permissions' => $viewer])['status']);
        self::assertStringNotContainsString('fa-trash-link', self::request('GET', '/admin/posts', ['permissions' => $viewer])['body']);
        self::assertSame(403, self::request('GET', '/admin/trash', ['permissions' => $viewer])['status']);

        // Delete without create: may delete for good, not restore
        $deleter = [1 => ['content.posts.view', 'content.posts.delete']];
        self::assertSame(200, self::request('GET', '/admin/posts/trash', ['permissions' => $deleter])['status']);
        self::assertSame(403, self::post('/admin/posts/trash', ['task' => 'restore', 'ids' => [$id]], ['permissions' => $deleter])['status']);
        self::assertNull(self::row('posts', 2));

        // Create without delete: may restore, not empty
        $creator = [1 => ['content.posts.view', 'content.posts.create']];
        self::assertSame(403, self::post('/admin/posts/trash', ['task' => 'empty'], ['permissions' => $creator])['status']);
        self::post('/admin/posts/trash', ['task' => 'restore', 'ids' => [$id]], ['permissions' => $creator]);
        self::assertNotNull(self::row('posts', 2));
    }

    public function testSiteTrashListsEveryType(): void
    {
        self::post('/admin/posts/delete/2', []);
        self::post('/admin/post-tags/delete/1', [], ['session' => self::SUPER]);

        $page = self::request('GET', '/admin/trash', ['session' => self::SUPER]);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Mountain cabin', $page['body']);
        self::assertStringContainsString('Garden', $page['body']);
        self::assertStringContainsString('href="https://example.test/en/admin/trash?type=posts"', $page['body'], 'type tabs');
        self::assertStringContainsString('fa-nav-link is-active" href="https://example.test/en/admin/trash"', $page['body'], 'Site → Trash in the sidebar');

        $filtered = self::request('GET', '/admin/trash?type=post-tags', ['session' => self::SUPER])['body'];
        self::assertStringContainsString('Garden', $filtered);
        self::assertStringNotContainsString('Mountain cabin', $filtered);
        self::assertSame(404, self::request('GET', '/admin/trash?type=unknown', ['session' => self::SUPER])['status']);
    }

    public function testTheActivityPageNeedsItsPermission(): void
    {
        self::post('/admin/posts/edit/1', self::postForm(['status' => 'draft']));
        self::post('/admin/posts/delete/2', []);

        self::assertSame(403, self::request('GET', '/admin/activity')['status'], 'editors have content.* only');
        $page = self::request('GET', '/admin/activity', ['session' => self::SUPER]);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Sea view villa', $page['body']);
        self::assertStringContainsString('Mountain cabin', $page['body']);
        self::assertStringContainsString('Status', $page['body'], 'the changed fields');
        self::assertStringContainsString('Activity</span>', $page['body'], 'Site → Activity in the sidebar');

        $deleted = self::request('GET', '/admin/activity?kind=deleted', ['session' => self::SUPER])['body'];
        self::assertStringContainsString('Mountain cabin', $deleted);
        self::assertStringNotContainsString('Sea view villa', $deleted);
        self::assertStringNotContainsString('/admin/activity"', self::request('GET', '/admin/posts')['body'], 'no Activity link without the permission');
    }

    public function testHistoryAndTheChangesDialog(): void
    {
        self::post('/admin/posts/edit/1', self::postForm(['status' => 'draft', 'title' => ['en' => 'Sea view villa', 'ar' => 'فيلا جديدة']]));
        $entry = self::activity()[0];
        self::assertSame('updated', $entry['action']);

        $edit = self::request('GET', '/admin/posts/edit/1')['body'];
        self::assertStringContainsString('data-admin-drawer="fa-history-drawer"', $edit);
        self::assertStringContainsString('id="fa-history-drawer"', $edit);
        self::assertStringContainsString('edited 2 fields', $edit);
        self::assertStringContainsString('It goes to the trash for 30 days', $edit, 'the delete confirmation says where it goes');

        $history = self::request('GET', '/admin/posts/history/1');
        self::assertSame(200, $history['status']);
        self::assertStringContainsString('History · Sea view villa', $history['body']);

        $dialog = self::request('GET', '/admin/activity/show/' . $entry['id'], ['headers' => ['X-Flo-Admin' => 'fragment']]);
        self::assertSame(200, $dialog['status']);
        self::assertStringContainsString('data-admin-changes', $dialog['body']);
        self::assertStringContainsString('Published', $dialog['body']);
        self::assertStringContainsString('Draft', $dialog['body']);
        self::assertStringContainsString('فيلا جديدة', $dialog['body']);
        self::assertStringContainsString('fa-diff-sub">العربية<', $dialog['body'], 'the language of a translated value');
        self::assertStringNotContainsString('<html', $dialog['body'], 'only the dialog');

        $noUpdate = [1 => ['content.posts.view']];
        self::assertSame(403, self::request('GET', '/admin/activity/show/' . $entry['id'], ['permissions' => $noUpdate])['status']);
        self::assertSame(403, self::request('GET', '/admin/posts/history/1', ['permissions' => $noUpdate])['status']);
        self::assertSame(404, self::request('GET', '/admin/activity/show/abc')['status']);
    }

    public function testActionAndBulkDeletesUseTheTrashAndUndoAsOne(): void
    {
        $config = ['admin_config' => ['resources' => [\FloCMS\Admin\Tests\Fixtures\Actions\PostActionResource::class]]];
        // The row Delete action (the Reactive list's path): one record, one Undo
        $open = self::request('GET', '/en/admin/posts/action/row/delete/2', $config);
        self::assertSame(200, $open['status']);
        self::assertStringContainsString('It goes to the trash for 30 days', $open['body'], 'the confirmation says where it goes');
        preg_match('/name="_operation" value="([^"]+)"/', $open['body'], $match);
        $deleted = self::post('/en/admin/posts/action/row/delete/2', ['_operation' => $match[1]], $config + ['session' => $open['session']]);
        self::assertNull(self::row('posts', 2));
        $notice = end($deleted['session']['_flo_admin_notifications']);
        self::assertSame('Post deleted.', $notice['title']);
        self::assertStringContainsString('/admin/trash/undo/', $notice['action']['url']);

        // Delete selected: both records share one Undo
        self::pdo()->exec("INSERT INTO posts (title, slug, status) VALUES ('{\"en\":\"Garden house\"}', 'garden-house', 'draft')");
        $ids = ['1', '3'];
        $page = self::request('GET', '/en/admin/posts/bulk/delete?' . http_build_query(['ids' => $ids]), $config);
        self::assertSame(200, $page['status'], $page['body']);
        self::assertStringContainsString('Move the 2 selected records to the trash?', $page['body']);
        preg_match('/name="_operation" value="([^"]+)"/', $page['body'], $match);
        $bulk = self::post('/en/admin/posts/bulk/delete', ['_operation' => $match[1], '_ids' => json_encode($ids)], $config + ['session' => $page['session']]);
        self::assertNull(self::row('posts', 1));
        self::assertNull(self::row('posts', 3));
        $notice = end($bulk['session']['_flo_admin_notifications']);
        self::assertSame('2 posts deleted.', $notice['title']);
        self::assertSame(self::trashRow(1)['batch'], self::trashRow(3)['batch']);

        $undo = self::post(parse_url($notice['action']['url'], PHP_URL_PATH), [], $config);
        self::assertSame('2 records restored.', $undo['session']['_flo_admin_notifications'][0]['title']);
        self::assertNotNull(self::row('posts', 1));
        self::assertNotNull(self::row('posts', 3));
        self::assertNull(self::row('posts', 2), 'the earlier delete is another operation');
    }
}
