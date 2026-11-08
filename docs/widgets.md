# Dashboard widgets (Admin 0.8, redesigned in 0.42)

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

Create a widget with the CLI, which writes the class and adds it to `'widgets'`:

```
php flo make:admin-widget ContentOverview --resource=Post
php flo make:admin-widget RecentPosts --type=records --resource=Post
php flo make:admin-widget PublicationChart --type=chart --resource=Post
```

`--type` is `stats` (default), `records` or `chart`; `--resource` names the resource it reads (required for `records`). If `config/admin.php` has a `'widgets'` list the command can't edit safely, it prints the line to add. `php flo admin:list` shows the registered widgets. If a listed class is not found, `php flo admin:doctor` names the file it expected (for example `admin/Widgets/ContentOverview.php`).

Or create the PHP files in `admin/Widgets` by hand. Admin registers an autoloader for that directory/namespace; no application Composer autoload change is needed. Only explicitly listed classes load as widgets. Register every source Resource in Admin too. The built-in `FloCMS\Admin\Users\UsersResource` is also available while user management is enabled, with its normal `users.manage` permission. The examples below assume an existing `App\Admin\Resources\PostResource` with `status`, `title`, `views`, and the usual primary key; adapt the columns and values to your schema. No example table, migration or widget is installed automatically.

## The dashboard (0.42)

With widgets registered, the dashboard opens with a header: a greeting with the person's first name ("Good afternoon, Sara"), today's date, the **period buttons** and **Refresh**, and the **quick buttons** under it. Without widgets it keeps the resource-count cards, now with the same greeting and quick buttons.

```php
'widget_columns' => 3,
'dashboard_periods' => [7, 30, 90, 365],   // the buttons, in days; [] hides them
'dashboard_period' => 30,                  // the one it opens with
'dashboard_actions' => [                   // the first is the main button
    App\Admin\Resources\ListingResource::class,                       // "New Property": its Create page
    [App\Admin\Resources\ArticleResource::class, 'label' => 'New article', 'icon' => 'pencil'],
    App\Admin\Pages\SalesReport::class,                               // a panel page
],
```

- **Period.** The buttons are links (`/admin/dashboard?period=7`), so the period stays in the address and works without JavaScript; Refresh keeps it. Every widget reads it from `$context->period()`: `from()` and `to()` ("Y-m-d H:i:s"), `previous()` (the same number of days just before), `label()` ("Last 30 days"), `days()`. A period of 7 days is today and the six days before. On panel pages widgets get the default period.
- **Quick buttons** show only for people who may use them: a resource's needs its create permission and `canCreate()`, a panel page its permission and `canAccess()`.
- **Widget headers.** Each widget may show a line beside its title, `protected string $headerSubtitle` or your own `headerSubtitle()`, and a link at the end, `headerLink()`. `RecordsWidget` links to its resource ("See all"); `ActivityWidget` to the Activity page.

```php
public function headerSubtitle(WidgetContext $context): string { return $context->period()->label(); }
public function headerLink(WidgetContext $context): ?array { return ['label' => 'See all', 'url' => $context->url('inquiries', ['status' => 'new'])]; }
```

Links in widgets (stats, list items, headers) must stay on the site: a path from the root or an address of this site. `$context->url($path, $query)` gives a panel address in the current language.

### Counting by period

`WidgetQuery` (0.42) also counts per value and per day:

```php
$inquiries = $context->query(InquiryResource::class);
$period = $context->period();
$now = $inquiries->where('created_at', '>=', $period->from())->count();
$before = $inquiries->where('created_at', '>=', $period->previous()->from())->where('created_at', '<', $period->from())->count();
$bySource = $inquiries->where('created_at', '>=', $period->from())->countBy('type');   // ['listing' => 20, 'contact' => 5], most first
$perDay = $inquiries->countByDay('created_at', $period->from(), $period->to());       // ['2026-11-08' => 3, ...]
$points = $period->series($perDay);   // labels + values: per day up to 31 days, per week up to 180, per month up to 1,780, else per year (at most 60 points)
```

