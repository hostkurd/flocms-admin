<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Choices\{HouseDialogResource, HouseResource};

/**
 * CheckboxList, TagsInput and TimePicker (0.37) through whole requests: the
 * form as a browser without JavaScript sends it, the stored values, an old
 * option kept until unticked, the list's columns and filters, the view page,
 * the activity log and CSV export.
 */
final class ChoiceFieldsRequestTest extends RequestTestCase
{
    private static function options(array $extra = []): array
    {
        return ['admin_config' => ['resources' => [HouseResource::class, HouseDialogResource::class]]] + $extra;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS houses');
        self::createTable($pdo, 'CREATE TABLE houses (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(190) NULL, features TEXT NULL, keywords TEXT NULL, opens_at TIME NULL, closes_at TIME NULL, slots TEXT NULL)');
        $insert = $pdo->prepare('INSERT INTO houses (title, features, keywords, opens_at, closes_at) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['Seaside villa', '["pool","sauna"]', 'villa, sea view', '09:30:00', '18:00:00']);
        $insert->execute(['Harbour flat', '["lift"]', '["Sea View","apartment"]', '11:00:00', null]);
        $insert->execute(['Stone house', '["garden"]', '["quiet"]', '14:00:00', null]);
    }

    /** The form as a browser sends it without JavaScript. */
    private static function form(array $extra = []): array
    {
        return $extra + [
            'title' => 'Seaside villa',
            'features' => ['_' => '', 'o0' => 'pool', 'o2' => 'sea_view'],
            'keywords' => 'villa, Sea view, sea VIEW، garden',
            'opens_at' => '9:30',
            'closes_at' => '18:00',
            'slots' => ['_' => ''],
        ];
    }

    public function testTheEditPageShowsTheFieldsAndSavesWhatWasSent(): void
    {
        $page = self::request('GET', '/en/admin/houses/edit/1', self::options());
        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertStringContainsString('data-admin-checks', $body);
        self::assertStringContainsString('name="features[o0]" value="pool" checked', $body);
        self::assertStringContainsString('“sauna” is no longer an option', $body);
        self::assertStringContainsString('name="keywords" placeholder="Separate tags with “,”" type="text" value="villa, sea view"', $body, 'legacy text read as a list');
        self::assertStringContainsString('type="time" value="09:30" class="fa-input fa-time" min="08:00" max="20:00" step="900"', $body);

        $saved = self::post('/en/admin/houses/edit/1', self::form(['_continue' => '1']), self::options(['session' => $page['session']]));
        self::assertTrue($saved['redirected'], (string) $saved['error']);
        $row = self::row('houses', 1);
        self::assertSame('["pool","sea_view"]', $row['features'], 'sauna unticked: gone');
        self::assertSame('["villa","Sea view","garden"]', $row['keywords']);
        self::assertSame('09:30:00', $row['opens_at']);

        // Everything emptied
        self::post('/en/admin/houses/edit/1', self::form(['features' => ['_' => ''], 'keywords' => '', 'opens_at' => '']), self::options());
        $row = self::row('houses', 1);
        self::assertNull($row['features']);
        self::assertNull($row['keywords']);
        self::assertNull($row['opens_at']);
    }

    public function testAnOldOptionStaysSavedUntilUnticked(): void
    {
        $page = self::request('GET', '/en/admin/houses/edit/1', self::options());
        self::assertSame(1, preg_match('#name="features\[k0\]" value="(~[^"]+)" checked#', $page['body'], $m));
        $signed = html_entity_decode($m[1], ENT_QUOTES);

        $saved = self::post('/en/admin/houses/edit/1', self::form(['features' => ['_' => '', 'o0' => 'pool', 'k0' => $signed]]), self::options(['session' => $page['session']]));
        self::assertTrue($saved['redirected'], (string) $saved['error']);
        self::assertSame('["pool","sauna"]', self::row('houses', 1)['features']);

        // A key the form never showed is refused
        $forged = self::post('/en/admin/houses/edit/1', self::form(['features' => ['_' => '', 'o0' => 'pool', 'k0' => '~forged|c2F1bmE=', 'o9' => 'jacuzzi']]), self::options(['session' => $page['session']]));
        self::assertFalse($forged['redirected']);
        self::assertStringContainsString('Features must be one of the listed values.', $forged['body']);
        self::assertSame('["pool","sauna"]', self::row('houses', 1)['features']);
    }

    public function testErrorsAreShownOnTheFields(): void
    {
        $response = self::post('/en/admin/houses/edit/1', self::form([
            'features' => ['_' => '', 'o0' => 'pool', 'o1' => 'parking', 'o2' => 'sea_view', 'o3' => 'garden', 'o4' => 'lift', 'o5' => 'air'],
            'keywords' => 'a, b, c, d, e',
            'closes_at' => '21:00',
        ]), self::options());
        self::assertFalse($response['redirected']);
        $body = $response['body'];
        self::assertStringContainsString('Choose at most 5 for Features.', $body);
        self::assertStringContainsString('Keywords can have at most 4 tags.', $body);
        self::assertStringContainsString('Viewings until must be 20:00 or earlier.', $body);
        self::assertStringContainsString('name="features[o5]" value="air" checked', $body, 'the choices are kept');
        self::assertStringContainsString('value="a, b, c, d, e"', $body);
    }

    public function testRepeaterItemsHoldTheFields(): void
    {
        $saved = self::post('/en/admin/houses/edit/2', self::form(['slots' => ['_' => '', 'k1' => ['_key' => 'k1', 'days' => ['_' => '', 'o0' => 'mon', 'o2' => 'wed'], 'at' => '10:00', 'notes' => 'quiet, early']]]), self::options());
        self::assertTrue($saved['redirected'], (string) $saved['error']);
        self::assertSame([['_key' => 'k1', 'days' => ['mon', 'wed'], 'at' => '10:00', 'notes' => ['quiet', 'early']]], json_decode((string) self::row('houses', 2)['slots'], true));
        $page = self::request('GET', '/en/admin/houses/edit/2', self::options());
        self::assertStringContainsString('name="slots[k1][days][o2]" value="wed" checked', $page['body']);
        self::assertStringContainsString('name="slots[k1][notes]" placeholder="Separate tags with “,”" type="text" value="quiet, early"', $page['body']);
    }

    public function testTheListShowsChipsAndTimesAndFilters(): void
    {
        $list = self::request('GET', '/en/admin/houses', self::options());
        self::assertSame(200, $list['status']);
        self::assertStringContainsString('<span class="fa-tag">Pool</span><span class="fa-tag">sauna</span>', $list['body']);
        self::assertStringContainsString('<time datetime="09:30">09:30</time>', $list['body']);

        $filtered = self::request('GET', '/en/admin/houses?filter%5Bkeywords%5D=SEA+VIEW', self::options());
        self::assertStringContainsString('Seaside villa', $filtered['body']);
        self::assertStringContainsString('Harbour flat', $filtered['body']);
        self::assertStringNotContainsString('Stone house', $filtered['body']);

        $byTime = self::request('GET', '/en/admin/houses?filter%5Bopens_at%5D%5Bfrom%5D=10%3A00&filter%5Bopens_at%5D%5Buntil%5D=15%3A00', self::options());
        self::assertStringNotContainsString('Seaside villa', $byTime['body']);
        self::assertStringContainsString('Harbour flat', $byTime['body']);
        self::assertStringContainsString('Stone house', $byTime['body']);
        self::assertStringContainsString('11:00', $byTime['body']);

        $any = self::request('GET', '/en/admin/houses?filter%5Bfeatures_any%5D%5Bo4%5D=lift&filter%5Bfeatures_any%5D%5Bo3%5D=garden', self::options());
        self::assertStringNotContainsString('Seaside villa', $any['body']);
        self::assertStringContainsString('Harbour flat', $any['body']);
        self::assertStringContainsString('Stone house', $any['body']);
        self::assertStringContainsString('Garden, Lift', $any['body'], 'the chip names the options');
    }

    public function testTheViewPageShowsChipsAndTimes(): void
    {
        $view = self::request('GET', '/en/admin/houses/view/1', self::options());
        self::assertSame(200, $view['status']);
        self::assertStringContainsString('<span class="fa-view-chips"><span>Pool</span><span>sauna</span></span>', $view['body']);
        self::assertStringContainsString('<span class="fa-view-chips"><span>villa</span><span>sea view</span></span>', $view['body']);
        self::assertStringContainsString('<time datetime="09:30">09:30</time>', $view['body']);
    }

    public function testCsvExportWritesListsAndTimes(): void
    {
        $csv = self::request('GET', '/en/admin/houses/export', self::options());
        self::assertSame(200, $csv['status'], (string) $csv['error']);
        self::assertStringContainsString('1,"Seaside villa","pool | sauna","villa | sea view","09:30:00","18:00:00",""', $csv['body'], 'keys and stored times');
        $readable = self::request('GET', '/en/admin/houses/export?headings=readable', self::options());
        self::assertStringContainsString('"Seaside villa","Pool | sauna","villa | sea view","09:30","18:00"', $readable['body'], 'labels and shown times');
    }
}
