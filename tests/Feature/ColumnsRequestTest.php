<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

final class ColumnsRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_user_preferences');
        $pdo->exec('CREATE TABLE admin_user_preferences (`user_id` INTEGER NOT NULL, `key` VARCHAR(100) NOT NULL, `value` TEXT NULL, `updated_at` DATETIME NULL, PRIMARY KEY (`user_id`, `key`))');
    }

    private static function stored(int $user, string $key): mixed
    {
        $statement = self::pdo()->prepare('SELECT `value` FROM admin_user_preferences WHERE user_id = ? AND `key` = ?');
        $statement->execute([$user, $key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : json_decode((string) $value, true);
    }

    public function testColumnsButtonHidesColumnsForTheUser(): void
    {
        $list = self::request('GET', '/en/admin/posts')['body'];
        self::assertStringContainsString('id="fa-list-columns-menu"', $list);
        self::assertStringContainsString('name="columns[]" value="featured"', $list);
        self::assertStringNotContainsString('name="columns[]" value="title"', $list, 'the title column is never offered');
        self::assertStringContainsString('data-label="Featured"', $list);

        $applied = self::request('GET', '/en/admin/posts?q=villa&sort=updated_at&dir=desc&_columns=apply&columns[]=updated_at');
        self::assertTrue($applied['redirected']);
        self::assertSame(['featured' => false], self::stored(1, 'columns.posts'));

        $after = self::request('GET', '/en/admin/posts?sort=updated_at&dir=desc')['body'];
        self::assertStringNotContainsString('data-label="Featured"', $after);
        self::assertStringContainsString('data-label="Updated at"', $after);
        self::assertStringContainsString('fa-columns-dot', $after);
        self::assertStringContainsString('data-label="Featured"', self::request('GET', '/en/admin/posts', ['session' => self::SUPER])['body'], 'other users keep their own columns');

        // Sorting by a column the user hid falls back to the default sort
        self::request('GET', '/en/admin/posts?_columns=apply&columns[]=featured');
        $sorted = self::request('GET', '/en/admin/posts?sort=updated_at&dir=desc')['body'];
        self::assertStringContainsString('name="sort" value="id"', $sorted, 'the default sort (id) is used');
        self::assertStringNotContainsString('data-label="Updated at"', $sorted);

        self::request('GET', '/en/admin/posts?_columns_reset=1');
        self::assertNull(self::stored(1, 'columns.posts'));
    }

    public function testReactiveListAppliesAndResetsColumns(): void
    {
        $options = ['admin_config' => ['reactive' => true]];
        $mounted = self::request('GET', '/en/admin/posts', $options);
        self::assertStringContainsString('data-admin-columns', $mounted['body']);
        $update = static function (array $previous, string $action, array $state = []) use ($options): array {
            preg_match('/data-rx-snapshot="([^"]+)"/', $previous['body'], $match);
            return self::post('/en/flo-reactive/update', ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => $action, 'state' => $state],
                $options + ['session' => $previous['session']]);
        };
        $applied = $update($mounted, 'applyColumns', ['columns' => '["featured"]']);
        self::assertSame(200, $applied['status']);
        self::assertStringNotContainsString('data-label="Updated at"', $applied['body']);
        self::assertSame(['updated_at' => false], self::stored(1, 'columns.posts'));
        self::assertSame(400, $update($mounted, 'applyColumns', ['columns' => '{"a":1}'])['status']);
        $reset = $update($applied, 'resetColumns');
        self::assertStringContainsString('data-label="Updated at"', $reset['body']);
        self::assertNull(self::stored(1, 'columns.posts'));
    }
}
