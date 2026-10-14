<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use PDO;

/**
 * Whole page requests through FloCMS\Core\App::run(), each in its own PHP
 * process like a real request: login gate, CSRF, permissions, list, create,
 * edit, delete, validation, RTL. The package registers itself exactly as on a
 * site (Composer autoload → bootstrap.php); the database is an SQLite file.
 */
final class PanelRequestTest extends RequestTestCase
{
    public function testVisitorsMustLogInFirst(): void
    {
        $result = self::request('GET', '/admin/posts', ['session' => []]);

        self::assertTrue($result['redirected']);
        self::assertSame('', $result['body']);
    }

    public function testListPage(): void
    {
        $result = self::request('GET', '/en/admin/posts');

        self::assertSame(200, $result['status']);
        $body = $result['body'];
        self::assertStringContainsString('<div class="fa-panel" dir="ltr" lang="en"', $body);
        self::assertStringContainsString('Test Panel', $body);
        self::assertStringContainsString('Sea view villa', $body);
        self::assertStringContainsString('Mountain cabin', $body);
        self::assertStringContainsString('<span class="fa-badge fa-badge-green">Published</span>', $body);
        self::assertStringContainsString('href="https://example.test/en/admin/posts/edit/1"', $body);
        self::assertStringContainsString('action="https://example.test/en/admin/posts/delete/1"', $body);
        self::assertStringContainsString('<input type="hidden" name="_token" value="tok">', $body);
        self::assertStringContainsString('New Post', $body);
        // Navigation: the current page is marked; tags need taxonomy.tags.view, which editors lack
        self::assertStringContainsString('aria-current="page"', $body);
        self::assertStringNotContainsString('/en/admin/post-tags', $body);
        self::assertStringContainsString('admin-v0.4.0.css', $body);
        self::assertStringNotContainsString('<style>', $body);
    }

    public function testSearchAndSort(): void
    {
        $body = self::request('GET', '/admin/posts?q=cabin&sort=title&dir=asc')['body'];

        self::assertStringContainsString('Mountain cabin', $body);
        self::assertStringNotContainsString('Sea view villa', $body);
        self::assertStringContainsString('class="fa-sort is-asc"', $body);
    }

    public function testUnknownResourcesAndCollidingSlugsAre404(): void
    {
        self::assertSame(404, self::request('GET', '/admin/unknown')['status']);
        // FloCMS\Controllers\NewsController (an application controller) wins
        self::assertSame(404, self::request('GET', '/admin/news')['status']);
    }

    public function testMissingPermissionIs403(): void
    {
        $session = ['role' => 0] + self::EDITOR;

        self::assertSame(403, self::request('GET', '/admin/posts', ['session' => $session])['status']);
    }

    public function testWithoutPermissionMapAdminAccessIsEnough(): void
    {
        self::assertSame(200, self::request('GET', '/admin/posts', ['permissions' => null, 'session' => ['role' => 0] + self::EDITOR])['status']);
    }

    public function testCreateFormShowsTranslatableFields(): void
    {
        $body = self::request('GET', '/admin/posts/create')['body'];

        self::assertStringContainsString('name="title[en]"', $body);
        self::assertStringContainsString('name="title[ar]"', $body);
        self::assertMatchesRegularExpression('/name="title\[ar\]"[^>]*dir="rtl"/', $body);
        self::assertStringContainsString('data-slug-from="f-title"', $body);
        self::assertStringContainsString('<input type="hidden" name="featured" value="0">', $body);
    }

    public function testCreateWithoutCsrfTokenIs419(): void
    {
        $result = self::request('POST', '/admin/posts/create', ['post' => ['title' => ['en' => 'X'], 'status' => 'draft']]);

        self::assertSame(419, $result['status']);
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM posts')->fetchColumn());
    }

    public function testCreate(): void
    {
        $result = self::post('/admin/posts/create', [
            'title' => ['en' => 'Lake house', 'ar' => 'بيت البحيرة', 'ku' => ''],
            'slug' => '',
            'body' => ['en' => 'Line 1', 'ar' => '', 'ku' => 'دێڕی یەک'],
            'status' => 'published',
            'views' => '12',
            'featured' => '1',
            'published_on' => '2026-10-12',
            'id' => '999',
        ]);

        self::assertTrue($result['redirected']);
        self::assertSame('success', $result['session']['__flash']['type']);
        self::assertSame('Post created.', $result['session']['__flash']['message']);

        $row = self::row('posts', 3);
        self::assertNotNull($row, 'the id from the input is ignored');
        self::assertSame('{"en":"Lake house","ar":"بيت البحيرة"}', $row['title']);
        self::assertSame('lake-house', $row['slug']);
        self::assertSame('{"en":"Line 1","ku":"دێڕی یەک"}', $row['body']);
        self::assertSame('12', (string) $row['views']);
        self::assertSame('1', (string) $row['featured']);
        self::assertSame('2026-10-12', $row['published_on']);
        self::assertNotNull($row['created_at']);
        self::assertNull(self::row('posts', 999));
    }

