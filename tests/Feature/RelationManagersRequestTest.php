<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Managers\{EnquiryResource, EstateResource, UnitResource};

/**
 * Relation managers (0.22): related records on a parent's edit page, limited to
 * that parent on the server (Reactive dialogs and the pages used without JavaScript).
 */
final class RelationManagersRequestTest extends RequestTestCase
{
    private const RESOURCES = [EstateResource::class, UnitResource::class, EnquiryResource::class];

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ($extra['admin_config'] ?? []) + ['resources' => self::RESOURCES]] + $extra;
    }

    private static function reactive(array $extra = []): array
    {
        return self::options(['admin_config' => ($extra['admin_config'] ?? []) + ['reactive' => true]] + $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (['units', 'enquiries', 'estates'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        self::createTable($pdo, 'CREATE TABLE estates (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(100) NOT NULL DEFAULT "")');
        self::createTable($pdo, 'CREATE TABLE units (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "", bedrooms VARCHAR(10) NULL, floor VARCHAR(20) NULL, estate_id INTEGER NULL, archived INTEGER NOT NULL DEFAULT 0)');
        self::createTable($pdo, 'CREATE TABLE enquiries (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "", email VARCHAR(100) NOT NULL DEFAULT "", estate_id INTEGER NULL)');
        $pdo->exec("INSERT INTO estates (title) VALUES ('Sea view villa'), ('Harbour flats'), ('Locked house')");
        $pdo->exec("INSERT INTO units (name, bedrooms, estate_id, archived) VALUES ('Flat 1', '2', 1, 0), ('Flat 2', '1', 1, 0), ('Penthouse', '4', 1, 0), ('Harbour A', '2', 2, 0), ('Old wing', '3', 1, 1), ('Locked unit', '1', 3, 0)");
        $pdo->exec("INSERT INTO enquiries (name, email, estate_id) VALUES ('Anna', 'anna@example.test', 1), ('Omar', 'omar@example.test', 2)");
    }

    /** The snapshot of one component on a page (the page may hold several). */
    private static function snapshot(array $page, string $key): string
    {
        preg_match_all('/<[^>]*data-rx-component="flo-admin-resource"[^>]*>/', $page['body'], $tags);
        foreach ($tags[0] as $tag) {
            if (str_contains($tag, 'id="rx-' . $key . '"') && preg_match('/data-rx-snapshot="([^"]+)"/', $tag, $match) === 1) {
                return html_entity_decode($match[1], ENT_QUOTES);
            }
        }
        self::fail('No component ' . $key . ' in: ' . substr($page['body'], 0, 4000));
    }

    private static function update(array $previous, string $action, array $state = [], array $options = [], string $key = 'rel-units'): array
    {
        $snapshot = str_contains($previous['body'], '<html') || str_contains($previous['body'], 'fa-record-form') ? self::snapshot($previous, $key) : null;
        if ($snapshot === null) {
            preg_match('/data-rx-snapshot="([^"]+)"/', $previous['body'], $match);
            $snapshot = html_entity_decode($match[1] ?? '', ENT_QUOTES);
        }
        return self::post('/en/flo-reactive/update', ['_snapshot' => $snapshot, '_action' => $action, 'state' => $state],
            self::reactive($options) + ['session' => $previous['session']]);
    }

    private static function draft(array $values): array
    {
        return ['draft' => json_encode($values, JSON_THROW_ON_ERROR)];
    }

    /** @return list<string> */
    private static function units(int $estate): array
    {
        return self::pdo()->query('SELECT name FROM units WHERE estate_id = ' . $estate . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testEditPageShowsTheManagersWithTabsAndCounts(): void
    {
        $page = self::request('GET', '/en/admin/estates/edit/1', self::reactive());
        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertGreaterThan(strpos($body, '</form>'), strpos($body, 'class="fa-managers"'), 'below the record form, never inside it');
        self::assertStringContainsString('data-admin-tab="fa-manager-units"', $body);
        self::assertStringContainsString('data-admin-manager-count-for="fa-manager-units">3</span>', $body, 'archived units are outside the child query()');
        self::assertStringContainsString('data-admin-manager-count-for="fa-manager-enquiries">1</span>', $body);
        self::assertStringContainsString('Penthouse', $body);
        self::assertStringNotContainsString('Harbour A', $body, 'another estate\'s unit');
        self::assertStringNotContainsString('Old wing', $body);
        self::assertStringNotContainsString('<th scope="col" class="">Estate', $body, 'the key column is left out');
        self::assertMatchesRegularExpression('~id="fa-rel-units-create-page"[^>]*data-rx-action="openAction"~', $body, 'New unit opens a dialog');
        self::assertStringNotContainsString('fa-rel-enquiries-create', $body, 'read-only');
        self::assertStringNotContainsString('Omar', $body);
        self::assertStringContainsString('data-admin-manager-count="3"', $body);
        self::assertStringContainsString('admin/units?static=1&amp;parent=estates%3A1%3Aunits', $body, 'the page without JavaScript');

        // The create page says the managers come once the record exists
        $create = self::request('GET', '/en/admin/estates/create', self::reactive())['body'];
        self::assertStringContainsString('fa-manager-pending', $create);
        self::assertStringContainsString('Add units and enquiries after saving', $create);
        self::assertStringNotContainsString('data-rx-component', $create);

        // Someone who may not view units sees only the enquiries, as a single card
        $permissions = self::PERMISSIONS;
        $permissions[1] = ['content.estates.*', 'content.enquiries.view'];
        $only = self::request('GET', '/en/admin/estates/edit/1', self::reactive(['permissions' => $permissions]))['body'];
        self::assertStringNotContainsString('rx-rel-units', $only);
        self::assertStringContainsString('rx-rel-enquiries', $only);
        self::assertStringNotContainsString('data-admin-tabs', substr($only, (int) strpos($only, 'fa-managers')), 'no tabs for one manager');

        // The sidebar has no Units entry; the parent's list counts them
        self::assertStringNotContainsString('href="https://example.test/en/admin/units"', $body);
        $list = self::request('GET', '/en/admin/estates', self::options())['body'];
        self::assertMatchesRegularExpression('~Sea view villa.*?fa-count-cell">3<~s', $list);
        self::assertMatchesRegularExpression('~Harbour flats.*?fa-count-cell">1<~s', $list);
    }

    public function testDialogsStayInsideTheParent(): void
    {
        $page = self::request('GET', '/en/admin/estates/edit/1', self::reactive());
        $open = self::update($page, 'create');
        self::assertStringContainsString('data-rx-open="true"', $open['body']);
        self::assertStringNotContainsString('name="estate_id"', $open['body'], 'the key is not a field here');
        self::assertStringNotContainsString('data-field="estate_id"', $open['body']);
        self::assertStringContainsString('data-field="floor"', $open['body'], 'fields that depend on the key see the parent');
        // The key is the parent's, whatever the draft says
        $saved = self::update($open, 'save', self::draft(['name' => 'Garden flat', 'bedrooms' => '2', 'estate_id' => '2']));
        self::assertSame(200, $saved['status'], $saved['body']);
        self::assertSame(['Flat 1', 'Flat 2', 'Penthouse', 'Old wing', 'Garden flat'], self::units(1));
        self::assertSame(['Harbour A'], self::units(2));
        self::assertStringContainsString('data-admin-manager-count="4"', $saved['body']);

        // Edit keeps the key; another estate's unit is not found
        $edit = self::update($saved, 'edit', ['selectedId' => '1']);
        self::assertStringContainsString('value="Flat 1"', $edit['body']);
        $edited = self::update($edit, 'save', self::draft(['name' => 'Flat 1A', 'bedrooms' => '2', 'estate_id' => '2']));
        self::assertSame(200, $edited['status'], $edited['body']);
        self::assertSame('1', (string) self::row('units', 1)['estate_id']);
        self::assertSame('Flat 1A', self::row('units', 1)['name']);
        foreach (['edit', 'confirmDelete'] as $action) {
            $other = self::update($edited, $action, ['selectedId' => '4']);
            self::assertSame(422, $other['status'], $action);
            self::assertSame('Harbour A', self::row('units', 4)['name']);
        }
        $deleteOld = self::update($edited, 'confirmDelete', ['selectedId' => '5']);
        self::assertSame(422, $deleteOld['status'], 'outside the child query() too');

        // Delete one of this estate's units
        $confirm = self::update($edited, 'confirmDelete', ['selectedId' => '2']);
        self::assertSame(200, self::update($confirm, 'delete')['status']);
        self::assertNull(self::row('units', 2));

        // Bulk actions see only this estate's records
        $bulk = self::update($edited, 'openBulk', ['selectedBulk' => 'delete', 'selectedIds' => '["1","4"]']);
        self::assertSame(422, $bulk['status'], 'a forged id of another estate');
        self::assertNotNull(self::row('units', 4));
    }

    public function testTheParentIsSignedAndCheckedOnEveryRequest(): void
    {
        $page = self::request('GET', '/en/admin/estates/edit/1', self::reactive());
        foreach (['parentId' => '2', 'parentSlug' => 'units', 'relation' => 'enquiries'] as $name => $value) {
            self::assertSame(400, self::update($page, 'refresh', [$name => $value])['status'], $name);
        }
        // Read-only manager: no Create, even asked for directly
        self::assertSame(404, self::update($page, 'create', [], [], 'rel-enquiries')['status']);
        self::assertSame(404, self::update($page, 'edit', ['selectedId' => '1'], [], 'rel-enquiries')['status']);

        // Losing the right to edit the parent closes its managers
        $permissions = self::PERMISSIONS;
        $permissions[1] = ['content.estates.view', 'content.units.*'];
        self::assertSame(403, self::update($page, 'refresh', [], ['permissions' => $permissions])['status']);
        // So does losing the child's view permission
        $permissions[1] = ['content.estates.*'];
        self::assertSame(403, self::update($page, 'refresh', [], ['permissions' => $permissions])['status']);
        // A parent that was deleted meanwhile
        self::pdo()->exec('DELETE FROM estates WHERE id = 1');
        self::assertSame(404, self::update($page, 'refresh')['status']);
        // A parent the user may not edit has no edit page (and so no managers)
        self::assertSame(403, self::request('GET', '/en/admin/estates/edit/3', self::reactive())['status']);
    }

    public function testWithoutJavaScriptTheChildPagesKeepTheParent(): void
    {
        $token = 'parent=' . rawurlencode('estates:1:units');
        $list = self::request('GET', '/en/admin/units?static=1&' . $token, self::reactive());
        self::assertSame(200, $list['status'], $list['body']);
        self::assertStringContainsString('Units · Sea view villa', $list['body']);
        self::assertStringContainsString('Back to Sea view villa', $list['body']);
        self::assertStringContainsString('Penthouse', $list['body']);
        self::assertStringNotContainsString('Harbour A', $list['body']);
        self::assertStringContainsString('admin/units/create?parent=estates%3A1%3Aunits', $list['body']);
        self::assertStringContainsString('admin/units/edit/1?parent=estates%3A1%3Aunits', $list['body']);
        self::assertStringContainsString('<input type="hidden" name="parent" value="estates:1:units">', $list['body'], 'the search form keeps it');
        self::assertStringNotContainsString('data-label="Estate"', $list['body'], 'no key column');
        self::assertStringNotContainsString('name="filter[estate_id]"', $list['body'], 'no key filter');
        self::assertStringContainsString('action="https://example.test/en/admin/units/delete/1?parent=estates%3A1%3Aunits"', self::request('GET', '/en/admin/units/edit/1?' . $token, self::reactive())['body'], 'delete keeps the parent');

        $create = self::request('GET', '/en/admin/units/create?' . $token, self::reactive());
        self::assertSame(200, $create['status']);
        self::assertStringNotContainsString('name="estate_id"', $create['body']);
        self::assertStringContainsString('action="https://example.test/en/admin/units/create?parent=estates%3A1%3Aunits"', $create['body']);
        self::assertStringContainsString('name="_back" value="https://example.test/en/admin/estates/edit/1"', $create['body'], 'back to the parent');
        $saved = self::post('/en/admin/units/create?' . $token, ['name' => 'Studio', 'bedrooms' => '1', 'estate_id' => '2', '_close' => '1'], self::reactive());
        self::assertTrue($saved['redirected'], $saved['body']);
        self::assertContains('Studio', self::units(1));

        self::assertSame(404, self::request('GET', '/en/admin/units/edit/4?' . $token, self::reactive())['status'], 'another estate\'s unit');
        self::assertSame(404, self::post('/en/admin/units/delete/4?' . $token, [], self::reactive())['status']);
        self::assertNotNull(self::row('units', 4));
        $edit = self::post('/en/admin/units/edit/1?' . $token, ['name' => 'Flat one', 'bedrooms' => '2', 'estate_id' => '2', '_close' => '1'], self::reactive());
        self::assertTrue($edit['redirected']);
        self::assertSame('1', (string) self::row('units', 1)['estate_id']);

        foreach (['estates:3:units' => 403, 'estates:9:units' => 404, 'estates:1:nothing' => 404, 'estates:1:enquiries' => 404, 'units:1:units' => 404, 'junk' => 404] as $bad => $status) {
            self::assertSame($status, self::request('GET', '/en/admin/units?static=1&parent=' . rawurlencode($bad), self::reactive())['status'], $bad);
        }
        // Read-only: the enquiries page under the parent has no New or Edit
        $enquiries = self::request('GET', '/en/admin/enquiries?static=1&parent=' . rawurlencode('estates:1:enquiries'), self::reactive());
        self::assertStringContainsString('Anna', $enquiries['body']);
        self::assertStringNotContainsString('Omar', $enquiries['body']);
        self::assertStringNotContainsString('enquiries/create', $enquiries['body']);
        self::assertSame(404, self::request('GET', '/en/admin/enquiries/create?parent=' . rawurlencode('estates:1:enquiries'), self::reactive())['status']);
    }

    public function testDeleteConfirmationCountsRelatedRecords(): void
    {
        $page = self::request('GET', '/en/admin/estates/edit/1', self::reactive())['body'];
        self::assertStringContainsString('This estate has 3 units and 1 enquiry.', $page);
        $two = self::request('GET', '/en/admin/estates/edit/2', self::reactive())['body'];
        self::assertStringContainsString('This estate has 1 unit and 1 enquiry.', $two);
    }

    public function testTheParentsHistoryListsItsRelatedRecords(): void
    {
        $pdo = self::pdo();
        $file = self::$root . '/history-migration.php';
        file_put_contents($file, \FloCMS\Admin\Console\Stub::render('trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']));
        (require $file)->up(new \FloCMS\Core\Database($pdo));
        try {
            $page = self::request('GET', '/en/admin/estates/edit/1', self::reactive());
            $saved = self::update(self::update($page, 'create'), 'save', self::draft(['name' => 'Garden flat', 'bedrooms' => '2']));
            self::assertSame(200, $saved['status'], $saved['body']);
            $meta = $pdo->query("SELECT meta FROM admin_activity WHERE resource = 'units' AND action = 'created'")->fetchColumn();
            self::assertSame(['estates:1'], json_decode((string) $meta, true)['parents']);
            // Deleted from the unit's own page: still noted (trash on)
            $delete = self::post('/en/admin/units/delete/2', [], self::reactive());
            self::assertTrue($delete['redirected'], $delete['body']);

            $history = self::request('GET', '/en/admin/estates/history/1', self::reactive())['body'];
            self::assertStringContainsString('Units · Garden flat — created this record', $history);
            self::assertStringContainsString('Units · Flat 2 — deleted this record', $history);
            self::assertStringNotContainsString('Parents', $history, 'the note is not shown as a detail');
            self::assertStringNotContainsString('Garden flat', self::request('GET', '/en/admin/estates/history/2', self::reactive())['body']);
            $permissions = self::PERMISSIONS;
            $permissions[1] = ['content.estates.*'];
            self::assertStringNotContainsString('Garden flat', self::request('GET', '/en/admin/estates/history/1', self::reactive(['permissions' => $permissions]))['body'], 'only with the child\'s view permission');
            // Restored from the trash under the same parent
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM admin_trash WHERE resource = 'units' AND record_id = '2'")->fetchColumn());
            // A unit moved to another estate on its own page: both estates' History tell it
            $moved = self::post('/en/admin/units/edit/3', ['name' => 'Penthouse', 'bedrooms' => '4', 'estate_id' => '2', '_close' => '1'], self::reactive());
            self::assertTrue($moved['redirected'], $moved['body']);
            self::assertStringContainsString('Units · Penthouse', self::request('GET', '/en/admin/estates/history/1', self::reactive())['body']);
            self::assertStringContainsString('Units · Penthouse', self::request('GET', '/en/admin/estates/history/2', self::reactive())['body']);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS admin_trash');
            $pdo->exec('DROP TABLE IF EXISTS admin_activity');
        }
    }
}
