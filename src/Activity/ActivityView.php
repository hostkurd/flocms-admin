<?php
declare(strict_types=1);

namespace FloCMS\Admin\Activity;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Http\PageView;
use FloCMS\Admin\Http\PanelView;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Dates;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\TemplateEngine;

/**
 * Renders the History list of a record and the Changes table of one entry.
 *
 * @internal
 */
final class ActivityView
{
    /** Changes shown under an entry in History; the rest open in the Changes dialog. */
    public const INLINE = 3;

    /** The History list (the drawer on a record's page, and its full-page fallback). */
    public static function history(string $title, array $entries, bool $drawer): string
    {
        return TemplateEngine::renderFile(PageView::viewPath('history'), [
            't' => PageView::translator(),
            'title' => $title,
            'days' => self::days($entries),
            'drawer' => $drawer,
            'kept' => Activity::days() > 0 ? AdminLang::get('activity.kept_days', ['days' => Activity::days()]) : AdminLang::get('activity.kept_forever'),
            'closeIcon' => Icons::svg('x'),
        ]);
    }

    /**
     * Entries grouped by day.
     *
     * @param list<ActivityEntry> $entries
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public static function days(array $entries): array
    {
        $days = [];
        $arrow = self::arrow();
        foreach ($entries as $entry) {
            $key = date('Y-m-d', $entry->time());
            if (!isset($days[$key])) { $days[$key] = ['label' => Dates::day($entry->time()), 'items' => []]; }
            $inline = [];
            foreach (array_slice($entry->changes, 0, self::INLINE) as $change) {
                $row = self::row($change);
                $inline[] = ['label' => $row['label'] . ($row['sub'] !== '' ? ' · ' . $row['sub'] : ''), 'old' => $row['inlineOld'], 'new' => $row['inlineNew'], 'arrow' => $row['inlineOld'] !== '' ? $arrow : ''];
            }
            $more = max(0, count($entry->changes) - self::INLINE);
            $days[$key]['items'][] = [
                'who' => $entry->who(), 'initial' => $entry->initial(), 'avatar' => self::avatarClass($entry->userId),
                'phrase' => $entry->phrase(), 'time' => Dates::time($entry->time()),
                'badge' => in_array($entry->action, ['created', 'deleted', 'restored', 'purged'], true) ? $entry->badge() : '',
                'badgeColor' => $entry->badgeColor(),
                'changes' => $inline, 'hasChanges' => $inline !== [] || $entry->details() !== [],
                'showUrl' => $entry->hasChanges() ? Admin::url('activity/show/' . $entry->id) : null,
                'showLabel' => $more > 0 ? AdminLang::get('activity.show_all_count', ['count' => count($entry->changes)]) : AdminLang::get('activity.show_changes'),
            ];
        }
        return array_values($days);
    }

    /** The Changes table of one entry (the dialog, and its full-page fallback). */
    public static function changes(ActivityEntry $entry, bool $dialog): string
    {
        $rows = array_map(static fn (array $change): array => self::row($change), $entry->changes);
        $describe = $entry->describe();
        return TemplateEngine::renderFile(PageView::viewPath('changes'), [
            't' => PageView::translator(),
            'title' => AdminLang::get('activity.changes_title', ['title' => $describe['target'] !== '' ? $describe['target'] : $entry->badge()]),
            'who' => $entry->who(), 'initial' => $entry->initial(), 'avatar' => self::avatarClass($entry->userId),
            'when' => Dates::full($entry->time()),
            'badge' => $entry->badge(), 'badgeColor' => $entry->badgeColor(),
            'reference' => self::reference($entry),
            'rows' => $rows,
            'details' => $entry->details(),
            'openUrl' => $describe['url'],
            'openLabel' => $entry->resourceClass() !== null ? AdminLang::get('activity.open_record', ['label' => mb_strtolower($entry->typeLabel())]) : AdminLang::get('activity.open'),
            'dialog' => $dialog,
            'closeIcon' => Icons::svg('x'),
            'backUrl' => Admin::url('activity'),
        ]);
    }

    /**
     * One change as table cells.
     *
     * @param array{field: string, label: string, lang: ?string, type: string, before: string, after: string} $change
     * @return array{label: string, sub: string, type: string, before: string, after: string, note: string, inlineOld: string, inlineNew: string, beforeEmpty: bool, afterEmpty: bool, lang: string, dir: string}
     */
    public static function row(array $change): array
    {
        $lang = $change['lang'] ?? null;
        $sub = $lang !== null && $lang !== '' ? Languages::name($lang) : '';
        $note = '';
        $before = $change['before'];
        $after = $change['after'];
        switch ($change['type']) {
            case 'hidden':
                $note = AdminLang::get('activity.hidden_value');
                $inlineOld = '';
                $inlineNew = AdminLang::get('activity.changed');
                break;
            case 'text':
                $note = AdminLang::get('activity.text_changed', ['before' => number_format((int) $before), 'after' => number_format((int) $after), 'arrow' => self::arrow()]);
                $inlineOld = '';
                $inlineNew = $note;
                break;
            case 'file':
                $key = $before === '' ? 'activity.file_added' : ($after === '' ? 'activity.file_removed' : 'activity.file_replaced');
                $note = AdminLang::get($key);
                $inlineOld = '';
                $inlineNew = $note;
                break;
            default:
                $inlineOld = $before;
                $inlineNew = $after !== '' ? $after : AdminLang::get('activity.empty');
                if ($inlineOld === '') { $inlineOld = AdminLang::get('activity.empty'); }
        }
        return [
            'label' => $change['label'] !== '' ? $change['label'] : $change['field'], 'sub' => $sub, 'type' => $change['type'],
            'before' => $before, 'after' => $after, 'note' => $note, 'inlineOld' => $inlineOld, 'inlineNew' => $inlineNew,
            'beforeEmpty' => $before === '', 'afterEmpty' => $after === '',
            'lang' => $lang ?? '', 'dir' => $lang !== null && $lang !== '' ? Languages::direction($lang) : '',
        ];
    }

    /** "Listing #42" */
    private static function reference(ActivityEntry $entry): string
    {
        $type = $entry->typeLabel();
        if ($entry->recordId === null || $entry->recordId === '' || $type === '') { return $type; }
        return $type . ' #' . $entry->recordId;
    }

    /** One of four avatar colours, the same for a person everywhere. */
    public static function avatarClass(?int $userId): string
    {
        return $userId === null ? 'fa-avatar-system' : ['', 'fa-avatar-2', 'fa-avatar-3', 'fa-avatar-4'][$userId % 4];
    }

    /** → in left-to-right languages, ← in right-to-left ones. */
    public static function arrow(): string
    {
        return PanelView::direction(AdminLang::language()) === 'rtl' ? '←' : '→';
    }
}
