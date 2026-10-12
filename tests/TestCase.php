<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Config;
use FloCMS\Core\Lang;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::languages(['en', 'ar', 'el'], 'en');
        Admin::reset();
        Admin::useConfig([], true, sys_get_temp_dir());
        AdminLang::reset();
        AdminLang::setLanguage('en');
        Translations::useStore(null);
        self::siteLang([]);
    }

    protected function tearDown(): void
    {
        Admin::reset();
        AdminLang::reset();
        Config::set('permissions', null);
        parent::tearDown();
    }

    /** @param list<string> $languages */
    protected static function languages(array $languages, string $default): void
    {
        Config::set('languages', $languages);
        Config::set('default_language', $default);
        Languages::reset();
    }

    /** Replace the site's loaded lang file (Lang has no setter: write a temp file). */
    protected static function siteLang(array $lines): void
    {
        $dir = sys_get_temp_dir() . '/flo-admin-lang-' . getmypid();
        if (!is_dir($dir . '/lang')) {
            mkdir($dir . '/lang', 0777, true);
        }
        file_put_contents($dir . '/lang/xx.php', '<?php return ' . var_export($lines, true) . ';');
        if (!defined('ROOT')) {
            define('ROOT', $dir);
        }
        if (ROOT === $dir) {
            Lang::load('xx');
        }
    }
}
