<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Admin, Support\AdminLang, Support\Str};

/** Rebuild the registry and permission gates on every mount or refresh. */
final class Dashboard
{
    private static function log(string $message): void { try { \FloCMS\Core\App::logger()->log('error', '[flocms-admin] ' . $message); } catch (\Throwable) {} }
    /**
     * The widgets the person may see, as grid items (also on panel pages, 0.36).
     *
     * @param list<Widget> $widgets
     */
    public static function items(array $widgets, int $columns, string $idPrefix): string
    {
        $items = '';
        foreach ($widgets as $index => $widget) {
            // No title or data provider executes for a denied widget.
            try { if (!$widget->authorized()) { continue; } }
            catch (\Throwable $error) { self::log('Admin widget authorization failed: ' . $error->getMessage()); continue; }
            $id = $idPrefix . $index;
            try { $content = $widget->render(new WidgetContext($widget->resources()), $id); }
            catch (\Throwable $error) {
                self::log('Admin widget ' . $widget::class . ' failed: ' . $error->getMessage());
                $content = '<p class="fa-muted" role="status">' . Str::e(AdminLang::get('widgets.unavailable')) . '</p>';
            }
            $span = $widget->columnSpan() === 'full' ? $columns : min($columns, $widget->columnSpan());
            $items .= '<section class="fa-card fa-widget fa-widget-span-' . $span . '" aria-labelledby="' . $id . '-title" data-admin-widget><h2 class="fa-widget-title" id="' . $id . '-title">' . Str::e($widget->title()) . '</h2>' . $content . '</section>';
        }
        return $items;
    }

    public static function render(bool $reactive = false): string
    {
        $registry = WidgetRegistry::fromConfig(Admin::config());
        try { $columns = Admin::config()->widgetColumns(); } catch (\InvalidArgumentException) { $columns = 2; }
        $items = self::items($registry->all(), $columns, 'fa-dashboard-widget-');
        $notice = '';
        if ($registry->problems() !== []) {
            foreach ($registry->problems() as $problem) { self::log('Admin widget configuration: ' . $problem); }
            $notice = '<p class="fa-muted" role="status">' . Str::e(AdminLang::get('widgets.unavailable')) . '</p>';
        }
        $form = '<form class="fa-widget-toolbar" method="get" action="' . Str::e(Admin::url('dashboard')) . '"' . ($reactive ? ' data-rx-submit="refresh"' : '') . '>' . ($reactive ? '<span class="fa-loading" data-rx-loading>' . Str::e(AdminLang::get('reactive.loading')) . '</span>' : '')
            . '<button type="submit" class="fa-btn fa-btn-sm" id="fa-dashboard-refresh">' . \FloCMS\Admin\Support\Icons::svg('refresh') . '<span>' . Str::e(AdminLang::get('widgets.refresh')) . '</span></button></form>';
        return $form . $notice . ($items !== '' ? '<div class="fa-widget-grid fa-widget-grid-' . $columns . '">' . $items . '</div>' : '<p class="fa-card fa-blank" role="status">' . Str::e(AdminLang::get('widgets.none')) . '</p>');
    }
}
