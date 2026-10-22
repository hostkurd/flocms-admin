<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Admin, Support\AdminLang, Support\Str};
use FloCMS\Core\Auth;

/** Project-owned PHP definitions; request state never selects a widget class. */
abstract class Widget
{
    protected string $title = '';
    protected ?string $permission = null;
    protected array $resources = [];
    protected int $sort = 0;
    protected int|string $columnSpan = 1;
    /** @var array<string, mixed> */
    private array $pageFilters = [];
    final public function __construct() {}
    /**
     * The filters' values of the panel page showing the widget (0.36), by field
     * name; empty on the dashboard. Read them in stats(), records() or data().
     *
     * @return array<string, mixed>
     */
    final public function pageFilters(): array { return $this->pageFilters; }
    /** @internal */
    final public function usePageFilters(array $filters): static { $this->pageFilters = $filters; return $this; }
    final public function title(): string { return AdminLang::label($this->title !== '' ? $this->title : Str::headline(preg_replace('/Widget$/', '', (new \ReflectionClass($this))->getShortName()) ?? '')); }
    final public function sort(): int { return $this->sort; }
    /** The widget's own permission, or null (0.21: listed on the Roles page). */
    final public function permission(): ?string { return $this->permission; }
    final public function columnSpan(): int|string { return $this->columnSpan; }
    final public function resources(): array { return $this instanceof RecordsWidget ? array_merge($this->resources, [$this->resourceClass()]) : $this->resources; }
    /** Narrow visibility after the permission and registered-resource checks. */
    public function canView(): bool { return true; }
    final public function authorized(): bool
    {
        if ($this->permission !== null && !Auth::can($this->permission)) { return false; }
        foreach ($this->resources() as $class) {
            if (!WidgetContext::registered($class) || !Auth::can($class::permission('view'))) { return false; }
        }
        return $this->canView();
    }
    final public function configurationErrors(): array
    {
        $errors = [];
        if ($this->columnSpan !== 'full' && (!is_int($this->columnSpan) || $this->columnSpan < 1 || $this->columnSpan > 4)) { $errors[] = 'columnSpan must be 1–4 or full'; }
        foreach ($this->resources() as $class) { if (!is_string($class) || !WidgetContext::registered($class)) { $errors[] = 'resource must be registered in Admin: ' . (is_string($class) ? $class : get_debug_type($class)); } }
        return $errors;
    }
    /** @internal HTML renderers are supplied by the three supported widget types. */
    abstract public function render(WidgetContext $context, string $id): string;
}
