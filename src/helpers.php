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
