<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Actions\{ActionRegistry, DuplicateAction};
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\Config;

/** Duplicate (0.40): the action's own rules, without requests. */
final class DuplicateActionTest extends TestCase
{
    private array $session;
    protected function setUp(): void
    {
        parent::setUp(); $this->session = $_SESSION ?? []; $_SESSION = ['role' => 1];
        Config::set('permissions', [1 => ['content.posts.view', 'content.posts.create']]);
    }
    protected function tearDown(): void { $_SESSION = $this->session; parent::tearDown(); }

    public function testExceptAndOnlyCannotBeCombined(): void
    {
        try { DuplicateAction::make()->except(['code'])->only(['title']); self::fail('except() then only()'); }
        catch (\LogicException $e) { self::assertSame('Use except() or only() on a DuplicateAction, not both.', $e->getMessage()); }
        $this->expectException(\LogicException::class);
        DuplicateAction::make()->only(['title'])->except(['code']);
    }

    public function testFieldNamesMustBeStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DuplicateAction::make()->except([3]);
    }

    public function testDefaultsAndPermissions(): void
    {
        $resource = new PostResource();
        $action = DuplicateAction::make();
        self::assertSame(['duplicate', 'duplicate', 'copy', 'Duplicate', 'Create'], [$action->getName(), $action->getKind(), $action->getIcon(), $action->getLabel($resource), $action->getSubmitLabel($resource)]);
        self::assertSame('Duplicate “Sea view villa”', $action->getTitle($resource, (object) ['id' => 1, 'title' => '{"en":"Sea view villa"}']));
        self::assertSame('content.posts.create', $action->permissionName($resource));
        $row = (object) ['id' => 1, 'title' => 'Villa'];
        self::assertTrue($action->allowed($resource, $row));
        self::assertFalse($action->allowed($resource, null), 'always about a record');
        Config::set('permissions', [1 => ['content.posts.create']]);
        self::assertFalse($action->allowed($resource, $row), 'needs view as well');
        Config::set('permissions', [1 => ['content.posts.view']]);
        self::assertFalse($action->allowed($resource, $row), 'needs create');
    }

    public function testOnlyARowAction(): void
    {
        self::assertSame(['duplicate'], array_keys(ActionRegistry::index([DuplicateAction::make()], 'row')));
        $this->expectException(\InvalidArgumentException::class);
        ActionRegistry::index([DuplicateAction::make()], 'table');
    }

    public function testItOpensTheWayCreateDoes(): void
    {
        // PostResource's Create opens as a page ($formMode 'page'); page() and modal() still choose.
        $resource = new PostResource();
        self::assertSame($resource::getFormMode(), DuplicateAction::make()->getMode($resource));
        self::assertSame('slide-over', DuplicateAction::make()->slideOver()->getMode($resource));
        self::assertSame('page', DuplicateAction::make()->page()->getMode($resource));
    }
}
