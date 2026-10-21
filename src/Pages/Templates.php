<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\Str;

/**
 * Page templates (0.30): views/page-templates/<name>.html in the site, found
 * by themselves ("full-width.html" is offered as "Full width"); "default" is
 * the package's own unless the site has one of that name.
 */
final class Templates
{
    public const DEFAULT = 'default';

    public static function directory(): string
    {
        $views = defined('VIEWS_PATH') ? (string) constant('VIEWS_PATH') : Admin::root() . DIRECTORY_SEPARATOR . 'views';
        return $views . DIRECTORY_SEPARATOR . 'page-templates';
    }

    /**
     * The templates to choose from: name => label, Default first.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $templates = [self::DEFAULT => \FloCMS\Admin\Support\AdminLang::get('pages.template_default')];
        foreach (glob(self::directory() . DIRECTORY_SEPARATOR . '*.html') ?: [] as $file) {
            $name = basename($file, '.html');
            if ($name === self::DEFAULT || preg_match('/^[a-z0-9][a-z0-9_-]{0,58}$/D', $name) !== 1) { continue; }
            $templates[$name] = Str::headline(str_replace(['-', '_'], ' ', $name));
        }
        return $templates;
    }

    /** A stored template name as one that exists, else "default". */
    public static function name(string $name): string
    {
        return $name !== '' && isset(self::all()[$name]) ? $name : self::DEFAULT;
    }

    /** The file of a template: the site's, else the package's default. */
    public static function path(string $name): string
    {
        $name = self::name($name);
        $site = self::directory() . DIRECTORY_SEPARATOR . $name . '.html';
        return is_file($site) ? $site : dirname(__DIR__, 2) . '/resources/pages/default.html';
    }
}
