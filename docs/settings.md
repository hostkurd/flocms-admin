# Settings, preferences and columns

Admin 0.14 adds four things that change how a site and its panel are configured from the browser:

- **Website settings pages**: values such as the site name, logo, contact details or social links, edited at `/admin/settings` and read on the website with `setting('site.title')`.
- **Admin panel settings**: the panel's name, logo, brand colour, theme, sidebar, content width and table density, changed without editing `config/admin.php`.
- **My preferences**: each user's own theme, panel language and table density.
- **The Columns button**: each user shows or hides the list columns a resource marks as toggleable.

## Setup

`php flo admin:install` writes two migrations the first time it runs (and on existing installations when run again): one for the `settings` table and one for `admin_user_preferences`. Create the tables with:

```bash
php flo admin:install
php flo migrate
php flo make:admin-settings     # optional: admin/settings.php with a General and a Contact page
```

`php flo admin:doctor` checks both tables, the settings pages and the uploads folder.

## Declaring settings pages

Pages are declared in `admin/settings.php` (set `settings_path` in `config/admin.php` to use another file). The file returns a list of pages; each page has a name, a label, an icon, an optional description and a schema of fields:

```php
<?php
use FloCMS\Admin\Form\Fields\{ImageUpload, Select, Textarea, TextInput, Toggle};
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Settings\SettingsPage;

return [
    SettingsPage::make('site')
        ->label('General')
        ->icon('settings')
        ->description('Name, tagline and logo')
        ->schema([
            TextInput::make('title')->translatable()->required()->rules('max:120'),
            Textarea::make('tagline')->translatable()->default('Homes by the sea'),
            ImageUpload::make('logo')->translatable(),
        ]),

    SettingsPage::make('contact')
        ->label('Contact & locations')
        ->icon('map-pin')
        ->schema([
            Section::make('office')->label('Office')->schema([
                TextInput::make('email')->email(),
                TextInput::make('phone')->tel(),
                Textarea::make('address')->translatable()->columnSpan('full'),
            ]),
        ]),

    SettingsPage::make('social')->label('Social media')->icon('link')->schema([
        TextInput::make('facebook')->url(),
        TextInput::make('instagram')->url(),
        Toggle::make('show_icons')->default(true),
    ]),
];
```

Every field type, layout (`Section`, `Grid`, `Tabs`), validation rule and `visibleWhen()` / `requiredWhen()` of resource forms works on settings pages. `Slug` fields are for records and are not accepted. A page named `panel` is reserved for the panel's own settings.

| Method | Default | |
| --- | --- | --- |
| `make('site')` | | Page name: lower case letters, digits and `_`; it is the first part of every key |
| `->label('General')` | the name | Shown in the list of pages and as the title; a key of the site's lang file is translated |
| `->icon('settings')` | `settings` | One of the panel's icon names |
| `->description('…')` | none | Subtitle in the list of pages and under the title |
| `->columns(2)` | 2 | Columns of the form grid (1–4) |
| `->permission('settings.seo')` | `settings.manage` | Permission needed to open and save the page |
| `->translationBadges()` | off | Use the resource forms' language badge and dialog instead of the page-level language switch |
| `->beforeSave(fn (array $values): array => …)` | | Change the values before they are stored (keys are field names) |
| `->afterSave(fn (array $values): void => …)` | | Run after the values are stored, e.g. to clear a cache of your own |

## Reading settings on the website

Each field is stored under `<page>.<field>`:

```php
setting('site.title')               // in the current language, else the default language
setting('site.title', lang: 'ar')
setting('contact.phone', '—')       // the second argument is used when nothing is set
setting('site.logo')                // public URL of the uploaded image
settings('site')                    // ['title' => …, 'tagline' => …, 'logo' => …]
```

In templates:

```html
<title>{{ setting('site.title') }}</title>
@if(setting('site.logo') !== '')<img src="{{ setting('site.logo') }}" alt="{{ setting('site.title') }}">@endif
<a href="mailto:{{ setting('contact.email') }}">{{ setting('contact.email') }}</a>
```

The value returned:

- a **translatable** field gives the text in the requested language, else the default language, else the first filled one (like `localized()`);
- a **file or image** gives its public URL; a translatable image falls back to the default language's image;
- a `Toggle` gives `1` or `0`; other fields give the text as saved;
- an **empty or never-saved** field gives its `->default()`, else the second argument of `setting()`, else `''`. Clearing a field in the admin brings its default back.

`FloCMS\Admin\Settings\Settings` offers the same with `get()`, `page()`, `translations('site.title')` (every language) and `stored()` (the raw value).

### Cache

The first `setting()` call of a request loads one PHP file, `storage/cache/flocms-admin-settings.php`, holding the saved values and the declared defaults; later calls cost nothing. Saving a settings page rebuilds it, and so does changing `admin/settings.php`. `php flo cache:clear` deletes it; the next request rebuilds it from the table. If you change the table by hand, run `php flo cache:clear`.

