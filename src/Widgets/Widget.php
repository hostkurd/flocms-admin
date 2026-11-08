<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Admin, Support\AdminLang, Support\Str};
use FloCMS\Core\Auth;

/** Project-owned PHP definitions; request state never selects a widget class. */
abstract class Widget
{
    protected string $title = '';
    /** A short line beside the title (0.42): plain text or a lang key. */
    protected string $description = '';
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
    final public function title(): string
    {
        $title = $this->title !== '' ? $this->title : Str::headline(preg_replace('/Widget$/', '', (new \ReflectionClass($this))->getShortName()) ?? '');
        $label = AdminLang::label($title);
        // The panel's own widgets (ActivityWidget) use the panel's words
        return $label === $title && str_starts_with($title, 'widgets.') ? AdminLang::get($title) : $label;
    }
    final public function sort(): int { return $this->sort; }
    /** The widget's own permission, or null (0.21: listed on the Roles page). */
    final public function permission(): ?string { return $this->permission; }
    final public function columnSpan(): int|string { return $this->columnSpan; }
    final public function resources(): array { return $this instanceof RecordsWidget ? array_merge($this->resources, [$this->resourceClass()]) : $this->resources; }
    /**
     * The line beside the title (0.42): $description by default. Override it to
     * show the period, e.g. return $context->period()->label();
     */
    public function subtitle(WidgetContext $context): string { return $this->description === '' ? '' : AdminLang::label($this->description); }
    /**
     * A link at the end of the widget's header (0.42), e.g. "See all":
     * ['label' => 'See all', 'url' => $context->url('inquiries')], or null.
     *
     * @return array{label: string, url: string}|null
     */
    public function link(WidgetContext $context): ?array { return null; }
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
    /** @internal HTML renderers are supplied by the supported widget types (StatsWidget, RecordsWidget, ChartWidget, BarListWidget, ProgressWidget, ListWidget, ActivityWidget). */
    abstract public function render(WidgetContext $context, string $id): string;
}
