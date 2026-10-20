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

if (!function_exists('media_folder')) {
    /**
     * The items in a media library folder (0.18), for sliders and galleries that
     * editors change by adding photos to a folder. The folder is named by its path
     * from the top ("Listings/Villas") or by a name only one folder has
     * ("Homepage slider"); case does not matter. Items directly in that folder,
     * by name (so editors can order them as "01 …", "02 …"), or 'newest' / 'oldest'.
     *
     *     foreach (media_folder('Homepage slider') as $slide) { echo $slide->img(['class' => 'slide']); }
     *
     * @return list<\FloCMS\Admin\Media\MediaItem>
     */
    function media_folder(string $path, string $sort = 'name', int $limit = 200): array
    {
        $store = \FloCMS\Admin\Media\MediaLibrary::storeOrNull();
        if ($store === null) { return []; }
        $id = \FloCMS\Admin\Media\MediaLibrary::folders()->resolve($path);
        if ($id === null) { return []; }
        $sort = in_array($sort, \FloCMS\Admin\Media\MediaStore::SORTS, true) ? $sort : 'name';
        return $store->search('', null, false, 1, max(1, min(500, $limit)), ['folder' => $id, 'sort' => $sort])['items'];
    }
}

if (!function_exists('repeater_items')) {
    /**
     * The items of a Repeater column (0.24), as plain arrays in the page's
     * language (translations fall back to the default language), without the
     * internal "_key":
     *
     *     @foreach(repeater_items($property->rooms) as $room)
     *         <li>{{ $room['name'] }} · {{ $room['size'] }} m²</li>
     *     @endforeach
     *
     * A media picker inside an item stays an id (or a list of ids): media($room['photo']).
     *
     * @return list<array<string, mixed>>
     */
    function repeater_items(mixed $value, ?string $lang = null): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        if (!is_array($value)) { return []; }
        $items = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) { continue; }
            unset($entry['_key']);
            foreach ($entry as $name => $part) {
                // {"en": …, "ar": …} is a translation; a list (several media ids) stays a list.
                if (is_array($part) && !array_is_list($part)) { $entry[$name] = Translations::get($part, $lang); }
            }
            $items[] = $entry;
        }
        return $items;
    }
}

if (!function_exists('key_values')) {
    /**
     * The pairs of a KeyValue column (0.24) in their order, key => value in the
     * page's language (falling back to the default language):
     *
     *     @foreach(key_values($property->specs) as $label => $value)
     *         <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
     *     @endforeach
     *
     * A numeric key ("2") becomes an integer key, as everywhere in PHP.
     *
     * @return array<int|string, string>
     */
    function key_values(mixed $value, ?string $lang = null): array
    {
        $pairs = [];
        foreach (repeater_items($value, $lang) as $item) {
            $key = $item['key'] ?? null;
            if (!is_scalar($key) || trim((string) $key) === '') { continue; }
            $pairs[(string) $key] = is_scalar($item['value'] ?? null) ? (string) $item['value'] : '';
        }
        return $pairs;
    }
}

if (!function_exists('blocks')) {
    /**
     * The blocks of a Builder column (0.29), printed with the site's templates
     * (views/blocks/<type>.html, else the package's): only published blocks in
     * their time window, in the page's language.
     *
     *     {!! blocks($page->content) !!}
     *
     * Put {!! blocks_assets() !!} in the layout's <head> for their styles and scripts.
     */
    function blocks(mixed $value, ?string $lang = null): string
    {
        return \FloCMS\Admin\Blocks\BlockRenderer::render($value, $lang);
    }
}

if (!function_exists('blocks_data')) {
    /**
     * The blocks shown now as plain arrays, for a site that renders them itself
     * (React, an API): [['id' => …, 'type' => 'slider', 'fields' => […], 'settings' => […], 'children' => […]], …].
     *
     * @return list<array<string, mixed>>
     */
    function blocks_data(mixed $value, ?string $lang = null): array
    {
        return \FloCMS\Admin\Blocks\BlockRenderer::data($value, $lang);
    }
}

if (!function_exists('blocks_assets')) {
    /**
     * The styles and scripts of the blocks printed on this page (the slider's
     * script only where there is a slider):
     *
     *     <head> … {!! blocks_assets() !!} </head>
     */
    function blocks_assets(): string
    {
        return \FloCMS\Admin\Blocks\BlockRenderer::assets();
    }
}

if (!function_exists('slider')) {
    /**
     * A Slider field's slides (0.29) as a slider, with the slider block's template:
     *
     *     {!! slider($property->photos, ['height' => 'fixed', 'height_px' => 480]) !!}
     *
     * Options are the slider block's settings (height, effect, interval, autoplay, arrows, dots, fit, overlay).
     */
    function slider(mixed $slides, array $options = []): string
    {
        return \FloCMS\Admin\Blocks\BlockRenderer::slider($slides, $options);
    }
}
