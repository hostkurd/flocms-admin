<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Http\AssetController;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\HttpException;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

/** The colour theme setting and the package's own asset endpoint (CSS, JS, fonts). */
final class ThemeAndAssetsTest extends TestCase
{
    public function testThemeDefaultsToLightAndAcceptsOnlyKnownValues(): void
    {
        self::assertSame('light', Admin::config()->theme());
        foreach (['light', 'dark', 'auto'] as $theme) {
            Admin::useConfig(['theme' => $theme]);
            self::assertSame($theme, Admin::config()->theme());
        }
        foreach (['Dark', 'sepia', '', 1, null, ['dark']] as $invalid) {
            Admin::useConfig(['theme' => $invalid]);
            self::assertSame('light', Admin::config()->theme());
        }
    }

    public function testShippedFontsAreServedImmutableWithTheirType(): void
    {
        foreach (AssetController::FONTS as $font) {
            $response = self::asset([$font]);
            self::assertSame(200, $response->status());
            self::assertSame('font/woff2', self::header($response, 'Content-Type'));
            self::assertSame('public, max-age=31536000, immutable', self::header($response, 'Cache-Control'));
            self::assertSame('nosniff', self::header($response, 'X-Content-Type-Options'));
            self::assertSame((string) file_get_contents(dirname(__DIR__, 2) . '/resources/fonts/' . $font), $response->body());
            self::assertStringStartsWith('wOF2', $response->body());
        }
    }

    public function testStylesheetReferencesOnlyShippedFontsByRelativeUrl(): void
    {
        $css = self::asset(['admin-v' . Admin::VERSION . '.css']);
        self::assertSame('text/css; charset=utf-8', self::header($css, 'Content-Type'));
        preg_match_all('/url\(([^)]+)\)/', $css->body(), $urls);
        self::assertSame(AssetController::FONTS, $urls[1]);
    }

    public function testUnknownNamesAndPathsAreNotServed(): void
    {
        foreach ([['other.woff2'], ['LICENSE-instrument-sans.txt'], ['admin-v0.0.1.css'], ['fonts', 'noto-sans-arabic-v1.woff2'], ['../fonts/noto-sans-arabic-v1.woff2'], []] as $params) {
            try {
                self::asset($params);
                self::fail('Served ' . implode('/', $params));
            } catch (HttpException $e) {
                self::assertSame(404, $e->status);
            }
        }
        self::assertSame(405, self::asset(AssetController::FONTS, 'POST')->status());
    }

    /** @param list<string> $params */
    private static function asset(array $params, string $method = 'GET'): Response
    {
        $controller = (new \ReflectionClass(AssetController::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(\FloCMS\Core\Controller::class, 'params');
        $property->setAccessible(true);
        $property->setValue($controller, $params);
        $controller->setRequest(new Request([], [], ['REQUEST_METHOD' => $method], [], []));

        return $controller->asset();
    }

    private static function header(Response $response, string $name): ?string
    {
        foreach ($response->headers() as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? (string) end($value) : (string) $value;
            }
        }

        return null;
    }
}
