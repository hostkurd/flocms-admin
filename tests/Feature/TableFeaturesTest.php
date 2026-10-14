<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;
use FloCMS\Admin\Tests\Fixtures\Resources\TableResource;

final class TableFeaturesTest extends RequestTestCase
{
    private function config(bool $reactive = true): array { return ['reactive' => $reactive, 'resources' => [TableResource::class]]; }
    private function mount(string $query = ''): array { return self::request('GET', '/en/admin/posts' . $query, ['admin_config' => $this->config()]); }
    private function update(array $previous, string $action, array $state = [], array $options = []): array
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $previous['body'], $match);
        self::assertNotEmpty($match[1] ?? '', $previous['body']);
        return self::post('/en/flo-reactive/update', ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => $action, 'state' => $state], $options + ['session' => $previous['session'], 'admin_config' => $this->config()]);
    }
    private function open(string $action = 'publish', array $ids = ['1', '2']): array
    {
        return $this->update($this->mount(), 'openBulk', ['selectedBulk' => $action, 'selectedIds' => json_encode($ids)]);
    }
    public function testFiltersComposeWithSearchSortAndQueryRestore(): void
    {
        self::pdo()->exec("UPDATE posts SET published_on='2026-10-14' WHERE id=1");
        $json = json_encode(['status' => 'published', 'featured' => '1', 'published_on' => ['from' => '2026-10-14', 'until' => '2026-10-14']]);
        $filtered = $this->update($this->mount(), 'applyFilters', ['filters' => $json, 'page' => '2']);
        self::assertSame(200, $filtered['status']);
        self::assertStringContainsString('Sea view villa', $filtered['body']);
        self::assertStringNotContainsString('Mountain cabin', $filtered['body']);
        self::assertStringContainsString('&quot;page&quot;:1', $filtered['body']);
        $restored = $this->mount('?admin-resource_filters=' . rawurlencode($json));
        self::assertStringNotContainsString('Mountain cabin', $restored['body']);
        $sorted = $this->update($filtered, 'sortBy', ['selectedSort' => 'title']);
        self::assertStringNotContainsString('Mountain cabin', $sorted['body']);
        $cleared = $this->update($sorted, 'clearFilters');
        self::assertStringContainsString('Mountain cabin', $cleared['body']);
    }
    public function testInvalidFiltersNeverBecomeSql(): void
    {
        self::assertSame(400, $this->mount('?admin-resource_filters=' . rawurlencode('{"unknown":"draft"}'))['status']);
        $invalid = $this->update($this->mount(), 'applyFilters', ['filters' => '{"status":"draft\u0027 OR 1=1"}']);
        self::assertSame(422, $invalid['status']);
        self::assertStringContainsString('invalid value', $invalid['body']);
        $date = $this->update($this->mount(), 'applyFilters', ['filters' => '{"published_on":{"from":"2026-02-30"}}']);
        self::assertStringContainsString('invalid value', $date['body']);
        $bookmarked = $this->mount('?admin-resource_filters=' . rawurlencode('{"published_on":{"from":"2026-02-30"}}'));
        self::assertSame(422, $this->update($bookmarked, 'applyFilters')['status']);
        self::assertSame('published', self::row('posts', 1)['status']);
    }
    public function testStandardFiltersAndPaginationLinksPreserveTheirState(): void
    {
        $response = self::request('GET', '/en/admin/posts?static=1&filter[status]=draft', ['admin_config' => $this->config()]);
        self::assertStringContainsString('Mountain cabin', $response['body']);
        self::assertStringNotContainsString('Sea view villa', $response['body']);
        self::assertStringContainsString('filter%5Bstatus%5D=draft', $response['body']);
        self::assertStringContainsString('static=1', $response['body']);
    }
    public function testBulkValidatesOnceCommitsSelectionOnlyAndRejectsReplay(): void
    {
        $open = $this->open();
        self::assertSame(200, $open['status']);
        self::assertStringContainsString('2 selected records', $open['body']);
        $invalid = $this->update($open, 'execute', ['draft' => '{}']);
        self::assertSame(422, $invalid['status']);
        self::assertSame('draft', self::row('posts', 2)['status']);
        $saved = $this->update($invalid, 'execute', ['draft' => '{"reason":"Reviewed"}']);
        self::assertSame(200, $saved['status']);
        self::assertSame('published', self::row('posts', 2)['status']);
        self::assertStringContainsString('Action completed for 2', $saved['body']);
        self::assertSame(409, $this->update($invalid, 'execute', ['draft' => '{"reason":"Again"}'], ['session' => $saved['session']])['status']);
    }
    public function testSingleDeniedRecordPreventsEveryMutation(): void
    {
        self::assertSame(403, $this->open('restricted')['status']);
        self::assertSame('published', self::row('posts', 1)['status']);
        self::assertSame('draft', self::row('posts', 2)['status']);
    }
    public function testStaleAndDeletedRecordsRejectTheWholeSelection(): void
    {
        $open = $this->open('delete');
        self::pdo()->exec("UPDATE posts SET slug='changed' WHERE id=2");
        $stale = $this->update($open, 'execute', ['draft' => '{}']);
        self::assertSame(422, $stale['status']);
        self::assertStringContainsString('No changes were saved', $stale['body']);
        self::assertNotNull(self::row('posts', 1));
        self::pdo()->exec('DELETE FROM posts WHERE id=2');
        self::assertSame(422, $this->update($open, 'execute', ['draft' => '{}'])['status']);
        self::assertNotNull(self::row('posts', 1));
    }
    public function testRevokedPermissionsAndMembershipTamperingNeverWrite(): void
    {
        $open = $this->open('delete');
        self::assertSame(400, $this->update($open, 'execute', ['bulkIds' => '["1"]', 'draft' => '{}'])['status']);
        $options = ['permissions' => [1 => ['content.posts.view']]];
        self::assertSame(403, $this->update($open, 'execute', ['draft' => '{}'], $options)['status']);
        self::assertSame(200, $this->update($open, 'cancel', [], $options)['status']);
        self::assertNotNull(self::row('posts', 1));
    }
    public function testCallbackFailureRollsBackAllRowsAndNotifications(): void
    {
        $open = $this->open('fail');
        $failed = $this->update($open, 'execute', ['draft' => '{}'], ['expect_error' => true]);
        self::assertStringContainsString('Bulk callback failed', (string) $failed['error']);
        self::assertSame('published', self::row('posts', 1)['status']);
        self::assertEmpty($failed['session']['_flo_admin_notifications'] ?? []);
    }
    public function testStandardBulkConfirmationHasSameGuards(): void
    {
        $options = ['admin_config' => $this->config(false)];
        $open = self::request('GET', '/en/admin/posts/bulk/delete?ids[]=1&ids[]=2', $options);
        preg_match('/name="_operation" value="([^"]+)"/', $open['body'], $match);
        self::assertNotEmpty($match[1] ?? '');
        self::assertSame(409, self::post('/en/admin/posts/bulk/delete', ['_operation' => $match[1], '_ids' => '["1"]'], $options + ['session' => $open['session']])['status']);
        $saved = self::post('/en/admin/posts/bulk/delete', ['_operation' => $match[1], '_ids' => '["1","2"]'], $options + ['session' => $open['session']]);
        self::assertTrue($saved['redirected']);
        self::assertNull(self::row('posts', 1)); self::assertNull(self::row('posts', 2));
    }
    public function testSelectionsAreBoundedAndClearWhenFiltersChange(): void
    {
        self::assertSame(400, $this->open('delete', array_map('strval', range(1, 51)))['status']);
        self::assertSame(400, $this->open('delete', ['1', '1'])['status']);
        self::assertSame(400, $this->open('delete', ['1', '01'])['status']);
        $selected = $this->update($this->mount(), 'refresh', ['selectedIds' => '["1"]']);
        $changed = $this->update($selected, 'applyFilters', ['filters' => '{"status":"draft"}']);
        self::assertStringContainsString('0 records selected', $changed['body']);
    }
    public function testSelectionDoesNotExpandToUnselectedRows(): void
    {
        $saved = $this->update($this->open('delete', ['1']), 'execute', ['draft' => '{}']);
        self::assertSame(200, $saved['status']);
        self::assertNull(self::row('posts', 1)); self::assertNotNull(self::row('posts', 2));
    }
    public function testScopeAndRecordPolicyAreRecheckedAtExecution(): void
    {
        $open = $this->open('delete');
        self::assertSame(422, $this->update($open, 'execute', ['draft' => '{}'], ['config' => ['test.exclude_first' => true]])['status']);
        self::assertNotNull(self::row('posts', 2));
        $restricted = $this->open('restricted', ['1']);
        self::pdo()->exec('UPDATE posts SET featured=0 WHERE id=1');
        self::assertSame(403, $this->update($restricted, 'execute', ['draft' => '{}'])['status']);
        self::assertSame('published', self::row('posts', 1)['status']);
    }
    public function testBulkOperationsBindTheActorAndCsrfSession(): void
    {
        $open = $this->open('delete'); $session = $open['session'];
        $session['_csrf_token'] = 'rotated';
        self::assertSame(419, $this->update($open, 'execute', ['draft' => '{}'], ['session' => $session])['status']);
        $session = $open['session']; $session['user_id'] = 2;
        self::assertSame(409, $this->update($open, 'execute', ['draft' => '{}'], ['session' => $session])['status']);
        self::assertNotNull(self::row('posts', 1));
    }
    public function testStandardCancelRemainsAvailableAfterBulkPermissionRevocation(): void
    {
        $options = ['admin_config' => $this->config(false)];
        $open = self::request('GET', '/en/admin/posts/bulk/delete?ids[]=1', $options);
        preg_match('/name="_operation" value="([^"]+)"/', $open['body'], $match);
        $canceled = self::post('/en/admin/posts/bulk/delete', ['_operation' => $match[1], '_ids' => '["1"]', '_cancel' => '1'], $options + ['session' => $open['session'], 'permissions' => [1 => ['content.posts.view']]]);
        self::assertTrue($canceled['redirected']); self::assertNotNull(self::row('posts', 1));
    }
}
