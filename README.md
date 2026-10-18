# flocms-admin

The admin panel for [FloCMS](https://github.com/hostkurd/FloCMS): a dashboard, users management, a profile page, website settings pages, and a builder for your own content. You describe a database table as a PHP class (a *resource*) and get a list page with search, sorting and pagination, plus create, edit and delete pages, under clean URLs such as `/admin/properties/edit/5`.

FloCMS provides the security (login, sessions, roles and permissions); flocms-admin provides the screens and always asks FloCMS who may do what.

- Server-rendered Reactive lists and dialogs, plus panel navigation without full document reloads. Compiled assets ship with Composer: no hosting-side build, including on cPanel.
- **Translatable fields**: one value per site language, stored as JSON in the field's column, with the same default-language input, language badge and translation dialog in modal and full-page forms.
- **Right-to-left** admin from the start (Arabic, Kurdish, …), with the right `dir` on every language input.
- Uses FloCMS's own login, CSRF protection, permissions and validation rules.
- **Forms**: sections, keyboard/RTL tabs, responsive grids and server-validated dependencies. [Forms guide](docs/forms.md).
- **Table filters and bulk Actions**: selects, toggles, date ranges and explicit, atomic multi-record workflows. [API guide](docs/tables-and-login.md).
- **Optional Admin login design** with existing FloCMS authentication; see [upgrade instructions](UPGRADING.md).
- **Actions and Notifications**: PHP-defined row/toolbar/page workflows, validated confirmation forms, and persistent localized feedback.
- **Website settings**: settings pages declared in PHP, a page-level language switch, one image per language, and a cached `setting('site.title')` helper for templates. [Settings guide](docs/settings.md).
- **Relationships**: `BelongsTo` (a city, an agent) and `BelongsToMany` (features, through a pivot table) with a searchable combobox, dependent choices, related titles in lists and relation filters. [Relationships guide](docs/relationships.md).
- **Media library**: images and videos uploaded once, with titles and alt text per language, in folders, picked in forms and the rich text editor, with "used in" tracking, focal points, a duplicate check and responsive `<img>` output on the website. [Media guide](docs/media.md).
- **Ctrl+K search**: one box, from any page, that finds records of every resource, the panel's pages, settings and media you may open. [Search guide](docs/search.md).
- **Relation managers**: a record's related records (a property's units) on its edit page, with search and New, Edit and Delete in dialogs, always limited to that parent by the server. [Relation managers guide](docs/relation-managers.md).
- **Roles and permissions**: change what each role may do in a permission grid, add roles with their own level, and keep every change in the activity log, on top of `config/config.php`. [Roles guide](docs/roles.md).
- **Panel settings and personal preferences**: name, logo, colour, theme and density from the browser; each user's own theme, panel language, density and visible columns.
- Optional and removable: no file of the site is edited.

## Requirements

- PHP 8.1+
- `hostkurd/flocms-core` ^2.4.0, `hostkurd/flocms-reactive` ^0.5.1 and `hostkurd/flocms-uploader` ^1.2.1 (installed by Composer)
- A stable secret `APP_KEY` of at least 32 bytes for signed Reactive snapshots
- MySQL 5.7+ or MariaDB 10.3+

## Installation

```bash
composer require hostkurd/flocms-admin
php flo admin:install
```

`admin:install` creates these and changes nothing else:

- `config/admin.php`, the package's settings (deleting it switches the panel off);
- `admin/Resources/`, where your resource classes live;
- two migrations: FloCMS's `settings` table, which the settings pages share with core (created only if the site lacks it), and `admin_user_preferences` (personal preferences).

Then create the tables, a resource and, if you like, website settings pages:

```bash
php flo migrate
php flo make:admin-resource Property --migration
php flo migrate
php flo make:admin-settings
```

Log in and you land on the dashboard (`/admin/dashboard`; on skeletons before 1.8, open it yourself or link it from `views/pages/admin_index.html`). `php flo admin:doctor` checks the setup at any time.

Create the first admin with `php flo user:create` if you have not yet.

### How it plugs in

FloCMS 2.3 asks *controller resolvers* for a controller when a URL has no application controller. This package registers one resolver from Composer's autoloader. It answers only for `/admin/<slug>` where `<slug>` is one of your resources, and only after FloCMS's admin login check. The skeleton's own `/admin` dashboard, `/admin/users` and the login page are untouched; **application controllers always win**.

