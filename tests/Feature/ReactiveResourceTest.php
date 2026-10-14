<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;

final class ReactiveResourceTest extends RequestTestCase
{
    private function mount(string $slug = 'posts', array $options = []): array
    {
        return self::request('GET', '/en/admin/' . $slug, $options + ['admin_config' => ['reactive' => true]]);
    }

    private function update(array $previous, string $action, array $state = [], array $options = []): array
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $previous['body'], $match);
        self::assertNotEmpty($match[1] ?? '', $previous['body']);
        return self::post('/en/flo-reactive/update', [
            '_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => $action, 'state' => $state,
        ], $options + ['session' => $previous['session'], 'admin_config' => ['reactive' => true]]);
    }

    public function testSearchPaginationSortAndQueryInitialization(): void
    {
        $mounted = $this->mount();
        self::assertStringContainsString('data-rx-component="flo-admin-resource"', $mounted['body']);
        $searched = $this->update($mounted, 'refresh', ['search' => 'villa', 'page' => '2']);
        self::assertStringContainsString('Sea view villa', $searched['body']);
        self::assertStringNotContainsString('Mountain cabin', $searched['body']);
        self::assertStringContainsString('&quot;page&quot;:1', $searched['body']);
        $sorted = $this->update($searched, 'sortBy', ['selectedSort' => 'title']);
        self::assertSame(200, $sorted['status']);
        self::assertStringContainsString('aria-sort="ascending"', $sorted['body']);
        self::assertSame(400, $this->update($searched, 'sortBy', ['selectedSort' => 'slug'])['status']);
        $restored = $this->mount('posts?admin-resource_q=cabin&admin-resource_page=1');
        self::assertStringNotContainsString('Sea view villa', $restored['body']);
        self::assertStringContainsString('Mountain cabin', $restored['body']);
    }

    public function testEditValidationPreservationCommitAndReplay(): void
    {
        $opened = $this->update($this->mount(), 'edit', ['selectedId' => '1']);
        self::assertStringContainsString('data-rx-open="true"', $opened['body']);
        self::assertStringContainsString('class="fa-lang-badge', $opened['body']);
        self::assertStringNotContainsString('fa-translations-inline', $opened['body']);
        $invalid = $this->update($opened, 'save', ['draft' => json_encode(['title' => ['en' => ''], 'slug' => 'changed', 'status' => 'draft'])]);
        self::assertSame(422, $invalid['status']);
        self::assertStringContainsString('Title is required.', $invalid['body']);
        self::assertSame('sea-view-villa', self::row('posts', 1)['slug']);
        $data = ['draft' => json_encode(['title' => ['en' => 'Updated', 'ar' => 'فيلا'], 'slug' => 'updated', 'status' => 'published', 'id' => '2'])];
        $saved = $this->update($invalid, 'save', $data);
        self::assertSame(200, $saved['status']);
        self::assertStringContainsString('Post saved.', $saved['body']);
        self::assertStringContainsString('data-rx-open="false"', $saved['body']);
        self::assertSame('updated', self::row('posts', 1)['slug']);
        self::assertSame('mountain-cabin', self::row('posts', 2)['slug']);
        self::assertSame(409, $this->update($invalid, 'save', $data, ['session' => $saved['session']])['status']);
    }

    public function testConflictingAndDeletedRecordsCannotBeOverwritten(): void
    {
        $open = $this->update($this->mount(), 'edit', ['selectedId' => '1']);
        self::pdo()->exec("UPDATE posts SET slug = 'external' WHERE id = 1");
        $draft = ['draft' => json_encode(['title' => ['en' => 'Stale'], 'slug' => 'stale', 'status' => 'draft'])];
        $conflict = $this->update($open, 'save', $draft);
        self::assertSame(422, $conflict['status']);
        self::assertStringContainsString('changed since you opened', $conflict['body']);
        self::assertSame('external', self::row('posts', 1)['slug']);
        self::pdo()->exec('DELETE FROM posts WHERE id = 1');
        self::assertSame(422, $this->update($open, 'save', $draft)['status']);
        self::assertNull(self::row('posts', 1));
    }

    public function testCreateDeleteAndCancelledOperations(): void
    {
        $open = $this->update($this->mount(), 'create');
        $draft = ['draft' => json_encode(['title' => ['en' => 'New post'], 'slug' => 'new-post', 'status' => 'draft'])];
        $saved = $this->update($open, 'save', $draft);
        self::assertSame(200, $saved['status']);
        self::assertNotNull(self::row('posts', 3));
        self::assertSame(409, $this->update($open, 'save', $draft, ['session' => $saved['session']])['status']);
        $delete = $this->update($saved, 'confirmDelete', ['selectedId' => '3']);
        $cancel = $this->update($delete, 'cancel');
        self::assertNotNull(self::row('posts', 3));
        self::assertSame(409, $this->update($delete, 'delete', [], ['session' => $cancel['session']])['status']);
        $delete = $this->update($cancel, 'confirmDelete', ['selectedId' => '3']);
        self::assertSame(200, $this->update($delete, 'delete')['status']);
        self::assertNull(self::row('posts', 3));
    }

    public function testSealedContextPermissionsCsrfAndActionAllowlist(): void
    {
        $mount = $this->mount();
        self::assertSame(400, $this->update($mount, 'edit', ['resourceSlug' => 'accounts'])['status']);
        self::assertSame(400, $this->update($mount, 'edit', ['editingId' => '2'])['status']);
        self::assertSame(404, $this->update($mount, 'render')['status']);
        $open = $this->update($mount, 'edit', ['selectedId' => '1']);
        $permissions = ['permissions' => [1 => ['content.posts.view']]];
        self::assertSame(403, $this->update($open, 'save', ['draft' => '{}'], $permissions)['status']);
        self::assertSame(200, $this->update($open, 'cancel', [], $permissions)['status']);
        self::assertSame('sea-view-villa', self::row('posts', 1)['slug']);
        $session = $open['session']; $session['_csrf_token'] = 'rotated';
        self::assertSame(419, $this->update($open, 'save', ['draft' => '{}'], ['session' => $session])['status']);
    }

    public function testAccountsStillNormalizeAndNeverReturnPasswords(): void
    {
        $mount = $this->mount('accounts', ['session' => self::SUPER]);
        $open = $this->update($mount, 'create');
        self::pdo()->exec("UPDATE users SET login = 'collision@example.test' WHERE id = 1");
        $failed = $this->update($open, 'save', ['draft' => json_encode([
            'full_name' => 'New', 'email' => 'collision@example.test', 'login' => '', 'password' => 'sensitive-secret', 'role' => '0', 'status' => '1',
        ])]);
        self::assertSame(422, $failed['status']);
        self::assertStringContainsString('This Login is already used.', $failed['body']);
        self::assertStringNotContainsString('sensitive-secret', $failed['body']);
        preg_match('/data-rx-snapshot="([^"]+)"/', $failed['body'], $match);
        $payload = explode('.', html_entity_decode($match[1]))[0];
        self::assertStringNotContainsString('sensitive-secret', (string) base64_decode(strtr($payload, '-_', '+/')));
    }

    public function testRecordPolicyAndScopeAreRecheckedAfterOpening(): void
    {
        $options = ['admin_config' => ['reactive' => true, 'resources' => [\FloCMS\Admin\Tests\Fixtures\Scoped\ScopedResource::class]], 'session' => self::SUPER];
        $open = $this->update($this->mount('scoped', $options), 'edit', ['selectedId' => '1'], $options);
        self::assertSame(200, $open['status']);
        self::pdo()->exec('UPDATE posts SET featured = 0 WHERE id = 1');
        $request = ['admin_config' => $options['admin_config']];
        self::assertSame(403, $this->update($open, 'save', ['draft' => '{"slug":"forged"}'], $request)['status']);
        self::pdo()->exec("UPDATE posts SET featured = 1, status = 'draft' WHERE id = 1");
        $missing = $this->update($open, 'save', ['draft' => '{"slug":"forged"}'], $request);
        self::assertSame(422, $missing['status']);
        self::assertStringContainsString('no longer available', $missing['body']);
        self::assertSame('sea-view-villa', self::row('posts', 1)['slug']);
    }

    public function testReactiveHookFailuresRollBackAndLeaveOperationRetryable(): void
    {
        $options = ['admin_config' => ['reactive' => true, 'resources' => [\FloCMS\Admin\Tests\Fixtures\Correctness\CorrectnessResource::class]]];
        $open = $this->update($this->mount('correctness', $options), 'edit', ['selectedId' => '1'], $options);
        $before = self::row('posts', 1);
        foreach (['fail-before', 'fail-after'] as $slug) {
            $failed = $this->update($open, 'save', ['draft' => json_encode(['title' => ['en' => 'New'], 'slug' => $slug])], $options + ['expect_error' => true]);
            self::assertSame(500, $failed['status']);
            self::assertSame($before, self::row('posts', 1));
            self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM tags')->fetchColumn());
        }
        $saved = $this->update($open, 'save', ['draft' => json_encode(['title' => ['en' => 'New'], 'slug' => 'hooks-ok'])], $options);
        self::assertSame(200, $saved['status']);
        self::assertSame(3, (int) self::pdo()->query('SELECT COUNT(*) FROM tags')->fetchColumn());
    }

    public function testMalformedDraftIsRejectedAndLargeRecordsCanStillBeDeleted(): void
    {
        $open = $this->update($this->mount(), 'edit', ['selectedId' => '1']);
        self::assertSame(400, $this->update($open, 'save', ['draft' => '[1,2,3]'])['status']);
        $invalid = $this->update($open, 'save', ['draft' => '{"title":{"en":["bad"]},"slug":{"bad":1},"status":"draft"}']);
        self::assertSame(422, $invalid['status']);
        self::assertSame('sea-view-villa', self::row('posts', 1)['slug']);
        $stmt = self::pdo()->prepare('UPDATE posts SET body = ? WHERE id = 1');
        $stmt->execute([json_encode(['en' => str_repeat('x', 7100)])]);
        $large = $this->update($this->mount(), 'edit', ['selectedId' => '1']);
        self::assertSame(422, $large['status']);
        self::assertStringContainsString('id="fa-admin-resource-fallback"', $large['body']);
        self::assertStringContainsString('Continue on full page', $large['body']);
        $delete = $this->update($this->mount(), 'confirmDelete', ['selectedId' => '1']);
        self::assertSame(200, $delete['status']);
        self::assertSame(200, $this->update($delete, 'delete')['status']);
        self::assertNull(self::row('posts', 1));
    }

    public function testDisabledIntegrationKeepsStandardPagesAndRejectsOldSnapshots(): void
    {
        $mounted = $this->mount();
        $disabled = ['admin_config' => ['reactive' => false]];
        self::assertSame(404, $this->update($mounted, 'refresh', [], $disabled)['status']);
        $page = self::request('GET', '/en/admin/posts', $disabled);
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('data-rx-component=', $page['body']);
        self::assertStringNotContainsString('reactive-v0.3.0.js', $page['body']);
        self::assertStringContainsString('data-admin-navigation="false"', $page['body']);
        self::assertStringContainsString('/en/admin/posts/edit/1', $page['body']);
    }

    public function testNavigationResponseOmitsApplicationLayoutAndUsesExternalVersionedAssets(): void
    {
        $result = self::request('GET', '/en/admin/dashboard', ['headers' => ['X-Flo-Admin' => 'navigate'], 'admin_config' => ['reactive' => true]]);
        self::assertStringNotContainsString('<html>', $result['body']);
        self::assertStringContainsString('admin-v0.4.1.js', $result['body']);
        self::assertStringContainsString('reactive-v0.3.0.js', $result['body']);
        self::assertStringNotContainsString('<script>', $result['body']);
        self::assertSame(404, self::request('GET', '/en/flo-admin/asset/unknown.js')['status']);
    }
}