    public function testValidationErrorsRenderTheFormAgain(): void
    {
        $result = self::post('/admin/posts/create', [
            'title' => ['en' => 'Short', 'ar' => str_repeat('ف', 30), 'ku' => ''],
            'slug' => 'sea-view-villa',
            'status' => 'other',
            'views' => '-1',
        ]);

        self::assertSame(200, $result['status']);
        $body = $result['body'];
        self::assertStringContainsString('Please correct the errors below.', $body);
        self::assertStringContainsString('Title must not exceed 20 characters.', $body);
        self::assertStringContainsString('data-open-on-load="1"', $body);
        self::assertStringContainsString('This Slug is already used.', $body);
        self::assertStringContainsString('Status must be one of the listed values.', $body);
        self::assertStringContainsString('Views must be at least 0.', $body);
        // Old input is kept
        self::assertStringContainsString('value="Short"', $body);
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM posts')->fetchColumn());
    }

    public function testEdit(): void
    {
        $body = self::request('GET', '/admin/posts/edit/1')['body'];
        self::assertStringContainsString('value="Sea view villa"', $body);
        self::assertStringContainsString('value="فيلا"', $body);
        self::assertStringContainsString('Edit Post', $body);

        $result = self::post('/admin/posts/edit/1', [
            'title' => ['en' => 'Sea view villa 2', 'ar' => '', 'ku' => 'ڤیلا'],
            'slug' => 'sea-view-villa',
            'status' => 'draft',
            '_continue' => '1',
        ]);

        self::assertTrue($result['redirected']);
        $row = self::row('posts', 1);
        self::assertSame('{"en":"Sea view villa 2","ku":"ڤیلا"}', $row['title']);
        self::assertSame('draft', $row['status']);
        self::assertSame('0', (string) $row['featured'], 'unchecked toggle saves 0');
        self::assertNotNull($row['updated_at']);
    }

    public function testEditOfAMissingRecordIs404(): void
    {
        self::assertSame(404, self::request('GET', '/admin/posts/edit/99')['status']);
        self::assertSame(404, self::request('GET', '/admin/posts/edit')['status']);
        self::assertSame(404, self::request('GET', '/admin/posts/edit/1%27%20OR%201')['status']);
    }

    public function testDelete(): void
    {
        self::assertSame(405, self::request('GET', '/admin/posts/delete/2')['status']);
        self::assertNotNull(self::row('posts', 2));

        $result = self::post('/admin/posts/delete/2', ['_back' => 'https://evil.test/']);
        self::assertTrue($result['redirected']);
        self::assertNull(self::row('posts', 2));
        self::assertSame('Post deleted.', $result['session']['__flash']['message']);
    }

    public function testCustomPermissionsHideAndRefuseActions(): void
    {
        $permissions = [1 => ['content.*', 'taxonomy.tags.view']];

        $body = self::request('GET', '/admin/post-tags', ['permissions' => $permissions])['body'];
        self::assertStringContainsString('Garden', $body);
        self::assertStringNotContainsString('/admin/post-tags/edit/1', $body, 'taxonomy.tags.update is not granted');
        self::assertStringNotContainsString('/admin/post-tags/delete/1', $body, 'delete needs settings.delete');
        self::assertStringNotContainsString('/admin/post-tags/create', $body);

        self::assertSame(403, self::post('/admin/post-tags/delete/1', [], ['permissions' => $permissions])['status']);
        self::assertSame(403, self::request('GET', '/admin/post-tags/create', ['permissions' => $permissions])['status']);
        self::assertNotNull(self::row('tags', 1));

        // Without the view permission the whole resource is closed
        self::assertSame(403, self::request('GET', '/admin/post-tags')['status']);
    }

    public function testRightToLeftAdmin(): void
    {
        $body = self::request('GET', '/ku/admin/posts/create')['body'];

        self::assertStringContainsString('<div class="fa-panel" dir="rtl" lang="ku"', $body);
        self::assertStringContainsString('دروستکردن', $body);
        self::assertStringContainsString('ئینگلیزی', $body, 'language names come from the site lang file');
    }

    public function testWithoutConfigFileThePanelIsOff(): void
    {
        rename(self::$root . '/config/admin.php', self::$root . '/config/admin.off');
        try {
            self::assertSame(404, self::request('GET', '/admin/posts')['status']);
        } finally {
            rename(self::$root . '/config/admin.off', self::$root . '/config/admin.php');
        }
    }
}
