<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Actions\Action;
use FloCMS\Admin\Actions\BulkAction;
use FloCMS\Admin\Actions\BulkExecutor;
use FloCMS\Admin\Mail\Message;
use FloCMS\Admin\Mailings\MailMessage;
use FloCMS\Admin\Support\Avatar;
use FloCMS\Admin\Support\ImageResize;
use FloCMS\Admin\Table\Columns\BadgeColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Filters\TabsFilter;
use FloCMS\Admin\Table\FilterSet;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\TestCase;

/** The 0.28 building blocks without a request. */
final class UsersUnitTest extends TestCase
{
    public function testAvatarsUseTheFirstLetterInAStableColour(): void
    {
        self::assertSame('L', Avatar::initial('lana Karim'));
        self::assertSame('ر', Avatar::initial('ريبين عزيز'));
        self::assertSame('S', Avatar::initial('  "Sara"'));
        self::assertSame('?', Avatar::initial(''));
        self::assertSame(Avatar::color(7), Avatar::color('7'));
        self::assertContains(Avatar::color('anyone'), range(1, 6));
        $html = Avatar::html('<b>Sara</b>', null, 3);
        self::assertStringContainsString('aria-hidden="true">B</span>', $html, 'the first letter, not markup');
        self::assertNull(Avatar::url('javascript:alert(1)'));
        self::assertNull(Avatar::url('../../etc/passwd.png'));
        self::assertNull(Avatar::url('uploads/missing.png'), 'a file that is not there is the letter');
        self::assertSame('https://cdn.example.test/me.jpg', Avatar::url('https://cdn.example.test/me.jpg'));
        self::assertStringContainsString('<img class="fa-avatar fa-avatar-size fa-avatar-img" src="https://cdn.example.test/me.jpg" alt=""', Avatar::html('Sara', 'https://cdn.example.test/me.jpg', 1));
    }

    public function testAnActionCanBeGreyedOutAndIsRefusedThen(): void
    {
        $resource = new PostResource();
        $action = Action::make('archive')->permission('content.posts.view')->disabled(static fn (?object $r): ?string => ($r->status ?? '') === 'archived' ? 'Already archived' : null);
        $archived = (object) ['id' => 1, 'status' => 'archived'];
        $draft = (object) ['id' => 2, 'status' => 'draft'];
        $this->withPermissions(['content.posts.view'], function () use ($action, $resource, $archived, $draft): void {
            self::assertFalse($action->allowed($resource, $archived), 'refused');
            self::assertSame('Already archived', $action->reasonDisabled($resource, $archived), 'shown with its reason');
            self::assertTrue($action->allowed($resource, $draft));
            self::assertNull($action->reasonDisabled($resource, $draft));
        });
        $this->withPermissions([], function () use ($action, $resource, $archived): void {
            self::assertNull($action->reasonDisabled($resource, $archived), 'not shown at all without the permission');
        });
    }

    public function testSkippedRecordsAreCountedByReason(): void
    {
        self::assertSame('', BulkExecutor::skipText(3, []));
        self::assertSame('1 is skipped: Already active.', BulkExecutor::skipText(2, ['Already active' => 1]));
        self::assertSame('3 are skipped: Already active (2), You can’t do this to your own account.', BulkExecutor::skipText(1, ['Already active' => 2, 'You can’t do this to your own account' => 1]));
        $bulk = BulkAction::make('activate')->skipUnavailable()->completedTitle('Activated :count users.');
        self::assertTrue($bulk->skipsUnavailable());
        self::assertSame('Activated 4 users.', $bulk->getCompletedTitle(4));
        self::assertSame('/to?ids=1,2', BulkAction::make('mail')->bulkUrl(static fn (array $ids): string => '/to?ids=' . implode(',', $ids))->getBulkUrl(['1', '2']));
    }