That last point has a consequence. If your site has `controllers/PropertiesController.php` (for a public `/properties` page), FloCMS uses it for `/admin/properties` too. Give the resource another slug, e.g. `protected static string $slug = 'listings';`. `admin:doctor` reports such collisions, and in debug mode they are logged to `storage/logs/app.log`.

## Dashboard, users and profile

| Page | URL | Who |
| --- | --- | --- |
| Dashboard | `/admin/dashboard` | Every logged-in admin user; one card per resource they may view, with its number of records |
| Users | `/admin/accounts` | `users.manage` (Admins and Super Admins in the skeleton) |
| Profile | `/admin/profile` | Every logged-in admin user, for their own account, with a "My preferences" tab |
| Settings | `/admin/settings` | `settings.manage` for website settings pages, `settings.panel` for Admin panel (Admins and Super Admins in the skeleton) |
| Roles | `/admin/roles` | `roles.manage` (Super Admins in the skeleton, through `*`) |

**`/admin`** opens the dashboard on skeleton 1.8+: the package sets `Config 'admin.home'` and the skeleton's `/admin` page follows it. Set `admin.home` in your `config/config.php` to send `/admin` elsewhere, or to `null` to keep the skeleton's page.

**Users** manages the skeleton's `users` table with the skeleton's own rules (`FloCMS\Core\Auth`):

