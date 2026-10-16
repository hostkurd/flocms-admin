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

    public function testBrandColourSettingsAreValidatedAndNormalised(): void
    {
        self::assertNull(Admin::config()->primaryColor());
        Admin::useConfig(['primary_color' => '#4F46E5']);
        self::assertSame('#4f46e5', Admin::config()->primaryColor());
        Admin::useConfig(['primary_color' => '#0af']);
        self::assertSame('#00aaff', Admin::config()->primaryColor());
        foreach (['red', '#12345', 'rgb(0,0,0)', '#4f46e5;x', 0, null] as $invalid) {
            Admin::useConfig(['primary_color' => $invalid]);
            self::assertNull(Admin::config()->primaryColor());
        }
        Admin::useConfig(['logo' => '/img/logo.svg', 'sidebar' => 'dark']);
        self::assertSame('/img/logo.svg', Admin::config()->logo());
        self::assertSame('dark', Admin::config()->sidebar());
        foreach (['javascript:alert(1)', '//evil.test/x.png', 'logo.png', '/a" onerror="x', ''] as $invalid) {
            Admin::useConfig(['logo' => $invalid, 'sidebar' => 'purple']);
            self::assertNull(Admin::config()->logo());
            self::assertSame('light', Admin::config()->sidebar());
        }
    }

    public function testBrandStylesheetExistsOnlyForTheConfiguredColour(): void
    {
        self::assertNull(\FloCMS\Admin\Support\Theme::fileName());
        Admin::useConfig(['primary_color' => '#4f46e5']);
        $name = (string) \FloCMS\Admin\Support\Theme::fileName();
        self::assertMatchesRegularExpression('/^theme-[0-9a-f]{12}\.css$/', $name);
        $css = self::asset([$name]);
        self::assertSame('text/css; charset=utf-8', self::header($css, 'Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', self::header($css, 'Cache-Control'));
        self::assertStringContainsString('--fa-primary:#4f46e5;', $css->body());
        self::assertStringContainsString('.fa-panel[data-theme="dark"]', $css->body());
        self::assertStringContainsString('@media (prefers-color-scheme:dark)', $css->body());
        Admin::useConfig(['primary_color' => '#047857']);
        self::assertNotSame($name, \FloCMS\Admin\Support\Theme::fileName());
        try { self::asset([$name]); self::fail('Served a stale brand stylesheet'); } catch (HttpException $e) { self::assertSame(404, $e->status); }
    }

    public function testContrastWithWhiteFollowsWcag(): void
    {
        self::assertEqualsWithDelta(21.0, \FloCMS\Admin\Support\Theme::contrastWithWhite('#000000'), 0.01);
        self::assertEqualsWithDelta(1.0, \FloCMS\Admin\Support\Theme::contrastWithWhite('#ffffff'), 0.01);
        self::assertGreaterThan(4.5, \FloCMS\Admin\Support\Theme::contrastWithWhite('#0b6e91'));
        self::assertLessThan(4.5, \FloCMS\Admin\Support\Theme::contrastWithWhite('#38bbe0'));
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
