<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Resources\{ArticleResource, PostResource};

/** RichEditor through real requests: rendering, server-side cleaning and the media endpoint's guards. */
final class RichEditorRequestTest extends RequestTestCase
{
    private function options(array $extra = []): array
    {
        return ['admin_config' => $extra + ['resources' => [ArticleResource::class, PostResource::class], 'media' => ['root' => self::$root . '/public/uploads/editor', 'url' => '/uploads/editor']]];
    }

    public function testEditPageRendersTheEditorWithLanguageTabsAndNoJavaScriptFallback(): void
    {
        $page = self::request('GET', '/en/admin/articles/edit/1', $this->options());
        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertMatchesRegularExpression('~data-admin-rich-editor data-script="https://example\.test/en/flo-admin/asset/admin-editor-v0\.13\.0\.js"~', $body);
        self::assertStringContainsString('<textarea id="f-body" name="body[en]" dir="ltr" lang="en"', $body);
        self::assertStringContainsString('name="body[ar]" dir="rtl" lang="ar"', $body);
        self::assertStringContainsString('&lt;p&gt;Hello&lt;/p&gt;</textarea>', $body);
        // Tabs and toolbar are hidden until the script runs; without it each language is labelled.
        self::assertMatchesRegularExpression('~<div class="fa-rich-tabs" role="tablist"[^>]* data-rich-tabs hidden>~', $body);
        self::assertMatchesRegularExpression('~<div class="fa-rich-toolbar" role="toolbar"[^>]* data-rich-toolbar hidden>~', $body);
        self::assertStringContainsString('<p class="fa-rich-lang" data-rich-lang-title><span class="fa-code">ku</span>', $body);
        self::assertStringContainsString('data-config="{&quot;toolbar&quot;:[&quot;heading2&quot;', $body);
        self::assertStringContainsString('&quot;media&quot;:&quot;https://example.test/en/admin/articles/media/body&quot;', $body);
        // Standard pages are not morphed by Reactive, so no ignore key is needed.
        self::assertStringNotContainsString('data-rx-ignore', $body);
        // The single-language note: plain name, its four buttons only, no tabs.
        $start = strpos($body, 'data-field="note"');
        $note = [$start === false ? '' : substr($body, $start, (int) strpos($body, '</form>', $start) - $start)];
        self::assertStringContainsString('name="note"', $note[0] ?? '');
        self::assertSame(4, substr_count($note[0] ?? '', 'class="fa-rich-btn"'));
        self::assertStringNotContainsString('fa-rich-tabs', $note[0] ?? '');
        self::assertStringNotContainsString('data-rich-dialog="image"', $note[0] ?? '');
    }

