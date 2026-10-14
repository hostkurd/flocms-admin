<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Actions\GuardedActionResource;

final class ActionRequestTest extends RequestTestCase
{
    private const CONFIG = ['reactive' => true, 'resources' => [GuardedActionResource::class]];
    private function mount(array $options = []): array
    {
        return self::request('GET', '/en/admin/posts', $options + ['admin_config' => self::CONFIG]);
    }
    private function update(array $previous, string $action, array $state = [], array $options = []): array
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $previous['body'], $match);
        self::assertNotEmpty($match[1] ?? '', $previous['body']);
        return self::post('/en/flo-reactive/update', ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => $action, 'state' => $state],
            $options + ['admin_config' => self::CONFIG, 'session' => $previous['session']]);
    }
    private function open(string $name, string $id = '2', string $placement = 'row'): array
    {
        return $this->update($this->mount(), 'openAction', ['selection' => $placement . ':' . $name . ':' . $id]);
    }
    private function submit(array $previous, array $input = [], array $options = []): array
    {
        return $this->update($previous, 'execute', ['draft' => json_encode((object) $input, JSON_THROW_ON_ERROR)], $options);
    }
    private function operation(array $page): string
    {
        preg_match('/name="_operation" value="([^"]+)"/', $page['body'], $match);
        self::assertNotEmpty($match[1] ?? ''); return $match[1];
    }

    public function testPublishValidationCommitReplayAndOneTimeNotification(): void
    {
        $open = $this->open('publish');
        self::assertSame(200, $open['status']);
        $invalid = $this->submit($open, ['reason' => '   ', 'status' => 'FORGED']);
        self::assertSame(422, $invalid['status']);
        self::assertStringContainsString('aria-invalid="true"', $invalid['body']);
        self::assertSame('draft', self::row('posts', 2)['status']);
        self::assertStringNotContainsString('data-admin-notification="', $invalid['body']);
        $saved = $this->submit($invalid, ['reason' => 'Reviewed', 'id' => '1', 'status' => 'FORGED']);
        self::assertSame(200, $saved['status']);
        self::assertSame('published', self::row('posts', 2)['status']);
        self::assertSame('Published: Reviewed', self::pdo()->query('SELECT name FROM tags WHERE id = 2')->fetchColumn());
        self::assertStringContainsString('demo.published', $saved['body']);
        self::assertStringContainsString('data-admin-notification="', $saved['body']);
        self::assertStringContainsString('data-rx-open="false"', $saved['body']);
        self::assertSame(409, $this->submit($invalid, ['reason' => 'Again'], ['session' => $saved['session']])['status']);
        $refresh = $this->update($saved, 'refresh');
        self::assertStringNotContainsString('data-admin-notification="', $refresh['body']);
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM tags')->fetchColumn());
    }

    public function testPlacementAllowlistSealedContextAndVisibility(): void
    {
        $mount = $this->mount();
        self::assertStringNotContainsString('data-admin-action="hidden"', $mount['body']);
        foreach (['row:unknown:2', 'table:publish:', 'page:publish:'] as $selection) {
            self::assertSame(404, $this->update($mount, 'openAction', ['selection' => $selection])['status']);
        }
        self::assertSame(400, $this->update($mount, 'openAction', ['selection' => 'page:announce:2'])['status']);
        self::assertSame(403, $this->open('hidden')['status']);
        $open = $this->open('publish');
        foreach (['activeAction', 'activePlacement', 'editingId', 'operation', 'fingerprint'] as $key) {
            self::assertSame(400, $this->update($open, 'execute', [$key => 'FORGED'])['status']);
        }
        $saved = $this->update($open, 'execute', ['selection' => 'row:unpublish:1', 'selectedId' => '1', 'draft' => '{"reason":"Reviewed"}']);
        self::assertSame(200, $saved['status']);
        self::assertSame('published', self::row('posts', 1)['status']);
        self::assertSame('published', self::row('posts', 2)['status']);
        self::assertSame(404, $this->update($mount, 'invoke')['status']);
    }

    public function testPageAndToolbarActionsExecuteWithTheirOwnPermissions(): void
    {
        $page = $this->open('announce', '', 'page');
        self::assertSame(200, $page['status']);
        $result = $this->submit($page);
        self::assertSame(200, $result['status']);
        self::assertStringContainsString('role="alert"', $result['body']);
        $immediate = $this->open('help', '', 'table');
        self::assertSame(200, $immediate['status']);
        self::assertStringContainsString('data-rx-open="false"', $immediate['body']);
        self::assertStringContainsString('demo.help_title', $immediate['body']);
        $limited = ['permissions' => [1 => ['content.posts.view', 'content.posts.update']]];
        self::assertSame(403, $this->update($this->mount($limited), 'openAction', ['selection' => 'row:publish:2'], $limited)['status']);
    }

    public function testCurrentPermissionsRecordPolicyAndScopeAreRechecked(): void
    {
        $open = $this->open('publish');
        $limited = ['permissions' => [1 => ['content.posts.view']]];
        self::assertSame(403, $this->submit($open, ['reason' => 'Reviewed'], $limited)['status']);
        self::assertSame(200, $this->update($open, 'cancel', [], $limited)['status']);
        $restricted = $this->open('restricted', '1');
        self::pdo()->exec('UPDATE posts SET featured = 0 WHERE id = 1');
        self::assertSame(403, $this->submit($restricted)['status']);
        self::pdo()->exec('UPDATE posts SET views = -1 WHERE id = 2');
        $scope = $this->submit($open, ['reason' => 'Reviewed']);
        self::assertSame(422, $scope['status']);
        self::assertStringContainsString('no longer available', $scope['body']);
        self::assertSame('draft', self::row('posts', 2)['status']);
    }

    public function testConflictAndDeletionCannotInvokeCustomCallbacks(): void
    {
        $open = $this->open('publish');
        self::pdo()->exec("UPDATE posts SET slug = 'external' WHERE id = 2");
        self::assertSame(422, $this->submit($open, ['reason' => 'Reviewed'])['status']);
        self::assertSame('external', self::row('posts', 2)['slug']);
        self::pdo()->exec('DELETE FROM posts WHERE id = 2');
        self::assertSame(422, $this->submit($open, ['reason' => 'Reviewed'])['status']);
        self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM tags')->fetchColumn());
    }

    public function testCallbackExceptionsRollBackWritesAndStagedNotifications(): void
    {
        $open = $this->open('fail'); $before = self::row('posts', 2);
        $failed = $this->submit($open, [], ['expect_error' => true]);
        self::assertSame(500, $failed['status']);
        self::assertStringContainsString('action failed', $failed['error']);
        self::assertSame($before, self::row('posts', 2));
        self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM tags')->fetchColumn());
        self::assertEmpty($failed['session']['_flo_admin_notifications'] ?? []);
        self::assertSame(200, $this->update($open, 'cancel', [], ['session' => $failed['session']])['status']);
        $rejected = $this->submit($this->open('reject'));
        self::assertSame(422, $rejected['status']);
        self::assertStringContainsString('Not ready.', $rejected['body']);
        self::assertStringNotContainsString('False validation success', $rejected['body']);
    }

    public function testActionBusinessValidationReadonlyContextAndPasswordScrubbing(): void
    {
        $open = $this->open('check');
        $invalid = $this->submit($open, ['note' => 'blocked', 'password' => 'sensitive-secret', 'stored' => 'FORGED']);
        self::assertSame(422, $invalid['status']);
        self::assertStringContainsString('Business validation failed.', $invalid['body']);
        self::assertStringContainsString('Stored: mountain-cabin', $invalid['body']);
        self::assertStringNotContainsString('FORGED', $invalid['body']);
        self::assertStringNotContainsString('sensitive-secret', $invalid['body']);
        preg_match('/data-rx-snapshot="([^"]+)"/', $invalid['body'], $match);
        $payload = explode('.', html_entity_decode($match[1]))[0];
        self::assertStringNotContainsString('sensitive-secret', (string) base64_decode(strtr($payload, '-_', '+/')));
        self::assertSame('mountain-cabin', self::row('posts', 2)['slug']);
        $valid = $this->submit($invalid, ['note' => 'approved', 'password' => '']);
        self::assertSame(200, $valid['status']);
        self::assertSame('checked', self::row('posts', 2)['slug']);
    }

    public function testNormalizedOversizedDraftCannotWrite(): void
    {
        $result = $this->submit($this->open('expand'), ['note' => 'small']);
        self::assertSame(422, $result['status']);
        self::assertStringContainsString('too large', $result['body']);
        self::assertSame('mountain-cabin', self::row('posts', 2)['slug']);
    }

    public function testCancelledExpiredAndCsrfOperationsCannotWrite(): void
    {
        $open = $this->open('publish');
        $cancel = $this->update($open, 'cancel');
        self::assertSame(409, $this->submit($open, ['reason' => 'Reviewed'], ['session' => $cancel['session']])['status']);
        $session = $open['session'];
        foreach ($session['_flo_admin_operations'] as &$entry) { $entry['issued'] = time() - 1801; }
        unset($entry);
        self::assertSame(409, $this->submit($open, ['reason' => 'Reviewed'], ['session' => $session])['status']);
        $session = $open['session']; $session['_csrf_token'] = 'rotated';
        self::assertSame(419, $this->submit($open, ['reason' => 'Reviewed'], ['session' => $session])['status']);
        self::assertSame('draft', self::row('posts', 2)['status']);
    }

    public function testOperationsCannotMoveToAnotherActorOrRenewedCsrfSession(): void
    {
        $open = $this->open('publish');
        $session = $open['session']; $session['user_id'] = 2;
        self::assertSame(409, $this->submit($open, ['reason' => 'Reviewed'], ['session' => $session])['status']);
        $page = self::request('GET', '/en/admin/posts/action/row/publish/2', ['admin_config' => self::CONFIG]);
        $session = $page['session']; $session['_csrf_token'] = 'renewed';
        $result = self::post('/en/admin/posts/action/row/publish/2', ['_token' => 'renewed', '_operation' => $this->operation($page), 'reason' => 'Reviewed'],
            ['admin_config' => self::CONFIG, 'session' => $session]);
        self::assertSame(409, $result['status']);
        self::assertSame('draft', self::row('posts', 2)['status']);
    }

    public function testLegacyFlashIsDeliveredOnceAndEmptyMessagesRemainHarmless(): void
    {
        $session = self::EDITOR;
        $session['__flash'] = ['message' => 'Review<br>drafts', 'type' => 'warning'];
        $page = self::request('GET', '/en/admin/dashboard', ['session' => $session]);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Review drafts', $page['body']);
        self::assertStringContainsString('role="alert"', $page['body']);
        $next = self::request('GET', '/en/admin/dashboard', ['session' => $page['session']]);
        self::assertStringNotContainsString('data-admin-notification="', $next['body']);
        $session['__flash']['message'] = '<br>  ';
        $empty = self::request('GET', '/en/admin/dashboard', ['session' => $session]);
        self::assertSame(200, $empty['status']);
        self::assertStringNotContainsString('data-admin-notification="', $empty['body']);
    }

    public function testStandardActionValidationExecutionReplayAndContextBinding(): void
    {
        $options = ['admin_config' => self::CONFIG];
        $page = self::request('GET', '/en/admin/posts/action/row/publish/2', $options);
        $token = $this->operation($page); $options['session'] = $page['session'];
        $invalid = self::post('/en/admin/posts/action/row/publish/2', ['_operation' => $token, 'reason' => ''], $options);
        self::assertStringContainsString('aria-invalid="true"', $invalid['body']);
        self::assertFalse($invalid['redirected']);
        self::assertSame('draft', self::row('posts', 2)['status']);
        self::assertSame(409, self::post('/en/admin/posts/action/row/unpublish/1', ['_operation' => $token], $options)['status']);
        self::assertSame(409, self::post('/en/admin/posts/action/page/announce', ['_operation' => $token], $options)['status']);
        $saved = self::post('/en/admin/posts/action/row/publish/2', ['_operation' => $token, 'reason' => 'Reviewed'], $options);
        self::assertTrue($saved['redirected']);
        self::assertSame('published', self::row('posts', 2)['status']);
        self::assertNotEmpty($saved['session']['_flo_admin_notifications']);
        self::assertSame(409, self::post('/en/admin/posts/action/row/publish/2', ['_operation' => $token, 'reason' => 'Again'],
            ['admin_config' => self::CONFIG, 'session' => $saved['session']])['status']);
    }

    public function testStandardActionsWorkWhenReactiveIsDisabledAndCancellationSkipsRevokedAbility(): void
    {
        $options = ['admin_config' => ['reactive' => false, 'resources' => [GuardedActionResource::class]]];
        $list = self::request('GET', '/en/admin/posts', $options);
        self::assertStringContainsString('/en/admin/posts/action/row/publish/2', $list['body']);
        self::assertStringContainsString('/en/admin/posts/action/table/help', $list['body']);
        self::assertStringContainsString('/en/admin/posts/action/page/announce', $list['body']);
        self::assertStringNotContainsString('data-rx-component=', $list['body']);
        $page = self::request('GET', '/en/admin/posts/action/row/publish/2', $options);
        $token = $this->operation($page);
        $cancel = self::post('/en/admin/posts/action/row/publish/2', ['_operation' => $token, '_cancel' => '1'],
            $options + ['session' => $page['session'], 'permissions' => [1 => ['content.posts.view']]]);
        self::assertTrue($cancel['redirected']);
        self::assertSame('draft', self::row('posts', 2)['status']);
        self::assertSame(404, self::request('GET', '/en/admin/posts/action/row/unknown/2', $options)['status']);
        self::assertSame(405, self::request('PUT', '/en/admin/posts/action/row/publish/2', $options + ['post' => ['_token' => 'tok']])['status']);
        self::assertSame(419, self::request('POST', '/en/admin/posts/action/row/publish/2', $options)['status']);
    }
}
