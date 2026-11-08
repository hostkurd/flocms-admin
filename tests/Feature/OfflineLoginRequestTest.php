<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Admin;

/**
 * 0.40.1: while the website is offline (offline_mode = 1), core answers every
 * website address with the offline page and a 503. The login page is an admin
 * address, so it opened, but its stylesheet and script came from a website
 * address (/flo-admin/asset/) and were replaced by the offline page: the page
 * was unstyled. The panel's own files now come from an admin address that
 * opens before signing in.
 */
final class OfflineLoginRequestTest extends RequestTestCase
{
    private const GUEST = ['session' => ['_csrf_token' => 'tok']];
    /** The sign-in pages the package draws (the login page itself is the site's view, with the same Assets::loginTags()). */
    private const PAGES = ['admin_config' => ['password_reset' => true, 'login_theme' => true], 'env' => ['MAIL_TRANSPORT' => 'log', 'MAIL_FROM' => 'panel@example.test']];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS settings');
        $pdo->exec('CREATE TABLE settings (`param` VARCHAR(190) NOT NULL, `value` TEXT NULL, `lang` VARCHAR(10) NOT NULL DEFAULT \'\', PRIMARY KEY (`param`, `lang`))');
        $pdo->exec("INSERT INTO settings (`param`, `value`, `lang`) VALUES ('offline_mode', '1', 'en'), ('panel.primary_color', '\"#0a5f8c\"', '')");
        file_put_contents(self::$root . '/templates/default/offline.html', '<?php http_response_code(503); ?>Back soon');
    }

    protected function tearDown(): void
    {
        self::pdo()->exec('DROP TABLE IF EXISTS settings');
        @unlink(self::$root . '/templates/default/offline.html');
        parent::tearDown();
    }

    public function testSignInPagesKeepTheirStylesWhileTheWebsiteIsOffline(): void
    {
        self::assertStringContainsString('Back soon', self::request('GET', '/en/', self::GUEST)['body'], 'the website is offline');

        $login = self::request('GET', '/en/admin/password/forgot', self::GUEST + self::PAGES);
        self::assertSame(200, $login['status']);
        preg_match_all('~(?:href|src)="https://example\.test(/en/[^"]+/asset/[^"]+)"~', $login['body'], $found);
        $expected = ['/en/admin/flo-assets/asset/admin-v' . Admin::VERSION . '.css', '/en/admin/flo-assets/asset/admin-v' . Admin::VERSION . '.js'];
        foreach ($expected as $url) {
            self::assertContains($url, $found[1], $login['body']);
        }
        self::assertCount(3, $found[1], 'the stylesheet, the brand colour and the script');

        foreach ($found[1] as $url) {
            $file = self::request('GET', $url, self::GUEST);
            self::assertSame(200, $file['status'], $url);
            self::assertStringNotContainsString('Back soon', $file['body'], $url);
        }
        $theme = array_values(array_filter($found[1], static fn (string $url): bool => str_contains($url, '/theme-')));
        self::assertStringContainsString('#0a5f8c', self::request('GET', $theme[0], self::GUEST)['body']);
    }

    public function testTheGuestAddressServesOnlyThePanelsOwnFiles(): void
    {
        // The fonts sit next to the stylesheet, so its relative url() references follow it
        // (checked directly: a font's bytes are not text the request harness can return)
        foreach (\FloCMS\Admin\Http\AssetController::FONTS as $font) {
            self::assertTrue(\FloCMS\Admin\Http\AssetController::panelFile($font), $font);
        }
        foreach (['rich-text-v' . Admin::VERSION . '.css', 'blocks-hero-v' . Admin::VERSION . '.css', 'admin-v0.1.0.css', 'unknown.js', '..%2Fcomposer.json'] as $name) {
            self::assertSame(404, self::request('GET', '/en/admin/flo-assets/asset/' . $name, self::GUEST)['status'], $name);
        }
        $other = self::request('GET', '/en/admin/flo-assets/other/admin-v' . Admin::VERSION . '.css', self::GUEST);
        self::assertTrue($other['status'] === 404 || $other['redirected'], 'no other action opens before signing in');
    }

    public function testTheWebsiteAddressStillServesEveryFileWhenOnline(): void
    {
        self::pdo()->exec("UPDATE settings SET `value` = '0' WHERE `param` = 'offline_mode'");
        foreach (['admin-v' . Admin::VERSION . '.css', 'rich-text-v' . Admin::VERSION . '.css'] as $name) {
            self::assertSame(200, self::request('GET', '/en/flo-admin/asset/' . $name, self::GUEST)['status'], $name);
        }
    }
}
