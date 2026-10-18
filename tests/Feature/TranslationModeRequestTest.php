<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Translations\{RoomResource, VillaResource};

/**
 * Page-level translation (0.23): the "Editing: language" switch on record
 * pages, in Reactive dialogs and on action pages; the language a form opens in;
 * errors per language; the translations column and its "Missing in" filter.
 * Storage is the same JSON as field mode.
 */
final class TranslationModeRequestTest extends RequestTestCase
{
    private const RESOURCES = [VillaResource::class, RoomResource::class, \FloCMS\Admin\Tests\Fixtures\Resources\PostResource::class];

    private static function options(array $extra = []): array
    {
        return ['admin_config' => ($extra['admin_config'] ?? []) + ['resources' => self::RESOURCES]] + $extra;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (['rooms', 'villas'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        self::createTable($pdo, 'CREATE TABLE villas (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NULL, summary TEXT NULL, description TEXT NULL, price VARCHAR(20) NULL, city VARCHAR(60) NULL, seo_title TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT "draft")');
        self::createTable($pdo, 'CREATE TABLE rooms (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NULL, size VARCHAR(10) NULL, villa_id INTEGER NULL)');
        $insert = $pdo->prepare('INSERT INTO villas (title, summary, description, price, city, seo_title, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute(['{"en":"Cliff top villa","ar":"فيلا على الجرف"}', '{"en":"Four bedrooms.","ar":"أربع غرف."}', '{"en":"<p>A bright villa.</p>","ar":"<p></p>"}', '420000', 'Chania', '{"en":"Villa in Chania"}', 'published']);
        $insert->execute(['{"en":"Harbour flats","ar":"شقق","ku":"شوقە"}', '{"en":"Flats.","ar":"شقق.","ku":"شوقە."}', '{"en":"<p>Flats.</p>","ar":"<p>شقق.</p>","ku":"<p>شوقە.</p>"}', '300000', 'Heraklion', '{"en":"Harbour","ar":"الميناء","ku":"بەندەر"}', 'published']);
        $insert->execute(['Old town house', null, null, '250000', 'Rethymno', null, 'draft']);
        $pdo->exec("INSERT INTO rooms (name, size, villa_id) VALUES ('{\"en\":\"Penthouse\",\"ar\":\"البنتهاوس\"}', '160', 1)");
    }

    public function testThePageModeFormShowsOneLanguageAtATime(): void
    {
        $page = self::request('GET', '/en/admin/villas/edit/1', self::options());
        self::assertSame(200, $page['status']);
        $body = $page['body'];
        self::assertStringContainsString('data-lang-scope data-lang-url data-default-lang="en" data-editing="en"', $body);
        self::assertStringContainsString('<input type="hidden" name="_editing" value="en" data-lang-editing-value>', $body);
        // The switch: hidden until the script shows it (every language stays visible without JavaScript)
        self::assertMatchesRegularExpression('~<div class="fa-lang-switch" id="fa-record-switch" data-lang-switch[^>]*data-total="4"[^>]* hidden>~', $body);
        self::assertStringContainsString('data-lang="ar" aria-pressed="false"', $body);
        self::assertStringContainsString('data-lang="en" aria-pressed="true"', $body);
        self::assertMatchesRegularExpression('~data-lang="ar".*?data-lang-count aria-hidden="true">2/4<~s', $body, 'title and summary; empty rich text is missing');
        self::assertMatchesRegularExpression('~data-lang="ku".*?data-lang-count aria-hidden="true">0/4<~s', $body);
        // One pane per language, the default one current; panes keep their language's direction
        self::assertStringContainsString('class="fa-lang-pane is-current" data-lang="en" data-input="f-title"', $body);
        self::assertStringContainsString('class="fa-lang-pane" data-lang="ar" data-input="f-title-ar"', $body);
        self::assertMatchesRegularExpression('~id="f-title-ar"[^>]*dir="rtl"~', $body);
        // Reference lines: the default language's text for empty translations (shown by the script)
        self::assertStringContainsString('data-ref-line data-lang="ku" data-ref-input="f-title-ku" data-ref-source="f-title" hidden><b dir="auto">English:</b>', $body);
        self::assertStringContainsString('data-ref-input="f-description-ar" data-ref-source="f-description" data-ref-limit="200" hidden>', $body);
        self::assertStringContainsString('A bright villa.', $body);
        // Shared fields: locked outside the default language, with the note below the input
        self::assertMatchesRegularExpression('~<div class="fa-field is-shared" data-field="price" data-same-all>~', $body);
        self::assertStringContainsString('Same in every language. Change it in English.', $body);
        self::assertStringContainsString('data-field="description" data-translatable data-rich', $body);
        self::assertStringNotContainsString('class="fa-lang-badge', $body, 'no badges in page mode');
        self::assertStringContainsString('data-lang-other data-template="You are editing the :language version. Shared fields are changed in English."', $body);
    }

    public function testFieldModeStaysTheDefaultForExistingSites(): void
    {
        $body = self::request('GET', '/en/admin/posts/edit/1', self::options())['body'];
        self::assertStringContainsString('class="fa-lang-badge', $body);
        self::assertStringNotContainsString('data-lang-switch', $body);
        self::assertStringNotContainsString('data-lang-scope', $body);

        // The panel default turns it on for resources that do not choose
        $page = self::request('GET', '/en/admin/posts/edit/1', self::options(['admin_config' => ['translation_mode' => 'page']]))['body'];
        self::assertStringContainsString('data-lang-switch', $page);
        self::assertStringNotContainsString('class="fa-lang-badge', $page);

        // A site with one language sees neither
        $single = self::request('GET', '/en/admin/villas/edit/1', self::options(['languages' => ['en']]))['body'];
        self::assertStringNotContainsString('data-lang-switch', $single);
        self::assertStringNotContainsString('data-lang-scope', $single);
        self::assertStringNotContainsString('data-same-all', $single);
    }

    public function testTheEditingLanguageComesFromTheAddressAndIsRememberedOnSave(): void
    {
        $arabic = self::request('GET', '/en/admin/villas/edit/1?editing=ar', self::options())['body'];
        self::assertStringContainsString('data-default-lang="en" data-editing="ar"', $arabic);
        self::assertStringContainsString('class="fa-lang-pane is-current" data-lang="ar" data-input="f-title-ar"', $arabic);
        self::assertStringContainsString('data-lang="ar" aria-pressed="true"', $arabic);
        self::assertStringNotContainsString(' hidden>Next missing', $arabic);
        self::assertMatchesRegularExpression('~<button type="button" class="fa-next-missing" data-next-missing>~', $arabic, 'Next missing: Arabic has empty fields');
        self::assertStringContainsString('data-editing="en"', self::request('GET', '/en/admin/villas/edit/1?editing=xx', self::options())['body'], 'an unknown language falls back');

        // Saving in Kurdish: the same JSON as field mode, back on the record in Kurdish, remembered
        $saved = self::post('/en/admin/villas/edit/1', ['_editing' => 'ku', '_continue' => '1',
            'title' => ['en' => 'Cliff top villa', 'ar' => 'فيلا على الجرف', 'ku' => 'ڤیلای سەر بەرد'],
            'summary' => ['en' => 'Four bedrooms.', 'ar' => 'أربع غرف.', 'ku' => ''],
            'description' => ['en' => '<p>A bright villa.</p>', 'ar' => '', 'ku' => ''],
            'price' => '430000', 'city' => 'Chania', 'seo_title' => ['en' => 'Villa in Chania', 'ar' => '', 'ku' => ''], 'status' => 'published'], self::options());
        self::assertTrue($saved['redirected'], (string) $saved['error']);
        $row = self::row('villas', 1);
        self::assertSame(['en' => 'Cliff top villa', 'ar' => 'فيلا على الجرف', 'ku' => 'ڤیلای سەر بەرد'], json_decode((string) $row['title'], true));
        self::assertSame(['en' => 'Four bedrooms.', 'ar' => 'أربع غرف.'], json_decode((string) $row['summary'], true), 'empty languages are left out, as in field mode');
        self::assertSame('430000', (string) $row['price']);

        // The next record opens in Kurdish; a new record starts in English
        $next = self::request('GET', '/en/admin/villas/edit/2', self::options(['session' => $saved['session']]))['body'];
        self::assertStringContainsString('data-editing="ku"', $next);
        $create = self::request('GET', '/en/admin/villas/create', self::options(['session' => $saved['session']]))['body'];
        self::assertStringContainsString('data-editing="en"', $create);
        // Saving in the default language forgets it again
        $back = self::post('/en/admin/villas/edit/2', ['_editing' => 'en', 'title' => ['en' => 'Harbour flats'], 'status' => 'published'], self::options(['session' => $saved['session']]));
        self::assertStringContainsString('data-editing="en"', self::request('GET', '/en/admin/villas/edit/1', self::options(['session' => $back['session']]))['body']);
    }

    public function testErrorsInAnotherLanguageOpenThatLanguageAndShowInTheSwitch(): void
    {
        $result = self::post('/en/admin/villas/edit/1', ['_editing' => 'en',
            'title' => ['en' => 'Cliff top villa', 'ar' => str_repeat('ف', 130), 'ku' => ''], 'status' => 'published'], self::options());
        self::assertSame(200, $result['status']);
        $body = $result['body'];
        self::assertStringContainsString('data-default-lang="en" data-editing="ar"', $body, 'opens on the language of the error');
        self::assertStringContainsString('Please check: Title (العربية).', $body);
        self::assertMatchesRegularExpression('~data-lang="ar" aria-pressed="true".*?<span class="fa-seg-err" data-lang-errors aria-hidden="true">1</span>~s', $body);
        self::assertStringContainsString('data-lang="ar" data-input="f-title-ar" data-lang-error="ar"', $body);
        self::assertMatchesRegularExpression('~class="fa-dot is-filled is-invalid is-current" data-lang="ar"~', $body);

        // An error in the default language wins over another language's
        $both = self::post('/en/admin/villas/edit/1', ['_editing' => 'ku',
            'title' => ['en' => '', 'ar' => str_repeat('ف', 130)], 'status' => 'published'], self::options())['body'];
        self::assertStringContainsString('data-editing="en"', $both);
        // The language being edited keeps its place when it has an error itself
        $own = self::post('/en/admin/villas/edit/1', ['_editing' => 'ar',
            'title' => ['en' => '', 'ar' => str_repeat('ف', 130)], 'status' => 'published'], self::options())['body'];
        self::assertStringContainsString('data-editing="ar"', $own);
    }

    public function testTheTranslationsColumnAndTheMissingInFilter(): void
    {
        $list = self::request('GET', '/en/admin/villas', self::options())['body'];
        self::assertMatchesRegularExpression('~>\s*Translations\s*</th>~', $list);
        self::assertStringContainsString('class="fa-tr-mark is-full" title="English: 4 of 4"', $list);
        self::assertStringContainsString('class="fa-tr-mark is-partial" title="العربية: 2 of 4"', $list);
        self::assertStringContainsString('class="fa-tr-mark is-none" title="کوردی: 0 of 4"', $list);
        self::assertStringContainsString('class="fa-tr-mark is-partial" title="English: 1 of 4"', $list, 'plain legacy text counts as the default language');
        self::assertStringContainsString('Missing in', $list, 'the column brings its filter');

        $missing = self::request('GET', '/en/admin/villas?filter%5Bmissing_in%5D=ar', self::options())['body'];
        self::assertStringContainsString('Cliff top villa', $missing, 'empty rich text counts as missing');
        self::assertStringContainsString('Old town house', $missing);
        self::assertStringNotContainsString('Harbour flats', $missing);
        $kurdish = self::request('GET', '/en/admin/villas?filter%5Bmissing_in%5D=ku', self::options())['body'];
        self::assertStringNotContainsString('Harbour flats', $kurdish);
        self::assertStringContainsString('Cliff top villa', $kurdish);
    }

    public function testDialogsRenderTheSwitchShownAndRememberTheLanguage(): void
    {
        $options = self::options(['admin_config' => ['reactive' => true]]);
        $page = self::request('GET', '/en/admin/villas/edit/1', $options);
        $open = self::update($page, 'edit', ['selectedId' => '1'], $options);
        $body = $open['body'];
        self::assertStringContainsString('data-rx-open="true"', $body);
        self::assertMatchesRegularExpression('~<dialog class="fa-editor is-switching"[^>]*data-lang-scope data-default-lang="en" data-editing="en"~', $body);
        self::assertMatchesRegularExpression('~<div class="fa-lang-switch" id="fa-rel-rooms-switch" data-lang-switch[^>]*data-total="1"[^>]*>~', $body);
        self::assertDoesNotMatchRegularExpression('~id="fa-rel-rooms-switch"[^>]* hidden>~', $body, 'shown by the server: the dialog only exists with JavaScript');
        self::assertStringContainsString('data-rx-bind="editing" value="en" data-lang-editing-value', $body);

        // Switched to Arabic: shared fields render locked, the empty reference shows
        $arabic = self::update($open, 'refreshForm', ['editing' => 'ar', 'draft' => json_encode(['name' => ['en' => 'Penthouse', 'ar' => ''], 'size' => '160'])], $options)['body'];
        self::assertStringContainsString('data-editing="ar"', $arabic);
        self::assertMatchesRegularExpression('~class="fa-field is-shared is-locked" data-field="size" data-same-all>~', $arabic);
        self::assertMatchesRegularExpression('~data-ref-line data-lang="ar" data-ref-input="fa-rel-rooms-f-name-ar" data-ref-source="fa-rel-rooms-f-name">~', $arabic);
        self::assertStringContainsString('class="fa-lang-pane is-current" data-lang="ar"', $arabic);

        // A failed save opens on the error's language; a good one remembers the language
        $failed = self::update($open, 'save', ['editing' => 'en', 'draft' => json_encode(['name' => ['en' => 'Penthouse', 'ku' => str_repeat('ش', 80)], 'size' => '160'])], $options);
        self::assertStringContainsString('data-editing="ku"', $failed['body']);
        $saved = self::update($open, 'save', ['editing' => 'ar', 'draft' => json_encode(['name' => ['en' => 'Penthouse', 'ar' => 'البنتهاوس الكبير'], 'size' => '170'])], $options);
        self::assertSame(200, $saved['status'], $saved['body']);
        self::assertSame(['en' => 'Penthouse', 'ar' => 'البنتهاوس الكبير'], json_decode((string) self::row('rooms', 1)['name'], true));
        $again = self::update($saved, 'edit', ['selectedId' => '1'], $options);
        self::assertStringContainsString('data-editing="ar"', $again['body']);
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
        preg_match('/data-rx-snapshot="([^"]+)"/', $page['body'], $match);
        return html_entity_decode($match[1] ?? '', ENT_QUOTES);
    }

    private static function update(array $previous, string $action, array $state, array $options, string $key = 'rel-rooms'): array
    {
        return self::post('/en/flo-reactive/update', ['_snapshot' => self::snapshot($previous, $key), '_action' => $action, 'state' => $state],
            $options + ['session' => $previous['session']]);
    }
}
