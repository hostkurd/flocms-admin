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
        $items = ''; $danger = ''; $group = null;
        foreach ($this->allowed('row', $record) as $action) {
            if ($action->getName() === 'edit' && $primary === '') {
                $primary = $this->item($action, 'row', $record, $reactive, $prefix, $backUrl, 'icon');
            } elseif ($action->getColor() === 'danger') {
                $danger .= $this->item($action, 'row', $record, $reactive, $prefix, $backUrl, 'menu');
            } else {
                // A divider between groups (0.28)
                if ($items !== '' && $action->getGroup() !== $group) { $items .= '<hr class="fa-menu-divider">'; }
                $group = $action->getGroup();
                $items .= $this->item($action, 'row', $record, $reactive, $prefix, $backUrl, 'menu');
            }
        }
        // The view page (0.27), when the title opens Edit: one click away in the menu.
        if ($record !== null && $primary !== '' && $this->resource::rowOpens() === 'edit' && ($viewUrl = $this->resource->viewUrl($record)) !== null) {
            $items = '<a class="fa-menu-item" data-admin-action="view" href="' . Str::e($viewUrl) . '">' . Icons::svg('eye') . '<span>' . Str::e(AdminLang::get('view.view')) . '</span></a>' . ($items !== '' && $this->firstGroup('row', $record) !== null ? '<hr class="fa-menu-divider">' : '') . $items;
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

    /**
     * The view page's More menu (0.27): the record's row actions the person may
     * run, except Edit (a button of its own there), as links and forms that
     * work without JavaScript; destructive ones last.
     */
    /** @return array{items: string, danger: string} */
    public function menu(object $record, ?string $backUrl = null): array
    {
        $items = ''; $danger = ''; $group = null;
        foreach ($this->allowed('row', $record) as $action) {
            if ($action->getName() === 'edit') { continue; }
            $item = $this->item($action, 'row', $record, false, 'fa-view', $backUrl, 'menu');
            if ($action->getColor() === 'danger') { $danger .= $item; continue; }
            if ($items !== '' && $action->getGroup() !== $group) { $items .= '<hr class="fa-menu-divider">'; }
            $group = $action->getGroup();
            $items .= $item;
        }
        return ['items' => $items, 'danger' => $danger];
    }

    private function firstGroup(string $placement, ?object $record): ?string
    {
        foreach ($this->allowed($placement, $record) as $action) {
            if ($action->getName() !== 'edit' && $action->getColor() !== 'danger') { return $action->getGroup(); }
        }
        return null;
    }

    /** @return list<Action> the actions the person may see: allowed, or greyed out with a reason (0.28) */
    private function allowed(string $placement, ?object $record): array
    {
        return array_values(array_filter($this->registry->all($placement), fn (Action $action): bool => $action->allowed($this->resource, $record) || $action->reasonDisabled($this->resource, $record) !== null));
    }

    /** @param 'button'|'icon'|'menu' $style */
    private function item(Action $action, string $placement, ?object $record, bool $reactive, string $prefix, ?string $backUrl, string $style): string
    {
        $id = $record !== null ? (string) $record->{$this->resource::getPrimaryKey()} : '';
        $name = $action->getName();
        $color = $action->getColor();
        $text = $action->getLabel($this->resource);
        // Greyed out (0.28): shown with its reason, never clickable; the server refuses it too.
        if (($reason = $action->reasonDisabled($this->resource, $record)) !== null) {
            $reasonId = Str::e($prefix . '-' . $name . '-' . ($id !== '' ? $id : $placement) . '-reason');
            return match ($style) {
                'menu' => '<span class="fa-menu-item is-disabled" role="menuitem" aria-disabled="true" aria-describedby="' . $reasonId . '" data-admin-action="' . Str::e($name) . '">' . self::menuIcon($action)
                    . '<span class="fa-menu-text"><span>' . Str::e($text) . '</span><small id="' . $reasonId . '">' . Str::e($reason) . '</small></span></span>',
                'icon' => '<button type="button" class="fa-icon-action" disabled title="' . Str::e($text . ': ' . $reason) . '" data-admin-action="' . Str::e($name) . '">' . Icons::svg($action->getIcon() ?? 'pencil') . '<span class="fa-sr">' . Str::e($text . ': ' . $reason) . '</span></button>',
                default => '<button type="button" class="fa-btn" disabled title="' . Str::e($reason) . '" data-admin-action="' . Str::e($name) . '">' . ($action->getIcon() !== null ? Icons::svg($action->getIcon()) : '') . '<span>' . Str::e($text) . '</span></button>',
            };
        }
        [$class, $label, $attributes] = match ($style) {
            'icon' => ['fa-icon-action', Icons::svg($action->getIcon() ?? 'pencil') . '<span class="fa-sr">' . Str::e($text) . '</span>', ' title="' . Str::e($text) . '"'],
            'menu' => ['fa-menu-item' . ($color === 'danger' ? ' is-danger' : ''), self::menuIcon($action) . '<span>' . Str::e($text) . '</span>', ''],
            default => ['fa-btn' . ($color === 'default' ? '' : ' fa-btn-' . $color), ($action->getIcon() !== null ? Icons::svg($action->getIcon()) : '') . '<span>' . Str::e($text) . '</span>', ''],
        };
 // An action with url() (0.28) opens its page: an ordinary link.
        if (($url = $action->getUrl($record)) !== null) {
            return '<a class="' . $class . '"' . $attributes . ' id="' . Str::e($prefix . '-' . $name . ($id !== '' ? '-' . $id : '-' . $placement)) . '" data-admin-action="' . Str::e($name) . '" href="' . Str::e($url) . '">' . $label . '</a>';
        }
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
        // Inside a parent (a relation manager without JavaScript) every link keeps it (0.22).
        $parent = $this->resource->parentScope()?->token();
        if ($backUrl !== null && (($name === 'create' && $kind === 'create' && $placement === 'page') || ($name === 'edit' && $kind === 'edit'))) {
            return '<a class="' . $class . '"' . $attributes . ' id="' . Str::e($prefix . '-' . $name . ($id !== '' ? '-' . $id : '-' . $placement)) . '" data-admin-action="' . Str::e($name) . '" href="' . Str::e(Admin::url($path, ['parent' => $parent, 'back' => $backUrl])) . '">' . $label . '</a>';
        }
        if ($name === 'delete' && $kind === 'delete') {
            return '<form method="post" class="fa-inline" data-confirm action="' . Str::e(Admin::url($this->resource::getSlug() . '/delete/' . $id, ['parent' => $parent])) . '">'
                . \FloCMS\Core\Csrf::field() . '<input type="hidden" name="_back" value="' . Str::e($backUrl ?? Admin::url($this->resource::getSlug())) . '">'
                . '<button type="submit" class="' . $class . '"' . $attributes . '>' . $label . '</button></form>';
        }
        return '<a class="' . $class . '"' . $attributes . ' href="' . Str::e(Admin::url($path, ['parent' => $parent])) . '">' . $label . '</a>';
    }

    /** Menu items always have an icon column so labels line up. */
    private static function menuIcon(Action $action): string
    {
        $icon = $action->getIcon() ?? match ($action->getKind()) { 'delete' => 'trash', 'edit' => 'pencil', default => null };
        return $icon !== null ? Icons::svg($icon) : '<span class="fa-icon" aria-hidden="true"></span>';
    }
}
