<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Admin, Support\AdminLang, Support\Dates, Support\Str};

/** Rebuild the registry and permission gates on every mount or refresh. */
final class Dashboard
{
    private static function log(string $message): void { try { \FloCMS\Core\App::logger()->log('error', '[flocms-admin] ' . $message); } catch (\Throwable) {} }
    /**
     * The widgets the person may see, as grid items (also on panel pages, 0.36).
     *
     * @param list<Widget> $widgets
     */
    public static function items(array $widgets, int $columns, string $idPrefix, ?Period $period = null): string
    {
        $items = '';
        foreach ($widgets as $index => $widget) {
            // No title or data provider executes for a denied widget.
            try { if (!$widget->authorized()) { continue; } }
            catch (\Throwable $error) { self::log('Admin widget authorization failed: ' . $error->getMessage()); continue; }
            $id = $idPrefix . $index;
            $context = new WidgetContext($widget->resources(), $period);
            $subtitle = ''; $link = null;
            try {
                $content = $widget->render($context, $id);
                $subtitle = $widget->headerSubtitle($context);
                $link = $widget->headerLink($context);
            } catch (\Throwable $error) {
                self::log('Admin widget ' . $widget::class . ' failed: ' . $error->getMessage());
                $content = '<p class="fa-muted" role="status">' . Str::e(AdminLang::get('widgets.unavailable')) . '</p>';
            }
            $span = $widget->columnSpan() === 'full' ? $columns : min($columns, $widget->columnSpan());
            $kind = self::kind($widget);
            // Statistics shown as separate cards (0.42): no frame or visible title of their own.
            if ($widget instanceof StatsWidget && $widget->separateCards() && !str_starts_with($content, '<p')) {
                $items .= '<section class="fa-widget-cards fa-widget-span-' . $span . '" aria-labelledby="' . $id . '-title" data-admin-widget><h2 class="fa-sr" id="' . $id . '-title">' . Str::e($widget->title()) . '</h2>' . $content . '</section>';
                continue;
            }
            $head = '<div class="fa-widget-head"><h2 class="fa-widget-title" id="' . $id . '-title">' . Str::e($widget->title()) . '</h2>'
                . ($subtitle !== '' ? '<span class="fa-widget-sub">' . Str::e($subtitle) . '</span>' : '')
                . (is_array($link) && is_string($link['url'] ?? null) && is_string($link['label'] ?? null) && self::safeUrl($link['url'])
                    ? '<a class="fa-widget-more" href="' . Str::e($link['url']) . '">' . Str::e(AdminLang::label($link['label'])) . \FloCMS\Admin\Support\Icons::svg('arrow-right', 'fa-icon fa-icon-sm fa-flip-rtl') . '</a>' : '')
                . '</div>';
            $items .= '<section class="fa-card fa-widget fa-widget-type-' . $kind . ' fa-widget-span-' . $span . '" aria-labelledby="' . $id . '-title" data-admin-widget>' . $head . $content . '</section>';
        }
        return $items;
    }

    /** A CSS name for the widget's type (0.42). */
    private static function kind(Widget $widget): string
    {
        return match (true) {
            $widget instanceof StatsWidget => 'stats', $widget instanceof RecordsWidget => 'records', $widget instanceof ChartWidget => 'chart',
            $widget instanceof BarListWidget => 'bars', $widget instanceof ProgressWidget => 'progress', $widget instanceof ListWidget => 'list',
            $widget instanceof ActivityWidget => 'activity', default => 'other',
        };
    }

    /** Links in widgets stay on this site: a path from the root or this site's address (0.42). */
    public static function safeUrl(string $url): bool
    {
        // Browsers drop tabs and line breaks from addresses, so "/\t/evil.com" would become "//evil.com"
        if (preg_match('/[\x00-\x20\x7F]/', $url)) { return false; }
        if (str_starts_with($url, '/') &&!str_starts_with($url, '//') && !str_contains($url, '\\')) { return true; }
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        return $base !== '' && ($url === $base || str_starts_with($url, $base . '/') || str_starts_with($url, $base . '?'));
    }

    public static function render(bool $reactive = false, ?Period $period = null): string
    {
        $period ??= Period::last(Admin::config()->dashboardPeriod());
        $registry = WidgetRegistry::fromConfig(Admin::config());
        try { $columns = Admin::config()->widgetColumns(); } catch (\InvalidArgumentException) { $columns = 2; }
        $items = self::items($registry->all(), $columns, 'fa-dashboard-widget-', $period);
        $notice = '';
        if ($registry->problems() !== []) {
            foreach ($registry->problems() as $problem) { self::log('Admin widget configuration: ' . $problem); }
            $notice = '<p class="fa-muted" role="status">' . Str::e(AdminLang::get('widgets.unavailable')) . '</p>';
        }
        return self::head($period, $reactive) . $notice . ($items !== '' ? '<div class="fa-widget-grid fa-widget-grid-' . $columns . '">' . $items . '</div>' : '<p class="fa-card fa-blank" role="status">' . Str::e(AdminLang::get('widgets.none')) . '</p>');
    }

