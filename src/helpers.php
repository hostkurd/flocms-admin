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

if (!function_exists('media')) {
    /**
     * An item of the media library (0.17), or null when it does not exist (or
     * the library is not set up). Each id is read once per request.
     *
     *     $cover = media($home->cover_id);
     *     echo $cover?->img(['class' => 'cover']);     <img> with alt, size and srcset
     *     $cover?->url();  $cover?->url('thumb');  $cover?->alt();
     */
    function media(mixed $id): ?\FloCMS\Admin\Media\MediaItem
    {
        $ids = \FloCMS\Admin\Form\Fields\MediaPicker::ids(is_int($id) || is_string($id) ? $id : null);
        if ($ids === []) { return null; }
        return \FloCMS\Admin\Media\MediaLibrary::storeOrNull()?->find($ids[0]);
    }
}

if (!function_exists('media_list')) {
    /**
     * The items of a multiple MediaPicker value (a JSON list) or a list of ids,
     * in their order, without missing ones; one query for all of them.
     *
     *     foreach (media_list($home->gallery) as $photo) { echo $photo->img(); }
     *
     * @return list<\FloCMS\Admin\Media\MediaItem>
     */
    function media_list(mixed $ids): array
    {
        $store = \FloCMS\Admin\Media\MediaLibrary::storeOrNull();
        return $store === null ? [] : $store->findMany(\FloCMS\Admin\Form\Fields\MediaPicker::ids($ids));
    }
}

if (!function_exists('media_preload')) {
    /**
     * Load many items in one query before a loop that calls media():
     *
     *     media_preload(array_column($homes, 'cover_id'));
     *
     * Accepts ids, JSON lists, or a mix.
     */
    function media_preload(array $values): void
    {
        $store = \FloCMS\Admin\Media\MediaLibrary::storeOrNull();
        if ($store === null) { return; }
        $ids = [];
        foreach ($values as $value) { foreach (\FloCMS\Admin\Form\Fields\MediaPicker::ids($value) as $id) { $ids[] = $id; } }
        $store->preload($ids);
    }
}