`countBy($column, $limit = 20)` returns up to 100 values. PHP turns number-like keys into integers (`countBy('bedrooms')` gives `[3 => 5]`); item labels accept them. `countByDay()` groups by the database's `DATE()`, so the database and PHP should use the same time zone; it covers at most 3,660 days. `where()` also takes `NOT LIKE`, and there is `whereNotNull()`. Each read counts towards the widget's budget of 64.

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

Headline numbers (0.42): set `protected bool $separateCards = true;` and the statistics become cards of their own, without the widget's frame or visible title, four in a row (two on a phone). A `Stat` can also have:

```php
Stat::make('Inquiries', $now)
    ->icon('mail')                          // a panel icon in the stat's colour
    ->trend($now, $before)                  // ▲ 23% green, ▼ red; nothing when $before is 0
    ->description('vs ' . $before . ' in the previous 30 days')
    ->sparkline($points['values'])          // a small line, up to 60 values
    ->url($context->url('inquiries', ['status' => 'new']));
Stat::make('Published', 28)->change(4);    // ▲ 4
Stat::make('Waiting', 3)->trend(3, 5)->invertTrend();   // going down is good: ▼ shows green
Stat::make('New', 4)->description('2 waiting more than 48 hours')->descriptionColor('warning');
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

Charts accept one series with matching `labels` and `values` lists, at most 60 points. Since 0.42:

- A `line` or `bar` chart may also show the previous period as a dashed line: `'previous' => [...]` with as many values (`$period->previous()->series($perDay, like: $period)` gives that), and `'label'` / `'previousLabel'` for the legend. A line chart is smooth, with a light fill under it.
- `protected string $type = 'donut';` draws a ring with a legend (value and share) and the total in the middle: up to 8 parts, none below 0; `'total' => 'inquiries'` is the word under the total.

```php
protected string $type = 'line';
protected function data(WidgetContext $context): array
{
    $query = $context->query(InquiryResource::class);
    $period = $context->period(); $previous = $period->previous();
    $now = $period->series($query->countByDay('created_at', $period->from(), $period->to()));
    $before = $previous->series($query->countByDay('created_at', $previous->from(), $previous->to()), like: $period);   // same points as $now
    return $now + ['previous' => $before['values'], 'label' => 'This period', 'previousLabel' => 'Previous period'];
}
```

 Values must be finite PHP integers/floats within ±1e15; cast numeric strings from external providers before returning them. Labels may be site language keys. Zero, negative, single-point and empty datasets are supported. SVG charts require no chart library, inline script, inline style, GD, or new runtime extension. Each chart has an accessible title/description and a native expandable table containing every full label and value. Only a few abbreviated x-axis labels are drawn to avoid overlapping text; the table is authoritative for exact values. Chart geometry follows chronological/list order from left to right; surrounding layout, labels and controls support RTL.

## Bars, progress, lists and activity (0.42)

Four more widget types. Their items are plain arrays; texts may be site lang keys, links must stay on the site, colours are `primary`, `success`, `warning`, `danger`, `neutral` or `chart1`–`chart6`.

```php
use FloCMS\Admin\Widgets\{BarListWidget, ProgressWidget, ListWidget, WidgetContext};

final class PropertiesByStatus extends BarListWidget          // label, value and a bar
{
    protected array $resources = [ListingResource::class];
    protected function items(WidgetContext $context): array
    {
        $listings = $context->query(ListingResource::class);
        return [
            ['label' => 'For sale', 'value' => $listings->where('status', '=', 'for_sale')->count()],
            ['label' => 'Sold', 'value' => $listings->where('status', '=', 'sold')->count(), 'color' => 'neutral'],
        ];
    }
    protected function footer(WidgetContext $context): string { return 'Drafts are not counted'; }
}

final class Developments extends ProgressWidget               // name, %, a line under the bar
{
    protected function items(WidgetContext $context): array
    {
        return [['label' => 'Urban86', 'value' => 62, 'meta' => 'Glyfada', 'description' => 'Completion expected June 2027', 'url' => $context->url('projects/edit/4')]];
    }
}

