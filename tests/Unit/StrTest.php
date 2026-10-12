<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase
{
    public function testSlugKeepsLettersOfAnyScript(): void
    {
        self::assertSame('sea-view-villa', Str::slug('  Sea view: villa! '));
        self::assertSame('فيلا-بحرية', Str::slug('فيلا بحرية'));
        self::assertSame('', Str::slug('---'));
    }

    public function testAppControllerClassMirrorsFloCms(): void
    {
        self::assertSame('FloCMS\\Controllers\\BlogPostsController', Str::appControllerClass('blog-posts'));
        self::assertSame('FloCMS\\Controllers\\NewsController', Str::appControllerClass('news'));
    }

    public function testNames(): void
    {
        self::assertSame('blog-post', Str::kebab('BlogPost'));
        self::assertSame('properties', Str::plural('property'));
        self::assertSame('boxes', Str::plural('box'));
        self::assertSame('Blog posts', Str::headline('blog-posts'));
    }
}
