<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Blocks\Core\Columns;
use FloCMS\Admin\Form\Fields\Builder;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;

/**
 * The editor of a Builder field (0.29): the structure of the page on the
 * left (one row per block, nested in Sections and Columns) and, on the
 * right, the selected block's fields (its tabs, then Settings).
 *
 * Every block's fields are in the page, so the form posts them all; each
 * block also posts its type, parent, column and place. Without JavaScript
 * every block's fields are shown one after another, the Add buttons and the
 * Up, Down and Remove buttons submit the form, and the server shows it
 * again, changed. With JavaScript (resources/js/builder.ts) a row selects
 * its block, blocks are added from a picker, dragged or moved with
 * Alt + arrow keys, duplicated and copied to another language at once.
 */
final class BuilderRenderer
{
    private string $id;
    /** @var array<string, array<string, mixed>> */
    private array $ctx;

    public function __construct(
        private readonly FormRenderer $parent,
        private readonly Builder $field,
        private readonly string $state,
        private readonly array $errors,
    ) {
        $this->ctx = $parent->languageContext();
        $this->id = $this->ctx['prefix'] . 'f-' . $field->getName();
    }

    private static function t(string $key, array $replace = []): string
    {
        return AdminLang::get('blocks.' . $key, $replace);
    }

    public function render(): string
    {
        $field = $this->field;
        $name = $field->getName();
        $base = $this->parent->nameOf($name);
        $lists = $field->lists($this->state);
        $keys = $field->listKeys();
        $writable = $field->isWritable();
        $hasError = $this->hasErrors($name);

        $label = Str::e($field->getLabel());
        if ($field->isRequired()) { $label .= ' <span class="fa-required" aria-hidden="true">*</span>'; }
        $shared = $this->ctx['switch'] && !$field->isPerLanguage();
        $html = '<div class="fa-field fa-span-full fa-builder' . ($hasError ? ' has-error' : '') . '" id="' . Str::e($this->id) . '" data-field="' . Str::e($name) . '" data-builder'
            . ' data-name="' . Str::e($base) . '" data-max="' . $field->getMaxBlocks() . '"' . ($writable ? '' : ' data-fixed') . ($shared ? ' data-same-all' : '')
            . ' data-default-lang="' . Str::e($this->ctx['default']) . '"'
            . ' data-count-text="' . Str::e(self::t('count', ['count' => ':count'])) . '"'
            . ' data-moved-text="' . Str::e(self::t('moved', ['block' => ':block', 'position' => ':position'])) . '"'
            . ' data-removed-text="' . Str::e(self::t('removed', ['block' => ':block'])) . '"'
            . ' data-copied-text="' . Str::e(self::t('copied', ['language' => ':language'])) . '"'
            . ' data-column-text="' . Str::e(self::t('column', ['number' => ':number'])) . '"'
            . ' data-columns-text="' . Str::e(self::t('columns_count', ['count' => ':count'])) . '"'
            . ' data-add-column-text="' . Str::e(self::t('add_to_column', ['number' => ':number'])) . '"'
            . ' data-drag-text="' . Str::e(self::t('drag', ['block' => ':block'])) . '"'
            . ' data-undo-text="' . Str::e(self::t('undo')) . '"'
            . ' data-item-of-text="' . Str::e(self::t('item_of', ['number' => ':number', 'count' => ':count'])) . '"'
            . ' data-back-text="' . Str::e(self::t('back')) . '" data-previous-text="' . Str::e(self::t('previous')) . '" data-next-text="' . Str::e(self::t('next')) . '"'
            . ' data-state-draft="' . Str::e(self::t('state_draft')) . '" data-state-ended="' . Str::e(self::t('state_ended')) . '" data-state-from="' . Str::e(self::t('state_from', ['date' => ':date'])) . '"'
            . ' data-state-desktop="' . Str::e(self::t('state_desktop')) . '" data-state-phone="' . Str::e(self::t('state_phone')) . '" data-summary-items="' . Str::e(self::t('summary_items', ['count' => ':count'])) . '" data-summary-empty="' . Str::e(self::t('summary_empty')) . '"'
            . ' data-where-top="' . Str::e(self::t('add_where_top')) . '" data-where-after="' . Str::e(self::t('add_where_after', ['block' => ':block'])) . '" data-where-in="' . Str::e(self::t('add_where_in', ['block' => ':block'])) . '" data-where-column="' . Str::e(self::t('add_where_column', ['block' => ':block', 'number' => ':number'])) . '"'
            . ' data-changed-text="' . Str::e(self::t('changed')) . '" data-copied-block-text="' . Str::e(self::t('copied_block')) . '" data-pasted-text="' . Str::e(self::t('pasted', ['block' => ':block'])) . '" data-recent-text="' . Str::e(self::t('recent')) . '"'
            . ' data-fold-text="' . Str::e(self::t('fold', ['block' => ':block'])) . '" data-unfold-text="' . Str::e(self::t('unfold', ['block' => ':block'])) . '" data-inside-text="' . Str::e(self::t('inside_count', ['count' => ':count'])) . '" data-moved-column-text="' . Str::e(self::t('moved_column', ['count' => ':count', 'number' => ':number'])) . '"'
            . ' data-not-here-text="' . Str::e(self::t('not_here', ['block' => ':block'])) . '"'
            . ' data-move-to-text="' . Str::e(self::t('move_to')) . '" data-move-top-text="' . Str::e(self::t('move_top', ['block' => ':block'])) . '"'
            . ' data-full-text="' . Str::e(self::t('full', ['max' => (string) $field->getMaxBlocks()])) . '">';
        $html .= '<div class="fa-builder-head"><span class="fa-label" id="' . Str::e($this->id) . '-label">' . $label . '</span></div>';
        if (($help = $field->getHelp()) !== null) { $html .= '<p class="fa-help" id="' . Str::e($this->id) . '-help">' . Str::e($help) . '</p>'; }
        if ($shared) {
            $html .= '<p class="fa-same-note is-line" hidden>' . Icons::svg('lock', 'fa-icon fa-same-icon') . '<span>' . Str::e(AdminLang::get('translations.same_note', ['language' => Languages::name($this->ctx['default'])])) . '</span></p>';
        }
        // Marks the field as sent even when every block was removed.
        $html .= '<input type="hidden" name="' . Str::e($base) . '[_sent]" value="1">';
        // How many blocks each list sends, first, so a request the server cut short is noticed (builder.ts keeps it up to date).
        foreach ($keys as $key) {
            $html .= '<input type="hidden" name="' . Str::e($base) . '[_total][' . Str::e($key) . ']" value="' . Builder::count($lists[$key]) . '" data-builder-total="' . Str::e($key) . '">';
        }
        if (isset($this->errors[$name])) {
            $html .= '<p class="fa-error" role="alert" id="' . Str::e($this->id) . '-error">' . Str::e($this->errors[$name]) . '</p>';
        }

        $perLanguage = $field->isPerLanguage() && count($this->ctx['languages']) > 1;
        if ($perLanguage) {
            // Without a language switch on the page the editor has its own (builder.ts shows it).
            $html .= '<div class="fa-builder-langs" data-builder-langs hidden><div class="fa-seg" role="group" aria-label="' . Str::e(self::t('language')) . '">';
            foreach ($keys as $key) {
                $html .= '<button type="button" class="fa-seg-btn" data-builder-lang="' . Str::e($key) . '" aria-pressed="' . ($key === $this->ctx['editing'] ? 'true' : 'false') . '">'
                    . '<span class="fa-seg-name">' . Str::e(Languages::name($key)) . '</span><span class="fa-seg-count" data-builder-lang-count>' . Builder::count($lists[$key]) . '</span></button>';
            }
            $html .= '</div></div><div class="fa-lang-panes fa-builder-panes" data-lang-panes>';
        }
        foreach ($keys as $key) {
            $list = $this->list($key, $lists[$key], $lists);
            $html .= $perLanguage ? '<div class="fa-lang-pane' . ($key === $this->ctx['editing'] ? ' is-current' : '') . '" data-lang="' . Str::e($key) . '">'
                . '<span class="fa-lang-pane-code"><code class="fa-lang-code' . ($key === $this->ctx['default'] ? ' is-default' : '') . '">' . Str::e($key) . '</code> ' . Str::e(Languages::name($key)) . '</span>' . $list . '</div>' : $list;
        }
        if ($perLanguage) { $html .= '</div>'; }
        if ($writable) { $html .= $this->templates(); }
        $html .= $this->dialogs();
        $html .= '<p class="fa-sr" aria-live="polite" data-builder-live></p>';
        return $html . '</div>';
    }