Without a database or before `php flo migrate`, `setting()` returns the defaults and never throws.

## The settings page

`/admin/settings` opens the first page the user may manage; each page is `/admin/settings/edit/<name>`. The list of pages stands on the start side (a select on phones), the form on the other side. A "Settings" entry appears in the sidebar under "Site" for users who may open at least one page.

**Languages.** On a multilingual site the header has an **Editing: language** switch. Every translatable field on the page shows the input of the chosen language, with `dir` and `lang` set; all languages are part of the form and saved together, so switching never loses typing. Small dots after each translatable label show which languages are filled. `required` applies to the default language, as on resources. While another language is chosen, fields that are the same in every language are dimmed, marked "All languages" and can only be changed in the default language. If a save fails, the page opens on the language that needs fixing. Without JavaScript every language's input is shown with its language code.

**Files.** Files uploaded on settings pages are **public**, because a logo or favicon is shown on the website and the login page. flocms-uploader stores them under `public/uploads/settings/<page>/<field>/` with random names (set `settings_uploads` in `config/admin.php` if your web root differs). `ImageUpload::make('logo')->translatable()` keeps one image per language; a language without its own shows the default language's image ("No file for this language: the English one is used"). Replacing or removing a file deletes the old one after the save. Uploads on resource forms stay private, and translatable uploads are only accepted on settings pages.

**State.** Like record pages, the header shows "Unsaved changes", Ctrl/⌘+S saves, and leaving with unsaved changes asks first.

### Storage

```sql
CREATE TABLE settings (`key` VARCHAR(190) PRIMARY KEY, `value` TEXT NULL, `updated_at` DATETIME NULL);
```

Values are JSON: `"Acme"`, `{"en":"Acme","ar":"أكمي"}`, `1`, `{"en":"site/logo/1a2b….png"}`. Saving a page writes only its own keys, in one transaction; empty values are removed. If your site already has a `settings` table, set `'settings_table' => 'site_settings'` before running the migration. `admin:doctor` lists stored keys that no page declares any more.

## Admin panel settings

**Settings → Admin panel** (`/admin/settings/edit/panel`, permission `settings.panel`) changes the panel's look:

| Setting | Key | `config/admin.php` |
| --- | --- | --- |
| Panel name (per language) | `panel.brand` | `brand` |
| Brand colour | `panel.primary_color` | `primary_color` |
| Logo (per language) | `panel.logo` | `logo` |
| Default theme | `panel.theme` | `theme` |
| Sidebar style | `panel.sidebar` | `sidebar` |
| Content width | `panel.content_width` | `content_width` |
| Table density | `panel.density` | `density` |

A saved value wins; an empty one ("Default (…)") uses `config/admin.php`, then the built-in default. `config/admin.php` therefore stays the place for the defaults you keep in Git, and nothing changes on an existing site until someone saves this page. The login page uses the same name, logo, colour and theme. Set `'panel_settings' => false` to hide the page; saved values are then ignored.

**Table density**: "Compact" reduces the padding of list rows so more fit on a screen.

## My preferences

`/admin/profile` has two tabs: **Account** (name, email, login, password) and **My preferences**:

| Preference | Values |
| --- | --- |
| Theme | Panel default, light, dark, follow the system |
| Panel language | Panel default (the language of the address), or one of the site's languages |
| Table density | Panel default, comfortable, compact |

A user's choice beats the panel settings for that user only. With a **panel language**, a panel page opened in another language (a bookmark, a link in an email) is shown in the preferred one; choosing a language from the top bar's language menu makes that the new preference. "Panel default" switches this off.

Preferences are kept in `admin_user_preferences` (`user_id`, `key`, JSON `value`). Before that table exists they last until logout, and the page says so. Deleting a user in Users deletes their preferences. The table has no foreign key, so it works on any MySQL/MariaDB storage engine.

## The Columns button

Mark the columns a user may hide:

```php
public function table(Table $table): Table
{
    return $table->columns([
        TextColumn::make('title')->searchable()->sortable(),        // the title column: never hidden
        TextColumn::make('city')->toggleable(),                     // shown, can be hidden
        TextColumn::make('price')->sortable()->toggleable(),
        DateColumn::make('updated_at')->toggleable(hiddenByDefault: true), // hidden until shown
    ]);
}
```

A **Columns** button appears beside Filters when the table has at least one toggleable column. Its menu lists them with a checkbox each, plus Reset; a dot on the button shows that the user changed something. The column that titles a row on phones (the first searchable column) is never listed.

The choice is a personal preference (`columns.<slug>`), stored as changes from the defaults: a column added to the table later follows its own default for everyone. Sorting by a column the user hid falls back to the table's default sort. In the full-page list (`?static=1`, or with `'reactive' => false`) the menu is a plain form, so it works without JavaScript.
