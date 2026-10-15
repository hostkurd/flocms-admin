# Dashboard widgets (Admin 0.8)

The dashboard's resource-count cards remain the default. Register project-owned widget classes in `config/admin.php` to replace those cards with a custom dashboard. Widgets are explicitly allowlisted, never discovered or selected by browser-supplied class names. An empty list restores the original dashboard.

```php
'widgets' => [
    App\Admin\Widgets\ContentOverview::class,
    App\Admin\Widgets\RecentPosts::class,
    App\Admin\Widgets\PublicationChart::class,
],
'widgets_path' => 'admin/Widgets',
'widgets_namespace' => 'App\\Admin\\Widgets',
'widget_columns' => 2, // 1–4; mobile stacks all widgets
```

Create the PHP files in `admin/Widgets`. Admin registers an autoloader for that directory/namespace; no application Composer autoload change is needed. Only explicitly listed classes load as widgets. Register every source Resource in Admin too. The built-in `FloCMS\Admin\Users\UsersResource` is also available while user management is enabled, with its normal `users.manage` permission. The examples below assume an existing `App\Admin\Resources\PostResource` with `status`, `title`, `views`, and the usual primary key; adapt the columns and values to your schema. No example table, migration or widget is installed automatically.

## Statistics

```php
<?php
namespace App\Admin\Widgets;

use App\Admin\Resources\PostResource;
use FloCMS\Admin\Widgets\{StatsWidget, Stat, WidgetContext};

final class ContentOverview extends StatsWidget
{
    protected string $title = 'Content overview'; // or a site language key
    protected array $resources = [PostResource::class];
    protected int|string $columnSpan = 'full';
    protected int $sort = -10;

    protected function stats(WidgetContext $context): array
    {
        $posts = $context->query(PostResource::class);
        return [
            Stat::make('Total posts', $posts->count())->resource(PostResource::class),
            Stat::make('Published', $posts->where('status', '=', 'published')->count())
                ->color('success')->description('Published content'),
            Stat::make('Views', $posts->sum('views')),
        ];
    }
}
```

A statistics widget returns a list of up to eight `Stat` objects. Values are finite integers/floats or escaped display strings (up to 256 bytes). Numbers get separators and fractional numbers two decimals; pass a formatted string for currency or different precision. Labels, descriptions and widget titles accept site language keys and render as escaped text. Colors are `primary`, `success`, `warning`, `danger`, or `neutral`. `resource()` links to an authorized resource list; it does not accept arbitrary URLs.

## Recent records

```php
<?php
namespace App\Admin\Widgets;

use App\Admin\Resources\PostResource;
use FloCMS\Admin\Table\Columns\{TextColumn, BadgeColumn};
use FloCMS\Admin\Widgets\{RecordsWidget, WidgetQuery};

final class RecentPosts extends RecordsWidget
{
    protected string $title = 'Recent posts';
    protected string $resource = PostResource::class;
    protected int $limit = 5; // 1–20
    protected string $sortColumn = 'id';
    protected string $sortDirection = 'desc';

    protected function columns(): array
    {
        return [TextColumn::make('title')->limit(70), BadgeColumn::make('status')];
    }

    protected function query(WidgetQuery $query): WidgetQuery
    {
        return $query->where('status', '=', 'published');
    }
}
```

The source resource is declared automatically by `RecordsWidget`; additional `$resources` may be declared for dependencies. Choose 1–8 distinct display columns. Existing Column rendering/formatting and automatic translation detection are reused. Select display-safe columns, never credentials or other sensitive fields. These tables are read-only and bounded; they link to the authorized resource list for normal resource actions. Use a unique ordering column for deterministic results, or the default primary-key order (`id` unless your resource uses another name, in which case set `sortColumn`).

## Charts