    /** One list: its structure and the blocks' fields. */
    private function list(string $key, array $nodes, array $lists): string
    {
        $field = $this->field;
        $writable = $field->isWritable();
        $count = Builder::count($nodes);
        $full = $count >= $field->getMaxBlocks();
        $listId = $this->id . '-' . $key;
        $html = '<div class="fa-builder-list" data-builder-list="' . Str::e($key) . '" id="' . Str::e($listId) . '">';

        // The structure
        $html .= '<div class="fa-builder-outline" data-builder-outline><div class="fa-builder-outline-head">'
            . '<h4 class="fa-builder-outline-title" id="' . Str::e($listId) . '-title">' . Str::e(self::t('structure')) . '</h4>'
            . '<span class="fa-builder-outline-tools">'
            . '<button type="button" class="fa-btn fa-btn-sm fa-btn-ghost" data-builder-action="collapse-all" aria-pressed="false" data-collapse-text="' . Str::e(self::t('collapse_all')) . '" data-expand-text="' . Str::e(self::t('expand_all')) . '" hidden>' . Str::e(self::t('collapse_all')) . '</button>'
            . '</span></div>';
        if ($nodes === []) {
            $html .= '<div class="fa-builder-empty" data-builder-empty>' . Icons::svg('section', 'fa-icon fa-builder-empty-icon')
                . '<p class="fa-builder-empty-title">' . Str::e(self::t('empty_title')) . '</p><p class="fa-help">' . Str::e(self::t('empty_help')) . '</p>';
            if ($writable && $key !== Builder::SINGLE && $key !== $this->ctx['default'] && ($lists[$this->ctx['default']] ?? []) !== []) {
                $html .= '<button type="submit" class="fa-btn fa-btn-sm" name="_repeater" value="' . Str::e($field->getName() . ':block:copy:' . $this->ctx['default'] . ':' . $key) . '" formnovalidate data-builder-copy-all="' . Str::e($this->ctx['default']) . '">'
                    . Icons::svg('copy') . '<span>' . Str::e(self::t('copy_all_from', ['language' => Languages::name($this->ctx['default'])])) . '</span></button>';
            }
            $html .= '</div>';
        }
        $html .= '<ul class="fa-builder-tree" role="list" aria-labelledby="' . Str::e($listId) . '-title" data-builder-slot data-parent="" data-column="0">';
        foreach ($nodes as $index => $node) { $html .= $this->row($key, $node, $index, count($nodes), 1); }
        $html .= '</ul>';
        if ($writable) {
            $html .= $this->picker($key, '', 0, $full, 'fa-builder-add-main');
            $html .= '<p class="fa-builder-foot"><span data-builder-count>' . Str::e(self::t('count', ['count' => (string) $count])) . '</span>'
                . '<span class="fa-builder-foot-hint" hidden data-builder-js>' . Str::e(self::t('reorder_hint')) . '</span></p>';
        }
        $html .= '</div>';

        // The blocks' fields
        $html .= '<div class="fa-builder-inspector" data-builder-inspector>'
            . '<div class="fa-builder-none" data-builder-none hidden>' . Icons::svg('settings', 'fa-icon') . '<p>' . Str::e(self::t('choose')) . '</p></div>';
        $walk = function (array $nodes, string $parent) use (&$walk, &$html, $key): void {
            foreach ($nodes as $index => $node) {
                $html .= $this->pane($key, $node, $parent, $index);
                $walk($node['children'] ?? [], (string) $node['id']);
            }
        };
        $walk($nodes, '');
        $html .= '</div>';
        return $html . '</div>';
    }

