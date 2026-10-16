<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Http\AssetController;
use FloCMS\Admin\Support\RichText;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Uploads\EditorMedia;
use FloCMS\Admin\Validation\FormValidator;
use FloCMS\Core\Http\Request;

/** The rich text cleaner, the RichEditor field, its markup and the video part uploads. */
final class RichTextTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function unsafe(): iterable
    {
        yield 'event handler' => ['<p onclick="x()" style="color:red" class="a" id="b">a</p>', '<p>a</p>'];
        yield 'script and style' => ['<p>a</p><script>alert(1)</script><style>p{}</style>', '<p>a</p>'];
        yield 'javascript links' => ['<a href="javascript:alert(1)">x</a><a href=" JaVaScRiPt:alert(1)">y</a><a href="java&#x09;script:alert(1)">z</a>', 'xyz'];
        yield 'protocol-relative and credentials' => ['<a href="//evil.test">p</a><a href="https://u:p@x.test">q</a>', 'pq'];
        yield 'image handlers and data URIs' => ['<img src="x" onerror="alert(1)"><img src="data:image/png;base64,AA">', ''];
        yield 'foreign iframe' => ['<iframe src="https://evil.test"></iframe><p>a</p>', '<p>a</p>'];
        yield 'forged embed iframe is rebuilt' => ['<div data-embed="youtube" data-id="dQw4w9WgXcQ"><iframe src="https://evil.test" onload="x"></iframe></div>', RichText::embedHtml(RichText::embed('https://youtu.be/dQw4w9WgXcQ'))];
        yield 'bad embed id' => ['<div data-embed="youtube" data-id="&quot;onload=x"></div>', ''];
        yield 'svg and math' => ['<svg><script>alert(1)</script></svg><math><mtext><table><mglyph><style><img src=x onerror=alert(1)>', ''];
        yield 'noscript mutation' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></p></noscript>', ''];
        yield 'form controls' => ['<form><button formaction="javascript:x">b</button></form><input autofocus onfocus=x>', ''];
        yield 'video only from mp4/webm addresses' => ['<video src="/v.mp4" autoplay onplay="x"></video><video src="javascript:x.mp4"></video><video src="/a.mov"></video>', '<video src="/v.mp4" controls preload="metadata"></video>'];
        yield 'attribute escaping' => ['<img src="/a.jpg" alt="&quot;&gt;&lt;script&gt;">', '<img src="/a.jpg" alt="&quot;&gt;&lt;script&gt;" loading="lazy">'];
    }

    /** @dataProvider unsafe */
    public function testUnsafeMarkupIsRemoved(string $input, string $expected): void
    {
        self::assertSame($expected, RichText::clean($input));
    }

    public function testEditorMarkupIsKeptAndNormalised(): void
    {
        self::assertSame('<p>Hello <strong>b</strong> <em>i</em> <u>u</u> <s>s</s></p>', RichText::clean('<p>Hello <b>b</b> <i>i</i> <u>u</u> <del>s</del></p>'));
        self::assertSame('<h2>T</h2><h4>t</h4><ul><li><p>one</p></li></ul><ol start="3"><li>c</li></ol><blockquote><p>q</p></blockquote><hr>', RichText::clean('<h1>T</h1><h6>t</h6><ul><li><p>one</p></li></ul><ol start="3"><li>c</li></ol><blockquote><p>q</p></blockquote><hr>'));
        self::assertSame("<pre><code>a  b\nc</code></pre>", RichText::clean("<pre><code>a  b\nc</code></pre>"));
        self::assertSame('<a href="https://x.test" target="_blank" rel="noopener noreferrer">x</a><a href="/about">a</a><a href="mailto:a@b.test">m</a><a href="tel:+964 750">t</a><a href="#top">h</a>',
            RichText::clean('<a href="https://x.test" target="_blank">x</a><a href="/about">a</a><a href="mailto:a@b.test">m</a><a href="tel:+964 750">t</a><a href="#top">h</a>'));
        self::assertSame('<img src="/uploads/a.jpg" alt="Sea" width="800" loading="lazy">', RichText::clean('<img src="/uploads/a.jpg" alt="Sea" width="800" height="99999">'));
        self::assertSame('<p>عربی <u>کوردی</u></p>', RichText::clean('<p>عربی <u>کوردی</u></p><p></p><p><br></p>'));
        self::assertSame('', RichText::clean('<p><br></p><p>&nbsp;</p>'));
    }

    public function testMediaCanBeSwitchedOff(): void
    {
        $html = '<p>a</p><img src="/a.jpg"><video src="/v.mp4"></video><div data-embed="vimeo" data-id="76979871"></div>';
        self::assertSame('<p>a</p>', RichText::clean($html, images: false, videos: false, embeds: []));
        self::assertStringContainsString('player.vimeo.com', RichText::clean($html, embeds: ['vimeo']));
        self::assertStringNotContainsString('data-embed', RichText::clean($html, embeds: ['youtube']));
    }

    public function testVideoLinksAreRecognised(): void
    {
        $id = static fn (string $url): ?string => RichText::embed($url)['id'] ?? null;
        self::assertSame('dQw4w9WgXcQ?start=90', $id('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1m30s'));
        self::assertSame('dQw4w9WgXcQ?start=42', $id('youtu.be/dQw4w9WgXcQ?t=42'));
        self::assertSame('dQw4w9WgXcQ', $id('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        self::assertSame('dQw4w9WgXcQ', $id('https://m.youtube.com/embed/dQw4w9WgXcQ'));
        self::assertSame('76979871', $id('https://vimeo.com/76979871'));
        self::assertSame('76979871?h=abcdef1234', $id('https://vimeo.com/76979871/abcdef1234'));
        self::assertSame('https://player.vimeo.com/video/76979871?dnt=1&h=abcdef1234', RichText::embed('https://vimeo.com/76979871/abcdef1234')['src']);
        foreach (['https://evil.test/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', 'javascript://youtube.com/%0aalert(1)', 'https://www.youtube.com/watch?v=short', 'https://vimeo.com/abc'] as $url) {
            self::assertNull(RichText::embed($url), $url);
        }
        self::assertNull(RichText::embed('https://vimeo.com/76979871', ['youtube']));
    }

    public function testPlainTextAndEmptiness(): void
    {
        self::assertSame('One Two three', RichText::plain('<p>One</p><p>Two <b>three</b></p>'));
        self::assertSame('One Two…', RichText::plain('<p>One</p><p>Two three</p>', 8));
        self::assertTrue(RichText::isEmpty('<p><br></p><p>&nbsp;</p>'));
        self::assertFalse(RichText::isEmpty('<p><img src="/a.jpg"></p>'));
    }

    public function testFieldOptionsAreChecked(): void
    {
        foreach ([fn () => RichEditor::make('b')->toolbar([]), fn () => RichEditor::make('b')->toolbar(['bold', 'bold']), fn () => RichEditor::make('b')->toolbar(['table']),
            fn () => RichEditor::make('b')->embeds(['dailymotion']), fn () => RichEditor::make('b')->maxImageSize(0), fn () => RichEditor::make('b')->maxVideoSize(4096),
            fn () => RichEditor::make('b')->maxLength(10)] as $invalid) {
            try { $invalid(); self::fail('Accepted an invalid option'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $field = RichEditor::make('body')->toolbar(['bold', 'image', 'embed'])->images(false);
        self::assertSame(['bold', 'embed'], $field->getToolbar());
        self::assertFalse($field->allowsImages());
        self::assertFalse($field->allowsVideos());
        self::assertSame(['youtube', 'vimeo'], $field->getEmbeds());
        self::assertSame('full', $field->getColumnSpan());
        self::assertTrue(RichEditor::make('body')->translatable()->isTranslatable());
    }

    public function testFieldCleansInputAndReportsOverlongText(): void
    {
        $field = RichEditor::make('body')->required()->maxLength(1024);
        self::assertSame('<p>a</p>', $field->stateFromInput(['body' => "<p onclick=\"x\">a</p>\r\n<script>x</script>"], ['en']));
        $form = (new Form())->schema([$field]);
        $validator = new FormValidator(['en'], 'en');
        self::assertSame(['body' => 'Body is required.'], $validator->validate($form, ['body' => $field->stateFromInput(['body' => '<p><br></p>'], ['en'])]));
        $long = $field->stateFromInput(['body' => '<p>' . str_repeat('a', 2000) . '</p>'], ['en']);
        self::assertSame(['body' => 'Body is too long (at most 1 KB of formatted text).'], $validator->validate($form, ['body' => $long]));
        // A huge submission is not cleaned (no time spent on it) and still fails.
        self::assertSame(['body' => 'Body is too long (at most 1 KB of formatted text).'], $validator->validate($form, ['body' => $field->stateFromInput(['body' => str_repeat('<b>x</b>', 2000)], ['en'])]));
    }

    private function editor(RichEditor $field, array $state, array $errors = [], ?string $media = '/en/admin/articles/media', ?string $key = null, array $languages = ['en', 'ar', 'ku']): string
    {
        return (new FormRenderer($state, $errors, $languages, 'en', true, false, 'p-', true, null, $media, $key))->render((new Form())->schema([$field]));
    }

    public function testMarkupHasTabsPanelsAndAKeyThatChangesWithErrors(): void
    {
        $field = RichEditor::make('body')->translatable();
        $state = ['body' => ['en' => '<p>Hi</p>', 'ar' => '', 'ku' => '<p>سڵاو</p>']];
        $html = $this->editor($field, $state, key: 'operation-1');
        self::assertSame(3, substr_count($html, 'role="tab"'));
        self::assertStringContainsString('<span class="fa-dot is-empty" data-lang="ar"></span>', $html);
        self::assertStringContainsString('<span class="fa-dot is-filled" data-lang="ku"></span>', $html);
        self::assertMatchesRegularExpression('~<div class="fa-rich-panel" id="p-f-body-ar-panel" data-lang="ar" dir="rtl" lang="ar" aria-labelledby="p-f-body-ar-tab">~', $html);
        self::assertStringContainsString('<textarea id="p-f-body" name="body[en]"', $html);
        self::assertStringContainsString('<textarea id="p-f-body-ku" name="body[ku]" dir="rtl" lang="ku"', $html);
        preg_match('/data-rx-ignore="([a-f0-9]{16})"/', $html, $first);
        preg_match('/data-rx-ignore="([a-f0-9]{16})"/', $this->editor($field, $state, key: 'operation-1'), $same);
        preg_match('/data-rx-ignore="([a-f0-9]{16})"/', $this->editor($field, $state, ['body.ar' => 'Too long'], key: 'operation-1'), $errored);
        preg_match('/data-rx-ignore="([a-f0-9]{16})"/', $this->editor($field, $state, key: 'operation-2'), $other);
        self::assertSame($first[1], $same[1]);
        self::assertNotSame($first[1], $errored[1]);
        self::assertNotSame($first[1], $other[1]);
        self::assertStringNotContainsString('data-rx-ignore', $this->editor($field, $state));
    }

    public function testInvalidLanguagesAreMarkedAndExplained(): void
    {
        $html = $this->editor(RichEditor::make('body')->translatable(), ['body' => ['en' => '', 'ar' => '<p>x</p>', 'ku' => '']], ['body' => 'Body is required.', 'body.ar' => 'Too long.']);
        self::assertStringContainsString('<span class="fa-dot is-invalid" data-lang="en"></span>', $html);
        self::assertStringContainsString('<span class="fa-dot is-invalid" data-lang="ar"></span>', $html);
        self::assertMatchesRegularExpression('~<textarea id="p-f-body" name="body\[en\]"[^>]* aria-invalid="true" aria-describedby="p-f-body-error"~', $html);
        self::assertStringContainsString('<p class="fa-error" role="alert" id="p-f-body-ar-error">Too long.</p>', $html);
    }

    public function testWithoutAnUploadAddressThereIsNoVideoButtonAndNoFileInput(): void
    {
        $html = $this->editor(RichEditor::make('body'), ['body' => ''], [], null, null, ['en']);
        self::assertStringNotContainsString('data-command="video"', $html);
        self::assertStringNotContainsString('data-rich-file', $html);
        // Without uploads the Image button opens the link dialog directly: no menu.
        self::assertStringNotContainsString('data-rich-menu', $html);
        self::assertMatchesRegularExpression('~data-command="image" title="Image" aria-label="Image" aria-haspopup="dialog"~', $html);
        self::assertStringContainsString('<dialog class="fa-rich-dialog" id="p-f-body-image-dialog" data-rich-dialog="image"', $html);
        self::assertStringContainsString('&quot;uploads&quot;:false', $html);
        self::assertStringContainsString('<textarea id="p-f-body" name="body"', $html);
        self::assertStringNotContainsString('fa-rich-tabs', $html);
        self::assertStringNotContainsString('data-rich-toolbar', $this->editor(RichEditor::make('body')->disabled(), ['body' => '']));
    }

    public function testWithUploadsImageOffersAMenuAndFilesOpenTheFilePickerDirectly(): void
    {
        $html = $this->editor(RichEditor::make('body')->videos(), ['body' => ''], [], '/en/admin/articles/media', null, ['en']);
        self::assertMatchesRegularExpression('~data-command="image"[^>]* aria-haspopup="menu" aria-expanded="false" aria-controls="p-f-body-image-menu"~', $html);
        self::assertStringContainsString('<div class="fa-rich-menu" id="p-f-body-image-menu" role="menu"', $html);
        self::assertStringContainsString('data-rich-action="image-upload"', $html);
        self::assertStringContainsString('data-rich-action="image-link"', $html);
        self::assertStringContainsString('<input type="file" class="fa-rich-file-input" accept="image/jpeg,image/png,image/gif,image/webp" data-rich-file="image"', $html);
        self::assertStringContainsString('accept="video/mp4,video/webm" data-rich-file="video"', $html);
        // Dialogs never nest a <form> inside the record form; every button is type=button.
        self::assertStringNotContainsString('<form', $html);
        self::assertSame(0, preg_match('~<button(?![^>]*type="button")~', $html));
        // Icons: one crisp 20px Lucide icon per button, shortcuts in the tooltip.
        self::assertStringContainsString('title="Bold (Ctrl+B)"', $html);
        self::assertMatchesRegularExpression('~<svg class="fa-rich-icon"[^>]* viewBox="0 0 24 24" width="20" height="20"[^>]* stroke-width="2"~', $html);
        self::assertStringContainsString('<div class="fa-rich-status" data-rich-status hidden>', $html);
    }

    public function testMediaSettingsHaveSafeDefaults(): void
    {
        Admin::useConfig([], true, '/srv/site');
        self::assertSame(['root' => '/srv/site/public/uploads/editor', 'url' => '/uploads/editor'], Admin::config()->media());
        Admin::useConfig(['media' => ['root' => 'web/media', 'url' => 'https://cdn.test/media/']], true, '/srv/site');
        self::assertSame(['root' => '/srv/site/web/media', 'url' => 'https://cdn.test/media'], Admin::config()->media());
        foreach (['javascript:alert(1)', '//evil.test/m', 'media', '/m" onload="x'] as $url) {
            Admin::useConfig(['media' => ['url' => $url]], true, '/srv/site');
            self::assertSame('/uploads/editor', Admin::config()->media()['url'], $url);
        }
    }

    public function testTheEditorScriptIsServedLikeTheOtherAssets(): void
    {
        $controller = (new \ReflectionClass(AssetController::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(\FloCMS\Core\Controller::class, 'params');
        $property->setAccessible(true);
        $property->setValue($controller, ['admin-editor-v' . Admin::VERSION . '.js']);
        $controller->setRequest(new Request([], [], ['REQUEST_METHOD' => 'GET'], [], []));
        $response = $controller->asset();
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('var FloAdminEditorBundle=', $response->body());
    }

    public function testVideoPartsAreJoinedValidatedAndStoredPublicly(): void
    {
        $root = sys_get_temp_dir() . '/flo-admin-media-' . bin2hex(random_bytes(4));
        Admin::useConfig(['media' => ['root' => $root . '/public/uploads/editor', 'url' => '/uploads/editor']], true, $root);
        $_SESSION = ['user_id' => 1, '_csrf_token' => 'tok'];
        $field = RichEditor::make('body')->maxVideoSize(8);
        $clip = (string) file_get_contents(dirname(__DIR__) . '/Browser/fixtures/clip.mp4');
        try {
            $start = EditorMedia::start($field, 'articles', 'Tour of the villa.mp4', strlen($clip));
            $offset = 0;
            foreach (str_split($clip, 1024) as $part) {
                $stream = fopen('php://memory', 'w+b');
                fwrite($stream, $part); rewind($stream);
                $status = EditorMedia::append($field, 'articles', $start['upload_id'], $offset, $stream);
                fclose($stream);
                $offset = $status['received'];
            }
            self::assertTrue($status['complete']);
            // Someone else's session cannot finish it.
            $_SESSION = ['user_id' => 2, '_csrf_token' => 'tok'];
            try { EditorMedia::complete($field, 'articles', $start['upload_id']); self::fail('Another user completed the upload'); }
            catch (\FloCMS\Uploader\Exceptions\UploadException) { self::assertTrue(true); }
            $_SESSION = ['user_id' => 1, '_csrf_token' => 'tok'];
            $url = EditorMedia::complete($field, 'articles', $start['upload_id'])['url'];
            self::assertMatchesRegularExpression('~^/uploads/editor/articles/body/\d{4}/\d{2}/[a-z0-9]+\.mp4$~', $url);
            self::assertSame($clip, file_get_contents($root . '/public' . $url));
            // A file that is not a video is refused when the parts are joined.
            $fake = EditorMedia::start($field, 'articles', 'fake.mp4', 13);
            $stream = fopen('php://memory', 'w+b'); fwrite($stream, '<?php echo 1;'); rewind($stream);
            EditorMedia::append($field, 'articles', $fake['upload_id'], 0, $stream);
            try { EditorMedia::complete($field, 'articles', $fake['upload_id']); self::fail('Stored a fake video'); }
            catch (\FloCMS\Uploader\Exceptions\ValidationException) { self::assertTrue(true); }
            self::assertCount(1, glob($root . '/public/uploads/editor/articles/body/*/*/*.mp4') ?: []);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
            $_SESSION = [];
        }
    }
}
