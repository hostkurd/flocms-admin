<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Blocks\BlockRenderer;
use FloCMS\Admin\Tests\TestCase;

/** Blocks on the website (0.29): templates, visibility, layout, settings, assets, cache and data. */
final class BlockRendererTest extends TestCase
{
    private const PAGE = [
        ['id' => 'bh1', 'type' => 'heading', 'data' => ['text' => 'Welcome <home>', 'level' => 'h1', 'align' => 'center'], 'settings' => ['anchor' => 'top', 'class' => 'hero bad"class', 'space_above' => 'none', 'devices' => 'desktop']],
        ['id' => 'bd1', 'type' => 'heading', 'data' => ['text' => 'Draft'], 'settings' => ['status' => 'draft']],
        ['id' => 'bf1', 'type' => 'heading', 'data' => ['text' => 'Future'], 'settings' => ['starts' => '2030-01-01 09:00']],
        ['id' => 'be1', 'type' => 'heading', 'data' => ['text' => 'Ended'], 'settings' => ['ends' => '2020-01-01 09:00']],
        ['id' => 'bs1', 'type' => 'section', 'data' => ['name' => 'Services', 'width' => 'narrow', 'background' => 'light'], 'children' => [
            ['id' => 'bc1', 'type' => 'columns', 'data' => ['layout' => '67-33', 'stack' => 1], 'children' => [
                ['id' => 'bt1', 'type' => 'rich_text', 'data' => ['body' => '<p>Left</p>']],
                ['id' => 'bt2', 'type' => 'rich_text', 'column' => 1, 'data' => ['body' => '<p>Right</p>']],
                ['id' => 'bt3', 'type' => 'rich_text', 'column' => 5, 'data' => ['body' => '<p>Lost column</p>']],
            ]],
        ]],
        ['id' => 'bx1', 'type' => 'gone', 'data' => []],
        ['id' => 'bc2', 'type' => 'call_to_action', 'data' => ['heading' => 'Start', 'button' => 'Contact', 'link' => '/contact']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        BlockRenderer::reset(strtotime('2026-10-21 12:00'));
        @array_map('unlink', glob(\FloCMS\Admin\Admin::root() . '/storage/cache/blocks/*.json') ?: []);
    }

    protected function tearDown(): void
    {
        BlockRenderer::reset();
        parent::tearDown();
    }

    public function testPrintsTheBlocksShownNowWithTheirSettings(): void
    {
        $html = blocks(json_encode(self::PAGE));
        self::assertStringContainsString('<div class="flo-block flo-block-heading is-boxed flo-space-above-none flo-space-below-medium flo-only-desktop hero" data-block="heading" data-block-id="bh1" id="top">', $html);
        self::assertStringContainsString('<h1 class="flo-heading is-align-center">Welcome &lt;home&gt;</h1>', $html, 'text is escaped');
        foreach (['Draft', 'Future', 'Ended'] as $hidden) { self::assertStringNotContainsString('>' . $hidden . '<', $html); }
        self::assertStringContainsString('flo-section-inner is-narrow', $html);
        self::assertMatchesRegularExpression('/--flo-column-share: 8">\s*<div class="flo-block flo-block-rich-text[^"]*"[^>]*><div class="flo-rich-text"><p>Left<\/p>/', $html);
        self::assertMatchesRegularExpression('/--flo-column-share: 4">.*Right.*Lost column/s', $html, 'a block of a column that went is in the last one');
        self::assertStringContainsString('flo-columns is-count-2 is-gap-medium is-align-top is-stacking', $html, 'toggles stored as 1/0 and defaults filled in');
        self::assertStringContainsString('<a class="flo-button" href="/contact">Contact</a>', $html);
        self::assertSame(['heading', 'section', 'columns', 'rich_text', 'call_to_action'], BlockRenderer::printed());
    }

    public function testEmptyContainersAndSlidersPrintNothing(): void
    {
        $page = [
            ['id' => 'b1', 'type' => 'section', 'data' => ['background' => 'dark', 'name' => 'Empty'], 'children' => []],
            ['id' => 'b2', 'type' => 'columns', 'data' => ['layout' => '50-50'], 'children' => []],
            ['id' => 'b3', 'type' => 'slider', 'data' => ['slides' => []]],
            ['id' => 'b4', 'type' => 'section', 'data' => ['name' => 'Hidden only'], 'children' => [['id' => 'b5', 'type' => 'heading', 'data' => ['text' => 'Draft'], 'settings' => ['status' => 'draft']]]],
        ];
        self::assertSame('', trim(blocks(json_encode($page))));
    }

    public function testTheBlocksNewOptionsReachTheWebsite(): void
    {
        $page = [
            ['id' => 'b1', 'type' => 'hero', 'data' => ['heading' => 'Hi', 'level' => 'h2', 'height_phone' => 'small', 'button' => 'Go', 'link' => '/go', 'new_tab' => '1']],
            ['id' => 'b2', 'type' => 'heading', 'data' => ['text' => 'Title', 'align' => 'end', 'subtitle' => 'A line under it']],
            ['id' => 'b3', 'type' => 'columns', 'data' => ['layout' => '25-25-25-25'], 'children' => [['id' => 'b4', 'type' => 'heading', 'data' => ['text' => 'One']]]],
            ['id' => 'b5', 'type' => 'section', 'data' => ['background' => 'colour', 'colour' => '#ffeecc', 'tone' => 'dark'], 'children' => [['id' => 'b6', 'type' => 'heading', 'data' => ['text' => 'In']]]],
            ['id' => 'b7', 'type' => 'cards', 'data' => ['cards' => [['title' => 'A', 'link' => '/a', 'button' => 'Read more']]]],
            ['id' => 'b8', 'type' => 'video', 'data' => ['url' => 'https://vimeo.com/123456', 'autoplay' => '1']],
        ];
        $html = blocks(json_encode($page));
        self::assertStringContainsString('<h2 class="flo-hero-heading">Hi</h2>', $html);
        self::assertStringContainsString('flo-hero is-large is-pos-bottom-start is-align-start is-phone-small', $html);
        self::assertStringContainsString('href="/go" target="_blank" rel="noopener"', $html);
        self::assertStringContainsString('<p class="flo-subtitle is-align-end">A line under it</p>', $html);
        self::assertStringContainsString('flo-columns is-count-4', $html);
        self::assertStringContainsString('flo-section is-bg-colour is-tone-dark', $html);
        self::assertStringContainsString('class="flo-card has-link"', $html);
        self::assertStringContainsString('<span class="flo-card-more" aria-hidden="true">Read more</span>', $html);
        self::assertStringContainsString('player.vimeo.com/video/123456?autoplay=1&amp;muted=1', $html);
        self::assertMatchesRegularExpression('/(blocks-motion|flo-blocks\/motion)[^"]*\.js/', BlockRenderer::assets(), 'reduced motion for the hero and the video');
    }

    public function testLinksInListsKeptAsJsonAreCleanedToo(): void
    {
        $page = [['id' => 'b1', 'type' => 'cards', 'data' => ['cards' => json_encode([['title' => 'A', 'link' => 'javascript:alert(1)'], ['title' => 'B', 'link' => '/b']])]]];
        $html = blocks(json_encode($page));
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('href="/b"', $html);
    }

    public function testListsPerLanguageFallBackToTheDefaultLanguage(): void
    {
        $value = json_encode(['en' => [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Hello']]], 'ar' => [['id' => 'b2', 'type' => 'heading', 'data' => ['text' => 'مرحبا']]]]);
        self::assertStringContainsString('مرحبا', blocks($value, 'ar'));
        self::assertStringContainsString('Hello', blocks($value, 'ku'));
        self::assertSame('', blocks(''));
        self::assertSame('', blocks('[{"id":"b1","type":"heading","data":{"text":""}}]'), 'a block with nothing to show prints nothing');
    }

    public function testAssetsOnlyForWhatThePageHas(): void
    {
        self::assertSame('', blocks_assets());
        blocks('[{"id":"b1","type":"heading","data":{"text":"Hi"}}]');
        $assets = blocks_assets();
        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="[^"]*/flo-admin/asset/blocks-blocks-v[^"]+\.css">~', $assets, 'the base styles, as a file (a strict CSP allows it)');
        self::assertStringNotContainsString('<style>', $assets);
        self::assertStringNotContainsString('flo-slider', $assets);
    }

    public function testTheHtmlIsCachedUntilTheNextBlockAppears(): void
    {
        $page = [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'Now']], ['id' => 'b2', 'type' => 'heading', 'data' => ['text' => 'Later'], 'settings' => ['starts' => '2026-10-21 13:00']]];
        $first = blocks(json_encode($page));
        self::assertCount(1, glob(\FloCMS\Admin\Admin::root() . '/storage/cache/blocks/*.json') ?: []);
        self::assertSame($first, blocks(json_encode($page)));
        self::assertStringNotContainsString('Later', $first);
        BlockRenderer::reset(strtotime('2026-10-21 13:30'));
        self::assertStringContainsString('Later', blocks(json_encode($page)), 'the cache ends when a block starts');
    }

    public function testDataForSitesThatRenderBlocksThemselves(): void
    {
        $data = blocks_data(json_encode(self::PAGE));
        self::assertSame(['heading', 'section', 'gone', 'call_to_action'], array_column($data, 'type'));
        self::assertSame(1, $data[1]['children'][0]['children'][1]['column']);
        self::assertSame('top', $data[0]['settings']['anchor']);
        $video = blocks_data('[{"id":"b1","type":"video","data":{"url":"https://youtu.be/dQw4w9WgXcQ"}}]');
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $video[0]['fields']['embed']);
    }

    public function testLinksCannotRunScripts(): void
    {
        $html = blocks(json_encode([
            ['id' => 'b1', 'type' => 'call_to_action', 'data' => ['heading' => 'A', 'button' => 'Go', 'link' => " java\tscript:alert(1)"]],
            ['id' => 'b2', 'type' => 'call_to_action', 'data' => ['heading' => 'B', 'button' => 'Mail', 'link' => 'mailto:hi@example.com']],
            ['id' => 'b3', 'type' => 'cards', 'data' => ['cards' => [['title' => 'C', 'link' => 'data:text/html,x'], ['title' => 'D', 'link' => '/about']]]],
        ]));
        self::assertStringNotContainsString('script:', $html);
        self::assertStringNotContainsString('data:text', $html);
        self::assertStringContainsString('href="mailto:hi@example.com"', $html);
        self::assertStringContainsString('href="/about"', $html);
        $data = blocks_data('[{"id":"b1","type":"call_to_action","data":{"heading":"A","link":"javascript:alert(1)"}}]');
        self::assertSame('', $data[0]['fields']['link']);
    }

    public function testListenersSeeEveryViewAndTurnTheCacheOff(): void
    {
        $seen = 0;
        BlockRenderer::rendering(static function (array $vars) use (&$seen): ?array { $seen++; return null; });
        $page = '[{"id":"b1","type":"heading","data":{"text":"Hi"}}]';
        blocks($page);
        blocks($page);
        self::assertSame(2, $seen);
        self::assertSame([], glob(\FloCMS\Admin\Admin::root() . '/storage/cache/blocks/*.json') ?: []);
    }
}
