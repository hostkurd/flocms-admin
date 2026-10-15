<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;
use FloCMS\Admin\{Admin, Auth\LoginView};
use FloCMS\Admin\Tests\TestCase;

final class LoginViewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('VIEWS_PATH')) { define('VIEWS_PATH', sys_get_temp_dir()); }
        if (!defined('DS')) { define('DS', DIRECTORY_SEPARATOR); }
    }
    private array $session;
    private array $post;
    protected function setUp(): void { parent::setUp(); $this->session = $_SESSION ?? []; $this->post = $_POST; $_SESSION = ['_csrf_token' => 'login-token']; $_POST = []; }
    protected function tearDown(): void { $_SESSION = $this->session; $_POST = $this->post; parent::tearDown(); }
    public function testThemeIsOptInAndDisabledWithoutInstallation(): void
    {
        self::assertNull(LoginView::render());
        Admin::useConfig(['login_theme' => true], false); self::assertNull(LoginView::render());
    }
    public function testThemeUsesExistingLoginRouteCsrfAndEscapedFeedback(): void
    {
        Admin::useConfig(['login_theme' => true, 'brand' => '<b>My CMS</b>']);
        $_POST = ['email' => 'demo@example.test', 'password' => 'must-not-appear'];
        \FloCMS\Core\Session::setFlash('<strong>Invalid login</strong><script>bad()</script>', 'danger');
        $html = LoginView::render();
        self::assertStringContainsString('&lt;b&gt;My CMS&lt;/b&gt;', $html);
        self::assertStringContainsString('/en/admin/users/login', $html);
        self::assertStringContainsString('value="login-token"', $html);
        self::assertStringContainsString('value="demo@example.test"', $html);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringNotContainsString('<script>bad()', $html);
        self::assertStringNotContainsString('must-not-appear', $html);
        self::assertStringNotContainsString('reactive-v', $html);
        self::assertStringContainsString('admin-v0.8.0.css', $html);
    }
    public function testSignedInNonadminCanStillSignOut(): void
    {
        Admin::useConfig(['login_theme' => true]); $_SESSION['isloggedin'] = true;
        $html = LoginView::render();
        self::assertStringContainsString('/en/admin/users/logout', $html);
        self::assertStringNotContainsString('name="password"', $html);
    }
}
