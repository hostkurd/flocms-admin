<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Duplicate\LotPlanResource;
use FloCMS\Admin\Tests\Fixtures\Duplicate\LotResource;
use FloCMS\Admin\Tests\Fixtures\Media\MediaTables;

/** Duplicate (0.40): the New form filled from a record, through whole requests. */
final class DuplicateRequestTest extends RequestTestCase
{
    private const TABLES = ['lots', 'lot_cities', 'lot_features', 'lot_feature', 'lot_plans', 'admin_trash', 'admin_activity'];
    private const BROCHURE = 'lots/brochure/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt';
    private const PLAN = 'lots/plan/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.txt';

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ($extra['admin_config'] ?? []) + ['resources' => [LotResource::class, LotPlanResource::class], 'media' => ['root' => self::$root . '/public/uploads/editor', 'url' => '/uploads/editor']]]
            + array_diff_key($extra, ['admin_config' => 1]);
    }

    private static function env(string $value, array $extra = []): array
    {
        return self::options($extra + ['env' => ['LOT_DUPLICATE' => $value]]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (self::TABLES as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        self::createTable($pdo, 'CREATE TABLE lots (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NULL, slug TEXT NULL, code VARCHAR(50) NULL, price VARCHAR(30) NULL,
            status VARCHAR(20) NOT NULL DEFAULT "draft", featured INTEGER NOT NULL DEFAULT 0, listed_on DATE NULL, city_id INTEGER NULL, cover_id INTEGER NULL,
            rooms TEXT NULL, body TEXT NULL, brochure TEXT NULL, plan TEXT NULL, secret VARCHAR(190) NULL, note VARCHAR(190) NULL)');
        self::createTable($pdo, 'CREATE TABLE lot_cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "")');
        self::createTable($pdo, 'CREATE TABLE lot_features (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "")');
        self::createTable($pdo, 'CREATE TABLE lot_feature (lot_id INTEGER NOT NULL, feature_id INTEGER NOT NULL, PRIMARY KEY (lot_id, feature_id))');
        self::createTable($pdo, 'CREATE TABLE lot_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, lot_id INTEGER NOT NULL, name VARCHAR(100) NOT NULL DEFAULT "")');
        $pdo->exec("INSERT INTO lot_cities (name) VALUES ('Athens'), ('Thessaloniki')");
        $pdo->exec("INSERT INTO lot_features (name) VALUES ('Pool'), ('Garden'), ('Sea view')");
        $insert = $pdo->prepare('INSERT INTO lots (title, slug, code, price, status, featured, listed_on, city_id, cover_id, rooms, body, brochure, plan, secret, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute(['{"en":"Villa A1","ar":"فيلا أ1"}', 'villa-a1', 'A1', '250000', 'published', 1, '2026-10-01', 2, 1,
            '[{"name":"Kitchen"},{"name":"Terrace"}]', '{"en":"<p>Sea view</p>"}', self::BROCHURE, self::PLAN, 'hunter2', 'stored']);
        $insert->execute(['{"en":"Secret lot"}', 'secret-lot', 'S1', '1', 'draft', 0, null, null, null, null, null, null, null, null, null]);
        $pdo->exec('INSERT INTO lot_feature (lot_id, feature_id) VALUES (1, 1), (1, 3)');
        $pdo->exec("INSERT INTO lot_plans (lot_id, name) VALUES (1, 'Ground floor')");
        $db = new \FloCMS\Core\Database($pdo);
        MediaTables::create($db);
        MediaTables::seed($db, self::$root . '/public/uploads/editor');
        $file = self::$root . '/trash-activity-migration.php';
        file_put_contents($file, \FloCMS\Admin\Console\Stub::render('trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']));
        (require $file)->up($db);
        // The stored files: a brochure, and a plan larger than the field's 1 KiB
        $storage = self::$root . '/storage/admin-uploads';
        exec('rm -rf ' . escapeshellarg($storage));
        foreach ([self::BROCHURE => 'Brochure of Villa A1', self::PLAN => str_repeat('p', 2048)] as $path => $content) {
            mkdir(dirname($storage . '/' . $path), 0700, true);
            file_put_contents($storage . '/' . $path, $content);
        }
    }

    protected function tearDown(): void
    {
        foreach (array_merge(self::TABLES, ['media_folders', 'media_usage', 'media']) as $table) { self::pdo()->exec('DROP TABLE IF EXISTS ' . $table); }
        parent::tearDown();
    }

    private static function storage(string $path = ''): string
    {
        return self::$root . '/storage/admin-uploads' . ($path !== '' ? '/' . $path : '');
    }

    /** The values a page's form shows: name => value (inputs, selected options, ticked boxes, text areas). */
    private static function value(string $body, string $name): ?string
    {
        if (preg_match('~<input(?=[^>]*\sname="' . preg_quote($name, '~') . '")[^>]*\svalue="([^"]*)"~', $body, $match) === 1) { return html_entity_decode($match[1], ENT_QUOTES); }
        if (preg_match('~<textarea(?=[^>]*\sname="' . preg_quote($name, '~') . '")[^>]*>(.*?)</textarea>~s', $body, $match) === 1) { return html_entity_decode($match[1], ENT_QUOTES); }
        return null;
    }

    /** The form as the page posts it, from the values it showed. */
    private static function posted(string $body, array $changes = []): array
    {
        return array_replace([
            'title' => ['en' => self::value($body, 'title[en]'), 'ar' => self::value($body, 'title[ar]'), 'ku' => self::value($body, 'title[ku]')],
            'slug' => self::value($body, 'slug'), 'code' => self::value($body, 'code'), 'price' => self::value($body, 'price'),
            'status' => preg_match('~<option value="(\w+)" selected~', $body, $m) === 1 ? $m[1] : '', 'featured' => '1',
            'listed_on' => self::value($body, 'listed_on'), 'city_id' => '2', 'features' => ['1', '3'], 'features__present' => '1',
            'cover_id' => self::value($body, 'cover_id'), 'rooms' => ['_' => '', 'k0' => ['_key' => 'k0', 'name' => 'Kitchen'], 'k1' => ['_key' => 'k1', 'name' => 'Terrace']],
            'body' => ['en' => '<p>Sea view</p>', 'ar' => '', 'ku' => ''],
            'brochure' => self::value($body, 'brochure'), '_upload_control' => ['brochure' => '1', 'plan' => '1'], 'plan' => self::value($body, 'plan'), 'secret' => '',
        ], $changes);
    }

    public function testTheNewFormIsFilledFromTheRecord(): void
    {
        $page = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        self::assertSame(200, $page['status'], (string) $page['error']);
        $body = $page['body'];
        self::assertStringContainsString('<h1 class="fa-page-title">Duplicate “Villa A1”</h1>', $body);
        self::assertStringContainsString('<p class="fa-alert fa-alert-info fa-duplicate-note" role="status">This is a copy of “Villa A1”. Nothing is saved until you save it. Records linked to the original are not copied.</p>', $body);
        self::assertStringContainsString('action="https://example.test/en/admin/lots/create?duplicate=1"', $body, 'the POST knows the source');
        // Translations, text, number, select, toggle, date, BelongsTo, BelongsToMany, MediaPicker, repeater, rich text
        self::assertSame(['Villa A1', 'فيلا أ1', ''], [self::value($body, 'title[en]'), self::value($body, 'title[ar]'), self::value($body, 'title[ku]')]);
        self::assertSame('250000', self::value($body, 'price'));
        self::assertStringContainsString('<option value="published" selected>', $body);
        self::assertMatchesRegularExpression('~name="featured" type="checkbox" value="1" class="fa-switch" checked~', $body);
        self::assertSame('2026-10-01', self::value($body, 'listed_on'));
        self::assertStringContainsString('<option value="2" dir="auto" selected>Thessaloniki', $body);
        self::assertSame('["1","3"]', self::value($body, 'features'));
        self::assertSame('1', self::value($body, 'cover_id'));
        self::assertSame(['Kitchen', 'Terrace'], [self::value($body, 'rooms[k0][name]'), self::value($body, 'rooms[k1][name]')]);
        self::assertStringContainsString('&lt;p&gt;Sea view&lt;/p&gt;', $body);
        // Automatic: the slug gets -copy, a unique field and the password start empty, a disabled field at its default
        self::assertSame('villa-a1-copy', self::value($body, 'slug'));
        self::assertSame('', self::value($body, 'code'));
        self::assertSame('', self::value($body, 'secret'));
        self::assertStringNotContainsString('hunter2', $body);
        self::assertSame('fresh', self::value($body, 'note'));
        // Create and duplicate again instead of Save and create another
        self::assertStringContainsString('name="_duplicate_again" value="1" class="fa-btn">Create and duplicate again</button>', $body);
        self::assertStringNotContainsString('name="_another"', $body);
        // The plan is larger than its field allows: left empty, with a note
        self::assertSame('', self::value($body, 'plan'));
        self::assertStringContainsString('These files could not be copied: Plan. Choose them again if you need them.', $body);
        self::assertMatchesRegularExpression('~^@upload:[a-f0-9]{64}$~', (string) self::value($body, 'brochure'));
        // Nothing saved yet
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM lots')->fetchColumn());
    }

    public function testSlugCopiesTakeTheNextFreeNumber(): void
    {
        self::pdo()->exec("INSERT INTO lots (title, slug) VALUES ('{\"en\":\"Villa A1\"}', 'villa-a1-copy')");
        self::assertSame('villa-a1-copy-2', self::value(self::request('GET', '/en/admin/lots/create?duplicate=1', self::options())['body'], 'slug'));
        self::pdo()->exec("INSERT INTO lots (title, slug) VALUES ('{\"en\":\"Villa A1\"}', 'villa-a1-copy-2')");
        self::assertSame('villa-a1-copy-3', self::value(self::request('GET', '/en/admin/lots/create?duplicate=1', self::options())['body'], 'slug'));
        // A copy of a copy: villa-a1-copy-3, not villa-a1-copy-copy
        self::assertSame('villa-a1-copy-3', self::value(self::request('GET', '/en/admin/lots/create?duplicate=3', self::options())['body'], 'slug'));
    }

    public function testSavingIsAnOrdinaryCreate(): void
    {
        $page = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        $saved = self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A2']), self::options(['session' => $page['session']]));
        self::assertTrue($saved['redirected'], substr($saved['body'], 0, 3000));
        $copy = self::row('lots', 3);
        // A disabled field is not written on a new record (as on Create)
        self::assertSame(['{"en":"Villa A1","ar":"فيلا أ1"}', 'villa-a1-copy', 'A2', '250000', 'published', 1, '2026-10-01', 2, 1, null],
            [$copy['title'], $copy['slug'], $copy['code'], $copy['price'], $copy['status'], (int) $copy['featured'], $copy['listed_on'], (int) $copy['city_id'], (int) $copy['cover_id'], $copy['note']]);
        self::assertSame(['Kitchen', 'Terrace'], array_column(json_decode((string) $copy['rooms'], true), 'name'));
        self::assertSame('', (string) $copy['secret'], 'the password is never copied');
        self::assertSame(['1', '3'], array_map('strval', self::pdo()->query('SELECT feature_id FROM lot_feature WHERE lot_id = 3 ORDER BY feature_id')->fetchAll(\PDO::FETCH_COLUMN)));
        // Library items are linked by id, never copied; the relation manager's children stay with the original
        self::assertSame(1, (int) self::pdo()->query("SELECT COUNT(*) FROM media_usage WHERE resource = 'lots' AND record_id = '3' AND field = 'cover_id' AND media_id = 1")->fetchColumn());
        self::assertSame(4, (int) self::pdo()->query('SELECT COUNT(*) FROM media')->fetchColumn());
        self::assertSame(['1' => 1], array_map('intval', self::pdo()->query('SELECT lot_id, COUNT(*) FROM lot_plans GROUP BY lot_id')->fetchAll(\PDO::FETCH_KEY_PAIR)));
        // The original is untouched
        $original = self::row('lots', 1);
        self::assertSame(['villa-a1', 'A1', 'hunter2', self::BROCHURE], [$original['slug'], $original['code'], $original['secret'], $original['brochure']]);
        // The activity entry says where the copy came from
        $entry = self::pdo()->query("SELECT action, record_id, meta FROM admin_activity WHERE resource = 'lots' ORDER BY id DESC")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['created', '3'], [$entry['action'], $entry['record_id']]);
        self::assertSame(['id' => '1', 'title' => '{"en":"Villa A1","ar":"فيلا أ1"}'], json_decode((string) $entry['meta'], true)['duplicated_from']);
    }

    public function testTheCopyGetsItsOwnFile(): void
    {
        $page = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        $token = (string) self::value($page['body'], 'brochure');
        // A pending upload of a new record of this resource, owned by this session
        $entry = $page['session']['_flo_admin_uploads'][substr($token, 8)];
        self::assertSame(['["lots","","brochure"]', '1', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt'], [$entry['scope'], $entry['actor'], $entry['name']]);
        self::assertSame('Brochure of Villa A1', file_get_contents(self::storage($entry['path'])));
        // The pending copy can be previewed by someone who may create
        self::assertSame('Brochure of Villa A1', self::request('GET', '/en/admin/lots/file/pending/brochure?token=' . rawurlencode($token), self::options(['session' => $page['session']]))['body']);

        $saved = self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A2']), self::options(['session' => $page['session']]));
        self::assertTrue($saved['redirected'], substr($saved['body'], 0, 3000));
        $path = (string) self::row('lots', 3)['brochure'];
        self::assertMatchesRegularExpression('~^lots/brochure/[a-f0-9]{40}\.txt$~', $path);
        self::assertNotSame(self::BROCHURE, $path, 'a new stored file');
        self::assertSame('Brochure of Villa A1', file_get_contents(self::storage($path)));
        self::assertNull(self::row('lots', 3)['plan']);
        // Deleting the copy removes its own file only
        self::assertTrue(self::post('/en/admin/lots/delete/3', [], self::options(['session' => $saved['session']]))['redirected']);
        self::assertNull(self::row('lots', 3));
        self::assertFileDoesNotExist(self::storage($path));
        self::assertFileExists(self::storage(self::BROCHURE));
        // A forged stored path of the original is refused on a new record
        $forged = self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A3', 'brochure' => self::BROCHURE]), self::options(['session' => $saved['session']]));
        self::assertFalse($forged['redirected']);
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM lots')->fetchColumn());
    }

    public function testAMissingFileIsLeftEmpty(): void
    {
        unlink(self::storage(self::BROCHURE));
        $body = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options())['body'];
        self::assertSame('', self::value($body, 'brochure'));
        self::assertStringContainsString('These files could not be copied: Brochure, Plan.', $body);
    }

    public function testUniqueChecksStillRun(): void
    {
        $page = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        $refused = self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A1']), self::options(['session' => $page['session']]));
        self::assertFalse($refused['redirected']);
        self::assertStringContainsString('<h1 class="fa-page-title">Duplicate “Villa A1”</h1>', $refused['body'], 'the form shows again as the copy');
        self::assertStringContainsString('fa-duplicate-note', $refused['body']);
        $slug = self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A2', 'slug' => 'villa-a1']), self::options(['session' => $page['session']]));
        self::assertFalse($slug['redirected']);
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM lots')->fetchColumn());
    }

    public function testExceptOnlyAndFill(): void
    {
        $except = self::request('GET', '/en/admin/lots/create?duplicate=1', self::env('except'))['body'];
        self::assertSame('', self::value($except, 'price'));
        self::assertSame('[]', self::value($except, 'features'));
        self::assertSame('Villa A1', self::value($except, 'title[en]'));

        $only = self::request('GET', '/en/admin/lots/create?duplicate=1', self::env('only'))['body'];
        self::assertSame(['Villa A1', '', '', '', '[]'], [self::value($only, 'title[en]'), self::value($only, 'slug'), self::value($only, 'price'), self::value($only, 'listed_on'), self::value($only, 'features')]);
        self::assertStringContainsString('<option value="published" selected>', $only);
        self::assertSame('', self::value($only, 'brochure'), 'no file copied when not listed');
        self::assertStringNotContainsString('could not be copied', $only);

        $fill = self::request('GET', '/en/admin/lots/create?duplicate=1', self::env('fill'))['body'];
        self::assertSame(['Copy of Villa A1', '999', 'villa-a1-copy'], [self::value($fill, 'title[en]'), self::value($fill, 'price'), self::value($fill, 'slug')]);
    }

    public function testPermissionsAreCheckedOnOpenAndSave(): void
    {
        // Without create, or without view, or a record the person may not view
        self::assertSame(403, self::request('GET', '/en/admin/lots/create?duplicate=1', self::options(['permissions' => [1 => ['content.lots.view', 'content.lots.update']]]))['status']);
        self::assertSame(403, self::request('GET', '/en/admin/lots/create?duplicate=1', self::options(['permissions' => [1 => ['content.lots.create']]]))['status']);
        self::assertSame(403, self::request('GET', '/en/admin/lots/create?duplicate=1', self::options(['env' => ['LOT_NO_CREATE' => '1']]))['status']);
        self::assertSame(403, self::request('GET', '/en/admin/lots/create?duplicate=2', self::options())['status'], 'canView() false');
        // On save, a record out of reach shows the form again with the input and an error; nothing is saved
        $gone = self::post('/en/admin/lots/create?duplicate=2', ['title' => ['en' => 'Secret copy'], 'code' => 'S2'], self::options());
        self::assertSame(200, $gone['status']);
        self::assertStringContainsString('The record you were copying is no longer available. Your input is kept: saving now creates a new record.', $gone['body']);
        self::assertSame('Secret copy', self::value($gone['body'], 'title[en]'));
        self::assertStringContainsString('action="https://example.test/en/admin/lots/create"', $gone['body'], 'posts as a plain new record now');
        self::assertStringNotContainsString('fa-duplicate-note', $gone['body']);
        $noView = self::post('/en/admin/lots/create?duplicate=1', ['title' => ['en' => 'Copy'], 'code' => 'A2'], self::options(['permissions' => [1 => ['content.lots.create']]]));
        self::assertStringContainsString('is no longer available', $noView['body']);
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM lots')->fetchColumn());
        self::assertSame(403, self::post('/en/admin/lots/create?duplicate=2', ['title' => ['en' => 'x']], self::options(['env' => ['LOT_CREATE' => 'hidden']]))['status'], 'no form when Create itself is refused');
        // Duplicate follows the resource's Create action: none, or one that refuses, means no Duplicate
        foreach (['none' => 404, 'hidden' => 403, 'disabled' => 403, 'permission' => 403] as $create => $status) {
            $options = self::options(['env' => ['LOT_CREATE' => $create]]);
            self::assertSame($status === 404 ? 404 : 403, self::request('GET', '/en/admin/lots/create', $options)['status'], $create . ': plain Create');
            self::assertSame(403, self::request('GET', '/en/admin/lots/create?duplicate=1', $options)['status'], $create . ': Duplicate');
            self::assertStringNotContainsString('create?duplicate=', self::request('GET', '/en/admin/lots', $options)['body'], $create . ': no menu item');
        }
        // A Create action with its own permission: Duplicate uses it
        $own = self::options(['env' => ['LOT_CREATE' => 'permission'], 'permissions' => [1 => ['content.*', 'lots.publish']]]);
        self::assertSame(200, self::request('GET', '/en/admin/lots/create?duplicate=1', $own)['status']);
        // Unknown or malformed records, and a resource without the action
        self::assertSame(404, self::request('GET', '/en/admin/lots/create?duplicate=99', self::options())['status']);
        self::assertSame(404, self::request('GET', '/en/admin/lots/create?duplicate=' . rawurlencode('../1'), self::options())['status']);
        self::assertSame(404, self::request('GET', '/en/admin/lots/create?duplicate=', self::options())['status']);
        self::assertSame(404, self::request('GET', '/en/admin/lots/create?duplicate[]=1', self::options())['status']);
        self::assertSame(404, self::request('GET', '/en/admin/lots/create?duplicate=1', self::env('none'))['status']);
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM lots')->fetchColumn());
        // The menus show it only to people who may use it
        $list = self::request('GET', '/en/admin/lots', self::options())['body'];
        self::assertStringContainsString('href="https://example.test/en/admin/lots/create?duplicate=1&amp;back=', $list);
        self::assertStringNotContainsString('create?duplicate=2', $list, 'not for a record the person may not view');
        self::assertStringNotContainsString('create?duplicate=', self::request('GET', '/en/admin/lots', self::options(['permissions' => [1 => ['content.lots.view', 'content.lots.update', 'content.lots.delete']]]))['body']);
    }

    public function testRowMenuViewPageAndQuickLinks(): void
    {
        $list = self::request('GET', '/en/admin/lots', self::options())['body'];
        // In the More menu with the copy icon, after a divider, before Delete
        self::assertMatchesRegularExpression('~<hr class="fa-menu-divider"><a class="fa-menu-item" id="fa-admin-resource-duplicate-1" data-admin-action="duplicate" href="https://example.test/en/admin/lots/create\?duplicate=1&amp;back=[^"]+"><svg[^>]*>.*?</svg><span>Duplicate</span></a><hr class="fa-menu-divider"><form method="post" class="fa-inline" data-confirm action="https://example.test/en/admin/lots/delete/1"~s', $list);
        self::assertStringContainsString('M9 9h11v11H9z', $list, 'the copy icon');
        $view = self::request('GET', '/en/admin/lots/view/1', self::options())['body'];
        self::assertStringContainsString('href="https://example.test/en/admin/lots/create?duplicate=1"', $view);
        // The standard action URL leads to the same page
        self::assertTrue(self::request('GET', '/en/admin/lots/action/row/duplicate/1', self::options())['redirected']);
    }

    public function testCreateAndDuplicateAgain(): void
    {
        $page = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        $saved = self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A2', '_duplicate_again' => '1']), self::options(['session' => $page['session']]));
        self::assertTrue($saved['redirected']);
        self::assertSame('A2', self::row('lots', 3)['code']);
        // The next copy of the same record: the slug moves on
        self::assertSame('villa-a1-copy-2', self::value(self::request('GET', '/en/admin/lots/create?duplicate=1', self::options(['session' => $saved['session']]))['body'], 'slug'));
    }

    public function testActivityShowsWhereACopyCameFrom(): void
    {
        $page = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        $saved = self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A2']), self::options(['session' => $page['session']]));
        self::assertTrue($saved['redirected']);
        $history = self::request('GET', '/en/admin/lots/history/3', self::options())['body'];
        self::assertStringContainsString('<p class="fa-timeline-from">Duplicated from <a href="https://example.test/en/admin/lots/edit/1">Villa A1</a></p>', $history);
        $activity = self::request('GET', '/en/admin/activity', self::options(['session' => self::SUPER]))['body'];
        self::assertStringContainsString('<span class="fa-cell-sub">Duplicated from <a href="https://example.test/en/admin/lots/edit/1">Villa A1</a></span>', $activity);
        $id = (int) self::pdo()->query("SELECT id FROM admin_activity WHERE record_id = '3'")->fetchColumn();
        self::assertStringContainsString('Duplicated from', self::request('GET', '/en/admin/activity/show/' . $id, self::options(['session' => self::SUPER]))['body']);
        // Arabic, and the original deleted: the title stays, without a link
        self::pdo()->exec('DELETE FROM lots WHERE id = 1');
        $arabic = self::request('GET', '/ar/admin/lots/history/3', self::options())['body'];
        self::assertStringContainsString('<p class="fa-timeline-from">نسخة من فيلا أ1</p>', $arabic);
    }

    private static function reactive(array $extra = []): array
    {
        return self::options(['admin_config' => ['reactive' => true]] + $extra);
    }

    private static function update(array $previous, string $action, array $state = [], array $extra = []): array
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $previous['body'], $match);
        self::assertNotEmpty($match[1] ?? '', substr($previous['body'], 0, 2000));
        return self::post('/en/flo-reactive/update', ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => $action, 'state' => $state],
            self::reactive(['session' => $previous['session']] + $extra));
    }

    /** The open dialog's draft, from the component's snapshot. */
    private static function draft(array $response): array
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $response['body'], $match);
        $raw = explode('.', html_entity_decode($match[1] ?? '', ENT_QUOTES))[0];
        $snapshot = json_decode((string) base64_decode(strtr($raw, '-_', '+/')), true);
        $state = $snapshot['state'] ?? [];
        return json_decode((string) ($state['draft'] ?? '{}'), true) ?? [];
    }

    public function testTheDialogOpensACopyAndSavesANewRecord(): void
    {
        $list = self::request('GET', '/en/admin/lots', self::reactive());
        self::assertStringContainsString('data-rx-value="row:duplicate:1" data-rx-action="openAction"', $list['body']);
        $opened = self::update($list, 'openAction', ['selection' => 'row:duplicate:1']);
        self::assertSame(200, $opened['status'], substr($opened['body'], 0, 3000));
        self::assertStringContainsString('data-rx-open="true"', $opened['body']);
        self::assertStringContainsString('class="fa-dialog-title">Duplicate “Villa A1”</h2>', $opened['body']);
        self::assertStringContainsString('This is a copy of “Villa A1”. Nothing is saved until you save it.', $opened['body']);
        self::assertStringContainsString('These files could not be copied: Plan.', $opened['body']);
        self::assertStringContainsString('name="_duplicate_again" value="1">Create and duplicate again</button>', $opened['body']);
        $draft = self::draft($opened);
        self::assertSame(['villa-a1-copy', '', '250000', ''], [$draft['slug'], $draft['code'], $draft['price'], $draft['secret']]);
        self::assertSame(['en' => 'Villa A1', 'ar' => 'فيلا أ1', 'ku' => ''], $draft['title']);
        self::assertMatchesRegularExpression('~^@upload:[a-f0-9]{64}$~', $draft['brochure']);

        $saved = self::update($opened, 'save', ['draft' => json_encode(['code' => 'A2'] + $draft, JSON_UNESCAPED_UNICODE)]);
        self::assertSame(200, $saved['status'], substr($saved['body'], 0, 3000));
        self::assertStringContainsString('data-rx-open="false"', $saved['body']);
        $copy = self::row('lots', 3);
        self::assertSame(['villa-a1-copy', 'A2'], [$copy['slug'], $copy['code']]);
        self::assertNotSame(self::BROCHURE, $copy['brochure']);
        self::assertFileExists(self::storage((string) $copy['brochure']));
        self::assertSame(['1', '3'], array_map('strval', self::pdo()->query('SELECT feature_id FROM lot_feature WHERE lot_id = 3 ORDER BY feature_id')->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame('villa-a1', self::row('lots', 1)['slug'], 'the original is never written');
        self::assertSame('1', json_decode((string) self::pdo()->query("SELECT meta FROM admin_activity WHERE record_id = '3'")->fetchColumn(), true)['duplicated_from']['id']);
    }

    public function testTheDialogCanDuplicateAgain(): void
    {
        $opened = self::update(self::request('GET', '/en/admin/lots', self::reactive()), 'openAction', ['selection' => 'row:duplicate:1']);
        $draft = self::draft($opened);
        $again = self::update($opened, 'save', ['draft' => json_encode(['code' => 'A2', '_duplicate_again' => '1'] + $draft, JSON_UNESCAPED_UNICODE)]);
        self::assertSame(200, $again['status'], substr($again['body'], 0, 3000));
        self::assertSame('A2', self::row('lots', 3)['code']);
        self::assertStringContainsString('data-rx-open="true"', $again['body'], 'a fresh copy opens');
        self::assertSame('villa-a1-copy-2', self::draft($again)['slug']);
        $next = self::update($again, 'save', ['draft' => json_encode(['code' => 'A3'] + self::draft($again), JSON_UNESCAPED_UNICODE)]);
        self::assertStringContainsString('data-rx-open="false"', $next['body']);
        self::assertSame(['villa-a1-copy-2', 'A3'], [self::row('lots', 4)['slug'], self::row('lots', 4)['code']]);
    }

    public function testTheDialogChecksPermissions(): void
    {
        $list = self::request('GET', '/en/admin/lots', self::reactive());
        self::assertSame(403, self::update($list, 'openAction', ['selection' => 'row:duplicate:2'])['status'], 'canView() false');
        $denied = self::request('GET', '/en/admin/lots', self::reactive(['permissions' => [1 => ['content.lots.view', 'content.lots.update']]]));
        self::assertStringNotContainsString('row:duplicate:', $denied['body']);
        self::assertSame(403, self::update($denied, 'openAction', ['selection' => 'row:duplicate:1'], ['permissions' => [1 => ['content.lots.view', 'content.lots.update']]])['status']);
        // Rechecked on save: the record became one the person may not view
        $opened = self::update($list, 'openAction', ['selection' => 'row:duplicate:1']);
        self::pdo()->exec("UPDATE lots SET title = '{\"en\":\"Secret villa\"}' WHERE id = 1");
        self::assertSame(403, self::update($opened, 'save', ['draft' => json_encode(['code' => 'A2'] + self::draft($opened), JSON_UNESCAPED_UNICODE)])['status']);
        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM lots')->fetchColumn());
    }

    /** The pending upload files on disk. */
    private static function pending(): array
    {
        return glob(self::storage('pending/*')) ?: [];
    }

    public function testReopeningACopyReusesItsPendingFile(): void
    {
        $first = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        $again = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options(['session' => $first['session']]));
        self::assertSame(self::value($first['body'], 'brochure'), self::value($again['body'], 'brochure'), 'a reload reuses the copy');
        self::assertCount(1, self::pending());
        self::assertCount(1, $again['session']['_flo_admin_uploads']);
        // Once saved, the next copy gets a new pending file
        $saved = self::post('/en/admin/lots/create?duplicate=1', self::posted($again['body'], ['code' => 'A2']), self::options(['session' => $again['session']]));
        self::assertTrue($saved['redirected']);
        self::assertCount(0, self::pending());
        $next = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options(['session' => $saved['session']]));
        self::assertNotSame(self::value($first['body'], 'brochure'), self::value($next['body'], 'brochure'));
    }

    public function testAFullPendingAreaIsNotCalledAMissingFile(): void
    {
        $session = self::EDITOR;
        for ($i = 0; $i < 32; ++$i) { $session['_flo_admin_uploads'][str_repeat(dechex($i % 16), 64 - 2) . sprintf('%02x', $i)] = ['path' => 'pending/x' . $i . '.txt', 'name' => 'x.txt', 'size' => 1, 'issued' => time(), 'scope' => '["lots","","brochure"]', 'actor' => '1', 'csrf' => hash('sha256', 'tok')]; }
        $body = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options(['session' => $session]))['body'];
        self::assertSame('', self::value($body, 'brochure'));
        self::assertStringContainsString('These files were not copied because too many files are waiting to be saved: Brochure.', $body);
        self::assertStringContainsString('These files could not be copied: Plan.', $body, 'the plan is still too large');
        self::assertStringContainsString('<input type="hidden" name="_duplicate_skipped[brochure]" value="limit">', $body);
    }

    public function testTheFilesNoteSurvivesAFailedSave(): void
    {
        $page = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        self::assertStringContainsString('<input type="hidden" name="_duplicate_skipped[plan]" value="file">', $page['body']);
        $refused = self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A1', '_duplicate_skipped' => ['plan' => 'file', 'title' => 'file', 'brochure' => 'limit']]), self::options(['session' => $page['session']]));
        self::assertFalse($refused['redirected']);
        self::assertStringContainsString('These files could not be copied: Plan.', $refused['body']);
        self::assertStringNotContainsString('Title', (string) preg_replace('~^.*?(<p class="fa-alert fa-alert-warning fa-duplicate-note"[^<]*</p>).*$~s', '$1', $refused['body']), 'only upload fields');
        self::assertStringNotContainsString('too many files', $refused['body'], 'the brochure was copied, so it is not listed');
    }

    public function testTranslatableSlugsAndThePrimaryKey(): void
    {
        self::pdo()->exec("UPDATE lots SET slug = '{\"en\":\"villa-a1\",\"ar\":\"villa-ar\"}' WHERE id = 1");
        self::pdo()->exec("UPDATE lots SET slug = '{\"en\":\"secret-lot\",\"ar\":\"villa-ar-copy\"}' WHERE id = 2");
        $body = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options(['env' => ['LOT_SLUG' => 'translatable', 'LOT_ID' => '1']]))['body'];
        self::assertSame(['villa-a1-copy', 'villa-ar-copy-2'], [self::value($body, 'slug[en]'), self::value($body, 'slug[ar]')]);
        self::assertSame('', self::value($body, 'id'), 'the primary key is never copied');
    }

    public function testActivityLinksOnlyToOriginalsThePersonMayView(): void
    {
        $page = self::request('GET', '/en/admin/lots/create?duplicate=1', self::options());
        self::assertTrue(self::post('/en/admin/lots/create?duplicate=1', self::posted($page['body'], ['code' => 'A2']), self::options(['session' => $page['session']]))['redirected']);
        self::pdo()->exec("UPDATE lots SET title = '{\"en\":\"Secret villa\"}' WHERE id = 1");
        $history = self::request('GET', '/en/admin/lots/history/3', self::options())['body'];
        self::assertStringContainsString('<p class="fa-timeline-from">Duplicated from Villa A1</p>', $history, 'canView() false: plain text');
    }

    public function testClosingTheDialogReleasesItsCopies(): void
    {
        $opened = self::update(self::request('GET', '/en/admin/lots', self::reactive()), 'openAction', ['selection' => 'row:duplicate:1']);
        self::assertCount(1, self::pending());
        $closed = self::update($opened, 'cancel');
        self::assertSame(200, $closed['status']);
        self::assertCount(0, self::pending());
        self::assertSame([], $closed['session']['_flo_admin_uploads']);
        // Opening another dialog releases them too
        $reopened = self::update($closed, 'openAction', ['selection' => 'row:duplicate:1']);
        $other = self::update($reopened, 'openAction', ['selection' => 'row:edit:1']);
        self::assertSame(200, $other['status']);
        self::assertCount(0, self::pending());
        // A Create action with its own permission: the dialog uses it instead of content.lots.create
        $own = ['env' => ['LOT_CREATE' => 'permission'], 'permissions' => [1 => ['content.lots.view', 'lots.publish']]];
        $list = self::request('GET', '/en/admin/lots', self::reactive($own));
        self::assertSame(200, self::update($list, 'openAction', ['selection' => 'row:duplicate:1'], $own)['status']);
    }
}
