<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Activity\ActivityEntry;
use FloCMS\Admin\Activity\ActivityView;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Dates;
use FloCMS\Admin\Support\Icons;
use FloCMS\Core\Auth;
use FloCMS\Core\HttpException;
use FloCMS\Core\TemplateEngine;

/**
 * Site → Activity (/admin/activity, permission activity.view): who changed what,
 * filtered by person, type, action and date; and /admin/activity/show/<id>, the
 * before → after table of one entry (a dialog, or a page without JavaScript),
 * which people who may edit that record can open from its History too.
 */
final class ActivityController extends PanelController
{
    public const PER_PAGE = 50;
    private const PERIODS = ['today' => 0, '7' => 7, '30' => 30, '365' => 365, 'all' => null];

    protected array $actionPermissions = ['admin_index' => Activity::PERMISSION, 'admin_show' => null];

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        if (!Activity::enabled()) { throw new HttpException(404); }
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $filters = $this->filters();
        $page = max(1, min(100000, (int) $this->text('page', '1')));
        $result = Activity::entries([
            'user' => $filters['by'] !== '' ? (int) $filters['by'] : null,
            'resource' => $filters['type'] !== '' ? $filters['type'] : null,
            'group' => $filters['kind'] !== '' ? $filters['kind'] : null,
            'since' => self::since($filters['period']),
            'search' => $filters['q'],
        ], $page, self::PER_PAGE);
        $last = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        $query = array_filter($filters, static fn (string $v): bool => $v !== '');

        $groups = [];
        foreach ($result['items'] as $entry) {
            $key = date('Y-m-d', $entry->time());
            if (!isset($groups[$key])) { $groups[$key] = ['label' => Dates::day($entry->time()), 'rows' => []]; }
            $describe = $entry->describe();
            $groups[$key]['rows'][] = [
                'time' => Dates::time($entry->time()),
                'who' => $entry->who(), 'initial' => $entry->initial(), 'avatar' => ActivityView::avatarClass($entry->userId),
                'badge' => $entry->badge(), 'badgeColor' => $entry->badgeColor(),
                'verb' => $describe['verb'], 'target' => $describe['target'], 'url' => $describe['url'],
                'fields' => $entry->fieldNames(),
                'showUrl' => $entry->hasChanges() ? Admin::url('activity/show/' . $entry->id) : null,
            ];
        }

        $people = [];
        foreach (Activity::people() as $id => $name) { $people[] = ['value' => (string) $id, 'label' => $name, 'selected' => $filters['by'] === (string) $id]; }
        $types = [];
        foreach (self::types() as $value => $label) { $types[] = ['value' => $value, 'label' => $label, 'selected' => $filters['type'] === $value]; }
        $periods = [];
        foreach (['today' => 'activity.period_today', '7' => 'activity.period_7', '30' => 'activity.period_30', '365' => 'activity.period_365', 'all' => 'activity.period_all'] as $value => $key) {
            $periods[] = ['value' => (string) $value, 'label' => AdminLang::get($key), 'selected' => $filters['period'] === (string) $value];
        }
        $actions = [];
        foreach (['' => 'activity.group_all', 'created' => 'activity.group_created', 'edited' => 'activity.group_edited', 'deleted' => 'activity.group_deleted', 'restored' => 'activity.group_restored', 'other' => 'activity.group_other'] as $value => $key) {
            $actions[] = ['label' => AdminLang::get($key), 'url' => Admin::url('activity', array_replace($query, ['kind' => $value !== '' ? $value : null, 'page' => null])), 'current' => $filters['kind'] === (string) $value];
        }

