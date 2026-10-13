<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Correctness\CorrectnessResource;

final class CorrectnessTest extends RequestTestCase
{
    private static function options(): array
    {
        return ['admin_config' => ['resources' => [CorrectnessResource::class]]];
    }

    public function testBlankLoginCannotBypassUniquenessOnCreateOrEdit(): void
    {
        self::pdo()->exec("UPDATE users SET login = 'collision@example.test' WHERE id = 1");
        foreach (['create', 'edit/4'] as $action) {
            $result = self::post('/admin/accounts/' . $action, [
                'full_name' => 'Collision', 'email' => 'collision@example.test', 'login' => '  ',
                'password' => 'long-enough', 'role' => '0', 'status' => '1',
            ], ['session' => self::ADMIN]);
            self::assertFalse($result['redirected']);
            self::assertStringContainsString('This Login is already used.', $result['body']);
        }
        self::assertSame(4, (int) self::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame('dara@example.test', self::row('users', 4)['login']);
    }

    public function testProfileValidatesFallbackAndExcludesSelf(): void
    {
        self::pdo()->exec("UPDATE users SET login = 'ali@example.test' WHERE id = 1");
        $data = ['full_name' => 'Ali', 'email' => 'ali@example.test', 'login' => '', 'password' => ''];
        $before = self::row('users', 2);
        $result = self::post('/admin/profile', $data, ['session' => self::ADMIN]);
        self::assertFalse($result['redirected']);
        self::assertStringContainsString('This Login is already used.', $result['body']);
        self::assertSame($before, self::row('users', 2));
        self::pdo()->exec("UPDATE users SET login = 'sara' WHERE id = 1");
        self::assertTrue(self::post('/admin/profile', $data, ['session' => self::ADMIN])['redirected']);
        self::assertSame($before['password'], self::row('users', 2)['password']);
    }

    public function testTranslatedUniqueRejectsSameLanguageDuplicates(): void
    {
        foreach ([['en' => 'Sea view villa'], ['en' => 'New', 'ar' => 'فيلا']] as $title) {
            $result = self::post('/admin/correctness/create', ['title' => $title, 'slug' => 'new'], self::options());
            self::assertFalse($result['redirected']);
            self::assertStringContainsString('already used', $result['body']);
        }
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM posts')->fetchColumn());
        self::assertTrue(self::post('/admin/correctness/edit/1', [
            'title' => ['en' => 'Sea view villa', 'ar' => 'فيلا'], 'slug' => 'sea-view-villa',
        ], self::options())['redirected']);
        self::assertTrue(self::post('/admin/correctness/create', [
            'title' => ['en' => 'فيلا'], 'slug' => 'cross-language',
        ], self::options())['redirected']);
    }

    public function testHookFailuresRollBackRecordAndBothHooks(): void
    {
        foreach (['fail-after', 'fail-before'] as $slug) {
            foreach (['create', 'edit/1'] as $action) {
                $before = self::row('posts', 1);
                $result = self::post('/admin/correctness/' . $action, [
                    'title' => ['en' => 'New title'], 'slug' => $slug,
                ], self::options() + ['expect_error' => true]);
                self::assertSame(500, $result['status']);
                self::assertStringContainsString('hook failed', $result['error']);
                self::assertSame($before, self::row('posts', 1));
                self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM posts')->fetchColumn());
                self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM tags')->fetchColumn());
            }
        }
        self::assertTrue(self::post('/admin/correctness/create', [
            'title' => ['en' => 'Good'], 'slug' => 'hooks-ok',
        ], self::options())['redirected']);
        self::assertSame(3, (int) self::pdo()->query('SELECT COUNT(*) FROM tags')->fetchColumn());
    }

    public function testFailedEditKeepsTrustedPlaceholderContext(): void
    {
        $result = self::post('/admin/correctness/edit/1', [
            'title' => ['en' => ''], 'slug' => 'changed', 'stored' => 'FORGED',
        ], self::options());
        self::assertFalse($result['redirected']);
        self::assertStringContainsString('Stored: sea-view-villa', $result['body']);
        self::assertStringNotContainsString('FORGED', $result['body']);
        self::assertStringContainsString('value="changed"', $result['body']);
    }

    public function testHiddenTranslationsSurviveAndCannotBeOverwrittenByInput(): void
    {
        $result = self::post('/admin/posts/edit/1', [
            'title' => ['en' => 'Updated', 'ar' => 'FORGED'], 'slug' => 'sea-view-villa', 'status' => 'draft',
        ], ['languages' => ['en']]);
        self::assertTrue($result['redirected']);
        self::assertSame(['en' => 'Updated', 'ar' => 'فيلا'], json_decode(self::row('posts', 1)['title'], true));
        // A configured language explicitly cleared still gets removed.
        self::assertTrue(self::post('/admin/posts/edit/1', [
            'title' => ['en' => 'Updated', 'ar' => ''], 'slug' => 'sea-view-villa', 'status' => 'draft',
        ])['redirected']);
        self::assertSame(['en' => 'Updated'], json_decode(self::row('posts', 1)['title'], true));
    }
}
