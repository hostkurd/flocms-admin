<?php
declare(strict_types=1);

// What a package's autoloaded file does (request tests require it).
use FloCMS\Admin\Admin;
use FloCMS\Admin\Extension;
use FloCMS\Admin\Tests\Fixtures\Extension\ComponentBlock;

Admin::extend(static function (Extension $panel): void {
    $panel->blocks([new ComponentBlock('hero', 'Hero'), new ComponentBlock('pricing', 'Pricing')]);
    $panel->pages([\FloCMS\Admin\Tests\Fixtures\PanelPages\GettingStarted::class]);
    $panel->sitemap(static fn (): array => [
        ['loc' => '/en/blog/first-post', 'lastmod' => '2026-11-01', 'alternates' => ['en' => '/en/blog/first-post', 'ar' => '/ar/blog/first-post']],
        ['loc' => 'javascript:alert(1)'],
    ]);
});
