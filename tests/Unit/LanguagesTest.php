<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Translation\Languages;

final class LanguagesTest extends TestCase
{
    public function testDefaultLanguageComesFirst(): void
    {
        self::languages(['ar', ' EL ', 'en', 'ar', 'bad code'], 'en');

        self::assertSame(['en', 'ar', 'el'], Languages::all());
        self::assertSame('en', Languages::default());
    }

    public function testDirection(): void
    {
        self::assertSame('rtl', Languages::direction('ar'));
        self::assertSame('rtl', Languages::direction('ku'));
        self::assertSame('ltr', Languages::direction('el'));

        Languages::setRtl(['el']);
        self::assertSame('rtl', Languages::direction('el'));
    }

    public function testNamesUseTheSiteKeyThenTheEndonym(): void
    {
        self::siteLang(['lng.ar' => 'عەرەبی']);

        self::assertSame('عەرەبی', Languages::name('ar'));
        self::assertSame('Ελληνικά', Languages::name('el'));
        self::assertSame('XQ', Languages::name('xq'));
    }
}