    public function testTabsAreAFilterKeptOutOfTheFiltersMenu(): void
    {
        $table = (new Table())->columns([TextColumn::make('title')->searchable()])
            ->tabs(TabsFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']))
            ->filters([\FloCMS\Admin\Table\Filters\SelectFilter::make('kind')->options(['a' => 'A'])]);
        self::assertSame(['status', 'kind'], array_keys($table->getFilters()), 'filters() keeps the tabs');
        $filters = new FilterSet($table, '{"status":"draft","kind":"a"}');
        self::assertSame(1, $filters->panelCount());
        self::assertSame(['kind'], array_column($filters->chips(), 'name'), 'no chip for the tab');
        self::assertSame(['status' => 'published', 'kind' => 'a'], $filters->withTab('published'));
        self::assertSame(['kind' => 'a'], $filters->withTab(''));
        $html = $filters->render('x');
        self::assertStringContainsString('<input type="hidden" name="filter[status]" value="draft" data-admin-filter="status">', $html);
        self::assertStringNotContainsString('<select class="fa-input" id="x-filter-status"', $html);
        $this->expectException(\InvalidArgumentException::class);
        $table->tabs(TabsFilter::make('other')->options(['x' => 'X']));
    }

    public function testThePersonCellAndStatusDots(): void
    {
        $cell = TextColumn::make('full_name')->avatar('image')->description('email')->descriptionMark(static fn (object $r): bool => true, 'Verified')
            ->render((object) ['id' => 5, 'full_name' => 'Lana <i>K</i>', 'email' => 'lana@example.test', 'image' => '']);
        self::assertStringContainsString('<!--fa-title-->Lana &lt;i&gt;K&lt;/i&gt;<!--/fa-title-->', $cell);
        self::assertStringContainsString('<span class="fa-cell-sub"><bdi>lana@example.test</bdi>', $cell);
        self::assertStringContainsString('<span class="fa-sr">Verified</span>', $cell);
        $dot = BadgeColumn::make('status')->labels(['1' => 'Active'])->colors(['1' => 'green'])->dot()->render((object) ['status' => 1]);
        self::assertSame('<span class="fa-status fa-status-green"><span class="fa-status-dot" aria-hidden="true"></span>Active</span>', $dot);
        $searched = TextColumn::make('full_name')->searchAlso(['email', 'phone']);
        self::assertTrue($searched->isSearchable());
        self::assertSame(['email', 'phone'], $searched->getAlsoSearched());
    }

    public function testImagesAreCroppedToASquare(): void
    {
        if (!extension_loaded('gd')) { self::markTestSkipped('GD is not installed.'); }
        $file = tempnam(sys_get_temp_dir(), 'sq') . '.jpg';
        $image = imagecreatetruecolor(800, 400); imagejpeg($image, $file); imagedestroy($image);
        self::assertTrue(ImageResize::square($file, 256, 'image/jpeg'));
        self::assertSame([256, 256], array_slice(getimagesize($file) ?: [], 0, 2));
        $small = tempnam(sys_get_temp_dir(), 'sq') . '.png';
        $image = imagecreatetruecolor(100, 60); imagepng($image, $small); imagedestroy($image);
        ImageResize::square($small, 256, 'image/png');
        self::assertSame([60, 60], array_slice(getimagesize($small) ?: [], 0, 2), 'never scaled up');
        self::assertFalse(ImageResize::square($small, 256, 'image/gif'), 'GIFs are kept as they are');
        @unlink($file); @unlink($small);
    }

    public function testEmailsTakeOnlyKnownHeadersAndTheirOwnText(): void
    {
        $message = new Message('a@example.test', 'Hi', 'Text', headers: ['List-Unsubscribe' => '<https://example.test/u>']);
        $mime = $message->mime('from@example.test', 'Panel', 'example.test');
        self::assertContains('List-Unsubscribe: <https://example.test/u>', $mime['headers']);
        try { new Message('a@example.test', 'Hi', 'Text', headers: ['Bcc' => 'x@example.test']); self::fail('Bcc accepted'); } catch (\InvalidArgumentException) {}
        try { new Message('a@example.test', 'Hi', 'Text', headers: ['Reply-To' => "a@example.test\r\nBcc: x@example.test"]); self::fail('a line break accepted'); } catch (\InvalidArgumentException) {}
        self::assertSame("Hello\n- one\n- two\nA link (https://example.test)", MailMessage::text('<p>Hello</p><ul><li>one</li><li>two</li></ul><p>A <a href="https://example.test">link</a></p>'));
    }

    /** @param list<string> $permissions */
    private function withPermissions(array $permissions, \Closure $test): void
    {
        $session = $_SESSION ?? [];
        $_SESSION = ['isloggedin' => true, 'admin_access' => true, 'role' => 1, 'user_id' => 1];
        \FloCMS\Core\Config::set('permissions', [1 => $permissions]);
        \FloCMS\Core\Config::set('roles', [1 => 'Editor']);
        try { $test(); } finally { $_SESSION = $session; }
    }
}
