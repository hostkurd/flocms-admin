<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;

/**
 * The "Editing: English · Arabic 7/9 · Kurdish 0/9" switch of page-level
 * translation (0.23): segmented buttons for up to four languages, a select
 * for more. Each language shows how many translatable fields are filled and
 * how many errors it has; "Next missing" jumps to the next empty field.
 *
 * The script (resources/js/translations.ts) shows the switch and keeps the
 * counts live. Without JavaScript it stays hidden and every language's input
 * is shown with its code. In a Reactive dialog (which only exists with
 * JavaScript) the server renders the switch already shown ($active), so the
 * page morphs into the same state the script would set.
 */
final class LanguageSwitch
{
    /** Segmented buttons for this many languages at most; a select above. */
    public const BUTTONS_MAX = 4;

    /**
     * @param list<string> $languages default language first
     * @param array{total: int, filled: array<string, int>, errors: array<string, int>} $stats from FormRenderer::languageStats()
     * @param 'buttons'|'select'|null $style null: buttons for up to four languages
     * @param array<string, string> $selectAttributes extra attributes of the select (settings pages keep their own hooks)
     */
    public static function render(string $id, array $languages, string $default, string $editing, array $stats, bool $active = false,
        ?string $style = null, array $selectAttributes = []): string
    {
        if (count($languages) < 2) { return ''; }
        $style ??= count($languages) <= self::BUTTONS_MAX ? 'buttons' : 'select';
        $total = max(0, $stats['total']);
        $t = static fn (string $key, array $replace = []): string => AdminLang::get($key, $replace);
        $data = ' data-lang-switch data-admin-untracked data-total="' . $total . '"'
            . ' data-count-text="' . Str::e($t('translations.count', ['filled' => ':filled', 'total' => ':total'])) . '"'
            . ' data-errors-text="' . Str::e($t('translations.errors', ['count' => ':count'])) . '"'
            . ($active ? '' : ' hidden');
        $next = '<button type="button" class="fa-next-missing" data-next-missing' . (self::showsNext($editing, $default, $stats) ? '' : ' hidden') . '>'
            . '<span>' . Str::e($t('translations.next_missing')) . '</span>' . Icons::svg('chevron-right', 'fa-icon fa-flip-rtl') . '</button>';

        if ($style === 'select') {
            $options = '';
            foreach ($languages as $lang) {
                $name = Languages::name($lang) . ($lang === $default ? ' · ' . $t('translations.default_language') : '');
                $filled = (int) ($stats['filled'][$lang] ?? 0);
                $errors = (int) ($stats['errors'][$lang] ?? 0);
                $options .= '<option value="' . Str::e($lang) . '" lang="' . Str::e($lang) . '" data-name="' . Str::e($name) . '"' . ($lang === $editing ? ' selected' : '') . '>'
                    . Str::e($name . ($total > 0 ? ' · ' . $filled . '/' . $total : '') . ($errors > 0 ? ' · ' . $t('translations.errors', ['count' => $errors]) : '')) . '</option>';
            }
            $attributes = '';
            foreach ($selectAttributes as $key => $value) { $attributes .= ' ' . Str::e($key) . '="' . Str::e($value) . '"'; }
            return '<div class="fa-lang-switch is-select" id="' . Str::e($id) . '"' . $data . '>'
                . '<label class="fa-editing"><span class="fa-editing-label">' . Str::e($t('translations.editing')) . '</span>'
                . '<select class="fa-input fa-select fa-editing-select" data-lang-select aria-describedby="' . Str::e($id . '-help') . '"' . $attributes . '>' . $options . '</select></label>'
                . '<span class="fa-sr" id="' . Str::e($id . '-help') . '">' . Str::e($t('translations.editing_help')) . '</span>' . $next . '</div>';
        }

        $buttons = '';
        foreach ($languages as $lang) {
            $filled = (int) ($stats['filled'][$lang] ?? 0);
            $errors = (int) ($stats['errors'][$lang] ?? 0);
            $name = Languages::name($lang);
            $status = $total > 0 ? $t('translations.count', ['filled' => $filled, 'total' => $total]) : '';
            if ($errors > 0) { $status .= ($status !== '' ? '. ' : '') . $t('translations.errors', ['count' => $errors]); }
            $buttons .= '<button type="button" class="fa-seg-btn' . ($lang === $default ? ' is-default' : '') . '" data-lang="' . Str::e($lang) . '" aria-pressed="' . ($lang === $editing ? 'true' : 'false') . '"'
                . ($lang === $default ? ' title="' . Str::e($name . ' · ' . $t('translations.default_language')) . '"' : '') . '>'
                . ($total > 0 ? self::ring($filled, $total) : '')
                . '<span class="fa-seg-name" lang="' . Str::e($lang) . '">' . Str::e($name) . '</span>'
                . ($lang === $default ? '<span class="fa-sr"> · ' . Str::e($t('translations.default_language')) . '</span>' : '')
                . ($total > 0 ? '<span class="fa-seg-count" data-lang-count aria-hidden="true">' . $filled . '/' . $total . '</span>' : '')
                . '<span class="fa-seg-err" data-lang-errors aria-hidden="true"' . ($errors > 0 ? '' : ' hidden') . '>' . $errors . '</span>'
                . '<span class="fa-sr" data-lang-status>' . ($status !== '' ? ', ' . Str::e($status) : '') . '</span></button>';
        }

        return '<div class="fa-lang-switch" id="' . Str::e($id) . '"' . $data . '>'
            . '<span class="fa-lang-switch-label" id="' . Str::e($id . '-label') . '">' . Str::e($t('translations.editing')) . '</span>'
            . '<span class="fa-seg" role="group" aria-labelledby="' . Str::e($id . '-label') . '" aria-describedby="' . Str::e($id . '-help') . '">' . $buttons . '</span>'
            . '<span class="fa-sr" id="' . Str::e($id . '-help') . '">' . Str::e($t('translations.editing_help')) . '</span>' . $next . '</div>';
    }

    /** The "You are editing the Arabic version…" note: the script shows it outside the default language. */
    public static function otherNote(string $default, string $editing, bool $active, bool $hasShared): string
    {
        $template = AdminLang::get('translations.editing_other', ['language' => ':language', 'default' => Languages::name($default)]);
        $shown = $active && $hasShared && $editing !== $default;
        return '<p class="fa-alert fa-alert-info fa-lang-other" data-lang-other data-template="' . Str::e($template) . '"' . ($shown ? '' : ' hidden') . '>'
            . ($shown ? Str::e(str_replace(':language', Languages::name($editing), $template)) : '') . '</p>';
    }

    /** A progress ring: full (green), partial or empty. */
    private static function ring(int $filled, int $total): string
    {
        $percent = $total > 0 ? (int) round(100 * min($filled, $total) / $total) : 0;
        $state = $filled >= $total ? 'is-full' : ($filled === 0 ? 'is-empty' : 'is-partial');
        return '<svg class="fa-ring ' . $state . '" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle class="fa-ring-track" cx="8" cy="8" r="6"/>'
            . '<circle class="fa-ring-fill" cx="8" cy="8" r="6" pathLength="100" stroke-dasharray="' . $percent . ' 100" transform="rotate(-90 8 8)"/></svg>';
    }

    /** @param array{total: int, filled: array<string, int>, errors: array<string, int>} $stats */
    private static function showsNext(string $editing, string $default, array $stats): bool
    {
        return $editing !== $default && (int) ($stats['filled'][$editing] ?? 0) < $stats['total'];
    }
}
