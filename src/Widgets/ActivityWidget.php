<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Activity\Activity, Activity\ActivityEntry, Activity\ActivityView, Admin, Support\AdminLang, Support\Avatar, Support\Dates, Support\Str};

/**
 * The latest changes from the activity log (0.42): who created, edited,
 * deleted or restored what, newest first; sign-ins and password events are
 * left out. Shown to people who may open the Activity page (activity.view)
 * while the log is on. Register it as it is, or extend it to change $limit:
 *
 *     'widgets' => [..., FloCMS\Admin\Widgets\ActivityWidget::class],
 */
class ActivityWidget extends Widget
{
    protected string $title = 'widgets.activity';
    /** How many changes it shows, 1–20. */
    protected int $limit = 5;
    /** Actions that change content (the log also records sign-ins and security events). */
    private const CONTENT = ['created', 'updated', 'deleted', 'purged', 'restored', 'action', 'bulk_action', 'reordered', 'settings_saved', 'page_saved',
        'media_uploaded', 'media_updated', 'media_replaced', 'media_deleted', 'media_moved', 'role_created', 'role_updated', 'role_deleted', 'role_reset'];
    public function canView(): bool { return Activity::canView(); }
    public function link(WidgetContext $context): ?array { return ['label' => AdminLang::get('widgets.see_all'), 'url' => Admin::url('activity')]; }
    final public function render(WidgetContext $context, string $id): string
    {
        $limit = max(1, min(20, $this->limit));
        $entries = array_values(array_filter(Activity::entries([], 1, 40)['items'], static fn (ActivityEntry $entry): bool => in_array($entry->action, self::CONTENT, true)));
        $entries = array_slice($entries, 0, $limit);
        if ($entries === []) { return '<p class="fa-muted">' . Str::e(AdminLang::get('widgets.no_activity')) . '</p>'; }
        Avatar::preload(array_map(static fn (ActivityEntry $entry): int => (int) $entry->userId, $entries));
        ActivityEntry::preloadSources($entries);
        $html = '';
        foreach ($entries as $entry) {
            $describe = $entry->describe();
            $target = $describe['target'] === '' ? '' : ($describe['url'] !== null ? '<a href="' . Str::e($describe['url']) . '" dir="auto">' . Str::e($describe['target']) . '</a>' : '<b dir="auto">' . Str::e($describe['target']) . '</b>');
            $html .= '<li class="fa-feed-item">' . ActivityView::face($entry->userId, $entry->who(), 'fa-avatar-sm') . '<span class="fa-feed-text"><span><b dir="auto">' . Str::e($entry->who()) . '</b> ' . Str::e(mb_strtolower($describe['verb'])) . ' ' . $target . '</span>'
                . '<time datetime="' . Str::e(date('c', $entry->time())) . '" title="' . Str::e(Dates::full($entry->time())) . '">' . Str::e(Dates::relative($entry->time())) . '</time></span></li>';
        }
        return '<ul class="fa-feed">' . $html . '</ul>';
    }
}
