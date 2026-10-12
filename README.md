# flocms-admin

An admin panel builder for [FloCMS](https://github.com/hostkurd/FloCMS). You describe a database table as a PHP class (a *resource*) and get a list page with search, sorting and pagination, plus create, edit and delete pages, under clean URLs such as `/admin/properties/edit/5`.

- Server-rendered pages, a little vanilla JavaScript, no build step: works on plain cPanel hosting.
- **Translatable fields**: one value per site language, stored as JSON in the field's column, edited through a language badge and a dialog.
- **Right-to-left** admin from the start (Arabic, Kurdish, …), with the right `dir` on every language input.
- Uses FloCMS's own login, CSRF protection, permissions and validation rules.
- Optional and removable: no file of the site is edited.

## Requirements

- PHP 8.1+
- `hostkurd/flocms-core` 2.3+ (skeleton 1.7.7+), which added controller resolvers
- MySQL 5.7+ or MariaDB 10.3+

## Installation

```bash
composer require hostkurd/flocms-admin
php flo admin:install
```

`admin:install` creates two things and changes nothing else:

- `config/admin.php`, the package's settings (deleting it switches the panel off);
- `admin/Resources/`, where your resource classes live.

Then create a resource and its table:

```bash
php flo make:admin-resource Property --migration
php flo migrate
```

Log in as an admin and open `/admin/properties`. `php flo admin:doctor` checks the setup at any time.

### How it plugs in

FloCMS 2.3 asks *controller resolvers* for a controller when a URL has no application controller. This package registers one resolver from Composer's autoloader. It answers only for `/admin/<slug>` where `<slug>` is one of your resources, and only after FloCMS's admin login check. The skeleton's own `/admin` dashboard, `/admin/users` and the login page are untouched; **application controllers always win**.

That last point has a consequence. If your site has `controllers/PropertiesController.php` (for a public `/properties` page), FloCMS uses it for `/admin/properties` too. Give the resource another slug, e.g. `protected static string $slug = 'listings';`. `admin:doctor` reports such collisions, and in debug mode they are logged to `storage/logs/app.log`.

## Resources

A resource is one table, with the fields of its form and the columns of its list:

```php
<?php
declare(strict_types=1);

namespace App\Admin\Resources;

use FloCMS\Admin\Form\Fields\{Date, Select, Slug, Textarea, TextInput, Toggle};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\{BadgeColumn, BooleanColumn, DateColumn, TextColumn};
use FloCMS\Admin\Table\Table;

final class PropertyResource extends Resource
{
    protected static string $table = 'properties';
    protected static string $slug = 'properties';     // URL: /admin/properties
    protected static bool $timestamps = true;         // fills created_at / updated_at
    protected static string $label = 'Property';      // or a lang key: 'admin.property'
    protected static string $pluralLabel = 'Properties';
    protected static ?string $icon = 'building';
    protected static int $navigationSort = 10;

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->translatable()->rules('required|max:190')->columnSpan(2),
            Slug::make('slug')->from('title')->unique()->rules('required|max:190'),
            Select::make('type')->options(['sale' => 'For sale', 'rent' => 'For rent'])->required(),
            TextInput::make('price')->numeric()->rules('min:0'),
            Date::make('available_from'),
            Textarea::make('description')->translatable()->rows(8),
            Toggle::make('published'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('title')->searchable()->sortable(),
                BadgeColumn::make('type')->colors(['sale' => 'green', 'rent' => 'blue'])
                    ->labels(['sale' => 'For sale', 'rent' => 'For rent']),
                TextColumn::make('price')->sortable()->format(fn ($value) => number_format((float) $value) . ' €'),
                BooleanColumn::make('published'),
                DateColumn::make('updated_at')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->perPage(25);
    }
}
```

Files named `*Resource.php` in `admin/Resources/` are loaded automatically (the package autoloads the `App\Admin\Resources` namespace, so the site's `composer.json` needs no change). To load only some, list the classes under `resources` in `config/admin.php`.

### Settings

| Property | Default | Meaning |
| --- | --- | --- |
| `$table` | (required) | Database table |
| `$slug` | (required) | URL segment: lower case letters, digits and single hyphens |
| `$primaryKey` | `'id'` | Primary key column |
| `$timestamps` | `false` | Fill `created_at` on insert and `updated_at` on every save |
| `$label`, `$pluralLabel` | from the table / slug | Text, or a key of the site's lang files |
| `$icon` | `'list'` | `list`, `file`, `building`, `home`, `users`, `tag`, `image`, `calendar`, `mail`, `box`, `star`, `settings` |
| `$navigationSort` | `0` | Order in the sidebar |
| `$permissionPrefix` | `'<permission_prefix>.<slug>'` | See [Permissions](#permissions) |
| `$permissions` | `[]` | Per-ability overrides: `['delete' => 'settings.delete']` |

Optional methods: `query(Database $query)` to narrow every query (e.g. `->where('site_id', '=', 1)`), `beforeSave(array $data, ?object $record): array`, `afterSave(object $record)` and `canDelete(object $record): bool`.

Only the fields declared in `form()` are ever written. The primary key, `created_at` and `updated_at` never come from the request.

### Form fields

| Field | Input | Notes |
| --- | --- | --- |
| `TextInput` | text | `->email()`, `->url()`, `->tel()`, `->numeric()`, `->password()`, `->maxLength(190)`; text and URL inputs can be translatable |
| `Textarea` | textarea | `->rows(8)`; translatable; full width by default |
| `Select` | select | `->options([...])` or `->options(fn () => [...])`; only listed values are accepted |
| `Toggle` | checkbox | Stored as `1` / `0`; an unchecked box saves `0` |
| `Date` | date | Stored as `Y-m-d`, empty as `NULL` |
| `DateTime` | datetime-local | Stored as `Y-m-d H:i:s`, empty as `NULL` |
| `Slug` | text | `->from('title')` fills it from the default-language title while empty (in the browser and on save) |
| `Hidden` | hidden | Written, not shown |
| `Placeholder` | read-only text | `->content(fn (?object $record) => ...)`; never written |

Every field: `->label()`, `->rules('required|max:190')`, `->required()`, `->default()`, `->help()`, `->placeholder()`, `->disabled()` (shown, never written), `->nullable()`, `->unique()`, `->columnSpan(2)`.

Passwords are hashed with `password_hash()` (use `->password(false)` to store them as typed), are never sent back to the browser, and an empty password on the edit page keeps the stored one.

### Validation

The rules are FloCMS's own: `required`, `string`, `integer`, `numeric`, `min`, `max`, `in`. `min`/`max` compare numbers when the field also has `integer` or `numeric`, and characters otherwise. On top of those, fields check their type (email, URL, date, select option), and `->unique()` checks the table, ignoring the record being edited. Messages are shown in the admin's language.

### Table columns

| Column | Shows | Options |
| --- | --- | --- |
| `TextColumn` | text | `->limit(60)` |
| `BooleanColumn` | Yes / No badge | |
| `DateColumn` | a date | `->dateFormat('Y-m-d H:i')` |
| `BadgeColumn` | a coloured badge | `->colors([...])` (gray, green, blue, yellow, red, purple), `->labels([...])` |

Every column: `->label()`, `->sortable()`, `->searchable()`, `->format(fn ($value, $record) => ...)` (the result is escaped), `->translatable()`. Search and sort only use columns marked `searchable()` / `sortable()`.

## Translatable fields

Mark a text field `->translatable()` and it gets one value per site language, from `LANGUAGES` and `DEFAULT_LANG` in `.env`:

```dotenv
LANGUAGES=en,ar,el
DEFAULT_LANG=en
```

The form shows the default-language input with a language badge beside it. The badge shows which languages are filled; clicking it opens a dialog with one input per language. Arabic, Kurdish and the other right-to-left languages get `dir="rtl"`. Everything is a real form field, so the page also works without JavaScript.

`required` applies to the default language only. The other languages are optional, and every other rule (`max:190`, …) applies to them when they are filled.

### Storage

Each translatable field is one column holding a JSON object:

```json
{"en":"Sea view villa","ar":"فيلا بإطلالة على البحر","el":"Βίλα με θέα στη θάλασσα"}
```

Use a `JSON` column (on MariaDB this is `LONGTEXT` with a validity check) or any text column. Empty languages are not stored; a field with no value at all is stored as `NULL`. Search looks in every language; sorting uses the admin's language.

**Existing columns.** A column that already holds plain text can be switched to translatable without a data migration: plain text is read as the default language and saved as JSON the next time the record is saved. (Change the column to `TEXT` first if it is shorter; it must not be a `JSON` column until all rows are JSON.)

### On the website

```php
{{ localized($property->title) }}          // current language, falls back to the default language
{{ localized($property->title, 'el') }}    // a given language
```

`localized()` returns the value in the requested language, else in the default language, else in the first filled language, else `''`. It accepts the raw column value, a decoded array or plain text. The same function is available as `FloCMS\Admin\Translation\Translations::get()`, plus `Translations::all()` for every language. If you later remove the package, replace these calls.

## Languages, RTL and the panel's own words

- The panel's language is the language of the URL: `/ku/admin/properties` shows the panel in Kurdish.
- Its direction comes from `lng.dir` in the site's `lang/<code>.php` (the skeleton's files have it), else from the package's list of right-to-left languages.
- The package ships its words in English, Arabic, Kurdish (Sorani) and Greek. A site changes any of them by adding an `admin.<key>` line to its own lang file, e.g. `'admin.actions.create' => 'Add'`. See `resources/lang/en.php` for the keys.
- Language names in the dialog come from the site's `lng.<code>` keys, else the name in that language.
- Labels you write (`$label`, field labels, options) are shown as written, unless they are keys of the site's lang file, which are translated.

## Permissions

Each action needs a permission, checked by FloCMS through `Controller::$actionPermissions`:

| Page | Permission |
| --- | --- |
| List | `content.<slug>.view` |
| Create | `content.<slug>.create` |
| Edit | `content.<slug>.update` |
| Delete | `content.<slug>.delete` |

With the skeleton's default roles, Editors (`content.*`), Admins and Super Admins can use every resource. Change the `content` prefix in `config/admin.php`, or per resource with `$permissionPrefix` / `$permissions`. Sites without a `permissions` map fall back to FloCMS's `admin_access` check. The sidebar and the Edit / Delete buttons follow the same permissions.

Deleting is a POST with a CSRF token (FloCMS rejects it otherwise) and asks for confirmation first.

## Configuration

`config/admin.php`:

| Key | Default | |
| --- | --- | --- |
| `resources` | `[]` | Classes to load; empty loads every `*Resource.php` in `resources_path` |
| `resources_path` | `'admin/Resources'` | Folder of the resource classes |
| `resources_namespace` | `'App\Admin\Resources'` | Their namespace |
| `brand` | `SITE_NAME_ADMIN` | Name at the top of the sidebar |
| `per_page` | `25` | Rows per page |
| `rtl_languages` | `ar, ku, ckb, fa, he, ur` | Content languages written right to left |
| `permission_prefix` | `'content'` | Prefix of the permission names |

The panel is drawn inside the active template's `layouts/admin.html`, which must print `$data['content']` (the skeleton's does). Its CSS and JavaScript are printed inline, so there is nothing to publish and nothing goes stale after `composer update`.

## Commands

| Command | |
| --- | --- |
| `php flo admin:install` | Create `config/admin.php` and `admin/Resources/` (never overwrites) |
| `php flo make:admin-resource Property [--table=properties] [--slug=properties] [--migration] [--force]` | Create a resource class, and optionally a migration |
| `php flo admin:doctor` | Check config, languages, layout, slug collisions, tables and columns |

## Removing the package

```bash
composer remove hostkurd/flocms-admin
```

Then delete `config/admin.php` and `admin/` (your own code, so the package never deletes it). The package creates no tables. Templates that call `localized()` need a replacement.

## Roadmap

- 0.2: filters, image and file fields (flocms-uploader), bulk delete, dashboard with stat cards, overridable views
- 0.3: relationships, a settings page, more generators

## Development

```bash
composer install
vendor/bin/phpunit
```

The MySQL / MariaDB tests run when `FLO_ADMIN_TEST_DB_NAME` (and `_HOST`, `_PORT`, `_USER`, `_PASS`) are set; the rest use SQLite.

## License

MIT