    /** A block's row in the structure, with the rows of the blocks inside it. */
    private function row(string $key, array $node, int $index, int $total, int $level, bool $template = false): string
    {
        $field = $this->field;
        $id = (string) $node['id'];
        $block = $field->block((string) $node['type']);
        $writable = $field->isWritable();
        $title = $template ? ($block !== null ? $block::label() : '') : $field->title($node);
        $kind = $block !== null ? $block::label() : self::t('unknown_kind');
        $pane = $this->paneId($key, $id);
        $error = !$template && $this->hasErrors($field->getName() . '.' . $key . '.' . $id);
        $state = $template ? '' : $this->stateText($node['settings'] ?? []);
        $op = $field->getName() . ':block:';
        $tool = static fn (string $verb, string $icon, string $label, bool $disabled = false): string => '<button type="submit" class="fa-builder-tool' . ($verb === 'remove' ? ' is-danger' : '') . '" name="_repeater"'
            . ' value="' . Str::e($op . $verb . ':' . $key . ':' . $id) . '" formnovalidate data-builder-action="' . $verb . '" aria-label="' . Str::e($label) . '" title="' . Str::e($label) . '"' . ($disabled ? ' disabled' : '') . '>' . Icons::svg($icon) . '</button>';

        $hiddenBlock = !$template && ($node['settings']['status'] ?? 'published') === 'draft';
        $html = '<li class="fa-builder-node' . ($error ? ' has-error' : '') . ($hiddenBlock ? ' is-draft' : '') . '" data-builder-node="' . Str::e($id) . '" data-type="' . Str::e((string) $node['type']) . '"'
            . ($block !== null && $block->holdsBlocks() ? ' data-holds' : '') . ' data-level="' . $level . '">';
        $html .= '<div class="fa-builder-row">';
        if ($writable) {
            $html .= '<button type="button" class="fa-builder-handle" data-builder-handle aria-label="' . Str::e(self::t('drag', ['block' => $title])) . '" hidden>' . Icons::svg('grip') . '</button>';
        }
        $holds = $block !== null && $block->holdsBlocks();
        if ($holds) {
            // Fold and unfold (0.32): builder.ts shows it and remembers it per page.
            $html .= '<button type="button" class="fa-builder-fold" data-builder-action="fold" aria-expanded="true" aria-label="' . Str::e(self::t('fold', ['block' => $title])) . '"'
                . ' title="' . Str::e(self::t('fold', ['block' => $title])) . '" data-builder-js hidden>' . Icons::svg('chevron-down') . '</button>';
        }
        $html .= '<span class="fa-builder-kind-icon" aria-hidden="true">' . Icons::svg($block !== null ? $block::icon() : 'square') . '</span>'
            . '<a class="fa-builder-row-main" href="#' . Str::e($pane) . '" data-builder-select aria-controls="' . Str::e($pane) . '">'
            . '<strong class="fa-builder-row-title" data-builder-title>' . Str::e($title) . '</strong>'
            . '<span class="fa-builder-row-kind">' . Str::e($kind) . '</span>'
            . '<span class="fa-builder-row-summary" data-builder-summary>' . Str::e($template ? '' : $this->summary($node)) . '</span>'
            . ($holds ? '<span class="fa-builder-row-inside" data-builder-inside></span>' : '') . '</a>'
            . '<span class="fa-builder-state' . ($state === '' ? '' : ' is-shown') . '" data-builder-state>' . ($state === '' ? '' : '<span class="fa-builder-state-dot" aria-hidden="true"></span>' . Str::e($state)) . '</span>';
        if ($error) { $html .= '<span class="fa-builder-row-error">' . Icons::svg('info', 'fa-icon') . '<span class="fa-sr">' . Str::e(self::t('has_errors')) . '</span></span>'; }
        if ($writable) {
            $html .= '<span class="fa-builder-row-tools" data-builder-js hidden>' . $this->rowTools($key, $node, $block, $title) . '</span>';
            $html .= '<span class="fa-builder-tools" data-nojs-only>' . $tool('up', 'arrow-up', self::t('move_up'), $index === 0 && !$template)
                . $tool('down', 'arrow-down', self::t('move_down'), $index >= $total - 1 && !$template) . '</span>';
        } elseif ($block !== null && $field->hasContent($block)) {
            $html .= '<span class="fa-builder-row-tools" data-builder-js hidden>' . $this->toolButton('edit', 'eye', self::t('view_block', ['block' => $title])) . '</span>';
        }
        $html .= '</div>';
        if ($block !== null && $block->holdsBlocks()) {
            $children = $node['children'] ?? [];
            if ($block instanceof Columns || $block->columnCount($node['data'] ?? []) > 1) {
                $layout = $block instanceof Columns ? (string) ($node['data']['layout'] ?? '50-50') : '';
                $shares = $block instanceof Columns ? Columns::shares($layout) : array_fill(0, $block->columnCount($node['data'] ?? []), 1);
                // Side by side as wide as the layout (0.32): admin.css has a grid per layout; others share the width.
                $html .= '<ol class="fa-builder-cols" data-builder-cols data-count="' . count($shares) . '"' . ($layout !== '' ? ' data-layout="' . Str::e($layout) . '"' : '') . '>';
                $sum = max(1, array_sum($shares));
                foreach ($shares as $column => $share) {
                    $in = array_values(array_filter($children, static fn (array $kid): bool => (int) ($kid['column'] ?? 0) === $column));
                    $html .= '<li class="fa-builder-col" data-builder-col="' . $column . '"><span class="fa-builder-col-head"><span class="fa-builder-col-label">' . Str::e(self::t('column', ['number' => (string) ($column + 1)])) . '</span>'
                        . '<span class="fa-builder-col-width" data-builder-col-width>' . (int) round($share * 100 / $sum) . '%</span></span>'
                        . '<ul class="fa-builder-tree is-nested" role="list" data-builder-slot data-parent="' . Str::e($id) . '" data-column="' . $column . '">';
                    foreach ($in as $i => $kid) { $html .= $this->row($key, $kid, $i, count($in), $level + 1); }
                    $html .= '</ul>' . ($writable ? $this->picker($key, $id, $column, false, 'fa-builder-add-in', $block) : '') . '</li>';
                }
                $html .= '</ol>';
            } else {
                $html .= '<ul class="fa-builder-tree is-nested" role="list" data-builder-slot data-parent="' . Str::e($id) . '" data-column="0">';
                foreach ($children as $i => $kid) { $html .= $this->row($key, $kid, $i, count($children), $level + 1); }
                $html .= '</ul>' . ($writable ? $this->picker($key, $id, 0, false, 'fa-builder-add-in', $block) : '');
            }
        }
        if ($writable) {
            // A "+" between blocks (0.32), shown when the pointer is in the gap; the row's own + does the same for the keyboard.
            $html .= '<button type="button" class="fa-builder-inserter" data-builder-action="insert" tabindex="-1" aria-hidden="true" data-builder-js hidden>' . Icons::svg('plus') . '</button>';
        }
        return $html . '</li>';
    }