- a role can only be given up to your own level (`Auth::canAssignRole()`);
- only users of a lower level can be edited or deleted, unless you hold `users.manage_any` (`Auth::canManageUser()`);
- nobody can delete themselves, change their own role or suspend themselves;
- passwords are hashed like `php flo user:create`, never shown, and kept when left empty;
- users created here are active and verified (no email is sent); the login defaults to the email;
- suspending a user ends their access on their next request (core's session refresh).

**Profile** edits your own name, email, login and password. Changing the email or the password asks for the current password; role and status are shown, not editable. Its **My preferences** tab holds your own theme, panel language and table density.

**Settings** shows the website settings pages of `admin/settings.php` and the panel's own **Admin panel** page. See the [Settings guide](docs/settings.md).

Set `'users' => false` in `config/admin.php` to switch users management and the profile page off. The URLs `dashboard`, `accounts`, `profile` and `settings` belong to the panel and cannot be used as resource slugs; `roles`, `trash`, `activity` and `search` can, but the panel's page is then not shown.

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
| `$icon` | `'list'` | `list`, `file`, `newspaper`, `book`, `folder`, `building`, `home`, `map-pin`, `users`, `user`, `tag`, `image`, `camera`, `video`, `calendar`, `mail`, `message`, `phone`, `bell`, `box`, `cart`, `credit-card`, `truck`, `briefcase`, `chart`, `grid`, `layers`, `link`, `globe`, `key`, `shield`, `heart`, `star`, `settings` |
| `$description` | `null` | One line under the list page title: text, or a key of the site's lang files |
| `$navigationSort` | `0` | Order in the sidebar |
| `$navigation` | `true` | `false` keeps the resource out of the sidebar, e.g. records managed inside their parent (0.22) |
| `$translationMode` | `''` (the panel's) | How translatable fields are edited: `'page'` (an "Editing: language" switch shows the whole form in one language) or `'field'` (a language badge per field). See [Translatable fields](#translatable-fields) (0.23) |
| `$formMode` | `'page'` | How Create and Edit open: `'page'` (full page with an optional sidebar), `'modal'` (dialog) or `'slide-over'` (panel from the side). See [Forms](docs/forms.md#pages-dialogs-and-slide-overs-012) |
| `$permissionPrefix` | `'<permission_prefix>.<slug>'` | See [Permissions](#permissions) |
| `$permissions` | `[]` | Per-ability overrides: `['delete' => 'settings.delete']` |

Optional methods:

| Method | |
| --- | --- |
| `query(Database $query): Database` | Narrow every query, e.g. `->where('site_id', '=', 1)` |
| `relations(): array` | Relation managers on the edit page: `[RelationManager::make(UnitResource::class, 'property_id')]` ([guide](docs/relation-managers.md)) |
| `canCreate(): bool` | False hides New and answers 403 |
| `canEdit(object $record): bool` | False hides Edit for that record and answers 403 |
| `canDelete(object $record): bool` | False hides Delete and answers 403 |
| `canReorder(): bool` | False hides Reorder on a reorderable list and answers 403 |
| `afterReorder(array $ids): void` | Runs inside the transaction after Reorder changed positions (keys in their new order) |
| `validate(array $state, ?object $record): array` | Checks after the field rules; return `field => message` |
| `normalizeState(array $state, ?object $record): array` | Normalize editable state before field/custom validation and password hashing |
| `beforeSave(array $data, ?object $record): array` | Change dehydrated data inside the save transaction |
| `afterSave(object $record): void` | Runs after the write, inside the transaction and before commit |

Only the fields declared in `form()` are ever written. The primary key, `created_at` and `updated_at` never come from the request.

### Form fields

| Field | Input | Notes |
| --- | --- | --- |
| `TextInput` | text | `->email()`, `->url()`, `->tel()`, `->numeric()`, `->password()`, `->maxLength(190)`; text and URL inputs can be translatable |
| `Textarea` | textarea | `->rows(8)`; translatable; full width by default |
| `RichEditor` | visual editor | Formatting, links, images, videos, YouTube/Vimeo; clean HTML; translatable with language tabs ([guide](docs/rich-text.md)) |
| `Select` | select | `->options([...])` or `->options(fn () => [...])`; only listed values are accepted |
| `Toggle` | checkbox | Stored as `1` / `0`; an unchecked box saves `0` |
| `Date` | date | Stored as `Y-m-d`, empty as `NULL` |
| `DateTime` | datetime-local | Stored as `Y-m-d H:i:s`, empty as `NULL` |
| `Slug` | text | `->from('title')` fills it from the default-language title while empty (in the browser and on save) |
| `Hidden` | hidden | Written, not shown |
| `Placeholder` | read-only text | `->content(fn (?object $record) => ...)`; never written |
| `BelongsTo` | searchable combobox | The related key in this table's column: `->resource(CityResource::class)` or `->table('brands', 'name')`, `->titleColumn()`, `->dependsOn('country_id')` ([guide](docs/relationships.md)) |
| `BelongsToMany` | chips with search | A pivot table: `->pivot('property_feature', 'property_id', 'feature_id')`, `->orderColumn('sort')`, `->max(20)` ([guide](docs/relationships.md)) |
| `ColorPicker` | colour | `#rrggbb` with a swatch |
| `MediaPicker` | a framed card: preview, sortable tiles, drop to upload | Items of the media library: `->images()` (default), `->videos()`, `->any()`, `->multiple()`, `->max(12)`, `->folder('Listings/Covers')`, `->aspectRatio('16:9')`; stores an id, or a JSON list of ids in order ([guide](docs/media.md)) |

Every field: `->label()`, `->rules('required|max:190')`, `->required()`, `->requiredOnCreate()`, `->default()`, `->help()`, `->placeholder()`, `->disabled()` (shown, never written), `->nullable()`, `->unique()`, `->columnSpan(2)`.

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
| `RelationColumn` | related titles | `->resource(CityResource::class)`; many-to-many with `->pivot(...)`, `->limit(3)` ([guide](docs/relationships.md)) |
| `MediaColumn` | thumbnails of media items | `->limit(3)`; loads the page's items in one query ([guide](docs/media.md)) |
| `TranslationsColumn` | how far each record is translated: EN 4/4 · AR 3/4 · KU 0/4 | `->fields([...])` (default: every translatable form field), `->withoutFilter()`; adds a **Missing in: language** filter (0.23) |
| `CountColumn` | how many related records | `->relation(UnitResource::class, 'property_id')`; one grouped query per page ([guide](docs/relation-managers.md#counts-in-the-parents-list)) |

Every column: `->label()`, `->sortable()`, `->searchable()`, `->format(fn ($value, $record) => ...)` (the result is escaped), `->translatable()`, `->toggleable(hiddenByDefault: false)`. Search and sort only use columns marked `searchable()` / `sortable()`. Toggleable columns get a **Columns** button where each user shows or hides them; the title column can never be hidden ([guide](docs/settings.md#the-columns-button)).

### Drag-and-drop ordering

`->reorderable('sort')` on the table lets editors put records in their own order (menu items, slides, FAQ entries). The order lives in an integer column of the resource's table; the list follows it when the table has no `defaultSort()`, and gets a **Reorder** button that opens Reorder mode: drag rows by their handle (mouse, touch, keyboard), each drop is saved at once. Filter first to order one group on its own. Options: `->newRecordsFirst()`, `->reorderLimit(1000)` (default 500). See the [Reordering guide](docs/reordering.md).

## Translatable fields

Mark a text field `->translatable()` and it gets one value per site language, from `LANGUAGES` and `DEFAULT_LANG` in `.env`:

```dotenv
LANGUAGES=en,ar,ku
DEFAULT_LANG=en
```

Translatable fields are edited in one of two ways, chosen in `config/admin.php` and per resource (0.23):

- **Page mode** (`'translation_mode' => 'page'`, written by `admin:install` since 0.23): an **Editing: English · Arabic 7/9 · Kurdish 0/9** switch in the record's head shows the whole form in one language, so a translator works through a post from top to bottom, right to left in Arabic and Kurdish. Each language shows how many translatable fields are filled; **Next missing** jumps to the next empty one, and an empty input shows the default language's text below it as a reference. Fields that are the same in every language (price, city, photo) stay visible but read-only outside the default language. Create and Edit dialogs (relation managers too) get the same switch. Best for content: posts, properties, pages.
- **Field mode** (`'field'`, the default when the key is missing, so sites from before 0.23 do not change): the form shows the default-language input with a language badge beside it. The badge shows which languages are filled; clicking it opens a dialog with one input per language. Best for small records with one translatable name: categories, cities.

```php
// config/admin.php: the panel default
'translation_mode' => 'page',

// a resource that differs from it
protected static string $translationMode = 'field';
```

Forms without translatable fields show no switch. Both modes store the same JSON, so a resource can move between them at any time. Arabic, Kurdish and the other right-to-left languages get `dir="rtl"` in either panel language. Everything is a real form field, so the page also works without JavaScript (page mode then shows every language with its code). `TranslationsColumn` shows on the list how far each record is translated, with a **Missing in** filter. See [Forms](docs/forms.md#page-level-translation-023).

`required` applies to the default language only. The other languages are optional, and every other rule (`max:190`, …) applies to them when they are filled.

### Storage

Each translatable field is one column holding a JSON object:

```json
{"en":"Sea view villa","ar":"فيلا بإطلالة على البحر","ku":"ڤیلای ڕووەو دەریا"}
```

Use a `JSON` column (on MariaDB this is `LONGTEXT` with a validity check) or any text column. Empty languages are not stored; a field with no value at all is stored as `NULL`. Search looks in every language; sorting uses the admin's language.

**Existing columns.** A column that already holds plain text can be switched to translatable without a data migration: plain text is read as the default language and saved as JSON the next time the record is saved. (Change the column to `TEXT` first if it is shorter; it must not be a `JSON` column until all rows are JSON.)

### On the website

```php
{{ localized($property->title) }}          // current language, falls back to the default language
{{ localized($property->title, 'ar') }}    // a given language
```

`localized()` returns the value in the requested language, else in the default language, else in the first filled language, else `''`. It accepts the raw column value, a decoded array or plain text. The same function is available as `FloCMS\Admin\Translation\Translations::get()`, plus `Translations::all()` for every language. If you later remove the package, replace these calls.

## Languages, RTL and the panel's own words

- The panel's language is the language of the URL: `/ku/admin/properties` shows the panel in Kurdish.
- Its direction comes from `lng.dir` in the site's `lang/<code>.php` (the skeleton's files have it), else from the package's list of right-to-left languages.
- The package ships its words in English, Arabic and Kurdish (Sorani), the languages of the skeleton. A site changes any of them by adding an `admin.<key>` line to its own lang file, e.g. `'admin.actions.create' => 'Add'`. See `resources/lang/en.php` for the keys.
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

Deleting is a POST with a CSRF token (FloCMS rejects it otherwise) and asks for confirmation first. Since 0.19 a deleted record goes to the trash: restoring it needs `create`, deleting it for good needs `delete`. The Activity page needs `activity.view` (History on a record needs `update`).

Since 0.21 the roles and their permissions can be changed on **Site → Roles** (permission `roles.manage`): the panel stores the changes in a table and gives them to flocms-core 2.4+ (`Auth::loadRolesUsing()`), so every check of the site sees them. See the [Roles guide](docs/roles.md).

## Configuration

`config/admin.php`:

| Key | Default | |
| --- | --- | --- |
| `resources` | `[]` | Classes to load; empty loads every `*Resource.php` in `resources_path` |
| `resources_path` | `'admin/Resources'` | Folder of the resource classes |
| `resources_namespace` | `'App\Admin\Resources'` | Their namespace |
| `brand` | `SITE_NAME_ADMIN` | Name at the top of the sidebar |
| `theme` | `'light'` | Colour theme: `'light'`, `'dark'`, or `'auto'` (follows the visitor's operating system) |
| `primary_color` | `null` | Brand colour as `#rrggbb` for buttons, links and highlights; `null` keeps the default blue. Buttons use white text, so `admin:doctor` warns below a 4.5:1 contrast |
| `logo` | `null` | Logo image URL (absolute, or a path starting with `/`) for the sidebar and login page; `null` shows the brand's first letter |
| `sidebar` | `'light'` | `'light'` or `'dark'` |
| `content_width` | `'full'` | `'full'` uses the whole window; `'boxed'` centres pages at most 1280 px wide |
| `density` | `'comfortable'` | List rows: `'comfortable'` or `'compact'` |
| `search` | `true` | Ctrl+K search (`/admin/search`); `['per_group' => 5, 'shortcut' => true, 'sources' => [...]]`; `false` switches it off ([guide](docs/search.md)) |
| `dialog_min_height` | `null` | Least height of the body of form and action dialogs: pixels (`240`) or a CSS length (`'20rem'`); `null` fits the content. One action: `->modalMinHeight(320)` |
| `panel_settings` | `true` | The "Admin panel" settings page; its saved values override `brand`, `logo`, `primary_color`, `theme`, `sidebar`, `content_width` and `density` |
| `settings_path` | `'admin/settings.php'` | File that declares the website settings pages |
| `settings_uploads` | `public/uploads/settings` at `/uploads/settings` | `['root' => …, 'url' => …]`: where files uploaded on settings pages are stored (public) |
| `per_page` | `25` | Rows per page |
| `media_library` | `true` | The media library (`/admin/media`), media fields and the editor's library; `false` switches it off; `['table' => …, 'usage_table' => …, 'folders_table' => …, 'max_image_kb' => …, 'max_video_mb' => …]` changes its tables and limits ([guide](docs/media.md)) |
| `users` | `true` | Users management (`/admin/accounts`) and the profile page |
| `trash` | `true` | Deleted records wait in the trash (`/admin/trash`); `['days' => 30, 'table' => 'admin_trash']`, `0` days keeps them until emptied; `false` deletes at once ([guide](docs/trash-and-activity.md)) |
| `activity` | `true` | The activity log (`/admin/activity`, History on records); `['days' => 365, 'logins' => false, 'table' => 'admin_activity']`; `false` switches it off |
| `roles` | `true` | The Roles page (`/admin/roles`); `['table' => 'admin_roles', 'permissions' => ['reports.export' => 'Export reports']]` names the site's own permissions; `false` switches it off and the roles of `config/config.php` apply again ([guide](docs/roles.md)) |
| `rtl_languages` | `ar, ku, ckb, fa, he, ur` | Content languages written right to left |
| `permission_prefix` | `'content'` | Prefix of the permission names |

The panel is drawn inside the active template's `layouts/admin.html`, which must print `$data['content']` (the skeleton's does). Its versioned CSS and JavaScript are served from the installed Composer package through Admin asset routes; no manual asset publishing or hosting-side Node build is required.

### Look and theming

The panel ships its own fonts: Instrument Sans for Latin text and Noto Sans Arabic for Arabic and Kurdish (both SIL Open Font License, in `resources/fonts/`). They are served by the same asset route as the CSS, and a browser downloads only the scripts a page uses (about 40 KB for Latin, 165 KB once for Arabic script).

All colours, radii and shadows are CSS custom properties on `.fa-panel` (and `.fa-login`). To adjust the look without overriding selectors, set them in your template's stylesheet, for example:

```css
.fa-panel, .fa-login { --fa-primary: #4f46e5; --fa-primary-hover: #4338ca; --fa-primary-text: #4f46e5; }
```

Administrators can change the name, logo, brand colour, theme, sidebar, width and density from **Settings → Admin panel**; a saved value there overrides `config/admin.php`, and each user can pick their own theme and density in **My preferences**. For the brand colour alone in code, set `primary_color` in `config/admin.php` instead: the package derives hover, soft and dark-theme shades from it and serves them as a small cached stylesheet (no inline styles, so a strict Content-Security-Policy keeps working). `logo` and `sidebar` complete the shell.

The main tokens are `--fa-bg`, `--fa-surface`, `--fa-text`, `--fa-muted`, `--fa-border`, `--fa-primary` (fills), `--fa-primary-text` (links and active states), `--fa-success` / `--fa-warning` / `--fa-danger` / `--fa-info` with `-soft` backgrounds, `--fa-radius` and `--fa-font`. The dark theme redefines the same names, so a site override applies to the light theme unless it also targets `.fa-panel[data-theme="dark"]`.

## Commands

| Command | |
| --- | --- |
| `php flo admin:install` | Create `config/admin.php`, `admin/Resources/` and the settings, preferences, media library, trash, activity log and roles migrations (never overwrites; run again after an upgrade to add what is missing) |
| `php flo make:admin-settings` | Create `admin/settings.php` with example settings pages |
| `php flo make:admin-resource Property [--table=properties] [--slug=properties] [--form=modal] [--migration] [--reorderable] [--force]` | Create a resource class (`--form`: `page`, `modal` or `slide-over`), and optionally a migration; `--reorderable` adds a `sort` column and the Reorder button |
| `php flo make:admin-widget PostStats [--type=stats\|records\|chart] [--resource=Post] [--force]` | Create a dashboard widget and register it in `config/admin.php` |
| `php flo admin:list [--permissions]` | List resources, settings pages and widgets with URLs, tables and permissions; `--permissions` prints the permission names for a role |
| `php flo admin:doctor` | Check config, languages, layout, the panel's pages, slug collisions, tables, columns (including the order column of reorderable lists), widgets, settings pages, the settings and preferences tables, the media library, the trash and activity tables (with a warning for `ON DELETE CASCADE` foreign keys) and the roles table |
| `php flo admin:media-import [--dry-run]` | Add the images and videos already in the media folder to the media library (and fingerprints to items from before 0.18), then rebuild where they are used |
| `php flo admin:media-usage` | Rebuild where media library items are used (after changing records outside the panel) |
| `php flo admin:trash-purge [--all]` | Delete for good the records older than the trash's days (`--all`: empty the whole trash) |
| `php flo admin:activity-purge` | Remove activity log entries older than its days |
| `php flo admin:roles` | List the roles in force: level, panel access, source and permissions |
| `php flo admin:roles-reset [role]` | Undo the Roles page for one role, or all (config roles get their config values back; added roles without users are removed) |

## Removing the package

```bash
composer remove hostkurd/flocms-admin
```

Then delete `config/admin.php` and `admin/` (your own code, so the package never deletes it). The `admin_user_preferences` table and the admin's rows in FloCMS's `settings` table stay until you remove them (`DROP TABLE admin_user_preferences;` and `DELETE FROM settings WHERE lang = '' AND param LIKE '%.%';`; keep the table itself, core uses it), and so do files in `public/uploads/settings/`. Templates that call `localized()`, `setting()` or `settings()` need a replacement first.

## Actions and Notifications

Resources receive Create/Edit/Delete Actions by default. Define additional workflows with `Action::make()` in `Resource::headerActions()`, `Table::headerActions()` or `Table::actions()`. Each action declares its permission, record policy, confirmation/form and transactional callback. `Notification::make()->title(...)->success()->send()` delivers feedback that survives panel navigation.

See the [Actions and Notifications guide](docs/actions-and-notifications.md) for the Publish/Unpublish example, API, concurrency rules, standard fallback and browser coverage.

## File and image fields

Admin 0.7 provides single-file `FileUpload` and `ImageUpload` fields for resource CRUD forms, including Reactive dialogs and no-JavaScript pages. Files use private managed storage with editor-authorized downloads, server-side validation, retained selections on validation errors, and transaction-aware replacement/removal. Since 0.18 every file input of the panel is a **FileDrop** (drop or browse, a preview, type and size checked before sending, progress), which your own admin pages can use too (`FileDrop::make('brochure')`). See the [Uploads guide](docs/uploads.md) for configuration, limits, permissions and cleanup.

## Media library

Admin 0.17 adds a media library at **Content → Media**: upload images and videos once (with progress; videos in parts), give each a title and an alt text per language, and pick them in forms with `MediaPicker` or in the rich text editor (**Image → Choose from the library**). Images get a web version and a thumbnail; the library records where each item is used, shows it on the item's page and refuses to delete an item in use. Its tables are `media`, `media_usage` and `media_folders` (generic, for the website and apps too). Admin 0.18 makes it a file manager: folders (categories shown as folders, nested, with drag and drop), multi-selection with batch move, edit, copy and delete, a details panel, a picker built the same way, focal points, a duplicate check on upload, and media fields drawn as framed cards with drag-and-drop ordering. On the website, `media($record->cover_id)?->img()` prints a responsive `<img>` with its alt text (and focal point), `media_list($record->gallery)` returns a gallery in order, and `media_folder('Homepage slider')` the items of a folder. Run `php flo admin:install` and `php flo migrate` to set it up. See the [Media library guide](docs/media.md).

## Trash and activity log

Admin 0.19 makes deleting safe and every change traceable. A deleted record goes to the trash: a toast offers **Undo** for 10 seconds, each list has a **Trash (n)** button with its own columns, and **Site → Trash** shows everything, with Restore, Delete for good and Empty trash. Records come back with the same id, their many-to-many links, files and media usage; a restore that would clash (a slug now taken, a parent gone) is refused with a reason. The **activity log** records who created, edited (field by field, before → after), deleted, restored, ran an action, reordered, saved settings or changed media, and when. Each record shows its **History** in a side panel, and **Site → Activity** (permission `activity.view`) lists everything with filters. Passwords are never recorded and any field can be kept out with `->hiddenFromActivity()`. Run `php flo admin:install` and `php flo migrate` to set it up. See the [Trash and activity log guide](docs/trash-and-activity.md).

## Search

Admin 0.20 adds a search field to the top bar: press **Ctrl+K** (⌘K on a Mac) or `/` on any page, type, and pick a result with the arrow keys and Enter. It finds records of every resource by the columns their list already searches (`->searchable()`, every language), the panel's pages, "New …" actions, settings pages and media items, grouped and limited to what the person may open; before typing it shows the last 8 records opened. `#42` finds record 42. Nothing to install. See the [Search guide](docs/search.md).

## Roles and permissions

Admin 0.21 adds **Site → Roles** for people with `roles.manage`. The list shows every role with its level, panel access, users and where it comes from (config, changed, or added in the panel). A role opens on a full page with a permission grid: one row per resource with View / Create / Edit / Delete, an **All content** switch (`content.*`, which also covers resources added later and the media library), the media library, each settings page, users, the panel's own pages, actions and widgets with their own permission, and **Other** for names the site's code uses. Beside it: the role's name per language, its level and panel access, and who holds it. Config roles can be changed and reset; new roles get the next free number and any level below yours. The top role is locked, nobody changes their own role or gives a permission they don't hold, and a save that would leave nobody able to manage roles is refused. Every change goes to the activity log. Needs flocms-core 2.4; run `php flo admin:install` and `php flo migrate`. See the [Roles guide](docs/roles.md).

## Relation managers

Admin 0.23 adds **page-level translation**: with `'translation_mode' => 'page'` (or `$translationMode = 'page'` on a resource) an "Editing: language" switch shows the whole form in one language, with a count of filled fields per language, **Next missing**, the default language's text under empty inputs, shared fields locked outside the default language and errors in other languages shown in the switch. The last language you saved in is remembered; `?editing=ar` opens one directly. Dialogs get the switch too, settings pages get the counts and Next missing, and `TranslationsColumn` with its **Missing in** filter shows what still needs translating. Storage is unchanged. See [Forms](docs/forms.md#page-level-translation-023).

Admin 0.22 shows a record's related records on its edit page, below the form: a property's units, a country's cities. Declare them on the parent with `relations()` (`RelationManager::make(UnitResource::class, 'property_id')`); the child resource brings its table, form, permissions and rules. Two or more managers become tabs with counts. Units are added, edited and deleted in dialogs without leaving the property; the server limits every read to this property and sets the key on every save, and you need edit rights on the property and view rights on units. Without JavaScript each manager links to the units list limited to the property. `$navigation = false` keeps a child out of the sidebar, `CountColumn` counts related records in the parent's list, and the parent's History lists its children's changes. See the [Relation managers guide](docs/relation-managers.md).

## Rich text

Admin 0.10 adds `RichEditor`: a visual editor (Tiptap) with headings, lists, links, quotes, code, uploaded images and videos and YouTube/Vimeo embeds, language tabs for translatable fields and right-to-left writing. The server cleans every saved value against a strict allow-list, so it can be printed on public pages. Media is uploaded through flocms-uploader to a public folder (`media` in `config/admin.php`; with the media library, into its `library/` folder as library items), from the toolbar or by pasting and dropping; videos upload in parts. Links and media links are entered in modal dialogs (Ctrl/⌘+K for links). Images, videos and embeds can be resized with grips and positioned (left, centre, right, text wrapping), images get captions and links, and paragraphs can be aligned, indented and switched to right-to-left; add `RichText::stylesheet()` to the website's layout so these show there too. See the [Rich text guide](docs/rich-text.md).

## Dashboard widgets

Admin 0.8 adds optional `StatsWidget`, `RecordsWidget` and `ChartWidget` classes, registered in `config/admin.php`. Build a dashboard with scoped resource counts/sums, recent records and accessible SVG bar/line charts. Widgets recheck permissions on every refresh and work without JavaScript. Empty widget configuration keeps the original resource-count cards. Start one with `php flo make:admin-widget PostStats --resource=Post`. See the [Widgets guide](docs/widgets.md) for complete project examples, source permissions, read limits and layout configuration.

## Roadmap

- 0.3: Reactive resource dialogs and lists, shared form submission, panel navigation and browser coverage
- 0.4: shared Actions, action forms/confirmation and persistent Notifications
- Completed through 0.7: table filters, bulk actions, richer Forms, and single image/file fields
- Completed in 0.8: configurable dashboard widgets
- 0.9: the redesign: tokens, light/dark/auto theme, shipped fonts, new list page, dialogs and forms, sidebar and topbar with brand settings, phone layouts and dashboard polish
- 0.10: `RichEditor` with image/video uploads and YouTube/Vimeo embeds
- 0.14: website settings pages with `setting()`, Admin panel settings, My preferences, the Columns button
- 0.15: relationship fields (`BelongsTo`, `BelongsToMany`), relation columns and filters
- 0.16: drag-and-drop ordering (`->reorderable()`, Reorder mode)
- 0.17: the media library (`/admin/media`, `MediaPicker`, `MediaColumn`, the editor's library, `media()` on the website)
- 0.18: media folders, the file-manager Media page and picker, batch editing, focal points, duplicate check, framed media fields, `FileDrop`
- 0.19: the trash (Undo, restore with the same id, Site → Trash) and the activity log (History on records, Site → Activity)
- 0.20: Ctrl+K search (records, pages, actions, settings and media; recent records)
- 0.21: the Roles page (permission grid, new roles with levels, reset, activity log; flocms-core 2.4)
- 0.22: relation managers (related records on the edit page, `CountColumn`, `$navigation`)
- 0.23: page-level translation (the "Editing: language" switch, `TranslationsColumn`, the Missing in filter)
- Next: repeater and key-value fields, then CSV import/export

## Development

```bash
composer install
vendor/bin/phpunit
npm ci
npm run typecheck
npm run build
npx playwright install chromium
npm run test:browser
```

The MySQL / MariaDB tests run when `FLO_ADMIN_TEST_DB_NAME` (and `_HOST`, `_PORT`, `_USER`, `_PASS`) are set; the rest use SQLite.

## License

MIT

### Upgrading to 0.4.1

Modal and full-page forms now share the same language badge and translation editor. The normal view switch is removed; existing full-page URLs and JavaScript-disabled operation remain supported. No migration or resource change is needed. See [UPGRADING.md](UPGRADING.md).

### Upgrading to 0.4.0

See [UPGRADING.md](UPGRADING.md) for action definitions, transaction ownership, notification presentation and retained 0.3/0.2.1 contracts. See the [Reactive Admin guide](docs/reactive-admin.md) for the runnable resource example and browser checks.
