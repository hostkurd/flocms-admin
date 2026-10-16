<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use FloCMS\Admin\{Admin, Resource, Support\AdminLang, Support\Icons, Support\Str};

/** One visual/placement contract for Reactive buttons and standard action links. */
final class ActionRenderer
{
    public function __construct(private readonly Resource $resource, private readonly ActionRegistry $registry) {}

    /**
     * Page, table and bulk actions as buttons. Row actions use the same markup
     * inside a compact group: Edit as an icon button, the rest in a "More" menu.
     */
    public function render(string $placement, ?object $record = null, bool $reactive = true, string $prefix = 'fa-admin-resource', ?string $backUrl = null): string
    {
        if ($placement === 'row') { return $this->renderRow($record, $reactive, $prefix, $backUrl); }
        $html = '';
        foreach ($this->allowed($placement, $record) as $action) {
            $html .= $this->item($action, $placement, $record, $reactive, $prefix, $backUrl, 'button');
        }
        return $html;
    }

    private function renderRow(?object $record, bool $reactive, string $prefix, ?string $backUrl): string
    {
        $primary = '';
        $items = ''; $danger = '';
        foreach ($this->allowed('row', $record) as $action) {
            if ($action->getName() === 'edit' && $primary === '') {
                $primary = $this->item($action, 'row', $record, $reactive, $prefix, $backUrl, 'icon');
            } elseif ($action->getColor() === 'danger') {
                $danger .= $this->item($action, 'row', $record, $reactive, $prefix, $backUrl, 'menu');
            } else {
                $items .= $this->item($action, 'row', $record, $reactive, $prefix, $backUrl, 'menu');
            }
        }
        // Destructive actions come last, after a divider.
        $menu = $items . ($items !== '' && $danger !== '' ? '<hr class="fa-menu-divider">' : '') . $danger;
        if ($primary === '' && $menu === '') { return ''; }
        $id = $record !== null ? (string) $record->{$this->resource::getPrimaryKey()} : '';
        $html = '<div class="fa-row-actions">' . $primary;
        if ($menu !== '') {
            $label = AdminLang::get('table.more_actions');
            // <details> keeps the menu usable without JavaScript; admin.js adds
            // outside-click/Escape closing and focus return.
            $html .= '<details class="fa-menu" id="' . Str::e($prefix . '-menu-' . $id) . '" data-admin-popover>'
                . '<summary class="fa-icon-action" aria-label="' . Str::e($label) . '" title="' . Str::e($label) . '">' . Icons::svg('more') . '</summary>'
                . '<div class="fa-menu-panel">' . $menu . '</div></details>';
        }
        return $html . '</div>';
    }

    /** @return list<Action> */
    private function allowed(string $placement, ?object $record): array
    {
        return array_values(array_filter($this->registry->all($placement), fn (Action $action): bool => $action->allowed($this->resource, $record)));
    }

    /** @param 'button'|'icon'|'menu' $style */
    private function item(Action $action, string $placement, ?object $record, bool $reactive, string $prefix, ?string $backUrl, string $style): string
    {
        $id = $record !== null ? (string) $record->{$this->resource::getPrimaryKey()} : '';
        $name = $action->getName();
        $color = $action->getColor();
        $text = $action->getLabel($this->resource);
        [$class, $label, $attributes] = match ($style) {
            'icon' => ['fa-icon-action', Icons::svg($action->getIcon() ?? 'pencil') . '<span class="fa-sr">' . Str::e($text) . '</span>', ' title="' . Str::e($text) . '"'],
            'menu' => ['fa-menu-item' . ($color === 'danger' ? ' is-danger' : ''), self::menuIcon($action) . '<span>' . Str::e($text) . '</span>', ''],
            default => ['fa-btn' . ($color === 'default' ? '' : ' fa-btn-' . $color), ($action->getIcon() !== null ? Icons::svg($action->getIcon()) : '') . '<span>' . Str::e($text) . '</span>', ''],
        };
        // A page-mode Create or Edit is an ordinary link (the panel loads it without a reload).
        $page = $action->getMode($this->resource) === 'page' && in_array($action->getKind(), ['create', 'edit'], true);
        if ($reactive && !$page) {
            return '<button type="button" id="' . Str::e($prefix . '-' . $name . ($id !== '' ? '-' . $id : '-' . $placement)) . '" class="' . $class . '"' . $attributes . ' data-admin-action="' . Str::e($name) . '" data-admin-placement="' . $placement . '" data-admin-record="' . Str::e($id) . '" data-rx-set="selection" data-rx-value="' . Str::e($placement . ':' . $name . ':' . $id) . '" data-rx-action="openAction" data-rx-dialog-trigger="editor">' . $label . '</button>';
        }
        $kind = $action->getKind();
        // Existing CRUD URLs continue to work, but custom definitions use
        // the same operation/authorization boundary through a standard form.
        $path = $this->resource::getSlug() . '/action/' . $placement . '/' . $name . ($id !== '' ? '/' . $id : '');
        if ($name === 'create' && $kind === 'create' && $placement === 'page') { $path = $this->resource::getSlug() . '/create'; }
        if ($name === 'edit' && $kind === 'edit') { $path = $this->resource::getSlug() . '/edit/' . $id; }
        if ($backUrl !== null && (($name === 'create' && $kind === 'create' && $placement === 'page') || ($name === 'edit' && $kind === 'edit'))) {
            return '<a class="' . $class . '"' . $attributes . ' id="' . Str::e($prefix . '-' . $name . ($id !== '' ? '-' . $id : '-' . $placement)) . '" data-admin-action="' . Str::e($name) . '" href="' . Str::e(Admin::url($path, ['back' => $backUrl])) . '">' . $label . '</a>';
        }
        if ($name === 'delete' && $kind === 'delete') {
            return '<form method="post" class="fa-inline" data-confirm action="' . Str::e(Admin::url($this->resource::getSlug() . '/delete/' . $id)) . '">'
                . \FloCMS\Core\Csrf::field() . '<input type="hidden" name="_back" value="' . Str::e($backUrl ?? Admin::url($this->resource::getSlug())) . '">'
                . '<button type="submit" class="' . $class . '"' . $attributes . '>' . $label . '</button></form>';
        }
        return '<a class="' . $class . '"' . $attributes . ' href="' . Str::e(Admin::url($path)) . '">' . $label . '</a>';
    }

    /** Menu items always have an icon column so labels line up. */
    private static function menuIcon(Action $action): string
    {
        $icon = $action->getIcon() ?? match ($action->getKind()) { 'delete' => 'trash', 'edit' => 'pencil', default => null };
        return $icon !== null ? Icons::svg($icon) : '<span class="fa-icon" aria-hidden="true"></span>';
    }
}
