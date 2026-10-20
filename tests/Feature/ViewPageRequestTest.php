<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\Fixtures\Resources\TagResource;
use FloCMS\Admin\Tests\Fixtures\View\NoEditPostResource;
use FloCMS\Admin\Tests\Fixtures\View\NoViewPostResource;
use FloCMS\Admin\Tests\Fixtures\View\ViewPostResource;

/**
 * The view page (0.27) through whole requests: who may open it, what it shows,
 * the language switch, and where the list's row title links to.
 */
final class ViewPageRequestTest extends RequestTestCase
{
    private const RESOURCES = [PostResource::class, TagResource::class, ViewPostResource::class, NoViewPostResource::class, NoEditPostResource::class];

    /** Viewers: may view posts, notes and drafts, may not edit them. */
    private const VIEWER = [1 => ['content.posts.view', 'content.notes.view', 'content.drafts.view']];

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ($extra['admin_config'] ?? []) + ['resources' => self::RESOURCES]] + $extra;
    }

    private static function viewer(array $extra = []): array
    {
        return self::options($extra + ['permissions' => self::VIEWER]);
    }

    public function testAViewerOpensTheRecordAsValuesWithoutEdit(): void
    {
        $page = self::request('GET', '/en/admin/posts/view/1', self::viewer());
        self::assertSame(200, $page['status'], $page['body']);
        $body = $page['body'];
        self::assertStringContainsString('<h1 class="fa-page-title" id="fa-view-title">Sea view villa</h1>', $body);
        self::assertStringContainsString('<dt>Status</dt>', $body);
        self::assertStringContainsString('Published', $body, 'the list\'s badge');
        self::assertStringContainsString('<code dir="ltr">sea-view-villa</code>', $body, 'the slug, with a copy button');
        self::assertStringContainsString('data-admin-copy="sea-view-villa"', $body);
        self::assertStringContainsString('fa-view-bool is-on', $body, 'featured: yes');
        self::assertStringNotContainsString('/en/admin/posts/edit/1', $body, 'no Edit for a viewer');
        $fields = substr($body, (int) strpos($body, '<div class="fa-view-layout'), (int) strpos($body, '</article>') - (int) strpos($body, '<div class="fa-view-layout'));
        self::assertStringContainsString('data-field="title"', $fields);
        self::assertStringNotContainsString('<input', $fields, 'values, never inputs');
        self::assertStringContainsString('data-admin-print hidden', $body, 'Print, shown by JavaScript');
        self::assertStringContainsString('class="fa-view-print-line"', $body);

        self::assertSame(403, self::request('GET', '/en/admin/posts/edit/1', self::viewer())['status'], 'the edit page stays closed');
        self::assertSame(405, self::post('/en/admin/posts/view/1', [], self::viewer())['status']);
        self::assertSame(404, self::request('GET', '/en/admin/posts/view/99', self::viewer())['status']);
        self::assertSame(403, self::request('GET', '/en/admin/posts/view/1', self::options(['permissions' => [1 => ['content.tags.view']]]))['status']);
    }

    public function testAnEditorSeesEditAndTheRowMenuOffersView(): void
    {
        $body = self::request('GET', '/en/admin/posts/view/1', self::options())['body'];
        self::assertMatchesRegularExpression('~<a class="fa-btn fa-btn-primary" href="https://example.test/en/admin/posts/edit/1"[^>]*data-admin-action="edit"~', $body);
        self::assertStringContainsString('action="https://example.test/en/admin/posts/delete/1"', $body, 'Delete in More');

        $list = self::request('GET', '/en/admin/posts', self::options())['body'];
        self::assertStringContainsString('<a class="fa-row-link" href="https://example.test/en/admin/posts/edit/1">', $list, 'the title opens Edit');
        self::assertMatchesRegularExpression('~data-admin-action="view" href="https://example.test/en/admin/posts/view/1"~', $list, 'and View is in the row menu');
    }

    public function testTheRowTitleOpensWhatThePersonMayOpen(): void
    {
        $list = self::request('GET', '/en/admin/posts', self::viewer());
        self::assertSame(200, $list['status'], $list['body']);
        self::assertStringContainsString('<a class="fa-row-link" href="https://example.test/en/admin/posts/view/1">', $list['body'], 'a viewer: the view page');

        $notes = self::request('GET', '/en/admin/notes', self::options())['body'];
        self::assertStringContainsString('<a class="fa-row-link" href="https://example.test/en/admin/notes/view/1">', $notes, '$rowOpens = view: the view page first, for editors too');
        self::assertStringNotContainsString('data-admin-action="view"', $notes, 'no View in the menu: the title is it');
        self::assertStringNotContainsString('href="https://example.test/en/admin/notes/view/2"', $notes, 'canView() false: no link');
        self::assertStringContainsString('<a class="fa-row-link" href="https://example.test/en/admin/notes/edit/2">', $notes, 'canView() false: Edit instead');

        $drafts = self::request('GET', '/en/admin/drafts', self::viewer())['body'];
        self::assertStringNotContainsString('fa-row-link', $drafts, '$viewPage = false and no update: no link');
        self::assertSame(404, self::request('GET', '/en/admin/drafts/view/1', self::options())['status']);
        self::assertStringContainsString('<a class="fa-row-link" href="https://example.test/en/admin/drafts/edit/1">', self::request('GET', '/en/admin/drafts', self::options())['body']);
    }

    public function testCanViewPasswordsHiddenOnViewAndFormatOnView(): void
    {
        self::pdo()->exec("UPDATE posts SET views = 1200 WHERE id = 1");
        $body = self::request('GET', '/en/admin/notes/view/1', self::viewer())['body'];
        self::assertStringContainsString('1200 views', $body, 'formatOnView()');
        self::assertStringNotContainsString('data-field="slug"', $body, 'hiddenOnView()');
        self::assertStringNotContainsString('data-field="secret"', $body, 'passwords are never shown');
        self::assertStringNotContainsString('never-shown', $body);
        self::assertSame(403, self::request('GET', '/en/admin/notes/view/2', self::viewer())['status'], 'canView() false');
        self::assertSame(403, self::request('GET', '/en/admin/notes/view/2', self::options())['status'], 'for editors too');
    }

    public function testTheLanguageSwitchMarksWhatIsNotTranslated(): void
    {
        $two = self::request('GET', '/en/admin/posts/view/2', self::viewer())['body'];
        self::assertStringContainsString('class="fa-view-switch"', $two);
        self::assertMatchesRegularExpression('~href="[^"]*posts/view/2\?showing=ar"[^>]*>AR<span class="fa-view-dot"~i', $two, 'Arabic has something missing');
        self::assertStringContainsString('aria-current="true"', $two);

        $arabic = self::request('GET', '/en/admin/posts/view/2?showing=ar', self::viewer())['body'];
        self::assertStringContainsString('<span class="fa-view-missing">Not translated</span>', $arabic, 'no English stand-in');
        self::assertStringNotContainsString('Mountain cabin</dd>', $arabic);
        self::assertStringContainsString('dir="rtl" lang="ar"', $arabic);

        $one = self::request('GET', '/en/admin/posts/view/1?showing=ar', self::viewer())['body'];
        self::assertStringContainsString('فيلا', $one);
        self::assertStringNotContainsString('<dt>Body</dt><dd dir="rtl" lang="ar"><span class="fa-view-missing">', $one, 'empty in every language is empty, not "not translated"');

        // An unknown language falls back to the default
        self::assertStringContainsString('Mountain cabin', self::request('GET', '/en/admin/posts/view/2?showing=xx', self::viewer())['body']);
        // The panel in Arabic shows Arabic first, and the switch still reaches English
        $panelAr = self::request('GET', '/ar/admin/posts/view/2', self::viewer())['body'];
        self::assertStringContainsString('<span class="fa-view-missing">', $panelAr);
        self::assertMatchesRegularExpression('~href="[^"]*posts/view/2\?showing=en"~', $panelAr);
        self::assertStringContainsString('Mountain cabin', self::request('GET', '/ar/admin/posts/view/2?showing=en', self::viewer())['body']);
    }

    public function testSearchRecentRecordsAndActivityLinkToTheViewPageForViewers(): void
    {
        $search = self::request('GET', '/admin/search?q=sea', self::viewer(['headers' => ['Accept' => 'application/json']]));
        $data = json_decode($search['body'], true);
        self::assertIsArray($data, $search['body']);
        $urls = array_merge(...array_map(static fn (array $group): array => array_column($group['items'], 'url'), $data['groups']));
        self::assertContains('https://example.test/en/admin/posts/view/1', $urls);

        $opened = self::request('GET', '/en/admin/posts/view/1', self::viewer());
        $recent = self::request('GET', '/admin/search', self::viewer(['session' => $opened['session'], 'headers' => ['Accept' => 'application/json']]));
        self::assertStringContainsString('posts/view/1', $recent['body'], 'remembered as a recent record, with its view page');
    }

    public function testHistoryIsForEditorsOrPeopleWhoMaySeeActivity(): void
    {
        $pdo = self::pdo();
        $file = self::$root . '/view-history-migration.php';
        file_put_contents($file, \FloCMS\Admin\Console\Stub::render('trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']));
        (require $file)->up(new \FloCMS\Core\Database($pdo));
        try {
            $saved = self::post('/en/admin/posts/edit/1', ['title' => ['en' => 'Sea view villa', 'ar' => 'فيلا'], 'slug' => 'sea-view-villa', 'status' => 'draft', 'featured' => '1', '_close' => '1'], self::options());
            self::assertTrue($saved['redirected'], $saved['body']);

            $editor = self::request('GET', '/en/admin/posts/view/1', self::options())['body'];
            self::assertStringContainsString('data-admin-drawer="fa-history-drawer"', $editor);
            self::assertStringContainsString('Updated', $editor);
            self::assertStringContainsString('by Sara', $editor, 'the latest Activity entry names who changed it');

            $viewer = self::request('GET', '/en/admin/posts/view/1', self::viewer())['body'];
            self::assertStringNotContainsString('fa-history-drawer', $viewer, 'a viewer without activity.view');
            self::assertSame(403, self::request('GET', '/en/admin/posts/history/1', self::viewer())['status']);

            $auditor = [1 => ['content.posts.view', 'activity.view']];
            self::assertStringContainsString('fa-history-drawer', self::request('GET', '/en/admin/posts/view/1', self::options(['permissions' => $auditor]))['body']);
            self::assertSame(200, self::request('GET', '/en/admin/posts/history/1', self::options(['permissions' => $auditor]))['status']);
            // The Activity page links the record to the page they may open
            $activity = self::request('GET', '/en/admin/activity', self::options(['permissions' => $auditor]))['body'];
            self::assertStringContainsString('href="https://example.test/en/admin/posts/view/1"', $activity);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS admin_trash');
            $pdo->exec('DROP TABLE IF EXISTS admin_activity');
        }
    }

    public function testEditLinksFollowTheListsEditAction(): void
    {
        // No Edit action in the list: editors open records on the view page, without an Edit button
        $list = self::request('GET', '/en/admin/entries', self::options())['body'];
        self::assertStringContainsString('<a class="fa-row-link" href="https://example.test/en/admin/entries/view/1">', $list);
        self::assertStringNotContainsString('/entries/edit/', $list);
        $view = self::request('GET', '/en/admin/entries/view/1', self::options())['body'];
        self::assertStringNotContainsString('/entries/edit/1', $view);
        self::assertSame(404, self::request('GET', '/en/admin/entries/edit/1', self::options())['status']);
    }
}