    /** "Draft", "From 1 Nov", "Ended" (not shown now), "Desktop only" or "Phones only". */
    private function stateText(array $settings): string
    {
        if (($settings['status'] ?? 'published') === 'draft') { return self::t('state_draft'); }
        $now = time();
        $starts = trim((string) ($settings['starts'] ?? ''));
        $ends = trim((string) ($settings['ends'] ?? ''));
        if ($starts !== '' && ($time = strtotime($starts)) !== false && $time > $now) { return self::t('state_from', ['date' => date('j M', $time)]); }
        if ($ends !== '' && ($time = strtotime($ends)) !== false && $time <= $now) { return self::t('state_ended'); }
        return match ($settings['devices'] ?? 'all') { 'desktop' => self::t('state_desktop'), 'phone' => self::t('state_phone'), default => '' };
    }

    /** An icon-only button for a row or the block panel: its name as a tooltip and for screen readers. */
    private function toolButton(string $action, string $icon, string $label, string $extra = '', string $class = ''): string
    {
        return '<button type="button" class="fa-builder-tool' . ($class !== '' ? ' ' . $class : '') . '" data-builder-action="' . Str::e($action) . '" aria-label="' . Str::e($label) . '" title="' . Str::e($label) . '"' . $extra . '>' . Icons::svg($icon) . '</button>';
    }

