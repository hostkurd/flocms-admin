<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Tables\OfferResource;

/**
 * Column totals and grouped lists (0.38) through whole requests, on SQLite
 * and MySQL/MariaDB alike: this page and all rows, the filters and the
 * search, group headings with counts and subtotals, "continued", the Group
 * by menu remembered as a preference, and the Reactive list.
 */
final class TablesRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CellsRequestTest::createOffers();
        self::pdo()->exec('DROP TABLE IF EXISTS admin_user_preferences');
    }

    protected function tearDown(): void
    {
        self::pdo()->exec('DROP TABLE IF EXISTS admin_user_preferences');
        parent::tearDown();
    }

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ['resources' => [OfferResource::class], 'per_page' => 2] + ($extra['admin_config'] ?? [])] + $extra;
    }

    private static function list(string $query = '', array $extra = []): string
    {
        $page = self::request('GET', '/en/admin/offers' . ($query !== '' ? '?' . $query : ''), self::options($extra));
        self::assertSame(200, $page['status']);
        return $page['body'];
    }

    /** The text of the totals row of one kind ("page" or "all"). */
    private static function totalsRow(string $body, string $kind): string
    {
        self::assertSame(1, preg_match('#<tr class="fa-totals-row is-' . $kind . '">(.*?)</tr>#s', $body, $m), 'a "' . $kind . '" totals row');
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public function testTotalsOfThisPageAndOfAllRows(): void
    {
        $body = self::list('group=none');
        $page = self::totalsRow($body, 'page');
        self::assertStringContainsString('This page 2 of 5 rows', $page);
        // The list's own order: newest first (rows 5 and 4)
        self::assertStringContainsString('Total € 309,000.50', $page);
        self::assertStringContainsString('Average € 154,500', $page);
        self::assertStringContainsString('Featured 1', $page);
        $all = self::totalsRow($body, 'all');
        self::assertStringContainsString('All 5 rows', $all);
        self::assertStringNotContainsString('matching the search', $all, 'no filter: no note');
        self::assertStringContainsString('Total € 2,409,000.50', $all);
        self::assertStringContainsString('Average € 481,800', $all);
        self::assertStringContainsString('Total 7', $all);
        self::assertStringContainsString('Lowest 0', $all);
        self::assertStringContainsString('Highest 3', $all);
        self::assertStringContainsString('Featured 2', $all);
        self::assertStringContainsString('<span class="fa-total-column">Price, </span>', $body, 'phones say the column');
    }

    public function testAllRowsFollowTheFiltersAndTheSearch(): void
    {
        $body = self::list('group=none&filter[status]=published');
        $all = self::totalsRow($body, 'all');
        self::assertStringContainsString('All 3 rows matching the search and filters', $all);
        self::assertStringContainsString('Total € 1,925,000.50', $all);
        self::assertStringContainsString('Featured 2', $all);

        $body = self::list('group=none&q=flat');
        self::assertStringNotContainsString('is-page', $body, 'one page: only "All rows"');
        $all = self::totalsRow($body, 'all');
        self::assertStringContainsString('All 2 rows', $all);
        self::assertStringContainsString('Total € 484,000.00', $all);

        $none = self::list('group=none&q=nothing-matches');
        self::assertStringNotContainsString('fa-totals', $none, 'no rows: no totals');
    }

    public function testGroupedByARelationWithSubtotals(): void
    {
        $body = self::list('group=city_id');
        self::assertStringContainsString('<summary class="fa-btn is-active">', $body);
        self::assertStringContainsString('Group by: City', $body);
        self::assertSame(1, preg_match_all('#<tr class="fa-group-row"#', $body), 'page 1: Athens only');
        self::assertMatchesRegularExpression('#fa-group-title" dir="auto">Athens</span><span class="fa-group-count">2 rows</span>#', $body);
        self::assertMatchesRegularExpression('#data-admin-group-member="fa-list-g[0-9a-f]{10}"#', $body);
        self::assertStringContainsString('aria-label="Collapse Athens"', $body);
        self::assertStringContainsString('aria-label="Select the “Athens” rows on this page"', $body);

        $page2 = self::list('group=city_id&page=2');
        self::assertMatchesRegularExpression('#>Chania</span><span class="fa-group-count">1 row</span>.*>Heraklion</span>#s', $page2);
        $page3 = self::list('group=city_id&page=3');
        self::assertStringContainsString('>No City</span>', $page3, 'empty values last');
        self::assertStringContainsString('Loft near Ladadika', $page3);
    }

    public function testAGroupContinuedOnTheNextPageAndItsSubtotalsCoverAllItsRows(): void
    {
        $body = self::list('group=status&page=2');
        self::assertStringContainsString('>Published</span><span class="fa-group-continued">continued</span><span class="fa-group-count">3 rows</span>', $body);
        self::assertSame(1, preg_match('#<tr class="fa-group-row".*?</tr>#s', $body, $head));
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $head[0])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('€ 1,925,000.50', $text, 'the subtotal of all three published rows');
        self::assertStringContainsString('fa-badge fa-badge-green', $head[0], 'the SelectColumn colour');

        $first = self::list('group=status');
        self::assertMatchesRegularExpression('#>Draft</span><span class="fa-group-count">1 row</span>.*>Published</span><span class="fa-group-count">3 rows</span>#s', $first);
        self::assertStringNotContainsString('fa-group-continued', $first);

        $desc = self::list('group=status:desc');
        self::assertMatchesRegularExpression('#>Sold</span>.*>Published</span>#s', $desc, 'groups in the other order');
    }

    public function testDateGroupsAndTheChoiceIsRemembered(): void
    {
        $body = self::list('group=created_at');
        self::assertStringContainsString('>September 2026</span><span class="fa-group-count">2 rows</span>', $body);
        self::assertStringContainsString('Oldest first', $body);

        // The choice is a preference: the next visit without ?group keeps it
        self::createPreferences();
        $session = self::request('GET', '/en/admin/offers?group=status', self::options())['session'];
        $again = self::list('', ['session' => $session]);
        self::assertStringContainsString('Group by: Status', $again);
        $none = self::request('GET', '/en/admin/offers?group=none', self::options(['session' => $session]))['session'];
        self::assertStringNotContainsString('fa-group-row', self::list('', ['session' => $none]));
        self::assertMatchesRegularExpression('#<a class="fa-group-choice is-active" href="[^"]*group=none" aria-current="true">#', self::list('', ['session' => $none]));

        // A name that is not a group of the list is ignored, and not remembered
        self::assertStringContainsString('Group by: Status', self::list('group=title', ['session' => self::request('GET', '/en/admin/offers?group=status', self::options())['session']]));
        // A page far past the end: the last page, no error
        self::assertTrue(self::request('GET', '/en/admin/offers?group=status&page=999999999999999999', self::options())['redirected'], 'sent to the last page');
        self::pdo()->exec('DELETE FROM admin_user_preferences');
        self::assertStringNotContainsString('fa-group-row', self::list('group=title'));
        self::assertStringNotContainsString('fa-group-row', self::list('group=%3Cscript%3E'));
    }

    public function testTheReactiveListGroupsAndTotalsToo(): void
    {
        $page = self::request('GET', '/en/admin/offers?admin-resource_group=city_id', self::options(['admin_config' => ['reactive' => true]]));
        self::assertSame(200, $page['status'], (string) $page['error']);
        self::assertStringContainsString('class="fa-group-row"', $page['body']);
        self::assertStringContainsString('data-rx-set="group" data-rx-value="status" data-rx-action="groupBy"', $page['body']);
        self::assertStringContainsString('data-admin-cells-reload', $page['body'], 'a hidden refresh button for the totals after a save');
        self::assertStringContainsString('fa-totals-row is-all', $page['body']);
    }

    private static function createPreferences(): void
    {
        self::pdo()->exec('CREATE TABLE admin_user_preferences (`user_id` INTEGER NOT NULL, `key` VARCHAR(100) NOT NULL, `value` TEXT NULL, `updated_at` DATETIME NULL, PRIMARY KEY (`user_id`, `key`))');
    }
}
