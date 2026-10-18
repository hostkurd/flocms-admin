<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Repeaters\{ApartmentDialogResource, ApartmentResource};

/**
 * Repeater and key-value fields (0.24) through whole requests: the full page
 * (with the no-JavaScript buttons), Reactive dialogs, page-level translation,
 * errors per item, and the stored JSON.
 */
final class RepeaterRequestTest extends RequestTestCase
{
    private const RESOURCES = [ApartmentResource::class, ApartmentDialogResource::class];

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ($extra['admin_config'] ?? []) + ['resources' => self::RESOURCES]] + $extra;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS apartments');
        self::createTable($pdo, 'CREATE TABLE apartments (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NULL, rooms TEXT NULL, specs TEXT NULL, hours TEXT NULL)');
        $pdo->prepare('INSERT INTO apartments (title, rooms, specs, hours) VALUES (?, ?, ?, ?)')->execute([
            '{"en":"Harbour apartment"}',
            '[{"_key":"ka1","name":{"en":"Master bedroom","ar":"غرفة النوم"},"size":"22","floor":"1"},{"_key":"ka2","name":{"en":"Kitchen"},"size":"16","floor":"0"}]',
            '[{"key":"Parking","value":{"en":"2 spaces"}}]',
            null,
        ]);
    }

    /** The form as posted by a browser: every item in its order. */
    private static function form(array $rooms, array $extra = []): array
    {
        return $extra + [
            'title' => ['en' => 'Harbour apartment', 'ar' => '', 'ku' => ''],
            'rooms' => ['_' => ''] + $rooms,
            'specs' => ['_' => '', 'k0' => ['_key' => 'k0', 'key' => 'Parking', 'value' => ['en' => '2 spaces', 'ar' => '', 'ku' => '']]],
            'hours' => ['_' => ''],
        ];
    }

    public function testTheEditPageShowsItemsAndSavesThemInTheirOrder(): void
    {
        $page = self::request('GET', '/en/admin/apartments/edit/1', self::options());
        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertStringContainsString('data-field="rooms" data-repeater data-name="rooms"', $body);
        self::assertStringContainsString('<span data-repeater-title>Master bedroom</span>', $body);
        self::assertStringContainsString('name="rooms[ka2][size]"', $body);
        self::assertStringContainsString('2 of up to 6', $body);
        self::assertStringContainsString('class="fa-field fa-span-full fa-repeater is-table is-key-value"', $body);
        self::assertStringContainsString('name="specs[k0][key]" type="text" value="Parking"', $body);
        self::assertStringContainsString('>Opening hours</span>', $body);

        // Kitchen first, a new study, the bedroom gone: saved as posted
        $saved = self::post('/en/admin/apartments/edit/1', self::form([
            'ka2' => ['_key' => 'ka2', 'name' => ['en' => 'Kitchen', 'ar' => 'مطبخ', 'ku' => ''], 'size' => '16', 'floor' => '0'],
            'knew01' => ['_key' => 'knew01', 'name' => ['en' => 'Study', 'ar' => '', 'ku' => ''], 'size' => '9', 'floor' => ''],
            'kempty' => ['_key' => 'kempty', 'name' => ['en' => '', 'ar' => '', 'ku' => ''], 'size' => '', 'floor' => ''],
        ], ['_continue' => '1']), self::options());
        self::assertTrue($saved['redirected'], (string) $saved['error']);
        self::assertSame([
            ['_key' => 'ka2', 'name' => ['en' => 'Kitchen', 'ar' => 'مطبخ'], 'size' => '16', 'floor' => '0'],
            ['_key' => 'knew01', 'name' => ['en' => 'Study'], 'size' => '9'],
        ], json_decode((string) self::row('apartments', 1)['rooms'], true), 'the empty item is dropped');
        self::assertSame([['key' => 'Parking', 'value' => ['en' => '2 spaces']]], json_decode((string) self::row('apartments', 1)['specs'], true));
        self::assertNull(self::row('apartments', 1)['hours']);

        // Every item removed: the column is emptied
        self::post('/en/admin/apartments/edit/1', self::form([]), self::options());
        self::assertNull(self::row('apartments', 1)['rooms']);
    }

    public function testErrorsInsideItemsShowOnTheItemAndInTheSummary(): void
    {
        $result = self::post('/en/admin/apartments/edit/1', self::form([
            'ka1' => ['_key' => 'ka1', 'name' => ['en' => 'Master bedroom', 'ar' => '', 'ku' => ''], 'size' => 'big', 'floor' => '1'],
            'ka2' => ['_key' => 'ka2', 'name' => ['en' => '', 'ar' => '', 'ku' => ''], 'size' => '16', 'floor' => '0'],
        ]), self::options());
        self::assertSame(200, $result['status']);
        $body = $result['body'];
        self::assertStringContainsString('Please check: Rooms → item 1: Size (m²), Rooms → item 2: Name.', $body);
        self::assertSame(2, substr_count($body, 'class="fa-repeater-item has-error"'));
        self::assertStringContainsString('Size (m²) must be a number.', $body);
        self::assertStringContainsString('value="big"', $body, 'the typed values come back');
        self::assertSame('[{"_key":"ka1","name":{"en":"Master bedroom","ar":"غرفة النوم"},"size":"22","floor":"1"},{"_key":"ka2","name":{"en":"Kitchen"},"size":"16","floor":"0"}]', self::row('apartments', 1)['rooms'], 'nothing written');
    }

    public function testTheNoJavaScriptButtonsChangeTheListWithoutSaving(): void
    {
        $rooms = [
            'ka1' => ['_key' => 'ka1', 'name' => ['en' => 'Master bedroom', 'ar' => '', 'ku' => ''], 'size' => '22', 'floor' => '1'],
            'ka2' => ['_key' => 'ka2', 'name' => ['en' => 'Kitchen', 'ar' => '', 'ku' => ''], 'size' => '17', 'floor' => '0'],
        ];
        $before = self::row('apartments', 1)['rooms'];
        $down = self::post('/en/admin/apartments/edit/1', self::form($rooms, ['_repeater' => 'rooms:down:ka1']), self::options())['body'];
        self::assertLessThan(strpos($down, '<span data-repeater-title>Master bedroom</span>'), strpos($down, '<span data-repeater-title>Kitchen</span>'));
        self::assertStringContainsString('value="17"', $down, 'other changes are kept');
        self::assertStringNotContainsString('Please check', $down, 'no validation on a list change');
        $added = self::post('/en/admin/apartments/edit/1', self::form($rooms, ['_repeater' => 'rooms:add']), self::options())['body'];
        self::assertStringContainsString('<span data-repeater-title>Item 3</span>', $added);
        $removed = self::post('/en/admin/apartments/edit/1', self::form($rooms, ['_repeater' => 'rooms:remove:ka2']), self::options())['body'];
        self::assertStringNotContainsString('data-key="ka2"', $removed);
        self::assertStringContainsString('data-admin-dirty="true"', $removed, 'the change is not saved yet');
        self::assertSame($before, self::row('apartments', 1)['rooms']);
    }

    public function testPageLevelTranslationLocksTheListOutsideTheDefaultLanguage(): void
    {
        $options = self::options(['admin_config' => ['translation_mode' => 'page']]);
        $page = self::request('GET', '/en/admin/apartments/edit/1?editing=ar', $options)['body'];
        self::assertMatchesRegularExpression('~id="fa-record-switch" data-lang-switch[^>]*data-total="4"~', $page, 'the title, two room names and one spec value; the empty template is not counted');
        self::assertStringContainsString('class="fa-lang-pane is-current" data-lang="ar" data-input="rooms-ka1-f-name-ar"', $page);
        self::assertStringContainsString('name="rooms[ka1][name][ar]"', $page);
        self::assertMatchesRegularExpression('~<div class="fa-field is-shared" data-field="size" data-same-all>~', $page, 'shared fields inside items are locked');
        self::assertStringNotContainsString('data-field="size" data-same-all><label class="fa-label" for="rooms-ka1-f-size">Size (m²)</label><p class="fa-same-note', $page, 'without a note on every field');
        // An error in an item's Arabic name opens Arabic
        $error = self::post('/en/admin/apartments/edit/1', self::form([
            'ka1' => ['_key' => 'ka1', 'name' => ['en' => 'Master bedroom', 'ar' => str_repeat('ف', 70), 'ku' => ''], 'size' => '22', 'floor' => '1'],
        ], ['_editing' => 'en']), $options)['body'];
        self::assertStringContainsString('data-editing="ar"', $error);
        self::assertStringContainsString('Rooms → item 1: Name (العربية)', $error);
    }

    public function testDialogsEditTheListThroughTheDraft(): void
    {
        $options = self::options(['admin_config' => ['reactive' => true, 'translation_mode' => 'page']]);
        $list = self::request('GET', '/en/admin/apartment-dialogs', $options);
        preg_match('/data-rx-snapshot="([^"]+)"/', $list['body'], $match);
        $open = self::post('/en/flo-reactive/update', ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => 'edit', 'state' => ['selectedId' => '1']], $options + ['session' => $list['session']]);
        self::assertStringContainsString('data-field="rooms" data-repeater data-name="rooms"', $open['body']);
        preg_match('/data-rx-snapshot="([^"]+)"/', $open['body'], $match);
        // The draft as the browser builds it from the dialog's inputs (nested names)
        $draft = json_encode(self::form([
            'ka2' => ['_key' => 'ka2', 'name' => ['en' => 'Kitchen', 'ar' => 'مطبخ'], 'size' => '16', 'floor' => '0'],
            'knew' => ['_key' => 'knew', 'name' => ['en' => 'Balcony'], 'size' => '6', 'floor' => ''],
        ]), JSON_UNESCAPED_UNICODE);
        $saved = self::post('/en/flo-reactive/update', ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => 'save', 'state' => ['draft' => $draft]], $options + ['session' => $open['session']]);
        self::assertSame(200, $saved['status'], $saved['body']);
        self::assertSame(['Kitchen', 'Balcony'], array_map(static fn (array $room): string => $room['name']['en'], json_decode((string) self::row('apartments', 1)['rooms'], true)));
    }
}
