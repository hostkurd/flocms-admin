<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Tables\{BuildingResource, OfferResource, RoomOverviewResource, RoomResource};

/**
 * Editable cells (0.38) through whole requests: the list's small forms, the
 * JSON answers scripts get, the same checks as the edit page (permission,
 * canEdit(), validation, unique), conflicts, the confirm page without
 * JavaScript, beforeUpdate(), the hooks, updated_at and the activity log.
 */
final class CellsRequestTest extends RequestTestCase
{
    private const TABLES = ['admin_trash', 'admin_activity'];
    private const JSON = ['X-Flo-Admin-Cell' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        self::createOffers();
        $pdo = self::pdo();
        foreach (self::TABLES as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        $file = self::$root . '/trash-activity-migration.php';
        file_put_contents($file, \FloCMS\Admin\Console\Stub::render('trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']));
        (require $file)->up(new \FloCMS\Core\Database($pdo));
        @unlink(self::hookLog());
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) { self::pdo()->exec('DROP TABLE IF EXISTS ' . $table); }
        parent::tearDown();
    }

    public static function createOffers(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS offers');
        $pdo->exec('DROP TABLE IF EXISTS cities');
        self::createTable($pdo, 'CREATE TABLE cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NULL)');
        self::createTable($pdo, 'CREATE TABLE offers (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(190) NOT NULL DEFAULT "", city_id INTEGER NULL,
            status VARCHAR(20) NOT NULL DEFAULT "draft", price DECIMAL(12,2) NULL, stock INTEGER NULL, featured INTEGER NOT NULL DEFAULT 0,
            published VARCHAR(20) NOT NULL DEFAULT "draft", published_at DATETIME NULL, note VARCHAR(190) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $pdo->exec("INSERT INTO cities (name) VALUES ('{\"en\":\"Athens\",\"ar\":\"أثينا\"}'), ('{\"en\":\"Chania\"}'), ('{\"en\":\"Heraklion\"}')");
        $insert = $pdo->prepare('INSERT INTO offers (title, city_id, status, price, stock, featured, published, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Villa in Kifisia', 1, 'published', '1250000.00', 1, 1, 'published', '2026-09-03 10:00:00'],
            ['Seaside flat', 1, 'draft', '385000.00', 3, 0, 'draft', '2026-09-20 10:00:00'],
            ['Stone house', 2, 'published', '465000.00', 1, 0, 'published', '2026-10-01 10:00:00'],
            ['Loft near Ladadika', null, 'published', '210000.50', 2, 1, 'published', '2026-10-05 10:00:00'],
            ['Locked flat', 3, 'sold', '99000.00', 0, 0, 'draft', '2026-10-09 10:00:00'],
        ] as $row) {
            $insert->execute([...$row, '2026-10-10 09:00:00']);
        }
    }

    private static function hookLog(): string
    {
        return self::$root . '/hooks.log';
    }

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ['resources' => [OfferResource::class]] + ($extra['admin_config'] ?? []), 'env' => ['FLO_TEST_HOOK_LOG' => self::hookLog()]] + $extra;
    }

    /** The token the list sends for a stored value. */
    private static function was(int $id, string $column): string
    {
        $row = self::row('offers', $id);
        return \FloCMS\Admin\Table\Columns\EditableColumn::token($row[$column] ?? null);
    }

    /** POST a cell as the script does (JSON answer). */
    private static function cell(int $id, string $column, string $value, string $was, array $extra = [], array $options = []): array
    {
        $result = self::post('/en/admin/offers/cell/' . $id, ['column' => $column, 'value' => $value, 'was' => $was, '_prefix' => 'fa-list-cell'] + $extra,
            self::options(['headers' => self::JSON] + $options));
        $result['json'] = json_decode($result['body'], true);
        return $result;
    }

    public function testTheListShowsEditableCellsAsSmallForms(): void
    {
        $page = self::request('GET', '/en/admin/offers', self::options());
        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertStringContainsString('<form class="fa-cell fa-cell-toggle" method="post" action="https://example.test/en/admin/offers/cell/1" data-admin-local data-admin-cell data-prefix="fa-list-cell" novalidate>', $body);
        self::assertStringContainsString('role="switch" aria-checked="true" aria-label="Featured: Villa in Kifisia"', $body);
        self::assertStringContainsString('<input type="hidden" name="was" value="v1" data-admin-cell-was>', $body);
        // The switch sends the other value
        self::assertMatchesRegularExpression('#name="column" value="featured">.{0,400}name="value" value="0" data-admin-cell-next#s', $body);
        self::assertStringContainsString('<select class="fa-cell-select" id="fa-list-cell-2-status" name="value" data-admin-cell-select>', $body);
        self::assertStringContainsString('<option value="draft" data-color="yellow" selected>Draft</option>', $body);
        self::assertStringContainsString('€ 1,250,000.00', $body, 'a number with its prefix and separators');
        self::assertMatchesRegularExpression('#name="value" value="1250000(\.00?)?" data-admin-cell-input inputmode="decimal" dir="ltr"#', $body);
        self::assertStringContainsString('maxlength="120"', $body, 'the title input takes its length from the form field');
        // canEdit() refuses "Locked flat": its cells are plain values
        self::assertStringNotContainsString('action="https://example.test/en/admin/offers/cell/5"', $body);
        self::assertStringContainsString('data-confirm data-confirm-title="Unpublish Villa in Kifisia?" data-confirm-text="Visitors will no longer see it." data-confirm-button="Unpublish">', $body);
    }

    public function testViewersSeeValuesWithoutForms(): void
    {
        $page = self::request('GET', '/en/admin/offers', self::options(['session' => ['role' => 4] + self::EDITOR, 'permissions' => self::PERMISSIONS + [4 => ['content.offers.view']]]));
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('data-admin-local data-admin-cell', $page['body']);
        self::assertStringContainsString('<span class="fa-badge fa-badge-green">Yes</span>', $page['body']);
        self::assertStringContainsString('<span class="fa-badge fa-badge-yellow">Draft</span>', $page['body']);

        $refused = self::cell(1, 'featured', '0', 'v1', [], ['session' => ['role' => 4] + self::EDITOR, 'permissions' => self::PERMISSIONS + [4 => ['content.offers.view']]]);
        self::assertSame(403, $refused['status']);
        self::assertSame('1', (string) self::row('offers', 1)['featured']);
    }

    public function testASwitchSavesWithTheEditPagesSideEffects(): void
    {
        $saved = self::cell(2, 'featured', '1', 'v0');
        self::assertSame(200, $saved['status'], $saved['body']);
        self::assertTrue($saved['json']['ok']);
        self::assertStringContainsString('aria-checked="true" aria-label="Featured: Seaside flat"', $saved['json']['html']);
        self::assertStringContainsString('id="fa-list-cell-2-featured"', $saved['json']['html'], 'the control keeps its id');
        $row = self::row('offers', 2);
        self::assertSame('1', (string) $row['featured']);
        self::assertNotSame('2026-10-10 09:00:00', $row['updated_at'], 'updated_at moves');
        self::assertSame("after:2\n", (string) @file_get_contents(self::hookLog()), 'afterSave() ran');
        $activity = self::pdo()->query("SELECT action, record_id, changes FROM admin_activity")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $activity);
        self::assertSame('updated', $activity[0]['action']);
        $changes = json_decode((string) $activity[0]['changes'], true);
        self::assertSame(['featured'], array_column($changes, 'field'), 'one changed field');
    }

    public function testTheValueGoesThroughTheFormsRules(): void
    {
        $text = self::cell(1, 'price', 'abc', self::was(1, 'price'));
        self::assertSame(422, $text['status'], $text['body']);
        self::assertFalse($text['json']['ok']);
        self::assertStringContainsString('Price', $text['json']['message']);
        self::assertEquals(1250000, (float) self::row('offers', 1)['price']);

        $negative = self::cell(1, 'stock', '-2', 'v1');
        self::assertSame(422, $negative['status']);

        $required = self::cell(1, 'title', '', 'vVilla in Kifisia');
        self::assertSame(422, $required['status']);

        $unique = self::cell(1, 'title', 'Stone house', 'vVilla in Kifisia');
        self::assertSame(422, $unique['status'], 'unique() applies');
        self::assertSame('Villa in Kifisia', self::row('offers', 1)['title']);

        $option = self::cell(2, 'status', 'archived', 'vdraft');
        self::assertSame(422, $option['status'], 'only the Select\'s options');

        $ok = self::cell(1, 'price', '1300000', self::was(1, 'price'));
        self::assertSame(200, $ok['status'], $ok['body']);
        self::assertStringContainsString('€ 1,300,000.00', $ok['json']['html']);
        self::assertEquals(1300000, (float) self::row('offers', 1)['price']);
    }

    public function testRequiredWhenSeesTheRecordsOtherValues(): void
    {
        // note is required when status is "sold": changing status to sold without a note is refused
        $sold = self::cell(2, 'status', 'sold', 'vdraft');
        self::assertSame(422, $sold['status'], $sold['body']);
        self::pdo()->exec("UPDATE offers SET note = 'Signed' WHERE id = 2");
        $sold = self::cell(2, 'status', 'sold', 'vdraft');
        self::assertSame(200, $sold['status'], $sold['body']);
        self::assertSame('Signed', self::row('offers', 2)['note'], 'the other field is not written');
    }

    public function testAChangeSinceTheListWasDrawnIsAConflict(): void
    {
        self::pdo()->exec("UPDATE offers SET status = 'sold' WHERE id = 1");
        $conflict = self::cell(1, 'status', 'draft', 'vpublished');
        self::assertSame(409, $conflict['status']);
        self::assertTrue($conflict['json']['conflict']);
        self::assertStringContainsString('somebody changed Status meanwhile', $conflict['json']['message']);
        self::assertStringContainsString('<option value="sold" data-color="gray" selected>Sold</option>', $conflict['json']['html'] ?? '', 'the current value comes back');
        self::assertSame('sold', self::row('offers', 1)['status']);

        // Another column of the same row changed: no conflict for this one
        self::pdo()->exec("UPDATE offers SET stock = 9 WHERE id = 3");
        self::assertSame(200, self::cell(3, 'featured', '1', 'v0')['status']);

        // Gone
        self::pdo()->exec('DELETE FROM offers WHERE id = 4');
        self::assertSame(404, self::cell(4, 'featured', '0', 'v1')['status']);
    }

    public function testOnlyEditableColumnsOfTheListCanBeSaved(): void
    {
        self::assertSame(404, self::cell(1, 'created_at', '2020-01-01', 'v2026-09-03 10:00:00')['status']);
        self::assertSame(404, self::cell(1, 'city_id', '2', 'v1')['status'], 'a relation column is not editable');
        self::assertSame(404, self::cell(1, 'secret', 'x', 'n')['status']);
        self::assertSame(403, self::cell(5, 'featured', '1', 'v0')['status'], 'canEdit() refuses');
        $get = self::request('GET', '/en/admin/offers/cell/1', self::options(['expect_error' => true]));
        self::assertSame(405, $get['status']);
        // No CSRF token: refused by FloCMS before the action
        $noToken = self::request('POST', '/en/admin/offers/cell/1', self::options(['post' => ['column' => 'featured', 'value' => '0', 'was' => 'v1'], 'headers' => self::JSON, 'expect_error' => true]));
        self::assertNotSame(200, $noToken['status']);
        self::assertSame('1', (string) self::row('offers', 1)['featured']);
    }

    public function testWithoutJavaScriptTheListComesBackWithAMessage(): void
    {
        $back = '/en/admin/offers?q=Villa';
        $saved = self::post('/en/admin/offers/cell/2', ['column' => 'stock', 'value' => '7', 'was' => 'v3', '_back' => $back], self::options());
        self::assertTrue($saved['redirected']);
        self::assertSame('7', (string) self::row('offers', 2)['stock']);
        self::assertStringContainsString('Saved: Stock for “Seaside flat”.', json_encode($saved['session'], JSON_UNESCAPED_UNICODE));

        $invalid = self::post('/en/admin/offers/cell/2', ['column' => 'stock', 'value' => 'many', 'was' => 'v7', '_back' => $back], self::options());
        self::assertTrue($invalid['redirected']);
        self::assertSame('7', (string) self::row('offers', 2)['stock']);
    }

    public function testACellThatAsksFirst(): void
    {
        // Without JavaScript: a confirm page with the same values
        $page = self::post('/en/admin/offers/cell/1', ['column' => 'published', 'value' => 'draft', 'was' => 'vpublished', '_back' => '/en/admin/offers'], self::options());
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<h1 class="fa-page-title">Unpublish Villa in Kifisia?</h1>', $page['body']);
        self::assertStringContainsString('<input type="hidden" name="confirmed" value="1">', $page['body']);
        self::assertStringContainsString('class="fa-btn fa-btn-danger">Unpublish</button>', $page['body']);
        self::assertSame('published', self::row('offers', 1)['published']);

        // A script that did not ask is told to
        $json = self::cell(1, 'published', 'draft', 'vpublished');
        self::assertSame(409, $json['status']);
        self::assertTrue($json['json']['confirm']);

        // Confirmed: saved, and beforeUpdate() wrote published_at too
        $done = self::cell(1, 'published', 'draft', 'vpublished', ['confirmed' => '1']);
        self::assertSame(200, $done['status'], $done['body']);
        $row = self::row('offers', 1);
        self::assertSame('draft', $row['published']);
        self::assertNull($row['published_at']);
        $again = self::cell(1, 'published', 'published', 'vdraft', ['confirmed' => '1']);
        self::assertSame(200, $again['status']);
        self::assertSame('2026-10-23 10:00:00', self::row('offers', 1)['published_at']);
        // Only the two stored values
        self::assertSame(422, self::cell(1, 'published', 'maybe', 'vpublished', ['confirmed' => '1'])['status']);
    }

    private static function createRooms(): void
    {
        $pdo = self::pdo();
        foreach (['suites', 'buildings'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        self::createTable($pdo, 'CREATE TABLE buildings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "")');
        self::createTable($pdo, 'CREATE TABLE suites (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "", rent INTEGER NULL, available INTEGER NOT NULL DEFAULT 0, building_id INTEGER NULL)');
        $pdo->exec("INSERT INTO buildings (name) VALUES ('Seaside apartments'), ('Harbour house')");
        $pdo->exec("INSERT INTO suites (name, rent, available, building_id) VALUES ('A1', 1150, 1, 1), ('A2', 1100, 0, 1), ('B1', 1300, 0, 1), ('H1', 900, 1, 2)");
    }

    private static function rooms(array $extra = []): array
    {
        return ['admin_config' => ['resources' => [BuildingResource::class, RoomResource::class, RoomOverviewResource::class], 'reactive' => true] + ($extra['admin_config'] ?? [])] + $extra;
    }

    public function testCellsInARelationManagerStayInsideTheParent(): void
    {
        self::createRooms();
        $page = self::request('GET', '/en/admin/buildings/edit/1', self::rooms());
        self::assertSame(200, $page['status'], (string) $page['error']);
        $body = $page['body'];
        self::assertStringContainsString('action="https://example.test/en/admin/suites/cell/1?parent=buildings%3A1%3Asuites"', $body);
        self::assertStringContainsString('aria-label="Available: A1"', $body);
        self::assertStringContainsString('Rent, Total € 3,550', (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $body)))), 'the manager\'s totals: this building only');
        // The read-only manager shows the same rooms as values
        self::assertSame(2, substr_count($body, 'action="https://example.test/en/admin/suites/cell/1?'), 'rent and available of A1');
        self::assertStringNotContainsString('suite-overview/cell', $body);

        $token = '?parent=' . rawurlencode('buildings:1:suites');
        $saved = self::post('/en/admin/suites/cell/2' . $token, ['column' => 'rent', 'value' => '1200', 'was' => 'v1100'], self::rooms(['headers' => self::JSON]));
        self::assertSame(200, $saved['status'], $saved['body']);
        self::assertSame('1200', (string) self::row('suites', 2)['rent']);
        self::assertStringContainsString('cell/2?parent=buildings%3A1%3Asuites', (string) json_decode($saved['body'], true)['html']);
        // Another building's room is outside this parent
        self::assertSame(404, self::post('/en/admin/suites/cell/4' . $token, ['column' => 'rent', 'value' => '1', 'was' => 'v900'], self::rooms(['headers' => self::JSON]))['status']);
        self::assertSame('900', (string) self::row('suites', 4)['rent']);
        // The read-only manager's token cannot save
        $readOnly = self::post('/en/admin/suite-overview/cell/1?parent=' . rawurlencode('buildings:1:suite-overview'), ['column' => 'rent', 'value' => '1', 'was' => 'v1150'], self::rooms(['headers' => self::JSON, 'expect_error' => true]));
        self::assertContains($readOnly['status'], [403, 404]);
        self::assertSame('1150', (string) self::row('suites', 1)['rent']);
    }
}
