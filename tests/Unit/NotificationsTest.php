<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Notifications\{Notification, Notifications, NotificationView};
use FloCMS\Admin\Tests\TestCase;

final class NotificationsTest extends TestCase
{
    private array $session;
    protected function setUp(): void { parent::setUp(); $this->session = $_SESSION ?? []; $_SESSION = []; }
    protected function tearDown(): void { $_SESSION = $this->session; parent::tearDown(); }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testDeliveriesAreEscapedAccessibleAndConsumedOnce(): void
    {
        if (!defined('DS')) { define('DS', DIRECTORY_SEPARATOR); }
        if (!defined('VIEWS_PATH')) { define('VIEWS_PATH', sys_get_temp_dir()); }
        \FloCMS\Core\Config::set('view.cache', false);
        Notification::make()->title('<script>alert(1)</script>')->body('<img src=x onerror=alert(1)>')->success()->send();
        Notification::make()->title('Warning')->warning()->send();
        $html = NotificationView::pending();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('aria-label="Dismiss notification"', $html);
        self::assertStringNotContainsString('data-admin-notification="', NotificationView::pending());
    }

    public function testNestedCaptureOnlyDeliversAfterOutermostSuccess(): void
    {
        Notifications::capture(static function (): void {
            Notification::make()->title('First')->info()->send();
            Notifications::capture(static function (): void { Notification::make()->title('Second')->success()->send(); });
            self::assertSame([], Notifications::pull());
        });
        self::assertSame(['First', 'Second'], array_column(Notifications::pull(), 'title'));
        try {
            Notifications::capture(static function (): void {
                Notifications::capture(static function (): void { Notification::make()->title('False success')->send(); });
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $error) { self::assertSame('rollback', $error->getMessage()); }
        self::assertSame([], Notifications::pull());
        Notification::make()->title('Later')->send();
        self::assertSame(['Later'], array_column(Notifications::pull(), 'title'));
    }

    public function testQueueIsBoundedAndUnicodePayloadsRemainValid(): void
    {
        for ($i = 0; $i < 12; $i++) { Notification::make()->title('Notice ' . $i)->body(str_repeat('ک', 3000))->send(); }
        $pending = Notifications::pull();
        self::assertCount(10, $pending);
        self::assertSame('Notice 2', $pending[0]['title']);
        self::assertSame('Notice 11', $pending[9]['title']);
        self::assertCount(10, array_unique(array_column($pending, 'id')));
        self::assertLessThanOrEqual(4096, strlen($pending[0]['body']));
        self::assertTrue(mb_check_encoding($pending[0]['body'], 'UTF-8'));
    }

    public function testSiteLanguageKeysAndMessageDirectionAreResolvedOnSend(): void
    {
        self::siteLang(['notice.title' => 'ئاگاداری']);
        \FloCMS\Admin\Support\AdminLang::setLanguage('ku');
        Notification::make()->title('notice.title')->warning()->send();
        $pending = Notifications::pull();
        self::assertSame('ئاگاداری', $pending[0]['title']);
        self::assertSame('rtl', $pending[0]['dir']);
        self::assertSame('ku', $pending[0]['lang']);
    }
}