    /** Show or hide on the website (Published ↔ Draft); both icons, CSS shows the one that fits. */
    private function toggleButton(bool $draft): string
    {
        $label = self::t($draft ? 'show_block' : 'hide_block');
        return '<button type="button" class="fa-builder-tool fa-builder-toggle" data-builder-action="toggle" aria-pressed="' . ($draft ? 'true' : 'false') . '" aria-label="' . Str::e($label) . '" title="' . Str::e($label) . '"'
            . ' data-show-text="' . Str::e(self::t('show_block')) . '" data-hide-text="' . Str::e(self::t('hide_block')) . '">' . Icons::svg('eye', 'fa-icon fa-builder-icon-shown') . Icons::svg('eye-off', 'fa-icon fa-builder-icon-hidden') . '</button>';
    }

    /** The copy-to-language menu of a block (its own language left out by builder.ts when it moves). */
    private function copyMenu(string $key): string
    {
        if (!$this->field->isPerLanguage() || count($this->ctx['languages']) < 2) { return ''; }
        $html = '<span class="fa-builder-copy">' . $this->toolButton('copy-menu', 'globe', self::t('copy_to'), ' aria-haspopup="true" aria-expanded="false"')
            . '<span class="fa-menu-panel fa-builder-copy-menu" role="menu" hidden>';
        foreach ($this->field->listKeys() as $lang) {
            $html .= '<button type="button" role="menuitem" class="fa-menu-item" data-builder-copy-to="' . Str::e($lang) . '"' . ($lang === $key ? ' hidden' : '') . '>' . Str::e(self::t('copy_to_language', ['language' => Languages::name($lang)])) . '</button>';
        }
        return $html . '</span></span>';
    }

    /** A row's buttons (0.31): Edit, Duplicate, Copy to language, Show or hide, Delete, and + (add after). */
    private function rowTools(string $key, array $node, ?Block $block, string $title): string
    {
        $draft = ($node['settings']['status'] ?? 'published') === 'draft';
        $html = '';
        if ($block !== null) {
            if ($this->field->hasContent($block)) { $html .= $this->toolButton('edit', 'pencil', self::t('edit_block', ['block' => $title])); }
            $html .= $this->toolButton('duplicate', 'copy', self::t('duplicate'));
            $html .= $this->copyMenu($key);
            $html .= $this->toggleButton($draft);
        }
        $html .= $this->toolButton('remove', 'trash', self::t('remove'), '', 'is-danger');
        // More (0.32): every action again, and Move to… (builder.ts fills the menu when it opens).
        $html .= $this->toolButton('more', 'more', self::t('more', ['block' => $title]), ' aria-haspopup="menu" aria-expanded="false"');
        return $html . $this->toolButton('insert', 'plus', self::t('add_after', ['block' => $title]), '', 'fa-builder-insert');
    }

    /** A short line about a block's content for its row: "3 items" for a list inside it. */
    private function summary(array $node): string
    {
        foreach ($node['data'] ?? [] as $value) {
            $list = is_string($value) && ($value[0] ?? '') === '[' ? json_decode($value, true) : (is_array($value) ? $value : null);
            if (is_array($list) && array_is_list($list) && $list !== [] && is_array($list[0] ?? null)) { return self::t('summary_items', ['count' => (string) count($list)]); }
        }
        return '';
    }

    private function paneId(string $key, string $id): string
    {
        return $this->id . '-' . $key . '-' . $id;
    }