    public function testSavedHtmlIsCleanedOnTheServerWhateverTheBrowserSends(): void
    {
        $saved = self::post('/en/admin/articles/edit/1', ['title' => ['en' => 'Welcome'], 'body' => [
            'en' => '<p onclick="steal()">Safe <strong>text</strong></p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">link</a><p></p>',
            'ar' => '<iframe src="https://evil.test"></iframe><div data-embed="youtube" data-id="dQw4w9WgXcQ"></div>',
        ], 'note' => '<p>Note <img src="/uploads/a.png"></p>'], $this->options());
        self::assertTrue($saved['redirected'], (string) $saved['body']);
        $row = self::row('articles', 1);
        $body = json_decode((string) $row['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('<p>Safe <strong>text</strong></p>link', $body['en']);
        self::assertStringStartsWith('<div data-embed="youtube" data-id="dQw4w9WgXcQ"><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $body['ar']);
        self::assertStringNotContainsString('evil.test', $body['ar']);
        // The note has no Image button, so images are not kept there.
        self::assertSame('<p>Note </p>', $row['note']);
    }

    public function testEmptyFormattingCountsAsEmptyForRequired(): void
    {
        $response = self::post('/en/admin/articles/edit/1', ['title' => ['en' => 'Welcome'], 'body' => ['en' => '<p><br></p><p>&nbsp;</p>']], $this->options());
        self::assertFalse($response['redirected']);
        self::assertStringContainsString('Body is required.', $response['body']);
        self::assertSame('{"en":"<p>Hello</p>"}', self::row('articles', 1)['body']);
    }

    public function testMediaEndpointChecksMethodPermissionFieldAndStep(): void
    {
        self::assertSame(405, self::request('GET', '/en/admin/articles/media/body/image', $this->options() + ['expect_error' => true])['status']);
        foreach (['title/image', 'missing/image', 'body/delete', 'body', 'note/image', 'note/start'] as $path) {
            self::assertSame(404, self::post('/en/admin/articles/media/' . $path, [], $this->options() + ['expect_error' => true])['status'], $path);
        }
        // Role 0 may not create or edit articles.
        $user = ['admin_access' => true, 'isloggedin' => true, 'role' => 0, 'user_id' => 4, 'fullname' => 'Dara', '_csrf_token' => 'tok'];
        self::assertSame(403, self::post('/en/admin/articles/media/body/image', [], $this->options() + ['session' => $user, 'expect_error' => true, 'permissions' => self::PERMISSIONS + [0 => ['content.articles.view']]])['status']);
        // CSRF is checked before anything else.
        self::assertSame(419, self::request('POST', '/en/admin/articles/media/body/image', $this->options() + ['post' => ['_token' => 'wrong'], 'expect_error' => true])['status']);
    }

    public function testAnImageThatDidNotArriveAsAnUploadIsRefused(): void
    {
        $response = self::post('/en/admin/articles/media/body/image', [], $this->options());
        self::assertSame(422, $response['status'], (string) $response['error'] . substr($response['body'], 0, 300));
        self::assertSame(['error' => 'This image cannot be used. Choose a JPEG, PNG, GIF or WebP file within the size limit.'], json_decode($response['body'], true));
    }

    public function testVideoPartsCheckNameSizeAndUploadId(): void
    {
        $refused = self::post('/en/admin/articles/media/body/start', ['name' => 'tour.php', 'size' => '1000'], $this->options());
        self::assertSame(422, $refused['status']);
        self::assertSame(422, self::post('/en/admin/articles/media/body/start', ['name' => 'tour.mp4', 'size' => (string) (9 * 1024 * 1024)], $this->options())['status']);
        self::assertSame(400, self::post('/en/admin/articles/media/body/start', ['name' => 'tour.mp4', 'size' => '-5'], $this->options() + ['expect_error' => true])['status']);

        $started = self::post('/en/admin/articles/media/body/start', ['name' => 'tour.mp4', 'size' => '3000'], $this->options());
        self::assertSame(200, $started['status']);
        $data = json_decode($started['body'], true, 4, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $data['upload_id']);
        self::assertSame(['chunk_size' => 1048576, 'received' => 0], array_diff_key($data, ['upload_id' => true]));
        self::assertSame(400, self::post('/en/admin/articles/media/body/chunk', ['upload_id' => $data['upload_id'], 'offset' => '0'], $this->options() + ['session' => $started['session'], 'expect_error' => true])['status']);
        self::assertSame(400, self::post('/en/admin/articles/media/body/chunk', ['upload_id' => '../x', 'offset' => '0'], $this->options() + ['session' => $started['session'], 'expect_error' => true])['status']);
        self::assertSame(422, self::post('/en/admin/articles/media/body/complete', ['upload_id' => $data['upload_id']], $this->options() + ['session' => $started['session']])['status']);
        // Another user cannot continue it.
        $other = self::ADMIN;
        self::assertSame(422, self::post('/en/admin/articles/media/body/abort', ['upload_id' => $data['upload_id']], $this->options() + ['session' => $other])['status']);
        $aborted = self::post('/en/admin/articles/media/body/abort', ['upload_id' => $data['upload_id']], $this->options() + ['session' => $started['session']]);
        self::assertSame(['ok' => true], json_decode($aborted['body'], true));
        self::assertSame([], glob(self::$root . '/storage/admin-uploads/chunks/' . $data['upload_id'] . '*') ?: []);
    }
}