    /**
     * The dashboard's header (0.42): "Good afternoon, Sara", today's date, the
     * period buttons and Refresh (with widgets), and the quick buttons.
     * $period null: no period buttons or Refresh (the dashboard without widgets).
     */
    public static function head(?Period $period, bool $reactive = false): string
    {
        $full = trim((string) (\FloCMS\Core\Session::get('fullname') ?: \FloCMS\Core\Session::get('email') ?: ''));
        $first = $full === '' ? '' : (preg_split('/\s+/u', $full)[0] ?? $full);
        $hour = (int) date('G');
        $part = $hour < 5 ? 'evening' : ($hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening'));
        $hello = $first === '' ? AdminLang::get('dashboard.hello_' . $part . '_anonymous') : AdminLang::get('dashboard.hello_' . $part, ['name' => $first]);
        $host = defined('SITE_URI') ? (string) parse_url((string) constant('SITE_URI'), PHP_URL_HOST) : '';
        $today = Dates::weekday(time());
        $line = $host === '' ? $today : AdminLang::get('dashboard.overview', ['date' => $today, 'site' => $host]);
        $tools = '';
        if ($period !== null) {
            $buttons = '';
            foreach (Admin::config()->dashboardPeriods() as $days) {
                $option = Period::last($days);
                $current = $days === $period->days();
                $buttons .= '<a class="fa-period' . ($current ? ' is-current' : '') . '" href="' . Str::e(Admin::url('dashboard', ['period' => $days])) . '"' . ($current ? ' aria-current="true"' : '') . '>' . Str::e($option->short()) . '</a>';
            }
            $tools = '<div class="fa-dash-tools">' . ($buttons !== '' ? '<nav class="fa-periods" aria-label="' . Str::e(AdminLang::get('widgets.period')) . '">' . $buttons . '</nav>' : '')
                . '<form class="fa-widget-toolbar" method="get" action="' . Str::e(Admin::url('dashboard')) . '"' . ($reactive ? ' data-rx-submit="refresh"' : '') . '>'
                . '<input type="hidden" name="period" value="' . $period->days() . '">'
                . ($reactive ? '<span class="fa-loading" data-rx-loading>' . Str::e(AdminLang::get('reactive.loading')) . '</span>' : '')
                . '<button type="submit" class="fa-btn fa-icon-btn fa-dash-refresh" id="fa-dashboard-refresh" aria-label="' . Str::e(AdminLang::get('widgets.refresh')) . '" title="' . Str::e(AdminLang::get('widgets.refresh')) . '">' . \FloCMS\Admin\Support\Icons::svg('refresh') . '</button></form></div>';
        }
        $actions = self::actions();
        return '<div class="fa-page-head fa-dash-head"><div><h1 class="fa-page-title">' . Str::e($hello) . '</h1><p class="fa-muted fa-subtitle">' . Str::e($line) . '</p></div>' . $tools . '</div>'
            . ($actions !== '' ? '<div class="fa-dash-actions">' . $actions . '</div>' : '');
    }

    /** The quick buttons the person may use (config 'dashboard_actions'). */
    private static function actions(): string
    {
        $html = '';
        foreach (Admin::config()->dashboardActions() as $action) {
            try {
                $class = $action['class'];
                $url = null; $label = null; $icon = $action['icon'];
                if (is_subclass_of($class, \FloCMS\Admin\Resource::class)) {
                    $registered = Admin::registry()->find($class::getSlug());
                    if ($registered !== $class || !\FloCMS\Core\Auth::can($class::permission('create')) || !(new $class())->canCreate()) { continue; }
                    $url = Admin::url($class::getSlug() . '/create');
                    $label = AdminLang::get('actions.create_label', ['label' => $class::getLabel()]);
                    $icon ??= 'plus';
                } elseif (is_subclass_of($class, \FloCMS\Admin\AdminPage::class)) {
                    if (Admin::panelPages()->findClass($class) === null || !(new $class())->allowed()) { continue; }
                    $url = $class::url();
                    $label = $class::getLabel();
                    $icon ??= $class::getIcon();
                } else {
                    self::log('dashboard_actions: ' . $class . ' is not a resource or panel page');
                    continue;
                }
                $label = $action['label'] !== null ? AdminLang::label($action['label']) : $label;
                $html .= '<a class="fa-btn' . ($html === '' ? ' fa-btn-primary' : '') . '" href="' . Str::e($url) . '">' . \FloCMS\Admin\Support\Icons::svg(in_array($icon, \FloCMS\Admin\Support\Icons::names(), true) ? $icon : 'plus') . '<span>' . Str::e($label) . '</span></a>';
            } catch (\Throwable $error) {
                self::log('dashboard_actions: ' . $error->getMessage());
            }
        }
        return $html;
    }
}