final class NeedsAttention extends ListWidget                 // icon, title, line, number, link
{
    protected function items(WidgetContext $context): array
    {
        return [['icon' => 'user-x', 'color' => 'danger', 'title' => 'Inquiries without an owner', 'description' => 'New and not assigned to anyone',
                 'value' => $count, 'url' => $context->url('inquiries')]];
    }
}
```

- `BarListWidget`: up to 12 rows; bars against the largest value, or against 100 with `protected bool $percent = true;`. `footer()` adds a line under them.
- `ProgressWidget`: up to 12 items, values kept within 0–100.
- `ListWidget`: up to 12 items. Items whose value is 0 are left out (`$showZero = true` keeps them); with none left it says `$empty` ("Nothing needs your attention").
- `ActivityWidget` is ready to use: register `FloCMS\Admin\Widgets\ActivityWidget::class` for the latest changes from the activity log (who created, edited, deleted or restored what). It shows only to people who may open the Activity page (`activity.view`) while the log is on. Extend it to change `$limit` (default 5).

## Authorization and scoped reads

Each widget may set `protected ?string $permission = 'reports.view';` for an additional permission and override `public function canView(): bool` to narrow access further. Before any data provider runs, Admin verifies that every declared resource is registered and that the current user has its view permission, then applies the widget's extra permission and `canView()`. A denied widget is omitted entirely, including its title; if every widget is denied, the dashboard shows an empty state rather than falling back to resource cards.

Use `WidgetContext::query(Resource::class)` to obtain an immutable read helper. It starts from the resource's `query()` scope afresh for every read, rechecks source permissions, validates column names, and binds filter values. Reusing a helper cannot lose tenant filters after an aggregate executes. Available methods:

- `where(column, operator, scalar)` with `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `LIKE`.
- `whereIn(column, values)` (up to 100 string/integer/boolean values; an empty list matches nothing), and `whereNull(column)`.
- `count()`, `sum(column)` (float result), and `records(columns, sort, direction, limit)` (1–8 columns, at most 20 rows).

The helper exposes no raw SQL or write method. Each widget has a 64-read budget; at most 16 distinct widget classes may be registered. Put normal tenant/record visibility restrictions in the Resource's query scope, just as for its list. Widgets do not infer a per-record view policy from edit/delete policies. These helpers are not a sandbox for trusted project PHP: directly using `App::db()`, external services or custom Column subclasses bypasses the helper's guarantees and remains the application author's responsibility. Keep constructors/configuration and `canView()` cheap; put read-only data access in `stats()`, `query()` or `data()`. Do not perform writes or other side effects in providers, because initial rendering, refresh and navigation can invoke them repeatedly.

## Refresh, layout and failure handling

Widgets refresh together with the **Refresh dashboard** button. With Reactive enabled, a signed, CSRF-protected component re-renders without a page reload. It accepts no widget class or writable data selector. Every refresh reconstructs configuration and rechecks current permissions, including revoked access. Signed state contains only the component identity and the chosen period (read-only, one of the configured ones); widget datasets are rendered into HTML and are not stored in client-writable state. There is no polling, shared result cache or persisted user layout.

The refresh control is also a normal GET form: the same dashboard works without JavaScript or with `'reactive' => false`. Existing installs need only the optional config keys; no layout/skeleton changes are required. Set `$columnSpan` to 1–4 or `'full'` (a dashboard of 3 columns suits the mockup-style layout: headline cards `'full'`, a chart 2, the rest 1); spans clamp to configured columns and stack on mobile. `$sort` orders widgets ascending, preserving registration order for ties. A provider failure shows a generic message for that widget while other widgets continue rendering; details go to the application's logger, never into the page. Missing/invalid/duplicate widget definitions are reported by `php flo admin:doctor`; doctor validates configuration without executing data providers.

The isolated browser fixture enables four example widgets after its test-only `/__test/widgets` setup endpoint. Run `npm run test:browser -- tests/Browser/widgets.spec.ts` for end-to-end coverage, and `vendor/bin/phpunit --filter Widget` for registry/rendering/scoping/authorization checks. Test endpoints and fixtures are excluded from Composer distributions.