        $total = $result['total'];
        $from = $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1;
        $html = TemplateEngine::renderFile(PageView::viewPath('activity'), [
            't' => PageView::translator(),
            'title' => AdminLang::get('activity.title'),
            'groups' => array_values($groups),
            'filters' => $filters,
            'people' => $people, 'types' => $types, 'periods' => $periods, 'actions' => $actions,
            'listUrl' => Admin::url('activity'),
            'clearUrl' => Admin::url('activity', array_diff_key($query, ['q' => 1])),
            'hasFilters' => array_diff_key($query, ['q' => 1, 'period' => 1]) !== [] || $filters['period'] !== '7',
            'resetUrl' => Admin::url('activity'),
            'summary' => $total === 0 ? '' : AdminLang::get('table.summary', ['from' => $from, 'to' => min($total, $page * self::PER_PAGE), 'total' => $total]),
            'pages' => array_map(static fn (?int $n): array => $n === null
                ? ['label' => '…', 'url' => null, 'current' => false]
                : ['label' => (string) $n, 'url' => $n === $page ? null : Admin::url('activity', ['page' => $n > 1 ? $n : null] + $query), 'current' => $n === $page], PageView::pageNumbers($page, $last)),
            'kept' => Activity::days() > 0 ? AdminLang::get('activity.kept_days', ['days' => Activity::days()]) : AdminLang::get('activity.kept_forever'),
            'searchIcon' => Icons::svg('search'), 'removeIcon' => Icons::svg('x'),
        ]);
        return $this->panel(AdminLang::get('activity.title'), $html, 'activity');
    }

    /** The changes of one entry: a dialog's content (X-Flo-Admin: fragment) or a page. */
    public function admin_show(): string|\FloCMS\Core\Http\Response
    {
        if (!Activity::enabled()) { throw new HttpException(404); }
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $id = (string) ($this->params[0] ?? '');
        if (!ctype_digit($id) || strlen($id) > 18) { throw new HttpException(404); }
        $entry = Activity::find((int) $id) ?? throw new HttpException(404);
        if (!self::maySee($entry)) { throw new HttpException(403); }
        if ($this->request->header('X-Flo-Admin') === 'fragment') {
            return \FloCMS\Core\Http\Response::html(ActivityView::changes($entry, true))->header('Cache-Control', 'no-store')->header('Vary', 'X-Flo-Admin');
        }
        return $this->panel(AdminLang::get('activity.changes'), ActivityView::changes($entry, false), Auth::can(Activity::PERMISSION) ? 'activity' : $entry->resource);
    }

    /** Anyone with activity.view; else whoever may edit that resource's records (its History). */
    public static function maySee(ActivityEntry $entry): bool
    {
        if (Auth::can(Activity::PERMISSION)) { return true; }
        $class = $entry->resourceClass();
        return $class !== null && Auth::can($class::permission('update'));
    }

    /** @return array{q: string, by: string, type: string, kind: string, period: string} */
    private function filters(): array
    {
        $by = $this->text('by', '');
        $type = $this->text('type', '');
        $action = $this->text('kind', '');
        $period = $this->text('period', '7');
        return [
            'q' => mb_substr($this->text('q', ''), 0, 100),
            'by' => ctype_digit($by) ? $by : '',
            'type' => array_key_exists($type, self::types()) ? $type : '',
            'kind' => in_array($action, ActivityEntry::GROUPS, true) ? $action : '',
            'period' => array_key_exists($period, self::PERIODS) ? $period : '7',
        ];
    }

    /** @return array<string, string> resource value => label: those that appear in the log */
    private static function types(): array
    {
        $types = [];
        foreach (Activity::resources() as $value) {
            $class = Admin::registry()->find($value);
            $types[$value] = match (true) {
                $class !== null => $class::getPluralLabel(),
                $value === 'settings' => AdminLang::get('settings.title'),
                $value === 'media' => AdminLang::get('media.title'),
                default => $value,
            };
        }
        asort($types, SORT_NATURAL | SORT_FLAG_CASE);
        return $types;
    }

    private static function since(string $period): ?string
    {
        $days = self::PERIODS[$period] ?? null;
        if ($days === null) { return null; }
        return $days === 0 ? date('Y-m-d 00:00:00') : date('Y-m-d H:i:s', time() - $days * 86400);
    }

    private function text(string $key, string $default): string
    {
        $value = $this->request->queryValue($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }
}
