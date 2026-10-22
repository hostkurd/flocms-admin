# Custom pages

Since 0.36 a site can add **pages of its own to the panel**: a report, a tool, a help page. Each page gets the panel's layout, sidebar, permissions, Ctrl+K search, right-to-left languages, dark mode and phone layout. You don't need a skeleton controller.

These are not the website pages of **Site → Pages** (0.30). Those are pages of the website, built from blocks.

```php
// admin/Pages/SalesReport.php
namespace App\Admin\Pages;

use FloCMS\Admin\AdminPage;

final class SalesReport extends AdminPage
{
    protected static string $label = 'Sales report';
    protected static ?string $icon = 'chart';
    protected static ?string $group = 'Reports';

    public function widgets(): array { return [MonthlySales::class]; }
    public function view(): ?string { return 'admin/sales-report'; }  // views/admin/sales-report.html
    public function data(array $filters): array { return ['months' => Sales::byMonth($filters)]; }
}
```

```php
// config/admin.php
'admin_pages' => [
    App\Admin\Pages\SalesReport::class,
],
```

The page opens at `/admin/sales-report`. By default only Super admin can open it: tick **Panel pages › Sales report** on a role's page (**Site → Roles**) to give it to other roles.

## Making a page

```bash
php flo make:admin-page SalesReport --table=listings --group=Reports
php flo make:admin-page ListingsReport --resource=Listing --group=Reports
php flo make:admin-page ExchangeRates --form --group=Tools
php flo make:admin-page GettingStarted --view
```

The command writes `admin/Pages/<Name>.php` and adds it to `'admin_pages'` (it adds that list to a `config/admin.php` written before 0.36). It takes these options, in any mix:

- `--view` also writes `views/admin/<slug>.html` with the panel's UI pieces.
- `--form` makes a tool page: a form with Save.
- `--table=listings` adds a read-only table of that database table, with filters (see below). Plain `--table` lists the database's tables to pick one from.
- `--resource=Listing` adds a read-only table of a resource's table, with the columns and labels of its list, and rows that open the record (`Listing`, `ListingResource` or the slug `listings`).

### What `--table` and `--resource` write

The generator reads the table, so the page works the first time it opens:

- **The table must exist.** A misspelt name stops with a suggestion ("Did you mean --table=listings?"). Without a terminal to ask in (a script, `--no-interaction`), plain `--table` names the tables and stops.
- **Columns:** up to six, text first (the first one titles each row on phones and is searchable):
  - text columns are searchable and sortable, numbers sortable, dates a `DateColumn`, true/false a `BooleanColumn`;
  - columns holding the panel's translations (`{"en": …, "ar": …}`) are found from their values and get `->translatable()`;
  - the key is the default sort (newest first) rather than a column;
  - left out, and listed when the command runs: long text, binary data, keys ending in `_id` (a `RelationColumn` can show their title), secret-looking columns (`password`, `token`, `secret` …) and anything past six.
- **Filters:** From/To dates when the table has a `created_at` (or another date column), and a select of the values when a `status`, `type`, `category` or similar column holds a few (at most 12).
- **`--resource`** takes the list's `TextColumn`, `DateColumn`, `BooleanColumn` and `BadgeColumn` columns with their labels and translatable setting, and links rows to the record's edit page. Other column types (relations, media, counts) are listed for you to add by hand.
- **No database connection:** the page is written with the table's name and an `id` column, and the command says so.

Change anything afterwards in the class: it is your code.

Then run `php flo admin:doctor`. It lists the pages and names anything wrong: a class that wasn't found, a view that doesn't exist, a slug that is already taken, a form without `submit()`.

The folder and namespace are `'admin_pages_path'` (default `admin/Pages`) and `'admin_pages_namespace'` (default `App\Admin\Pages`). The panel loads these classes itself, so `composer.json` needs no entry for them.

## What a page holds

Every method is optional. A page shows its parts in this order:

| Part | Method | What it is |
|------|--------|------------|
| Header | static properties, `actions()` | Title, description, group, and buttons |
| Filters | `filters(Form $form): Form` | Fields whose values are kept in the address |
| Widgets | `widgets(): array` | Dashboard widgets, as classes |
| View | `view(): ?string`, `data(array $filters): array` | A template of your own |
| Table | `table(Table $table): Table` | Read-only rows from any query |
| Form | `form(Form $form): Form`, `fill(): array`, `submit(array $data)` | A form with Save |

A page with none of them shows how to add one.

### The definition

