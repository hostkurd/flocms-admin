<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use FloCMS\Admin\{Admin, Resource, Support\Icons, Support\Str};

/** One visual/placement contract for Reactive buttons and standard action links. */
final class ActionRenderer
{
    public function __construct(private readonly Resource $resource, private readonly ActionRegistry $registry) {}
    public function render(string $placement, ?object $record = null, bool $reactive = true, string $prefix = 'fa-admin-resource', ?string $backUrl = null): string
    {
        $id = $record !== null ? (string) $record->{$this->resource::getPrimaryKey()} : '';
        $html = '';
        foreach ($this->registry->all($placement) as $action) {
            if (!$action->allowed($this->resource, $record)) { continue; }
            $name = $action->getName();
            $color = $action->getColor();
            $class = 'fa-btn' . ($placement === 'row' ? ' fa-btn-sm' : '') . ($color === 'default' ? '' : ' fa-btn-' . $color);
            $label = ($action->getIcon() !== null ? Icons::svg($action->getIcon()) : '') . '<span>' . Str::e($action->getLabel($this->resource)) . '</span>';
            if ($reactive) {
                $html .= '<button type="button" id="' . Str::e($prefix . '-' . $name . ($id !== '' ? '-' . $id : '-' . $placement)) . '" class="' . $class . '" data-admin-action="' . Str::e($name) . '" data-admin-placement="' . $placement . '" data-admin-record="' . Str::e($id) . '" data-rx-set="selection" data-rx-value="' . Str::e($placement . ':' . $name . ':' . $id) . '" data-rx-action="openAction" data-rx-dialog-trigger="editor">' . $label . '</button>';
            } else {
                $kind = $action->getKind();
                // Existing CRUD URLs continue to work, but custom definitions use
                // the same operation/authorization boundary through a standard form.
                $path = $this->resource::getSlug() . '/action/' . $placement . '/' . $name . ($id !== '' ? '/' . $id : '');
                if ($name === 'create' && $kind === 'create' && $placement === 'page') { $path = $this->resource::getSlug() . '/create'; }
                if ($name === 'edit' && $kind === 'edit') { $path = $this->resource::getSlug() . '/edit/' . $id; }
                if ($name === 'delete' && $kind === 'delete') {
                    $html .= '<form method="post" class="fa-inline" data-confirm action="' . Str::e(Admin::url($this->resource::getSlug() . '/delete/' . $id)) . '">'
                        . \FloCMS\Core\Csrf::field() . '<input type="hidden" name="_back" value="' . Str::e($backUrl ?? Admin::url($this->resource::getSlug())) . '">'
                        . '<button type="submit" class="' . $class . '">' . $label . '</button></form>';
                    continue;
                }
                $html .= '<a class="' . $class . '" href="' . Str::e(Admin::url($path)) . '">' . $label . '</a>';
            }
        }
        return $html;
    }
}
