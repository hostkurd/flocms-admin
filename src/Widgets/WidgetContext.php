<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Admin, Resource, Data\RecordRepository, Translation\Translations};
use FloCMS\Core\{App, Auth, Database};

/** Scoped, request-local, read-only entry point for widget providers. */
final class WidgetContext
{
    private int $queries = 0;
    public function __construct(private readonly array $resources) {}
    public static function registered(string $class): bool
    {
        return is_subclass_of($class, Resource::class) && Admin::registry()->find($class::getSlug()) === $class;
    }
    public function resource(string $class): Resource
    {
        if (!in_array($class, $this->resources, true) || !self::registered($class)) { throw new \LogicException('Widgets must declare every registered resource they use.'); }
        Auth::authorize($class::permission('view'));
        return new $class();
    }
    public function query(string $class): WidgetQuery { $this->resource($class); return new WidgetQuery(fn (): Database => $this->newQuery($class)); }
    public function resourceUrl(string $class): string { return Admin::url($this->resource($class)::getSlug()); }
    /** @internal Fresh scope for every terminal read; builder resets cannot erase tenant filters. */
    private function newQuery(string $class): Database
    {
        if (++$this->queries > 64) { throw new \RuntimeException('A widget exceeded its 64-query budget.'); }
        $resource = $this->resource($class);
        return (new RecordRepository($resource, App::db() ?? throw new \RuntimeException('Database required.'), Translations::store()))->newQuery();
    }
}
