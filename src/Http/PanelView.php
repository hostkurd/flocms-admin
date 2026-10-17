<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\Auth;
use FloCMS\Core\Lang;
use FloCMS\Core\Session;

/**
 * Data of the panel shell (sidebar, top bar, flash message) around a page.
 * The shell is the content of the site's own admin layout.
 *
 * @internal
 */
final class PanelView
{
    public static function template(): string
    {
        return PageView::viewPath('panel');
    }

    /** @return array<string, mixed> */
    public function data(string $title, string $page, ?string $activeSlug): array
    {
        $lang = AdminLang::language();
        $siteBase = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') . '/' . rawurlencode($lang) : '';

        $nav = [];
        foreach (Admin::registry()->all() as $slug => $class) {
            /** @var class-string<Resource> $class */
            if (!Auth::can($class::permission('view'))) {
                continue;
            }
            $nav[] = [
                'label' => $class::getPluralLabel(),
                'url' => Admin::url($slug),
                'active' => $slug === $activeSlug,
                'icon' => Icons::svg($class::getIcon()),
            ];
        }

        $flash = Session::getFlash();
        if ($flash !== null) {
            $type = in_array($flash['type'] ?? '', ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
            // Messages set elsewhere in the site may contain <br>: shown as text
            $message = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $flash['message'])));
            if ($message !== '') {
                \FloCMS\Admin\Notifications\Notification::make()->title($message)->{$type}()->send();
            }
        }

        $user = (string) (Session::get('fullname') ?: Session::get('email') ?: '');
        $registry = Admin::registry();
        $usersEnabled = $registry->find('accounts') !== null;

        return [
            't' => PageView::translator(),
            'title' => $title,
            'brand' => \FloCMS\Admin\Support\Appearance::brand(),
            'page' => $page,
            'nav' => $nav,
            'notifications' => \FloCMS\Admin\Notifications\NotificationView::pending(),
            'user' => $user,
            'dir' => self::direction($lang),
            'lang' => $lang,
            'dashboardUrl' => Admin::url('dashboard'),
            'dashboardActive' => $activeSlug === 'dashboard',
            'mediaUrl' => !class_exists(\FloCMS\Admin\Support\Str::appControllerClass('media')) && \FloCMS\Admin\Media\MediaLibrary::enabled() && \FloCMS\Admin\Media\MediaLibrary::can('view') ? Admin::url('media') : null,
            'mediaActive' => $activeSlug === 'media',
            'mediaIcon' => Icons::svg('image'),
            'mediaPicker' => self::mediaPicker(),
            'usersUrl' => $usersEnabled && Auth::can('users.manage') ? Admin::url('accounts') : null,
            'usersActive' => $activeSlug === 'accounts',
            'settingsUrl' => !class_exists(\FloCMS\Admin\Support\Str::appControllerClass('settings')) && \FloCMS\Admin\Http\SettingsController::allowedPages() !== [] ? Admin::url('settings') : null,
            'settingsActive' => $activeSlug === 'settings',
            'settingsIcon' => Icons::svg('settings'),
            'profileUrl' => $usersEnabled ? Admin::url('profile') : null,
            'logoutUrl' => Admin::url('users/logout'),
            'siteUrl' => $siteBase !== '' ? $siteBase . '/' : '/',
            'dashboardIcon' => Icons::svg('dashboard'),
            'usersIcon' => Icons::svg('users'),
            'logoutIcon' => Icons::svg('logout'),
            'menuIcon' => Icons::svg('menu'),
            'assets' => \FloCMS\Admin\Assets::tags(),
            'navigation' => Admin::config()->reactiveEnabled(),
            'panelBase' => Admin::url(),
            'theme' => \FloCMS\Admin\Support\Appearance::theme(),
            'sidebar' => \FloCMS\Admin\Support\Appearance::sidebar(),
            'contentWidth' => \FloCMS\Admin\Support\Appearance::contentWidth(),
            'density' => \FloCMS\Admin\Support\Appearance::density(),
            'logo' => \FloCMS\Admin\Support\Appearance::logo(),
            'brandInitial' => self::initial(\FloCMS\Admin\Support\Appearance::brand()),
            'userInitial' => self::initial($user),
            'breadcrumbs' => $this->breadcrumbs($title, $activeSlug),
            'languages' => self::languageLinks($lang),
            'globeIcon' => Icons::svg('globe'),
            'chevronIcon' => Icons::svg('chevron-down', 'fa-icon fa-icon-sm'),
            'externalIcon' => Icons::svg('external'),
            'profileIcon' => Icons::svg('user'),
        ];
    }

    /** Texts of the media screens (resources/js/media), as media.<key>. */
    private const MEDIA_STRINGS = [
        'picker_title', 'search', 'search_placeholder', 'upload', 'select', 'more', 'empty', 'failed', 'images', 'videos', 'no_results', 'too_big',
        'folders', 'details', 'kind', 'all_types', 'status', 'any_status', 'unused', 'no_alt', 'no_alt_short', 'not_used', 'sort',
        'sort_newest', 'sort_oldest', 'sort_name', 'sort_largest', 'view', 'view_grid', 'view_list', 'selected_items', 'items', 'loading',
        'new_folder', 'new_folder_in', 'new_folder_here', 'create_folder', 'rename_folder', 'move_folder', 'delete_folder', 'delete_folder_confirm',
        'top_level', 'folder_options', 'collapse', 'expand', 'all_media', 'unfiled', 'folder', 'folder_empty', 'search_everywhere', 'count',
        'this_folder_only', 'video', 'added', 'used_count', 'max_reached', 'selected_one', 'selected_many', 'move_to', 'edit_details',
        'copy_addresses', 'copied_count', 'copied', 'copy', 'delete', 'select_all_loaded', 'clear_selection', 'delete_one_confirm',
        'delete_many_confirm', 'delete_kept_note', 'drop_to_upload', 'pick_for_details', 'focal_help', 'focal_set', 'focal_done', 'replace',
        'replaced', 'replace_in_text', 'open_page', 'item_title', 'alt', 'alt_placeholder', 'alt_missing', 'delete_in_use', 'dimensions', 'size',
        'uploaded', 'used_in', 'used_nowhere', 'uploads', 'hide_uploads', 'show_uploads', 'close', 'waiting_short', 'uploading_short',
        'upload_done_short', 'reused', 'wrong_type', 'retry_named', 'cancel_named', 'use_existing', 'upload_anyway', 'uploading_of',
        'uploads_waiting', 'uploads_done', 'stat_items', 'stat_kinds', 'stat_size', 'stat_no_alt', 'choose_one', 'choose_title_image',
        'choose_title_video', 'choose_title_media', 'choose_title_many', 'limit_one', 'limit_many', 'keys_help', 'nothing_chosen',
        'add', 'change', 'remove', 'choose_image', 'choose_video', 'move_before', 'move_after', 'moved', 'max', 'missing_item', 'none',
        'choose_from_library', 'saved', 'drop_here_field', 'options_named', 'set_focal', 'remove_named', 'batch_progress', 'batch_alt_count',
        'previous', 'next', 'save_next', 'copy_title', 'batch_done', 'focal_click', 'drag_hint', 'move_earlier', 'move_later', 'or_drop',
        'replace_from_library', 'tile_label',
    ];

    /**
     * Settings of the media screens (the Media page, the picker, media fields, the rich
     * editor), or '' when the library is off for this user.
     */
    private static function mediaPicker(): string
    {
        if (!\FloCMS\Admin\Media\MediaLibrary::enabled() || !\FloCMS\Admin\Media\MediaLibrary::can('view')) { return ''; }
        $strings = [];
        foreach (self::MEDIA_STRINGS as $key) { $strings[$key] = AdminLang::get('media.' . $key); }
        $strings['cancel'] = AdminLang::get('actions.cancel');
        $strings['save'] = AdminLang::get('actions.save');
        $languages = [];
        foreach (\FloCMS\Admin\Translation\Languages::all() as $code) {
            $languages[] = ['code' => $code, 'name' => \FloCMS\Admin\Translation\Languages::name($code), 'dir' => \FloCMS\Admin\Translation\Languages::direction($code)];
        }
        $library = \FloCMS\Admin\Media\MediaLibrary::class;
        $urls = [];
        foreach (['lookup', 'details', 'save', 'move', 'folders', 'delete', 'upload', 'video', 'pending', 'replace'] as $route) { $urls[$route] = Admin::url('media/' . $route); }
        $urls['page'] = Admin::url('media');
        return (string) json_encode([
            'urls' => $urls,
            'csrf' => \FloCMS\Core\Csrf::token(),
            'maxImage' => $library::maxImageBytes(), 'maxVideo' => $library::maxVideoBytes(),
            'chunk' => \FloCMS\Admin\Media\MediaFiles::CHUNK_BYTES,
            'can' => ['create' => $library::can('create'), 'update' => $library::can('update'), 'delete' => $library::can('delete')],
            'languages' => $languages, 'defaultLanguage' => \FloCMS\Admin\Translation\Languages::default(),
            'strings' => $strings,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Uppercase first letter for the logo mark and avatar; empty when there is no text. */
    private static function initial(string $text): string
    {
        $text = trim($text);
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1));
    }

    /**
     * Where the current page sits: Dashboard; Site / Users; Content / Posts; Content / Posts / New post.
     *
     * @return list<array{label: string, url: ?string, current: bool}>
     */
    private function breadcrumbs(string $title, ?string $activeSlug): array
    {
        $items = $this->trail($title, $activeSlug);
        $last = array_key_last($items);
        foreach ($items as $index => $item) { $items[$index]['current'] = $index === $last; }
        return $items;
    }

    /** @return list<array{label: string, url: ?string}> */
    private function trail(string $title, ?string $activeSlug): array
    {
        if ($activeSlug === null || in_array($activeSlug, ['dashboard', 'profile'], true)) {
            return [['label' => $title, 'url' => null]];
        }
        if ($activeSlug === 'settings') {
            $items = [['label' => AdminLang::get('nav.site'), 'url' => null], ['label' => AdminLang::get('settings.title'), 'url' => null]];
            if ($title !== AdminLang::get('settings.title')) { $items[] = ['label' => $title, 'url' => null]; }
            return $items;
        }
        if ($activeSlug === 'media') {
            $items = [['label' => AdminLang::get('nav.resources'), 'url' => null], ['label' => AdminLang::get('media.title'), 'url' => $title === AdminLang::get('media.title') ? null : Admin::url('media')]];
            if ($title !== AdminLang::get('media.title')) { $items[] = ['label' => $title, 'url' => null]; }
            return $items;
        }
        if ($activeSlug === 'accounts') {
            return [['label' => AdminLang::get('nav.site'), 'url' => null], ['label' => $title, 'url' => null]];
        }
        $class = Admin::registry()->find($activeSlug);
        if ($class === null) {
            return [['label' => $title, 'url' => null]];
        }
        /** @var class-string<Resource> $class */
        $plural = $class::getPluralLabel();
        $items = [['label' => AdminLang::get('nav.resources'), 'url' => null]];
        if ($title === $plural) {
            $items[] = ['label' => $plural, 'url' => null];
        } else {
            $items[] = ['label' => $plural, 'url' => Admin::url($activeSlug)];
            $items[] = ['label' => $title, 'url' => null];
        }
        return $items;
    }

    /**
     * The current admin page in every site language. Switching language is an
     * ordinary page load (the language is part of the URL).
     *
     * @return list<array{code: string, name: string, url: string, current: bool}>
     */
    private static function languageLinks(string $current): array
    {
        $languages = Languages::all();
        if (count($languages) < 2) {
            return [];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '');
        $query = parse_url($uri, PHP_URL_QUERY);
        $rest = preg_match('~/admin(/[^?#]*)?$~', $path, $match) === 1 ? trim($match[1] ?? '', '/') : 'dashboard';
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        $links = [];
        foreach ($languages as $code) {
            $links[] = [
                'code' => $code,
                'name' => Languages::name($code),
                'url' => $base . '/' . rawurlencode($code) . '/admin' . ($rest !== '' ? '/' . $rest : '') . '?' . (is_string($query) && $query !== '' ? $query . '&' : '') . PanelController::SWITCH_MARKER . '=1',
                'current' => $code === $current,
            ];
        }
        return $links;
    }

    /** The site's lng.dir for the admin language, else the package's RTL list. */
    public static function direction(string $lang): string
    {
        $dir = Lang::get('lng.dir', '');
        if (is_string($dir) && in_array(strtolower($dir), ['rtl', 'ltr'], true)) {
            return strtolower($dir);
        }

        return Languages::direction($lang);
    }

}
