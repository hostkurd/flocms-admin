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
- **Panel settings and personal preferences**: name, logo, colour, theme and density from the browser; each user's own theme, panel language, density and visible columns.
- Optional and removable: no file of the site is edited.

## Requirements

- PHP 8.1+
- `hostkurd/flocms-core` ^2.3.2, `hostkurd/flocms-reactive` ^0.5.1 and `hostkurd/flocms-uploader` ^1.2.1 (installed by Composer)
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
- two migrations, for the `settings` table (settings pages) and `admin_user_preferences` (personal preferences).

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

**`/admin`** opens the dashboard on skeleton 1.8+: the package sets `Config 'admin.home'` and the skeleton's `/admin` page follows it. Set `admin.home` in your `config/config.php` to send `/admin` elsewhere, or to `null` to keep the skeleton's page.

**Users** manages the skeleton's `users` table with the skeleton's own rules (`FloCMS\Core\Auth`):

- a role can only be given up to your own (`Auth::canAssignRole()`);
- only users of a lower role can be edited or deleted, unless you hold `users.manage_any` (`Auth::canManageUser()`);
- nobody can delete themselves, change their own role or suspend themselves;
- passwords are hashed like `php flo user:create`, never shown, and kept when left empty;
- users created here are active and verified (no email is sent); the login defaults to the email;
- suspending a user ends their access on their next request (core's session refresh).

**Profile** edits your own name, email, login and password. Changing the email or the password asks for the current password; role and status are shown, not editable. Its **My preferences** tab holds your own theme, panel language and table density.

**Settings** shows the website settings pages of `admin/settings.php` and the panel's own **Admin panel** page. See the [Settings guide](docs/settings.md).

Set `'users' => false` in `config/admin.php` to switch users management and the profile page off. The URLs `dashboard`, `accounts`, `profile` and `settings` belong to the panel and cannot be used as resource slugs.

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
| `$formMode` | `'page'` | How Create and Edit open: `'page'` (full page with an optional sidebar), `'modal'` (dialog) or `'slide-over'` (panel from the side). See [Forms](docs/forms.md#pages-dialogs-and-slide-overs-012) |
| `$permissionPrefix` | `'<permission_prefix>.<slug>'` | See [Permissions](#permissions) |
| `$permissions` | `[]` | Per-ability overrides: `['delete' => 'settings.delete']` |

Optional methods:

| Method | |
| --- | --- |
| `query(Database $query): Database` | Narrow every query, e.g. `->where('site_id', '=', 1)` |
| `canCreate(): bool` | False hides New and answers 403 |
| `canEdit(object $record): bool` | False hides Edit for that record and answers 403 |
| `canDelete(object $record): bool` | False hides Delete and answers 403 |
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

Every column: `->label()`, `->sortable()`, `->searchable()`, `->format(fn ($value, $record) => ...)` (the result is escaped), `->translatable()`, `->toggleable(hiddenByDefault: false)`. Search and sort only use columns marked `searchable()` / `sortable()`. Toggleable columns get a **Columns** button where each user shows or hides them; the title column can never be hidden ([guide](docs/settings.md#the-columns-button)).

## Translatable fields

Mark a text field `->translatable()` and it gets one value per site language, from `LANGUAGES` and `DEFAULT_LANG` in `.env`:

```dotenv
LANGUAGES=en,ar,ku
DEFAULT_LANG=en
```

The form shows the default-language input with a language badge beside it. The badge shows which languages are filled; clicking it opens a dialog with one input per language. Arabic, Kurdish and the other right-to-left languages get `dir="rtl"`. Everything is a real form field, so the page also works without JavaScript.

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

Deleting is a POST with a CSRF token (FloCMS rejects it otherwise) and asks for confirmation first.

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
| `panel_settings` | `true` | The "Admin panel" settings page; its saved values override `brand`, `logo`, `primary_color`, `theme`, `sidebar`, `content_width` and `density` |
| `settings_path` | `'admin/settings.php'` | File that declares the website settings pages |
| `settings_table` | `'settings'` | Table of the settings values |
| `settings_uploads` | `public/uploads/settings` at `/uploads/settings` | `['root' => …, 'url' => …]`: where files uploaded on settings pages are stored (public) |
| `per_page` | `25` | Rows per page |
| `users` | `true` | Users management (`/admin/accounts`) and the profile page |
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
| `php flo admin:install` | Create `config/admin.php`, `admin/Resources/` and the settings and preferences migrations (never overwrites; run again after an upgrade to add what is missing) |
| `php flo make:admin-settings` | Create `admin/settings.php` with example settings pages |
| `php flo make:admin-resource Property [--table=properties] [--slug=properties] [--migration] [--force]` | Create a resource class, and optionally a migration |
| `php flo make:admin-widget PostStats [--type=stats\|records\|chart] [--resource=Post] [--force]` | Create a dashboard widget and register it in `config/admin.php` |
| `php flo admin:list [--permissions]` | List resources, settings pages and widgets with URLs, tables and permissions; `--permissions` prints the permission names for a role |
| `php flo admin:doctor` | Check config, languages, layout, the panel's pages, slug collisions, tables, columns, widgets, settings pages and the settings and preferences tables |

## Removing the package

```bash
composer remove hostkurd/flocms-admin
```

Then delete `config/admin.php` and `admin/` (your own code, so the package never deletes it). The `settings` and `admin_user_preferences` tables stay until you drop them (`DROP TABLE settings, admin_user_preferences;`), and so do files in `public/uploads/settings/`. Templates that call `localized()`, `setting()` or `settings()` need a replacement first.

## Actions and Notifications

Resources receive Create/Edit/Delete Actions by default. Define additional workflows with `Action::make()` in `Resource::headerActions()`, `Table::headerActions()` or `Table::actions()`. Each action declares its permission, record policy, confirmation/form and transactional callback. `Notification::make()->title(...)->success()->send()` delivers feedback that survives panel navigation.

See the [Actions and Notifications guide](docs/actions-and-notifications.md) for the Publish/Unpublish example, API, concurrency rules, standard fallback and browser coverage.

## File and image fields

Admin 0.7 provides single-file `FileUpload` and `ImageUpload` fields for resource CRUD forms, including Reactive dialogs and no-JavaScript pages. Files use private managed storage with editor-authorized downloads, server-side validation, retained selections on validation errors, and transaction-aware replacement/removal. See the [Uploads guide](docs/uploads.md) for configuration, limits, permissions and cleanup.

## Rich text

Admin 0.10 adds `RichEditor`: a visual editor (Tiptap) with headings, lists, links, quotes, code, uploaded images and videos and YouTube/Vimeo embeds, language tabs for translatable fields and right-to-left writing. The server cleans every saved value against a strict allow-list, so it can be printed on public pages. Media is uploaded through flocms-uploader to a public folder (`media` in `config/admin.php`), from the toolbar or by pasting and dropping; videos upload in parts. Links and media links are entered in modal dialogs (Ctrl/⌘+K for links). Images, videos and embeds can be resized with grips and positioned (left, centre, right, text wrapping), images get captions and links, and paragraphs can be aligned, indented and switched to right-to-left; add `RichText::stylesheet()` to the website's layout so these show there too. See the [Rich text guide](docs/rich-text.md).

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
- Next: relationship fields and managers

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
