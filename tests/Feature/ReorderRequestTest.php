<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Reorder\{FaqResource, LockedFaqResource, SmallLimitFaqResource};
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;

/** Reorder mode through whole requests: page, saving, refusals, permissions and the no-JS buttons. */
final class ReorderRequestTest extends RequestTestCase
{
    private const RESOURCES = [FaqResource::class, LockedFaqResource::class, PostResource::class];

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ($extra['admin_config'] ?? []) + ['resources' => self::RESOURCES]] + $extra;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS faqs');
        $pdo->exec('DROP TABLE IF EXISTS faq_events');
        self::createTable($pdo, 'CREATE TABLE faqs (id INTEGER PRIMARY KEY AUTOINCREMENT, question TEXT NULL, category VARCHAR(20) NOT NULL DEFAULT "",
            sort INTEGER NOT NULL DEFAULT 0, archived INTEGER NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        self::createTable($pdo, 'CREATE TABLE faq_events (id INTEGER PRIMARY KEY AUTOINCREMENT, ids VARCHAR(255) NOT NULL DEFAULT "")');
        // Shipping and billing rows interleaved; every position still 0, as after adding the column.
        $pdo->exec("INSERT INTO faqs (question, category, archived, updated_at) VALUES
            ('{\"en\":\"Where is my parcel?\",\"ar\":\"أين طردي؟\"}', 'shipping', 0, '2026-01-01 10:00:00'),
            ('{\"en\":\"How do I pay?\"}', 'billing', 0, '2026-01-01 10:00:00'),
            ('{\"en\":\"Old question\"}', 'billing', 1, '2026-01-01 10:00:00'),
            ('{\"en\":\"Can I get a refund?\"}', 'billing', 0, '2026-01-01 10:00:00'),
            ('{\"en\":\"Do you ship abroad?\"}', 'shipping', 0, '2026-01-01 10:00:00'),
            ('{\"en\":\"Is VAT included?\"}', 'billing', 0, '2026-01-01 10:00:00')");
    }

    /** @return array<int, int> id => position */
    private static function positions(): array
    {
        return array_map('intval', self::pdo()->query('SELECT id, sort FROM faqs ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    private static function save(array $ids, array $filters = [], array $options = []): array
    {
        return self::post('/en/admin/faqs/reorder', ['order' => json_encode(array_map('strval', $ids)), '_filters' => json_encode((object) $filters)],
            self::options($options + ['headers' => ['Accept' => 'application/json']]));
    }

    public function testListShowsTheReorderButtonAndTheEditorsOrder(): void
    {
        self::pdo()->exec('UPDATE faqs SET sort = CASE id WHEN 1 THEN 3 WHEN 2 THEN 1 WHEN 4 THEN 2 WHEN 5 THEN 5 WHEN 6 THEN 4 ELSE 0 END');
        $body = self::request('GET', '/en/admin/faqs', self::options())['body'];
        self::assertStringContainsString('href="https://example.test/en/admin/faqs/reorder" data-admin-reorder-link', $body);
        self::assertStringContainsString('>Reorder</span>', $body);
        // No defaultSort(): the list follows sort, then id.
        $order = ['How do I pay?', 'Can I get a refund?', 'Where is my parcel?', 'Is VAT included?', 'Do you ship abroad?'];
        $positions = array_map(static fn (string $q): int|false => strpos($body, $q), $order);
        self::assertSame($positions, array_values(array_filter($positions, 'is_int')));
        $sorted = $positions; sort($sorted);
        self::assertSame($sorted, $positions);
    }

    public function testButtonKeepsTheActiveFilterAndIsHiddenWithoutPermission(): void
    {
        $body = self::request('GET', '/en/admin/faqs?filter[category]=billing', self::options())['body'];
        self::assertStringContainsString('href="https://example.test/en/admin/faqs/reorder?filter%5Bcategory%5D=billing"', $body);

        $viewOnly = [0 => [], 1 => ['content.faqs.view']];
        $body = self::request('GET', '/en/admin/faqs', self::options(['permissions' => $viewOnly]))['body'];
        self::assertStringNotContainsString('data-admin-reorder-link', $body);
        self::assertSame(403, self::request('GET', '/en/admin/faqs/reorder', self::options(['permissions' => $viewOnly]))['status']);

        self::assertStringNotContainsString('data-admin-reorder-link', self::request('GET', '/en/admin/locked-faqs', self::options())['body']);
        self::assertSame(403, self::request('GET', '/en/admin/locked-faqs/reorder', self::options())['status']);
        self::assertStringNotContainsString('data-admin-reorder-link', self::request('GET', '/en/admin/posts', self::options())['body'], 'not reorderable');
        self::assertSame(404, self::request('GET', '/en/admin/posts/reorder', self::options())['status']);
    }

    public function testReactiveListShowsTheButtonAndDoneReturnsToItsFilters(): void
    {
        $reactive = ['admin_config' => ['reactive' => true]];
        $body = self::request('GET', '/en/admin/faqs?admin-resource_filters=' . rawurlencode('{"category":"billing"}'), self::options($reactive))['body'];
        self::assertStringContainsString('href="https://example.test/en/admin/faqs/reorder?filter%5Bcategory%5D=billing" data-admin-reorder-link', $body);
        $body = self::request('GET', '/en/admin/faqs/reorder?filter[category]=billing', self::options($reactive))['body'];
        self::assertStringContainsString('href="https://example.test/en/admin/faqs?admin-resource_filters=%7B%22category%22%3A%22billing%22%7D" data-reorder-done', $body);
    }

    public function testReorderPageListsTheScopeInOrderWithHandlesAndButtons(): void
    {
        $response = self::request('GET', '/en/admin/faqs/reorder', self::options());
        self::assertSame(200, $response['status']);
        $body = $response['body'];
        self::assertStringContainsString('<h1 class="fa-page-title">Reorder FAQs</h1>', $body);
        self::assertStringContainsString('data-reorder-url="https://example.test/en/admin/faqs/reorder"', $body);
        self::assertSame(5, substr_count($body, 'data-reorder-handle'), 'archived rows are outside the scope');
        self::assertStringNotContainsString('Old question', $body);
        self::assertStringContainsString('aria-label="Move Where is my parcel?"', $body);
        self::assertStringContainsString('name="move_up" value="1" aria-label="Move Where is my parcel? up" title="Move Where is my parcel? up" disabled', $body, 'the first row cannot go up');
        self::assertStringContainsString('name="move_down" value="6" aria-label="Move Is VAT included? down" title="Move Is VAT included? down" disabled', $body, 'the last row cannot go down');
        self::assertStringContainsString('<input type="hidden" name="_filters" value="{}">', $body);
        self::assertStringContainsString('href="https://example.test/en/admin/faqs" data-reorder-done', $body);
    }

    public function testFilteredPageShowsOnlyThatGroupAndItsChip(): void
    {
        $body = self::request('GET', '/en/admin/faqs/reorder?filter[category]=billing', self::options())['body'];
        self::assertSame(3, substr_count($body, 'data-reorder-handle'));
        self::assertStringNotContainsString('Where is my parcel?', $body);
        self::assertStringContainsString('Only these records:', $body);
        self::assertStringContainsString('<span class="fa-chip-label">Category</span> Billing', $body);
        self::assertStringContainsString('name="_filters" value="{&quot;category&quot;:&quot;billing&quot;}"', $body);
        self::assertStringContainsString('href="https://example.test/en/admin/faqs?filter%5Bcategory%5D=billing" data-reorder-done', $body);
    }

    public function testSavingNumbersTheScopeOnceThenHandsOutTheSetsPositions(): void
    {
        $response = self::save([6, 1, 2, 4, 5]);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame(['ok' => true], json_decode($response['body'], true));
        self::assertSame([1 => 2, 2 => 3, 3 => 0, 4 => 4, 5 => 5, 6 => 1], self::positions(), 'archived row 3 untouched');
        self::assertSame('2026-01-01 10:00:00', self::row('faqs', 1)['updated_at'], 'ordering is not an edit');
        self::assertSame(['6,1,2,4,5'], self::pdo()->query('SELECT ids FROM faq_events')->fetchAll(\PDO::FETCH_COLUMN), 'afterReorder ran once');
    }

    public function testFilteredSaveReordersTheGroupAmongItsOwnPositions(): void
    {
        self::save([1, 2, 4, 5, 6]);   // 1..5 in id order
        $response = self::save([6, 4, 2], ['category' => 'billing']);
        self::assertSame(200, $response['status'], $response['body']);
        // Billing rows held positions 2, 3, 5: now 6 → 2, 4 → 3, 2 → 5. Shipping rows keep 1 and 4.
        self::assertSame([1 => 1, 2 => 5, 3 => 0, 4 => 3, 5 => 4, 6 => 2], self::positions());
    }

    public function testChangedSetIsRefusedWithAMessageAndNothingMoves(): void
    {
        self::save([1, 2, 4, 5, 6]);
        $before = self::positions();
        $response = self::save([6, 2, 4, 1]);   // row 5 is missing: someone else added it meanwhile
        self::assertSame(409, $response['status']);
        $json = json_decode($response['body'], true);
        self::assertSame(['ok' => false, 'message' => 'The list changed, so it was reloaded. Try again.', 'reload' => true], $json);
        self::assertSame($before, self::positions());
        self::assertSame('The list changed, so it was reloaded. Try again.', self::flash($response['session']), 'shown again after the page reloads');

        self::assertSame(409, self::save([1, 2, 3, 4, 5, 6])['status'], 'an archived key is outside the scope');
    }

    public function testKeysWithDotsAreAccepted(): void
    {
        self::pdo()->exec("UPDATE faqs SET sort = id");
        $response = self::save([6, 1, 2, 4, 5]);
        self::assertSame(200, $response['status']);
        $response = self::post('/en/admin/faqs/reorder', ['order' => json_encode(['6', '1', '2', '4', 'a.b@c']), '_filters' => '{}'], self::options(['headers' => ['Accept' => 'application/json']]));
        self::assertSame(409, $response['status'], 'any printable key is checked against the set, not refused as malformed');
        $response = self::post('/en/admin/faqs/reorder', ['order' => json_encode(['6', "1\n"]), '_filters' => '{}'], self::options(['headers' => ['Accept' => 'application/json']]));
        self::assertSame(400, $response['status'], 'control characters are malformed');
    }

    public function testMalformedOrdersAreBadRequests(): void
    {
        foreach (['not json', '{"a":1}', '[1,1,2,4,5,6]', json_encode([[1]]), json_encode([str_repeat('x', 192)])] as $order) {
            $response = self::post('/en/admin/faqs/reorder', ['order' => $order, '_filters' => '{}'], self::options(['headers' => ['Accept' => 'application/json']]));
            self::assertSame(400, $response['status'], $order);
        }
        $response = self::post('/en/admin/faqs/reorder', ['order' => '["1"]', '_filters' => '{"category":"nope"}'], self::options(['headers' => ['Accept' => 'application/json']]));
        self::assertSame(400, $response['status'], 'an invalid filter never widens the set');
        $response = self::post('/en/admin/faqs/reorder', ['order' => '["1"]', '_filters' => '{"unknown":"x"}'], self::options(['headers' => ['Accept' => 'application/json']]));
        self::assertSame(400, $response['status']);
    }

    public function testSavingNeedsTheTokenAndTheUpdatePermission(): void
    {
        $response = self::request('POST', '/en/admin/faqs/reorder', self::options(['post' => ['order' => '["6","1","2","4","5"]', '_filters' => '{}']]));
        self::assertSame(419, $response['status']);
        $response = self::save([6, 1, 2, 4, 5], [], ['permissions' => [0 => [], 1 => ['content.faqs.view']]]);
        self::assertSame(403, $response['status']);
        $response = self::post('/en/admin/locked-faqs/reorder', ['order' => '["6","1","2","4","5"]', '_filters' => '{}'], self::options(['headers' => ['Accept' => 'application/json']]));
        self::assertSame(403, $response['status'], 'canReorder() refuses');
        self::assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0], self::positions());
    }

    public function testButtonsWithoutJavaScriptMoveOnePlaceAndKeepFocus(): void
    {
        $response = self::post('/en/admin/faqs/reorder', ['move_down' => '1', '_filters' => '{}'], self::options());
        self::assertTrue($response['redirected']);
        self::assertSame([1 => 2, 2 => 1, 3 => 0, 4 => 3, 5 => 4, 6 => 5], self::positions());

        $response = self::post('/en/admin/faqs/reorder', ['move_up' => '6', '_filters' => '{"category":"billing"}'], self::options());
        self::assertTrue($response['redirected']);
        // Billing in order: 2 (1), 4 (3), 6 (5) → 6 swaps with 4.
        self::assertSame([1 => 2, 2 => 1, 3 => 0, 4 => 5, 5 => 4, 6 => 3], self::positions());

        self::post('/en/admin/faqs/reorder', ['move_up' => '2', '_filters' => '{}'], self::options());
        self::assertSame([1 => 2, 2 => 1, 3 => 0, 4 => 5, 5 => 4, 6 => 3], self::positions(), 'the first row stays first');

        $body = self::request('GET', '/en/admin/faqs/reorder?moved=6&step=up', self::options())['body'];
        self::assertMatchesRegularExpression('~name="move_up" value="6"[^>]*autofocus~', $body);
        $body = self::request('GET', '/en/admin/faqs/reorder?moved=2&step=up', self::options())['body'];
        self::assertMatchesRegularExpression('~name="move_down" value="2"[^>]*autofocus~', $body, 'at the top, focus moves to the enabled button');
    }

    public function testNewRecordsGoToTheEndOfTheOrder(): void
    {
        self::pdo()->exec('UPDATE faqs SET sort = id * 10');   // gaps: the largest position counts, not the row count
        $response = self::post('/en/admin/faqs/create', ['question' => ['en' => 'New question'], 'category' => 'billing'], self::options());
        self::assertTrue($response['redirected'], $response['body']);
        $row = self::pdo()->query("SELECT sort FROM faqs WHERE question LIKE '%New question%'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(61, (int) $row['sort'], 'after 60 (archived row 3 at 30 is outside the scope, but 60 is the largest)');
    }

    public function testTooManyRowsAsksToFilterFirst(): void
    {
        $body = self::request('GET', '/en/admin/faqs/reorder', self::options(['admin_config' => ['resources' => [SmallLimitFaqResource::class]]]))['body'];
        self::assertStringContainsString('There are 5 records here. Reorder works with up to 3 at a time: filter the list first.', $body);
        self::assertStringNotContainsString('data-reorder-handle', $body);
        $response = self::post('/en/admin/faqs/reorder', ['order' => '["1","2","4"]', '_filters' => '{}'], self::options(['admin_config' => ['resources' => [SmallLimitFaqResource::class]], 'headers' => ['Accept' => 'application/json']]));
        self::assertSame(409, $response['status']);
        self::assertStringContainsString('up to 3 at a time', json_decode($response['body'], true)['message']);
    }

    public function testArabicPageIsTranslated(): void
    {
        $body = self::request('GET', '/ar/admin/faqs/reorder', self::options())['body'];
        self::assertStringContainsString('aria-label="نقل أين طردي؟"', $body);
        self::assertStringContainsString('>تم</a>', $body);
    }

    private static function flash(array $session): string
    {
        array_walk_recursive($session, static function ($value) use (&$found): void { if (is_string($value) && str_contains($value, 'list changed')) { $found = $value; } });
        return (string) ($found ?? '');
    }
}