| Property | Default | |
|----------|---------|---|
| `$slug` | from the class name | `SalesReport` → `sales-report`, so the page is at `/admin/sales-report`. A trailing `Page` is dropped (`HelpPage` → `help`). Use lower case letters, digits and single hyphens. |
| `$label` | from the class name | A key of the site's lang files (`admin.sales_report`) or plain text. |
| `$description` | `null` | A line under the title, also used by search. |
| `$icon` | `'file'` | One of the panel's icons (the same names as resources' icons). |
| `$group` | `null` | The sidebar group (`'Reports'`, or a lang key). `null` puts the page under **Content**, after the resources. |
| `$sort` | `0` | The page's order in its group. Groups are ordered by the lowest sort of their pages. |
| `$width` | `'default'` | `'full'` uses the whole width even when the panel is boxed. `'narrow'` suits a form or a short text. |
| `$permission` | `null` | The permission that opens the page. `null` means `panel.<slug>`. |
| `$navigation` | `true` | `false` keeps the page out of the sidebar. It still opens, and search still finds it. |
| `$globallySearchable` | `true` | `false` keeps the page out of Ctrl+K search. |
| `$submitLabel` | `''` | The form's Save button, for example `'Save rates'`. |
| `$routes` | `[]` | More addresses of the page (see [Routes](#routes)). |
| `$activity` | `true` | `false` stops a saved form from being written to the activity log. |

Methods:

- `canAccess(): bool` narrows who may open the page, after its permission (for example the user's own team).
- `badge(): string|int|null` returns a number or a short text for the page's sidebar item. `null`, `0` and `''` show nothing. It runs on every panel page, so keep it cheap: one `COUNT`, not a report.

## Example 1: a report with filters, widgets and a table

```php
namespace App\Admin\Pages;

use FloCMS\Admin\Actions\Action;
use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Form\Fields\Date;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Table\Columns\DateColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;

final class SalesReport extends AdminPage
{
    protected static string $label = 'Sales report';
    protected static ?string $description = 'Bookings and income by month';
    protected static ?string $icon = 'chart';
    protected static ?string $group = 'Reports';
    protected static int $sort = 10;

    public function filters(Form $form): Form
    {
        return $form->schema([
            Date::make('from')->label('From'),
            Date::make('to')->label('To'),
            Select::make('city')->options(['erbil' => 'Erbil', 'sulaimani' => 'Sulaimani', 'duhok' => 'Duhok']),
        ]);
    }

    public function widgets(): array
    {
        return [\App\Admin\Widgets\SalesStats::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (Database $db, array $filters): Database {
                $query = $db->table('bookings')->where('status', '=', 'paid');
                if (isset($filters['from'])) { $query = $query->where('paid_at', '>=', $filters['from']); }
                if (isset($filters['to'])) { $query = $query->where('paid_at', '<=', $filters['to'] . ' 23:59:59'); }
                if (isset($filters['city'])) { $query = $query->where('city', '=', $filters['city']); }
                return $query;
            })
            ->columns([
                TextColumn::make('guest')->searchable()->sortable(),
                TextColumn::make('listing')->toggleable(),
                TextColumn::make('city')->sortable(),
                TextColumn::make('amount')->sortable(),
                DateColumn::make('paid_at')->label('Paid on')->sortable(),
            ])
            ->url(fn (object $row): string => \FloCMS\Admin\Admin::url('bookings/edit/' . $row->id))
            ->defaultSort('paid_at', 'desc')
            ->perPage(25);
    }

    public function actions(): array
    {
        return [
            Action::make('email')->label('Email this report')->color('primary')
                ->form(fn (Form $form) => $form->schema([TextInput::make('to')->email()->required()]))
                ->action(function (array $data, AdminPage $page): string {
                    // $page->filterValues() holds the filters the report was opened with.
                    Reports::send($data['to'], $page->filterValues());
                    return 'The report is on its way.';
                }),
        ];
    }
}
```

**Filters** are a form whose values are kept in the address (`?filter[city]=erbil`), so a filtered report can be bookmarked and shared. Use simple fields: text, select, date, toggle. A field's `default()` applies until the form is sent; **Apply** sends the fields as they are (an emptied field stays empty) and **Reset** goes back to the defaults. A value a field refuses (a city that isn't in the options, text in a numeric field) is shown with its error and left out. The values reach:

- `data(array $filters)` and the table's `query()`, as `$filters`;
- widgets, as `$this->pageFilters()`;
- actions and routes, as `$page->filterValues()`.

Only valid, non-empty values are given, so test them with `isset()`.

**The table** is read-only, from any query of yours. It has the list's search (the `searchable()` columns), sorting (the `sortable()` columns, never anything from the address), pages, the **Columns** button (the `toggleable()` columns, remembered for each person) and **Download CSV**. The CSV holds every row of the filters and the search, up to the export limit, with the columns the person shows. `->exportable(false)` removes the download, and `'export' => false` in `config/admin.php` removes it everywhere.

- `->url(fn (object $row) => …)` makes the first searchable column a link. Only `http(s)://` and site addresses starting with `/` are used.
- The other column types work too: `BadgeColumn`, `BooleanColumn`, `DateColumn` and `->format(fn ($value, $row) => …)`.
- Search and sort use the column's name in SQL, so name columns after real columns of the query. Use `->searchAlso(['email'])` for more columns to search.
- Give the table a `defaultSort()` on a unique column (or one ending in one, such as `id`): without an order, the database may return rows in a different order on each page. `admin:doctor` reminds you.
- The table has no Edit, Delete or bulk actions: rows that people edit belong to a resource.

**Widgets** are the dashboard's classes (`php flo make:admin-widget`), with the same permission checks. Each widget reads the page's filters with `$this->pageFilters()`:

```php
final class SalesStats extends StatsWidget
{
    protected array $resources = [BookingResource::class];

    protected function stats(WidgetContext $context): array
    {
        $filters = $this->pageFilters();
        $paid = $context->query(BookingResource::class)->where('status', '=', 'paid');
        if (isset($filters['city'])) { $paid = $paid->where('city', '=', $filters['city']); }
        return [Stat::make('Bookings', $paid->count()), Stat::make('Income', number_format($paid->sum('amount')))];
    }
}
```

On the dashboard `pageFilters()` is empty.

**Header actions** are the Actions API's `Action::make()`:

- An action with a `form()`, or with a confirmation (the default), opens its own page. **Cancel** returns to the report with its filters.
- `->requiresConfirmation(false)` without a form runs at once.
- The callback is `fn (array $data, AdminPage $page)`. It may return a message to show, send a `Notification`, throw a `ValidationException` (the error shows on the field) or return a `Response` (a download).
- `->permission('reports.email')` gives an action its own permission; without one it needs the page's. `->visible()`, `->authorize()`, `->disabled()` and `->url()` work as on resources.
- Running an action is written to the activity log.
- An action's page and its button are sent by the browser itself, not through panel navigation, so an action may answer with a download.
- Upload fields are not supported in page actions.

## Example 2: a tool page with a form

```php
final class ExchangeRates extends AdminPage
{
    protected static string $label = 'Exchange rates';
    protected static ?string $icon = 'settings';
    protected static ?string $group = 'Tools';
    protected static string $width = 'narrow';
    protected static string $submitLabel = 'Save rates';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('base')->options(['USD' => 'US dollar', 'EUR' => 'Euro'])->required(),
            TextInput::make('iqd')->label('Iraqi dinar (IQD)')->numeric()->required(),
            Toggle::make('round')->label('Round prices to whole numbers'),
        ]);
    }

    public function fill(): array
    {
        $row = \FloCMS\Core\App::db()->table('rates')->where('id', '=', 1)->first();
        return $row !== null ? (array) $row : ['base' => 'USD'];
    }

    public function submit(array $data): ?string
    {
        if ((float) $data['iqd'] <= 0) {
            throw new \FloCMS\Core\ValidationException(['iqd' => ['Enter a rate above zero.']]);
        }
        \FloCMS\Core\App::db()->table('rates')->where('id', '=', 1)->update($data);
        return 'Rates saved. Prices on the site now use them.';
    }
}
```

The form uses the resources' fields and checks:

- sections, tabs and grids;
- translatable fields (their value in `$data` is `['en' => …, 'ar' => …]`: encode it before storing it in one column);
- repeaters (`$data` holds the list);
- `MediaPicker`, `RichEditor`, `CodeEditor`, `KeyValue`;
- the error summary, the **unsaved changes** warning and Ctrl+S.

`fill()` gives the starting values by field name.

`submit(array $data)` gets the checked values. It may:

- save them anywhere;
- throw a `ValidationException` (the errors show on the fields and nothing else happens);
- send a `Notification`;
- return a message (the default message is "Saved.").

A save is written to the activity log as **Page saved**, with the fields that changed. Passwords and fields marked `hiddenFromActivity()` are left out.

Not on page forms in 0.36: upload fields (`FileUpload`, `ImageUpload`; use a `MediaPicker` or a settings page), `Slug`, and the relation fields `BelongsTo` and `BelongsToMany` (use a `Select` with options).

## Example 3: a help page with a view

```php
final class GettingStarted extends AdminPage
{
    protected static ?string $icon = 'help-circle';
    protected static ?string $group = 'Help';
    protected static ?string $permission = 'panel.help'; // one permission for all help pages

    public function view(): ?string { return 'admin/getting-started'; }

    public function data(array $filters): array
    {
        return ['steps' => [['label' => 'Add your logo', 'done' => setting('site.logo') !== null, 'url' => \FloCMS\Admin\Admin::url('settings/edit/site')]]];
    }
}
```

```html
<!-- views/admin/getting-started.html -->
<div class="fa-split">
    <section class="fa-card">
        <h2>Set up your site</h2>
        <ul>@foreach($steps as $step)<li>{{ $step['label'] }} @if(!$step['done'])<a href="{{ $step['url'] }}">Open</a>@endif</li>@endforeach</ul>
    </section>
    <section class="fa-card fa-empty">
        <h2>No questions yet</h2>
        <p>When people send a question from the site, it shows up here.</p>
    </section>
</div>
```

The view is rendered with the template engine. It gets what `data()` returns, `$filters` and `$page`. Escape with `{{ }}` as everywhere.

## The UI pieces

These classes make a view look like the rest of the panel without CSS of your own. They follow the theme, RTL and dark mode, and they use no `style` attributes, so a strict Content-Security-Policy keeps working.

| Class | What it is |
|-------|------------|
| `fa-card` | A white box with a border (padded inside a page's view). `<h2>` inside is its heading. |
| `fa-stat-row` with `fa-stat-tile` | A row of numbers: `<div class="fa-stat-tile"><span class="fa-stat-label">Bookings</span><span class="fa-stat-value">42</span></div>` |
| `fa-split` | Two columns (3:2); `fa-split is-even` is 1:1. One column on phones. |
| `fa-empty` | An empty state: a title, a line of text and an optional button, centred. |
| `fa-btn`, `fa-btn fa-btn-primary` | Buttons and links that look like buttons. |
| `fa-table` | A table with the list's look. Wrap it in `fa-table-wrap` to scroll on phones. |
| `fa-muted`, `fa-alert fa-alert-info` | Quieter text, and a notice. |

## Routes

A page answers only the addresses it declares:

```php
protected static array $routes = ['chart' => 'chartData', 'pdf' => 'downloadPdf'];

public function chartData(): array { return Sales::byMonth($this->filterValues()); }           // JSON
public function downloadPdf(): \FloCMS\Core\Http\Response { return Response::make($pdf)->header('Content-Type', 'application/pdf'); }
```

`/admin/sales-report/route/chart` calls `chartData()`:

- An array is sent as JSON.
- A `Response` is sent as it is.
- A string is shown inside the panel.

Routes keep the page's permission, `canAccess()` and filters, and CSRF protection on POST. Anything after the route name is passed as arguments, cast to the method's `int`, `float` or `bool` parameters: `/route/chart/2026` calls `chartData(2026)` for `chartData(int $year)`. Too many or too few parts, or text where a number is expected, answers 404.

Panel navigation loads links as panel pages, so give a link to a route that downloads a file the `download` attribute (or `data-no-navigate`).

## Permissions, sidebar and search

- **Permissions**: each page needs `panel.<slug>` unless `$permission` names another. They are listed on the Roles page under **Panel pages**, with an **All panel pages** switch (`panel.*`). Super admin (`*`) has them all. Pages sharing a permission share a box. Someone without the permission gets 403 on every address of the page, and doesn't see it in the sidebar or search.
- **Sidebar**: pages without a group come under **Content** after the resources. Then come the groups, then **Site**.
- **Search**: Ctrl+K finds a page by its label, group or description.
- **Home page**: `'home' => App\Admin\Pages\SalesReport::class` in `config/admin.php` makes `/admin`, and the page after signing in, open that page. People who may not open it get the dashboard. The Dashboard link stays in the sidebar.
- **Addresses** that can't be a page's slug: the panel's own pages (`dashboard`, `settings`, `media`, `roles`, `trash`, `activity`, `search`, `profile` …), a resource's slug, and a slug the site's own controller answers (`NewsController` answers `news`). `admin:doctor` and the log (with `APP_DEBUG`) say which page was skipped and why.

## When a part fails

A part that throws (the filters, a widget, the view, the table or the form) shows a box saying it could not be shown, and why, in plain words. The rest of the page still works.

| Problem | What the box says |
|---------|-------------------|
| A table that isn't in the database | The table "bookings" was not found in the database. Check table() in admin/Pages/SalesReport.php. |
| A column that isn't in the table | The column "nickname" was not found. Check view() / data() in admin/Pages/SalesReport.php. |
| No database connection | The database is not connected. Check the database settings in .env. |
| A setup mistake the panel finds (a missing view, a filter field it can't use) | The panel's message, with the method and file. |
| Anything else | An error in table() of admin/Pages/SalesReport.php. The details are in the site's log. |

The error goes to the log (`storage/logs/app.log`) too. With `APP_DEBUG=true` the box also shows the error itself, with its file and line. Without it, your own exceptions' messages stay out of the page, since they may hold anything.

## Not in 0.36

- Editing or deleting rows from a page's table.
- Several tabs of sub-pages in one class.
- Pages outside the panel (public forms).
- A page inside a resource (a record's extra tab).
- Upload fields on a page's form.
- Header actions in a dialog: they open their own page.
