<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Console\Stub;

/**
 * Pages (0.30) through whole requests: addresses (with parents and in each
 * language), the home page, drafts and dates, previews, the sitemap, the
 * meta tags, and the editor in the panel.
 */
final class PagesRequestTest extends RequestTestCase
{
    private const ON = ['admin_config' => ['pages' => true]];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_pages');
        $file = self::$root . '/pages-migration.php';
        file_put_contents($file, Stub::render('pages-migration', ['table' => 'admin_pages']));
        (require $file)->up(new \FloCMS\Core\Database($pdo));
        $insert = $pdo->prepare('INSERT INTO admin_pages (id, title, slug, type, blocks, html, body, status, publish_at, unpublish_at, parent_id, menu, sort, meta_description, noindex, is_home, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $blocks = json_encode(['en' => [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Welcome home', 'level' => 'h1']]]]);
        foreach ([
            [1, '{"en":"Home"}', '{"en":"home"}', 'builder', $blocks, null, null, 'published', null, null, null, 0, 0, null, 0, 1],
            [2, '{"en":"About us","ar":"من نحن"}', '{"en":"about-us","ar":"من-نحن"}', 'rich_text', null, null, '{"en":"<p>We build websites.</p>","ar":"<p>نبني المواقع.</p>"}', 'published', null, null, null, 1, 1, '{"en":"Who we are"}', 0, 0],
            [3, '{"en":"Services"}', '{"en":"services"}', 'rich_text', null, null, '{"en":"<p>All services</p>"}', 'published', null, null, null, 1, 2, null, 0, 0],
            [4, '{"en":"Web design"}', '{"en":"web-design"}', 'html', null, '{"en":"<section class=\"custom\">Design work</section>"}', null, 'published', null, null, 3, 1, 0, null, 0, 0],
            [5, '{"en":"Secret plans"}', '{"en":"plans"}', 'rich_text', null, null, '{"en":"<p>Draft</p>"}', 'draft', null, null, null, 1, 3, null, 0, 0],
            [6, '{"en":"Coming soon"}', '{"en":"soon"}', 'rich_text', null, null, '{"en":"<p>Later</p>"}', 'scheduled', '2099-01-01 09:00:00', null, null, 0, 4, null, 0, 0],
            [7, '{"en":"Old offer"}', '{"en":"offer"}', 'rich_text', null, null, '{"en":"<p>Gone</p>"}', 'published', null, '2020-01-01 09:00:00', null, 0, 5, null, 0, 0],
            [8, '{"en":"Under a draft"}', '{"en":"inside"}', 'rich_text', null, null, '{"en":"<p>x</p>"}', 'published', null, null, 5, 0, 6, null, 0, 0],
            [9, '{"en":"Thanks"}', '{"en":"thanks"}', 'rich_text', null, null, '{"en":"<p>Thank you</p>"}', 'published', null, null, null, 0, 7, null, 1, 0],
        ] as $row) { $insert->execute([...$row, '2026-10-20 10:00:00']); }
    }

    protected function tearDown(): void
    {
        self::pdo()->exec('DROP TABLE IF EXISTS admin_pages');
        parent::tearDown();
    }

    private static function visit(string $uri, array $options = []): array
    {
        return self::request('GET', $uri, $options + ['session' => []] + self::ON);
    }

    public function testPublishedPagesAnswerAtTheirAddresses(): void
    {
        $about = self::visit('/en/about-us');
        self::assertSame(200, $about['status']);
        self::assertStringContainsString('<h1 class="flo-page-title">About us</h1>', $about['body']);
        self::assertStringContainsString('<div class="flo-rich-text"><p>We build websites.</p></div>', $about['body']);

        $nested = self::visit('/en/services/web-design');
        self::assertSame(200, $nested['status'], 'a parent page and a hyphen in second place (core 2.6 fallbacks)');
        self::assertStringContainsString('<section class="custom">Design work</section>', $nested['body'], 'HTML as written');

        self::assertSame(404, self::visit('/en/web-design')['status'], 'only under its parent');
        self::assertSame(404, self::visit('/en/nowhere')['status']);
    }

    public function testDraftsDatesAndPagesUnderDraftsAre404(): void
    {
        foreach (['/en/plans', '/en/soon', '/en/offer', '/en/plans/inside'] as $uri) {
            self::assertSame(404, self::visit($uri)['status'], $uri);
        }
    }

    public function testTheHomePageAndLanguages(): void
    {
        $home = self::visit('/en/');
        self::assertSame(200, $home['status']);
        self::assertStringContainsString('Welcome home', $home['body']);

        $arabic = self::visit('/ar/' . rawurlencode('من-نحن'));
        self::assertSame(200, $arabic['status']);
        self::assertStringContainsString('نبني المواقع', $arabic['body']);

        // Not translated: the default language's page, pointing search engines at it
        $kurdish = self::visit('/ku/about-us');
        self::assertSame(200, $kurdish['status']);
        self::assertStringContainsString('We build websites.', $kurdish['body']);
        self::assertSame(404, self::visit('/ku/about-us', ['admin_config' => ['pages' => ['missing' => '404']]])['status']);
    }

    public function testMetaTagsInTheLayout(): void
    {
        file_put_contents(self::$root . '/templates/default/layouts/default.html', '<head>{!! page_meta() !!}</head><body>{!! $content !!}</body>');
        try {
            $page = self::visit('/en/about-us')['body'];
            self::assertStringContainsString('<title>About us</title>', $page);
            self::assertStringContainsString('<meta name="description" content="Who we are">', $page);
            self::assertStringContainsString('<link rel="canonical" href="https://example.test/en/about-us">', $page);
            self::assertStringContainsString('hreflang="ar" href="https://example.test/ar/' . rawurlencode('من-نحن') . '"', $page);
            self::assertStringContainsString('hreflang="x-default"', $page);
            self::assertStringContainsString('<meta property="og:title" content="About us">', $page);

            self::assertStringContainsString('<link rel="canonical" href="https://example.test/en/about-us">', self::visit('/ku/about-us')['body'], 'a stand-in points at the original');
            self::assertStringContainsString('<meta name="robots" content="noindex">', self::visit('/en/thanks')['body']);
        } finally {
            file_put_contents(self::$root . '/templates/default/layouts/default.html', '<?= $data[\'content\'] ?>');
        }
    }

    public function testTheSitemapListsLiveIndexablePages(): void
    {
        $sitemap = self::visit('/sitemap.xml');
        self::assertSame(200, $sitemap['status']);
        self::assertStringContainsString('<loc>https://example.test/en/about-us</loc>', $sitemap['body']);
        self::assertStringContainsString('<loc>https://example.test/en/services/web-design</loc>', $sitemap['body']);
        self::assertStringContainsString('hreflang="ar"', $sitemap['body']);
        foreach (['plans', 'soon', 'offer', 'thanks', 'plans/inside'] as $hidden) { self::assertStringNotContainsString('/en/' . $hidden . '<', $sitemap['body'], $hidden); }
        self::assertSame(404, self::visit('/sitemap.xml', ['admin_config' => ['pages' => ['sitemap' => false]]])['status']);
    }

    public function testWithoutPagesNothingAnswers(): void
    {
        self::assertSame(404, self::request('GET', '/en/about-us', ['session' => []])['status']);
    }

    public function testTheEditorPreviewsUnsavedChanges(): void
    {
        $edit = self::request('GET', '/en/admin/site-pages/edit/2', ['session' => self::SUPER] + self::ON);
        self::assertSame(200, $edit['status'], $edit['body']);
        self::assertStringContainsString('formaction="https://example.test/en/admin/page-preview?page=2"', $edit['body']);
        self::assertStringContainsString('data-seo-preview', $edit['body']);

        $preview = self::post('/en/admin/page-preview?page=2', ['title' => ['en' => 'About us, changed'], 'slug' => ['en' => 'about-us'], 'type' => 'rich_text',
            'body' => ['en' => '<p>Not saved yet</p>'], 'status' => 'published'], ['session' => self::SUPER] + self::ON);
        self::assertTrue($preview['redirected'], substr($preview['body'], 0, 3000) . ' status ' . $preview['status']);
        $files = glob(self::$root . '/storage/cache/page-previews/*.json') ?: [];
        self::assertCount(1, $files, json_encode($preview));
        $shown = self::visit('/en/flo-preview/' . basename($files[0], '.json'));
        self::assertSame(200, $shown['status']);
        self::assertStringContainsString('Not saved yet', $shown['body'], 'anyone with the link, without an account');
        self::assertStringContainsString('<p>We build websites.</p>', (string) self::row('admin_pages', 2)['body'], 'nothing saved');
        self::assertSame(404, self::visit('/en/flo-preview/' . str_repeat('a', 32))['status']);
    }

    public function testWithoutPublishLivePagesAreReadOnly(): void
    {
        $live = self::request('GET', '/en/admin/site-pages/edit/2', ['session' => self::EDITOR] + self::ON);
        self::assertStringNotContainsString('name="title[en]"', $live['body'], 'a published page can\'t be changed without pages.publish');
        $changed = self::post('/en/admin/site-pages/edit/2', ['title' => ['en' => 'Taken over'], 'slug' => ['en' => 'about-us'], 'type' => 'rich_text', 'body' => ['en' => '<p>x</p>']], ['session' => self::EDITOR] + self::ON);
        self::assertStringNotContainsString('Taken over', (string) self::row('admin_pages', 2)['title'], json_encode($changed['status']));

        $draft = self::request('GET', '/en/admin/site-pages/edit/5', ['session' => self::EDITOR] + self::ON);
        self::assertSame(200, $draft['status'], 'drafts stay editable');
        self::assertStringContainsString('name="title[en]"', $draft['body']);
    }

    public function testAnAddressIsCheckedAsVisitorsSeeIt(): void
    {
        // "About us" is /ar/team in Arabic; a new page "team" without an Arabic address answers at /ar/team too (the default language's stands in).
        $update = self::pdo()->prepare('UPDATE admin_pages SET slug = ? WHERE id = 2');
        $update->execute(['{"en":"about-us","ar":"team"}']);
        $clash = self::post('/en/admin/site-pages/create', ['title' => ['en' => 'Team'], 'slug' => ['en' => 'team'], 'type' => 'rich_text', 'body' => ['en' => '<p>x</p>'], 'status' => 'draft'], ['session' => self::SUPER] + self::ON);
        self::assertFalse($clash['redirected']);
        self::assertStringContainsString('already has this address', $clash['body']);
    }

    public function testScheduledPagesWhoseTimeCameAreListedAsPublished(): void
    {
        self::pdo()->exec("UPDATE admin_pages SET publish_at = '2020-01-01 09:00:00' WHERE id = 6");
        self::request('GET', '/en/admin/site-pages', ['session' => self::SUPER] + self::ON);
        self::assertSame('published', self::row('admin_pages', 6)['status']);
        self::assertSame('draft', self::row('admin_pages', 5)['status']);
    }

    public function testSavingChecksAddressesAndSchedules(): void
    {
        $fields = static fn (array $more): array => $more + ['type' => 'rich_text', 'body' => ['en' => '<p>x</p>'], 'status' => 'published'];
        $taken = self::post('/en/admin/site-pages/create', $fields(['title' => ['en' => 'About again'], 'slug' => ['en' => 'about-us']]), ['session' => self::SUPER] + self::ON);
        self::assertStringContainsString('already has this address', $taken['body']);
        $reserved = self::post('/en/admin/site-pages/create', $fields(['title' => ['en' => 'Admin'], 'slug' => ['en' => 'admin']]), ['session' => self::SUPER] + self::ON);
        self::assertStringContainsString('already used by the site', $reserved['body']);

        $made = self::post('/en/admin/site-pages/create', $fields(['title' => ['en' => 'Team members'], 'slug' => ['en' => ''], 'publish_at' => '2099-05-01T09:00']), ['session' => self::SUPER] + self::ON);
        self::assertTrue($made['redirected'], $made['body']);
        $row = self::pdo()->query("SELECT * FROM admin_pages WHERE slug LIKE '%team-members%'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'the address is made from the title');
        self::assertSame('scheduled', $row['status'], 'a time to come');

        // Without pages.publish a new page stays a draft
        $draft = self::post('/en/admin/site-pages/create', $fields(['title' => ['en' => 'Editor page'], 'slug' => ['en' => 'editor-page']]), ['session' => self::EDITOR] + self::ON);
        self::assertTrue($draft['redirected'], $draft['body']);
        self::assertSame('draft', self::pdo()->query("SELECT status FROM admin_pages WHERE slug LIKE '%editor-page%'")->fetchColumn());
    }
}
