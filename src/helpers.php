<?php
declare(strict_types=1);

use FloCMS\Admin\Translation\Translations;

if (!function_exists('localized')) {
    /**
     * The value of a translatable field in the current language, falling back
     * to the default language, then to the first filled language, then ''.
     *
     *     {{ localized($property->title) }}
     *     localized($property->title, 'ar')
     *
     * Accepts the raw column value (JSON text), an already decoded array or a
     * plain string (read as the default language).
     */
    function localized(mixed $value, ?string $lang = null): string
    {
        return Translations::get($value, $lang);
    }
}

if (!function_exists('setting')) {
    /**
     * A website setting declared in admin/settings.php ("<page>.<field>"):
     *
     *     {{ setting('site.title') }}              current language, then the default one
     *     setting('contact.phone', '—')            default when nothing is stored
     *     setting('site.title', lang: 'ar')
     *     setting('site.logo')                     public URL of an uploaded file
     */
    function setting(string $key, mixed $default = null, ?string $lang = null): mixed
    {
        return \FloCMS\Admin\Settings\Settings::get($key, $default, $lang);
    }
}

if (!function_exists('settings')) {
    /**
     * All values of one settings page, keyed by field name:
     *
     *     $site = settings('site');   // ['title' => …, 'tagline' => …, 'logo' => …]
     *
     * @return array<string, mixed>
     */
    function settings(string $page, ?string $lang = null): array
    {
        return \FloCMS\Admin\Settings\Settings::page($page, $lang);
    }
}