    /** A block's fields: its head, its place (hidden inputs) and its form. */
    private function pane(string $key, array $node, string $parent, int $order, bool $template = false): string
    {
        $field = $this->field;
        $id = (string) $node['id'];
        $type = (string) $node['type'];
        $block = $field->block($type);
        $writable = $field->isWritable();
        $base = $this->parent->nameOf($field->getName()) . '[' . $key . '][' . $id . ']';
        $paneId = $this->paneId($key, $id);
        $title = $template ? ($block !== null ? $block::label() : '') : $field->title($node);
        $kind = $block !== null ? $block::label() : self::t('unknown_kind');
        $op = $field->getName() . ':block:';

        $html = '<section class="fa-builder-pane" id="' . Str::e($paneId) . '" data-builder-pane="' . Str::e($id) . '" data-type="' . Str::e($type) . '" aria-labelledby="' . Str::e($paneId) . '-title" tabindex="-1">';
        $html .= '<header class="fa-builder-pane-head"><span class="fa-builder-kind-icon" aria-hidden="true">' . Icons::svg($block !== null ? $block::icon() : 'square') . '</span>'
            . '<span class="fa-builder-pane-names"><span class="fa-builder-pane-kind">' . Str::e($kind) . '</span><h4 class="fa-builder-pane-title" id="' . Str::e($paneId) . '-title" data-builder-pane-title>' . Str::e($title) . '</h4></span>'
            . '<span class="fa-builder-pane-tools">';
        if ($writable) {
            // Without JavaScript: Remove submits the form (with it, the row's and the panel's buttons do).
            $html .= '<button type="submit" class="fa-builder-tool is-danger" name="_repeater" value="' . Str::e($op . 'remove:' . $key . ':' . $id) . '" formnovalidate data-builder-action="remove" data-nojs-only aria-label="' . Str::e(self::t('remove')) . '" title="' . Str::e(self::t('remove')) . '">' . Icons::svg('trash') . '</button>';
        }
        $html .= $this->toolButton('close', 'x', self::t('close'), ' data-builder-js hidden') . '</span></header>';

        // Its place: builder.ts keeps these in step with the structure.
        $hidden = static fn (string $part, string $value, string $data): string => '<input type="hidden" name="' . Str::e($base . '[' . $part . ']') . '" value="' . Str::e($value) . '" data-builder-' . $data . '>';
        $html .= $hidden('type', $type, 'type') . $hidden('parent', $parent, 'parent') . $hidden('column', (string) (int) ($node['column'] ?? 0), 'column') . $hidden('order', (string) $order, 'order');

        if ($block === null) {
            $html .= $hidden('raw', Builder::sign((string) json_encode($node['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'raw|' . $key . '|' . $id), 'raw');
            $html .= '<div class="fa-builder-pane-body"><p class="fa-help">' . Str::e(self::t('unknown_help', ['type' => $type])) . '</p></div>';
            return $html . '</section>';
        }

        $errors = [];
        if (!$template) {
            $start = $field->getName() . '.' . $key . '.' . $id . '.';
            foreach ($this->errors as $errorKey => $message) {
                if (str_starts_with((string) $errorKey, $start)) { $errors[substr((string) $errorKey, strlen($start))] = $message; }
            }
        }
        $state = $block->prepareState(($node['data'] ?? []) + ($node['settings'] ?? []), $key . '|' . $id);
        $child = $this->parent->child($state, $errors, $paneId . '-', $base . '[f]', $op . 'item:' . $key . ':' . $id . ':');
        $content = $field->hasContent($block);
        // The block's own name sits in the panel too (Section, Columns, Slider).
        $settingErrors = array_intersect_key($errors, array_flip([...$field->settingNames($block), ...($block->nameField() !== null ? [$block->nameField()] : [])]));
        // The block panel (0.31): Edit, then the actions, then the settings every block shares.
        $html .= '<div class="fa-builder-pane-actions" data-builder-js hidden>';
        if ($content) {
            $html .= '<button type="button" class="fa-btn fa-btn-primary fa-builder-edit" data-builder-action="edit">' . Icons::svg($writable ? 'pencil' : 'eye') . '<span>' . Str::e(self::t($writable ? 'edit_content' : 'view_content')) . '</span></button>';
        }
        if ($writable) {
            $html .= '<span class="fa-builder-pane-buttons">' . $this->toolButton('duplicate', 'copy', self::t('duplicate')) . $this->copyMenu($key)
                . $this->toggleButton(($node['settings']['status'] ?? 'published') === 'draft')
                . $this->toolButton('remove', 'trash', self::t('remove'), '', 'is-danger') . '</span>';
        }
        if ($content && count($errors) > count($settingErrors)) { $html .= '<p class="fa-error fa-builder-pane-error">' . Str::e(self::t('content_errors')) . '</p>'; }
        $html .= '</div>';
        $html .= '<div class="fa-builder-pane-settings" data-builder-settings>' . $child->render($field->settingsForm($block, !$writable)) . '</div>';
        if ($content) {
            $html .= '<div class="fa-builder-pane-body" data-builder-fields="' . Str::e($id) . '" data-list="' . Str::e($key) . '">' . $child->render($field->contentForm($block, !$writable)) . '</div>';
        }
        return $html . '</section>';
    }

    /**
     * Add block: a list of the kinds that may sit here, as buttons that send the
     * form without JavaScript; builder.ts turns the main one into the picker
     * (search and categories) and opens it from the other Add buttons too.
     */
    private function picker(string $key, string $parent, int $column, bool $full, string $class, ?Block $container = null): string
    {
        $field = $this->field;
        $parentType = $container !== null ? $container::type() : '';
        $inColumn = $parent !== '' && $container !== null && ($container instanceof Columns || $container->columnCount([]) > 1);
        $label = $parent === '' ? $field->getAddLabel() : ($inColumn ? self::t('add_to_column', ['number' => (string) ($column + 1)]) : self::t('add_inside'));
        $shown = $parent === '' ? $label : self::t('add');
        $html = '<details class="fa-builder-add ' . $class . '" data-builder-add data-parent="' . Str::e($parent) . '" data-column="' . $column . '">'
            . '<summary class="fa-builder-add-button"' . ($full ? ' aria-disabled="true"' : '') . ($shown !== $label ? ' aria-label="' . Str::e($label) . '"' : '') . '>' . Icons::svg('plus') . '<span>' . Str::e($shown) . '</span></summary>';
        if ($parent !== '') {
            $html .= '<div class="fa-builder-picker-list">';
            foreach ($this->choices($parentType) as $block) {
                $html .= '<button type="submit" class="fa-builder-choice is-compact" name="_repeater" value="' . Str::e($field->getName() . ':block:add:' . $key . ':' . $block::type() . ':' . $parent . ':' . $column) . '" formnovalidate data-builder-choice="' . Str::e($block::type()) . '">'
                    . '<span class="fa-builder-kind-icon" aria-hidden="true">' . Icons::svg($block::icon()) . '</span><span class="fa-builder-choice-name">' . Str::e($block::label()) . '</span></button>';
            }
            return $html . '</div></details>';
        }
        // The main picker: categories, search and a description per kind.
        $categories = [];
        foreach ($this->choices('') as $block) { $categories[$block::category()] = true; }
        $all = $this->choices('', true);
        $html .= '<div class="fa-builder-picker" data-builder-picker role="group" aria-label="' . Str::e(self::t('add')) . '">'
            . '<div class="fa-builder-picker-search" data-builder-js hidden>' . Icons::svg('search', 'fa-icon') . '<input type="search" class="fa-input" data-builder-search placeholder="' . Str::e(self::t('search')) . '" aria-label="' . Str::e(self::t('search')) . '" autocomplete="off"></div>'
            . '<div class="fa-builder-picker-main"><div class="fa-builder-cats" role="tablist" data-builder-js hidden aria-label="' . Str::e(self::t('categories')) . '">'
            . '<button type="button" role="tab" aria-selected="true" data-builder-cat="">' . Str::e(self::t('cat_all')) . '</button>';
        foreach (Block::CATEGORIES as $category) {
            if (isset($categories[$category])) { $html .= '<button type="button" role="tab" aria-selected="false" data-builder-cat="' . $category . '">' . Str::e(self::t('cat_' . $category)) . '</button>'; }
        }
        $html .= '</div><div class="fa-builder-picker-list" data-builder-choices>';
        foreach ($all as $block) {
            $html .= '<button type="submit" class="fa-builder-choice" name="_repeater" value="' . Str::e($field->getName() . ':block:add:' . $key . ':' . $block::type()) . '" formnovalidate'
                . ' data-builder-choice="' . Str::e($block::type()) . '" data-category="' . Str::e($block::category()) . '" data-allowed="' . Str::e(implode(' ', $this->places($block))) . '"'
                . ' data-search-text="' . Str::e(mb_strtolower($block::label() . ' ' . $block::description())) . '"' . ($block->allowedIn('') ? '' : ' hidden data-nested-only') . '>'
                . '<span class="fa-builder-kind-icon" aria-hidden="true">' . Icons::svg($block::icon()) . '</span>'
                . '<span class="fa-builder-choice-text"><span class="fa-builder-choice-name">' . Str::e($block::label()) . '</span>'
                . ($block::description() !== '' ? '<span class="fa-builder-choice-help">' . Str::e($block::description()) . '</span>' : '') . '</span></button>';
        }
        $html .= '<p class="fa-help" data-builder-no-match hidden>' . Str::e(self::t('no_match')) . '</p></div></div></div>';
        return $html . '</details>';
    }

    /**
     * The kinds that may be added inside a parent of this type ('' the top level).
     *
     * @return list<Block>
     */
    private function choices(string $parentType, bool $everywhere = false): array
    {
        return array_values(array_filter($this->field->available(), fn (Block $block): bool => $this->field->canAdd($block) && ($everywhere || $block->allowedIn($parentType))));
    }

    /** @return list<string> where a kind may sit: "top", "section", "columns", … */
    private function places(Block $block): array
    {
        $places = $block->allowedIn('') ? ['top'] : [];
        foreach ($this->field->available() as $other) {
            if ($other->holdsBlocks() && $block->allowedIn($other::type())) { $places[] = $other::type(); }
        }
        return $places;
    }

    /** A row and a pane per kind for Add (builder.ts replaces __ID__ and __LANG__). */
    private function templates(): string
    {
        $html = '';
        foreach ($this->choices('', true) as $block) {
            $node = $this->field->newNode($block, '__ID__');
            $html .= '<template data-builder-template="' . Str::e($block::type()) . '"><ul>' . $this->row('__LANG__', $node, 0, 1, 1, true) . '</ul>'
                . $this->pane('__LANG__', $node, '', 0, true) . '</template>';
        }
        return $html;
    }

    /**
     * The dialogs builder.ts opens (0.31): a block's fields, Add block and
     * Delete. Inside the form, so the fields moved into them are sent with it.
     */
    private function dialogs(): string
    {
        $close = '<button type="button" class="fa-builder-tool fa-dialog-close" data-builder-dialog-close aria-label="' . Str::e(self::t('close')) . '" title="' . Str::e(self::t('close')) . '">' . Icons::svg('x') . '</button>';
        $writable = $this->field->isWritable();
        $html = '<dialog class="fa-builder-dialog fa-builder-edit-dialog" data-builder-edit-dialog aria-labelledby="' . Str::e($this->id) . '-edit-title">'
            . '<header class="fa-dialog-head fa-builder-dialog-head"><span class="fa-builder-kind-icon" aria-hidden="true" data-builder-dialog-icon></span>'
            . '<span class="fa-builder-pane-names"><span class="fa-builder-pane-kind" data-builder-dialog-kind></span><h3 class="fa-dialog-title" id="' . Str::e($this->id) . '-edit-title" data-builder-dialog-title></h3></span>'
            . $close . '</header>'
            . '<div class="fa-dialog-body fa-builder-dialog-body" data-builder-dialog-body></div>'
            . '<footer class="fa-dialog-foot"><span class="fa-builder-dialog-hint">' . Str::e(self::t($writable ? 'dialog_hint' : 'dialog_hint_read')) . '</span><button type="button" class="fa-btn fa-btn-primary" data-builder-dialog-close>' . Str::e(self::t('done')) . '</button></footer></dialog>';
        if (!$writable) { return $html; }
        $html .= '<dialog class="fa-builder-dialog fa-builder-add-dialog" data-builder-add-dialog aria-labelledby="' . Str::e($this->id) . '-add-title">'
            . '<header class="fa-dialog-head fa-builder-dialog-head"><span class="fa-builder-pane-names"><h3 class="fa-dialog-title" id="' . Str::e($this->id) . '-add-title">' . Str::e(self::t('add')) . '</h3><span class="fa-builder-pane-kind" data-builder-add-place></span></span>' . $close . '</header>'
            . '<div class="fa-dialog-body fa-builder-dialog-body" data-builder-add-body><button type="button" class="fa-builder-paste" data-builder-paste hidden>' . Icons::svg('clipboard') . '<span class="fa-builder-choice-text"><span class="fa-builder-choice-name">' . Str::e(self::t('paste')) . '</span><span class="fa-builder-choice-help" data-builder-paste-name></span></span></button></div></dialog>';
        $html .= '<dialog class="fa-builder-dialog fa-builder-confirm" data-builder-confirm role="alertdialog" aria-labelledby="' . Str::e($this->id) . '-confirm-title" aria-describedby="' . Str::e($this->id) . '-confirm-text">'
            . '<header class="fa-dialog-head fa-builder-dialog-head"><h3 class="fa-dialog-title" id="' . Str::e($this->id) . '-confirm-title" data-builder-confirm-title data-template="' . Str::e(self::t('delete_title', ['block' => ':block'])) . '"></h3></header>'
            . '<div class="fa-dialog-body"><p class="fa-builder-confirm-text" id="' . Str::e($this->id) . '-confirm-text" data-builder-confirm-text data-one="' . Str::e(self::t('delete_text')) . '" data-many="' . Str::e(self::t('delete_text_inside', ['count' => ':count'])) . '"></p></div>'
            . '<footer class="fa-dialog-foot"><button type="button" class="fa-btn" data-builder-dialog-close>' . Str::e(self::t('cancel')) . '</button>'
            . '<button type="button" class="fa-btn fa-btn-danger" data-builder-confirm-yes>' . Icons::svg('trash') . '<span>' . Str::e(self::t('delete')) . '</span></button></footer></dialog>';
        return $html;
    }

    private function hasErrors(string $prefix): bool
    {
        foreach (array_keys($this->errors) as $key) {
            if ($key === $prefix || str_starts_with((string) $key, $prefix . '.')) { return true; }
        }
        return false;
    }
}
