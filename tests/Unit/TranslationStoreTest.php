<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Translation\JsonColumnStore;
use FloCMS\Admin\Translation\Translations;

final class TranslationStoreTest extends TestCase
{
    public function testEncodeKeepsUnicodeAndSlashesAndDropsEmptyLanguages(): void
    {
        $json = (new JsonColumnStore())->encode(['en' => 'Sea view / villa', 'ar' => 'فيلا', 'ku' => '  ', 'fr' => '']);

        self::assertSame('{"en":"Sea view / villa","ar":"فيلا"}', $json);
    }

    public function testEncodeReturnsNullWhenNothingIsFilled(): void
    {
        self::assertNull((new JsonColumnStore())->encode(['en' => '', 'ar' => ' ']));
    }

    public function testEncodeIgnoresInvalidLanguageCodes(): void
    {
        self::assertSame('{"en":"A"}', (new JsonColumnStore())->encode(['en' => 'A', 'EN GB' => 'x', '<b>' => 'y']));
    }

    public function testDecodeReadsJsonObjects(): void
    {
        self::assertSame(['en' => 'Title', 'ku' => 'ناونیشان'], (new JsonColumnStore())->decode('{"en":"Title","ku":"ناونیشان"}', 'en'));
    }

    public function testDecodeReadsLegacyPlainTextAsTheDefaultLanguage(): void
    {
        $store = new JsonColumnStore();

        self::assertSame(['en' => 'Old title'], $store->decode('Old title', 'en'));
        self::assertSame(['en' => '{not json'], $store->decode('{not json', 'en'));
        self::assertSame(['ar' => '123'], $store->decode(123, 'ar'));
    }

    public function testDecodeOfEmptyValues(): void
    {
        $store = new JsonColumnStore();

        self::assertSame([], $store->decode(null, 'en'));
        self::assertSame([], $store->decode('', 'en'));
        self::assertSame([], $store->decode('{}', 'en'));
    }

    public function testTranslationsGetFallsBackToDefaultThenFirstFilled(): void
    {
        $value = '{"ar":"عنوان","ku":"ناونیشان"}';

        self::assertSame('ناونیشان', Translations::get($value, 'ku'));
        self::assertSame('عنوان', Translations::get($value, 'fr'), 'no fr, no en: first filled');
        self::assertSame('Title', Translations::get('{"en":"Title","ar":"عنوان"}', 'ku'), 'default language');
        self::assertSame('', Translations::get(null));
        self::assertSame('Plain', Translations::get('Plain', 'ar'), 'legacy plain text');
    }

    public function testLocalizedHelper(): void
    {
        self::assertTrue(function_exists('localized'));
        self::assertSame('Title', localized('{"en":"Title"}', 'en'));
    }
}
