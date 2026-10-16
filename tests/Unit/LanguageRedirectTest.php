<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Http\PanelController;
use FloCMS\Admin\Preferences\Preferences;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;

final class LanguageRedirectTest extends TestCase
{
    private ?\FloCMS\Core\Database $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = App::$db;
        App::$db = null;
        $_SESSION = ['user_id' => 5];
        Preferences::reset();
    }

    protected function tearDown(): void
    {
        App::$db = $this->previousDb;
        $_SESSION = [];
        unset($_SERVER['REQUEST_URI']);
        Preferences::reset();
        parent::tearDown();
    }

    private function redirectAt(string $uri, string $lang): ?string
    {
        $_SERVER['REQUEST_URI'] = $uri;
        AdminLang::setLanguage($lang);
        return PanelController::languageRedirect();
    }

    public function testTargetsAreBuiltFromTheSiteAddress(): void
    {
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        self::assertNull($this->redirectAt('/en/admin/posts', 'en'), 'no preference: no redirect');

        Preferences::current()->set('language', 'ku');
        self::assertSame($base . '/ku/admin/posts/edit/5?q=villa', $this->redirectAt('/en/admin/posts/edit/5?q=villa', 'en'));
        self::assertSame($base . '/ku/admin/dashboard', $this->redirectAt('/admin/dashboard', 'en'), 'no language segment: one redirect, not a loop');
        self::assertSame($base . '/ku/admin', $this->redirectAt('/cms/en/admin', 'en'));
        self::assertNull($this->redirectAt('/ku/admin/posts', 'ku'));
        self::assertNull($this->redirectAt('/en/users/login', 'en'), 'only admin pages');

        // The menu's marker makes the chosen language the preference, then drops the marker
        self::assertSame($base . '/ar/admin/posts?page=2', $this->redirectAt('/ar/admin/posts?page=2&switch_language=1', 'ar'));
        self::assertSame('ar', Preferences::current()->get('language'));
    }
}
