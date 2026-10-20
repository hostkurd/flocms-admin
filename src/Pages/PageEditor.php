<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Translations;

/**
 * The parts of the page editor that are not fields (0.30): the search and
 * social previews, the Preview and View on site buttons, and the Page card.
 * resources/js/pages.ts keeps the previews in step with what is typed.
 */
final class PageEditor
{
    private static function t(string $key, array $replace = []): string
    {
        return AdminLang::get('pages.' . $key, $replace);
    }

    public static function seoPreview(?object $record): string
    {
        $lang = AdminLang::language();
        $title = $record !== null ? (Translations::get($record->meta_title ?? '', $lang) ?: Translations::get($record->title ?? '', $lang)) : '';
        $description = $record !== null ? Translations::get($record->meta_description ?? '', $lang) : '';
        $url = $record !== null && isset($record->id) ? Pages::url($record, $lang) : (defined('SITE_URI') ? (string) constant('SITE_URI') : '') . '/' . $lang . '/…';
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: 'example.com');
        $crumbs = static fn (string $url): string => $host . (($path = trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/')) !== '' ? ' › ' . str_replace('/', ' › ', $path) : '');
        return '<div class="fa-seo-preview" data-seo-preview data-empty-title="' . Str::e(self::t('seo_untitled')) . '" data-seo-urls="' . Str::e(self::urls($record, $crumbs)) . '">'
            . '<div class="fa-seo-card fa-seo-search" aria-label="' . Str::e(self::t('seo_search')) . '"><p class="fa-seo-kicker">' . Str::e(self::t('seo_search')) . '</p>'
            . '<p class="fa-seo-url" dir="ltr" data-seo-url>' . Str::e($crumbs($url)) . '</p>'
            . '<p class="fa-seo-title" data-seo-title>' . Str::e($title !== '' ? $title : self::t('seo_untitled')) . '</p>'
            . '<p class="fa-seo-desc" data-seo-desc>' . Str::e($description) . '</p></div>'
            . '<div class="fa-seo-card fa-seo-social" aria-label="' . Str::e(self::t('seo_social')) . '"><p class="fa-seo-kicker">' . Str::e(self::t('seo_social')) . '</p>'
            . '<div class="fa-seo-social-image" data-seo-image aria-hidden="true">' . Icons::svg('image', 'fa-icon') . '</div>'
            . '<p class="fa-seo-host" dir="ltr">' . Str::e(strtoupper($host)) . '</p>'
            . '<p class="fa-seo-title" data-seo-title>' . Str::e($title !== '' ? $title : self::t('seo_untitled')) . '</p>'
            . '<p class="fa-seo-desc" data-seo-desc>' . Str::e($description) . '</p></div></div>';
    }

    /**
     * The page's address in each language, as JSON (lang => text), for the
     * "Editing: language" switch (resources/js/pages.ts).
     */
    private static function urls(?object $record, callable $format): string
    {
        if ($record === null || !isset($record->id)) { return '{}'; }
        $urls = [];
        foreach (\FloCMS\Admin\Translation\Languages::all() as $lang) { $urls[$lang] = $format(Pages::url($record, $lang)); }
        return (string) json_encode($urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Preview (the page as edited, saved or not, in a new tab) and View on site. */
    public static function buttons(?object $record): string
    {
        $html = '<div class="fa-page-buttons">'
            . '<button type="submit" class="fa-btn" formaction="' . Str::e(Admin::url('page-preview', ['page' => $record !== null && isset($record->id) ? (int) $record->id : null])) . '" formtarget="_blank" formnovalidate name="_preview" value="1" data-admin-download data-admin-preview>'
            . Icons::svg('eye', 'fa-icon') . '<span>' . Str::e(self::t('preview')) . '</span></button>';
        if ($record !== null && isset($record->id) && Pages::isVisible($record)) {
            $html .= '<a class="fa-btn fa-btn-ghost" href="' . Str::e(Pages::url($record, AdminLang::language())) . '" data-page-href="' . Str::e(self::urls($record, static fn (string $url): string => $url)) . '" target="_blank" rel="noopener">' . Icons::svg('external', 'fa-icon') . '<span>' . Str::e(self::t('view_on_site')) . '</span></a>';
        }
        return $html . '<p class="fa-help">' . Str::e(self::t('preview_help')) . '</p></div>';
    }

    /** The page's address (with Copy), home badge, when it was created and changed, and History. */
    public static function info(?object $record): string
    {
        if ($record === null || !isset($record->id)) { return '<p class="fa-help">' . Str::e(self::t('info_new')) . '</p>'; }
        $url = Pages::url($record, AdminLang::language());
        $id = 'fa-page-address-' . (int) $record->id;
        $date = static fn (mixed $value): string => ($time = Pages::time(is_string($value) ? $value : null)) === null ? '–' : \FloCMS\Admin\Support\Dates::full($time);
        $html = '<dl class="fa-page-info">'
            . '<div><dt>' . Str::e(self::t('address')) . '</dt><dd class="fa-page-address"><input class="fa-input" id="' . Str::e($id) . '" value="' . Str::e(rawurldecode($url)) . '" readonly dir="ltr" aria-label="' . Str::e(self::t('address')) . '" data-page-urls="' . Str::e(self::urls($record, 'rawurldecode')) . '">'
            . '<button type="button" class="fa-btn fa-btn-sm" data-media-copy="' . Str::e($id) . '" data-copied="' . Str::e(self::t('copied')) . '" hidden>' . Str::e(self::t('copy')) . '</button></dd></div>';
        if ((int) ($record->is_home ?? 0) === 1) { $html .= '<div><dt>' . Str::e(self::t('home')) . '</dt><dd><span class="fa-badge fa-badge-blue">' . Str::e(self::t('home_badge')) . '</span></dd></div>'; }
        $html .= '<div><dt>' . Str::e(self::t('created')) . '</dt><dd>' . Str::e($date($record->created_at ?? null)) . '</dd></div>'
            . '<div><dt>' . Str::e(self::t('changed')) . '</dt><dd>' . Str::e($date($record->updated_at ?? null)) . '</dd></div></dl>';
        if (\FloCMS\Admin\Activity\Activity::enabled()) {
            $html .= '<a class="fa-link" href="' . Str::e(Admin::url(PagesResource::SLUG . '/history/' . (int) $record->id)) . '">' . Icons::svg('clock', 'fa-icon') . '<span>' . Str::e(self::t('history')) . '</span></a>';
        }
        return $html;
    }
}
