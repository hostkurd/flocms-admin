<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Actions\{Action, ActionRegistry, ActionRenderer, EditAction, CreateAction};
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Config;

final class ActionDefinitionTest extends TestCase
{
    private array $session;
    protected function setUp(): void
    {
        parent::setUp(); $this->session = $_SESSION ?? []; $_SESSION = ['role' => 1];
        Config::set('permissions', [1 => ['content.posts.view', 'content.posts.publish', 'content.posts.update']]);
    }
    protected function tearDown(): void { $_SESSION = $this->session; parent::tearDown(); }

    public function testPermissionVisibilityAndRecordAuthorizationAreIndependent(): void
    {
        $resource = new PostResource();
        $action = Action::make('publish')->visible(static fn (?object $row): bool => $row?->status === 'draft')
            ->authorize(static fn (?object $row): bool => $row?->owner === 1);
        self::assertTrue($action->allowed($resource, (object) ['status' => 'draft', 'owner' => 1]));
        self::assertFalse($action->allowed($resource, (object) ['status' => 'published', 'owner' => 1]));
        self::assertFalse($action->allowed($resource, (object) ['status' => 'draft', 'owner' => 2]));
        $action->permission('restricted.permission');
        self::assertFalse($action->allowed($resource, (object) ['status' => 'draft', 'owner' => 1]));
    }

    public function testActionsRenderEscapedLabelsAndStablePlacementSelection(): void
    {
        $resource = new PostResource();
        $table = $resource->table(new Table())->actions([EditAction::make()->label('<b>Edit</b>'), Action::make('publish')]);
        $renderer = new ActionRenderer($resource, new ActionRegistry($resource, $table));
        $row = (object) ['id' => 7];
        self::assertStringContainsString('&lt;b&gt;Edit&lt;/b&gt;', $renderer->render('row', $row));
        self::assertStringContainsString('data-rx-value="row:publish:7"', $renderer->render('row', $row));
        self::assertStringContainsString('/action/row/publish/7', $renderer->render('row', $row, false));
        self::assertStringNotContainsString('<b>Edit</b>', $renderer->render('row', $row, false));
    }

    public function testDuplicateActionNamesWithinAPlacementAreRejected(): void
    {
        $resource = new PostResource();
        $this->expectException(\InvalidArgumentException::class);
        new ActionRegistry($resource, (new Table())->actions([Action::make('same'), Action::make('same')]));
    }

    public function testRecordActionsCannotBeRegisteredInNonrecordPlacements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ActionRegistry(new PostResource(), (new Table())->headerActions([EditAction::make()]));
    }

    public function testCrudHandlersCannotOverrideValidatedPersistence(): void
    {
        $this->expectException(\LogicException::class);
        CreateAction::make()->action(static function (): void {});
    }

    public function testUnsafeActionNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Action::make('../execute');
    }
}
