<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Relations\{CategoryResource, CityResource, CountryResource, FeatureResource, HomeResource};

final class RelationsRequestTest extends RequestTestCase
{
    private const RESOURCES = [HomeResource::class, CityResource::class, CountryResource::class, FeatureResource::class, CategoryResource::class];

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ['resources' => self::RESOURCES] + ($extra['admin_config'] ?? [])] + $extra;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (['home_feature', 'homes', 'cities', 'countries', 'features', 'agents', 'categories'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        self::createTable($pdo, 'CREATE TABLE countries (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "")');
        self::createTable($pdo, 'CREATE TABLE cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NULL, country_id INTEGER NULL, archived INTEGER NOT NULL DEFAULT 0)');
        self::createTable($pdo, 'CREATE TABLE features (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NULL)');
        self::createTable($pdo, 'CREATE TABLE agents (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name VARCHAR(100) NOT NULL DEFAULT "")');
        self::createTable($pdo, 'CREATE TABLE homes (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(100) NOT NULL DEFAULT "", country_id INTEGER NULL, city_id INTEGER NULL, agent_id INTEGER NULL)');
        self::createTable($pdo, 'CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "", parent_id INTEGER NULL)');
        $pivot = 'CREATE TABLE home_feature (home_id INTEGER NOT NULL, feature_id INTEGER NOT NULL, sort INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(30) NULL, PRIMARY KEY (home_id, feature_id))';
        $pdo->exec($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? $pivot . ' ENGINE=InnoDB' : $pivot);
        $pdo->exec("INSERT INTO countries (name) VALUES ('Iraq'), ('Greece')");
        $pdo->exec("INSERT INTO cities (name, country_id, archived) VALUES ('{\"en\":\"Erbil\",\"ar\":\"أربيل\"}', 1, 0), ('{\"en\":\"Sulaimani\"}', 1, 0), ('{\"en\":\"Athens\"}', 2, 0), ('{\"en\":\"Old town\"}', 1, 1)");
        $pdo->exec("INSERT INTO features (name) VALUES ('{\"en\":\"Pool\"}'), ('{\"en\":\"Garden\"}'), ('{\"en\":\"Sea view\"}'), ('{\"en\":\"Parking\"}')");
        $pdo->exec("INSERT INTO agents (full_name) VALUES ('Dara Agent')");
        $pdo->exec("INSERT INTO homes (title, country_id, city_id, agent_id) VALUES ('Villa', 1, 1, 1), ('Flat', 2, 3, NULL)");
        $pdo->exec("INSERT INTO home_feature (home_id, feature_id, sort, created_at) VALUES (1, 2, 1, '2026-01-01'), (1, 1, 2, '2026-01-01'), (1, 3, 3, '2026-01-01'), (2, 4, 1, NULL)");
        $pdo->exec("INSERT INTO categories (name, parent_id) VALUES ('Root', NULL), ('Child', 1)");
    }

    private static function pivot(int $home): array
    {
        return self::pdo()->query('SELECT feature_id, sort FROM home_feature WHERE home_id = ' . $home . ' ORDER BY sort')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private static function lookup(string $query, array $options = []): array
    {
        $response = self::request('GET', '/en/admin/homes/lookup/' . $query, self::options($options));
        self::assertSame(200, $response['status'], $response['body']);
        return array_column(json_decode($response['body'], true)['results'], 'label', 'id');
    }

    public function testEditPageRendersRelationControls(): void
    {
        $body = self::request('GET', '/en/admin/homes/edit/1', self::options())['body'];
        self::assertStringContainsString('data-admin-relation data-mode="single" data-lookup="https://example.test/en/admin/homes/lookup/city_id?record=1" data-complete="true" data-depends="country_id" data-depends-label="Country"', $body);
        self::assertMatchesRegularExpression('~<option value="1" dir="auto" selected>Erbil</option>~', $body);
        self::assertStringNotContainsString('Athens', $body, 'only cities of the chosen country');
        self::assertStringNotContainsString('Old town', $body, 'the related resource scope applies');
        self::assertStringContainsString('name="features[]"', $body);
        self::assertStringContainsString('name="features" value="[&quot;2&quot;,&quot;1&quot;,&quot;3&quot;]" data-relation-json disabled', $body, 'chosen order');
        self::assertStringContainsString('>Dara Agent</option>', $body);
    }

    public function testLookupSearchesTitlesInEveryLanguageWithinScopeAndParent(): void
    {
        self::assertSame(['1' => 'Erbil'], self::lookup('city_id?q=erb&parent=1'));
        self::assertSame(['1' => 'Erbil'], self::lookup('city_id?q=' . rawurlencode('أرب') . '&parent=1'), 'other languages are searched too');
        self::assertSame(['1' => 'Erbil', '2' => 'Sulaimani'], self::lookup('city_id?parent=1'), 'archived cities are outside the scope');
        self::assertSame(['3' => 'Athens'], self::lookup('city_id?parent=2'));
        self::assertSame([], self::lookup('city_id?parent='), 'no country chosen');
        self::assertSame([], self::lookup('city_id?parent=99'), 'not a valid country');
        self::assertSame(['2' => 'Garden'], self::lookup('features?q=gard'));
        self::assertSame(['1' => 'Dara Agent'], self::lookup('agent_id?q=1'), 'a key matches too');
        self::assertSame(['4' => 'Parking'], self::lookup('features?q=park&for=filter'));
        self::assertSame(404, self::request('GET', '/en/admin/homes/lookup/title?q=a', self::options())['status']);
        self::assertSame(404, self::request('GET', '/en/admin/homes/lookup/agent_id?for=filter', self::options())['status']);

        $permissions = self::PERMISSIONS;
        $permissions[1] = ['content.homes.view'];
        self::assertSame(403, self::request('GET', '/en/admin/homes/lookup/city_id?parent=1', self::options(['permissions' => $permissions]))['status'], 'form lookups need create or update');
        self::assertSame(200, self::request('GET', '/en/admin/homes/lookup/features?for=filter', self::options(['permissions' => $permissions]))['status'], 'filter lookups need view');
        $permissions[1] = [];
        self::assertSame(403, self::request('GET', '/en/admin/homes/lookup/features?for=filter', self::options(['permissions' => $permissions]))['status']);
    }

    public function testSavingWritesKeysAndPivotRowsInOrder(): void
    {
        $saved = self::post('/en/admin/homes/create', ['title' => 'Cabin', 'country_id' => '1', 'city_id' => '2', 'agent_id' => '', 'features' => '["4","2"]', '_close' => '1'], self::options());
        self::assertTrue($saved['redirected'], $saved['body']);
        $id = (int) self::pdo()->query("SELECT id FROM homes WHERE title = 'Cabin'")->fetchColumn();
        self::assertSame(['country_id' => 1, 'city_id' => 2, 'agent_id' => null], array_map(static fn ($v) => $v === null ? null : (int) $v, array_intersect_key(self::row('homes', $id), ['country_id' => 0, 'city_id' => 0, 'agent_id' => 0])));
        self::assertSame([4 => 1, 2 => 2], array_map('intval', self::pivot($id)));

        // Edit: drop Sea view, reorder; the unchanged rows keep their other columns
        self::post('/en/admin/homes/edit/1', ['title' => 'Villa', 'country_id' => '1', 'city_id' => '1', 'agent_id' => '1', 'features' => '["1","2"]', '_close' => '1'], self::options());
        self::assertSame([1 => 1, 2 => 2], array_map('intval', self::pivot(1)));
        self::assertSame('2026-01-01', self::pdo()->query('SELECT created_at FROM home_feature WHERE home_id = 1 AND feature_id = 2')->fetchColumn());

        // Without JavaScript the multiple select sends features[]
        self::post('/en/admin/homes/edit/1', ['title' => 'Villa', 'country_id' => '1', 'city_id' => '1', 'features' => ['3'], '_close' => '1'], self::options());
        self::assertSame([3 => 1], array_map('intval', self::pivot(1)));
    }

    public function testInvalidRelationsAreRefused(): void
    {
        $cases = [
            'a city of another country' => ['city_id' => '3'],
            'an archived city' => ['city_id' => '4'],
            'an unknown agent' => ['agent_id' => '9'],
            'an unknown feature' => ['features' => '["1","99"]'],
            'too many features' => ['features' => '["1","2","3","4"]'],
        ];
        foreach ($cases as $why => $change) {
            $response = self::post('/en/admin/homes/edit/1', $change + ['title' => 'Villa', 'country_id' => '1', 'city_id' => '1', 'features' => '["1"]'], self::options());
            self::assertFalse($response['redirected'], $why);
            self::assertStringContainsString('aria-invalid="true"', $response['body'], $why);
        }
        self::assertSame('1', (string) self::row('homes', 1)['city_id']);
        self::assertSame([2 => 1, 1 => 2, 3 => 3], array_map('intval', self::pivot(1)), 'nothing written');

        $self = self::post('/en/admin/categories/edit/2', ['name' => 'Child', 'parent_id' => '2'], self::options());
        self::assertStringContainsString('A record cannot be its own Parent.', $self['body']);
        self::assertStringContainsString('lookup/parent_id?record=2', self::request('GET', '/en/admin/categories/edit/2', self::options())['body']);
        self::assertStringNotContainsString('<option value="2" dir="auto">Child</option>', self::request('GET', '/en/admin/categories/edit/2', self::options())['body']);
    }

    public function testDeletingARecordRemovesItsPivotRows(): void
    {
        $deleted = self::post('/en/admin/homes/delete/1', [], self::options());
        self::assertTrue($deleted['redirected'], $deleted['body']);
        self::assertNull(self::row('homes', 1));
        self::assertSame([], self::pivot(1));
        self::assertSame([4 => 1], array_map('intval', self::pivot(2)));
    }

    public function testListShowsRelatedTitlesAndFiltersByThem(): void
    {
        $list = self::request('GET', '/en/admin/homes', self::options())['body'];
        self::assertStringContainsString('<span dir="auto">Erbil</span>', $list);
        self::assertMatchesRegularExpression('~<span dir="auto">Garden</span>, <span dir="auto">Pool</span> <span class="fa-muted">\+1</span>~', $list);
        self::assertStringContainsString('data-lookup="https://example.test/en/admin/homes/lookup/features?for=filter"', $list);

        $byCity = self::request('GET', '/en/admin/homes?filter[city_id]=3', self::options())['body'];
        self::assertStringContainsString('>Flat<', $byCity);
        self::assertStringNotContainsString('>Villa<', $byCity);
        self::assertMatchesRegularExpression('~<span class="fa-chip-label">City</span> Athens~', $byCity);

        $byFeature = self::request('GET', '/en/admin/homes?filter[features]=' . rawurlencode('["1","4"]'), self::options())['body'];
        self::assertStringContainsString('>Flat<', $byFeature);
        self::assertStringContainsString('>Villa<', $byFeature);
        self::assertStringContainsString('Pool, Parking', $byFeature);
        $array = self::request('GET', '/en/admin/homes?filter[features][]=4', self::options())['body'];
        self::assertStringNotContainsString('>Villa<', $array);

        $invalid = self::request('GET', '/en/admin/homes?filter[city_id]=4', self::options())['body'];
        self::assertStringContainsString('fa-error', $invalid, 'an archived city is not a valid filter value');
    }

    public function testReactiveListAppliesRelationFilters(): void
    {
        $options = self::options(['admin_config' => ['reactive' => true]]);
        $mounted = self::request('GET', '/en/admin/homes', $options);
        self::assertStringContainsString('<span dir="auto">Athens</span>', $mounted['body']);
        preg_match('/data-rx-snapshot="([^"]+)"/', $mounted['body'], $match);
        $filtered = self::post('/en/flo-reactive/update', ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => 'applyFilters',
            'state' => ['filters' => '{"features":"[\"2\"]"}']], $options + ['session' => $mounted['session']]);
        self::assertSame(200, $filtered['status'], $filtered['body']);
        self::assertStringContainsString('>Villa<', $filtered['body']);
        self::assertStringNotContainsString('>Flat<', $filtered['body']);
    }

    public function testADeleteRefusedByAForeignKeyShowsAMessage(): void
    {
        if (!getenv('FLO_ADMIN_TEST_DB_NAME')) {
            self::markTestSkipped('Foreign keys are checked on MySQL/MariaDB.');
        }
        $pdo = self::pdo();
        $pdo->exec('ALTER TABLE homes ADD CONSTRAINT homes_city FOREIGN KEY (city_id) REFERENCES cities (id) ON DELETE RESTRICT');
        $response = self::post('/en/admin/cities/delete/1', [], self::options());
        self::assertTrue($response['redirected']);
        self::assertNotNull(self::row('cities', 1));
        self::assertStringContainsString('still used by other records', json_encode($response['session'], JSON_UNESCAPED_UNICODE));
    }
}