```php
<?php
namespace App\Admin\Widgets;

use App\Admin\Resources\PostResource;
use FloCMS\Admin\Widgets\{ChartWidget, WidgetContext};

final class PublicationChart extends ChartWidget
{
    protected string $title = 'Publication status';
    protected array $resources = [PostResource::class];
    protected string $type = 'bar'; // bar or line

    protected function data(WidgetContext $context): array
    {
        $posts = $context->query(PostResource::class);
        return ['labels' => ['Draft', 'Published'], 'values' => [
            $posts->where('status', '=', 'draft')->count(),
            $posts->where('status', '=', 'published')->count(),
        ]];
    }
}
```

Charts accept one series with matching `labels` and `values` lists, at most 60 points. Values must be finite PHP integers/floats within ±1e15; cast numeric strings from external providers before returning them. Labels may be site language keys. Zero, negative, single-point and empty datasets are supported. SVG charts require no chart library, inline script, inline style, GD, or new runtime extension. Each chart has an accessible title/description and a native expandable table containing every full label and value. Only a few abbreviated x-axis labels are drawn to avoid overlapping text; the table is authoritative for exact values. Chart geometry follows chronological/list order from left to right; surrounding layout, labels and controls support RTL.

## Authorization and scoped reads

Each widget may set `protected ?string $permission = 'reports.view';` for an additional permission and override `public function canView(): bool` to narrow access further. Before any data provider runs, Admin verifies that every declared resource is registered and that the current user has its view permission, then applies the widget's extra permission and `canView()`. A denied widget is omitted entirely, including its title; if every widget is denied, the dashboard shows an empty state rather than falling back to resource cards.

Use `WidgetContext::query(Resource::class)` to obtain an immutable read helper. It starts from the resource's `query()` scope afresh for every read, rechecks source permissions, validates column names, and binds filter values. Reusing a helper cannot lose tenant filters after an aggregate executes. Available methods:

- `where(column, operator, scalar)` with `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `LIKE`.
- `whereIn(column, values)` (up to 100 string/integer/boolean values; an empty list matches nothing), and `whereNull(column)`.
- `count()`, `sum(column)` (float result), and `records(columns, sort, direction, limit)` (1–8 columns, at most 20 rows).

The helper exposes no raw SQL or write method. Each widget has a 64-read budget; at most 16 distinct widget classes may be registered. Put normal tenant/record visibility restrictions in the Resource's query scope, just as for its list. Widgets do not infer a per-record view policy from edit/delete policies. These helpers are not a sandbox for trusted project PHP: directly using `App::db()`, external services or custom Column subclasses bypasses the helper's guarantees and remains the application author's responsibility. Keep constructors/configuration and `canView()` cheap; put read-only data access in `stats()`, `query()` or `data()`. Do not perform writes or other side effects in providers, because initial rendering, refresh and navigation can invoke them repeatedly.

## Refresh, layout and failure handling

Widgets refresh together with the **Refresh dashboard** button. With Reactive enabled, a signed, CSRF-protected component re-renders without a page reload. It accepts no widget class or writable data selector. Every refresh reconstructs configuration and rechecks current permissions, including revoked access. Signed state contains only the component identity; widget datasets are rendered into HTML and are not stored in client-writable state. There is no polling, shared result cache or persisted user layout.

The refresh control is also a normal GET form: the same dashboard works without JavaScript or with `'reactive' => false`. Existing installs need only the optional config keys; no layout/skeleton changes are required. Set `$columnSpan` to 1–4 or `'full'`; spans clamp to configured columns and stack on mobile. `$sort` orders widgets ascending, preserving registration order for ties. A provider failure shows a generic message for that widget while other widgets continue rendering; details go to the application's logger, never into the page. Missing/invalid/duplicate widget definitions are reported by `php flo admin:doctor`; doctor validates configuration without executing data providers.

The isolated browser fixture enables four example widgets after its test-only `/__test/widgets` setup endpoint. Run `npm run test:browser -- tests/Browser/widgets.spec.ts` for end-to-end coverage, and `vendor/bin/phpunit --filter Widget` for registry/rendering/scoping/authorization checks. Test endpoints and fixtures are excluded from Composer distributions.
