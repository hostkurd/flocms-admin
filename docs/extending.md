# Extending the panel from a package (0.43)

A package (for example `hostkurd/flocms-frontend`) can add blocks, resources, panel pages, settings pages and sitemap addresses to the panel **without lines in the site's `config/admin.php`**. It calls `Admin::extend()` once, usually from a file Composer loads (`"autoload": {"files": ["src/admin.php"]}`):

```php
<?php
// src/admin.php of your package
use FloCMS\Admin\Admin;
use FloCMS\Admin\Extension;
use FloCMS\Admin\Settings\SettingsPage;
use FloCMS\Admin\Form\Fields\TextInput;

if (class_exists(Admin::class)) {   // the panel is optional for the package
    Admin::extend(static function (Extension $panel): void {
        $panel->blocks([new ComponentBlock('hero'), new ComponentBlock('pricing'), LatestPosts::class]);
        $panel->resources([FormEntriesResource::class]);
        $panel->pages([MenusPage::class]);
        $panel->settings([
            SettingsPage::make('site_style')->label('Site style')->icon('palette')->schema([
                TextInput::make('accent')->label('Accent colour'),
            ]),
        ]);
        $panel->sitemap(static fn (): iterable => [
            ['loc' => '/en/blog/first-post', 'lastmod' => '2026-11-01', 'alternates' => ['en' => '/en/blog/first-post', 'ar' => '/ar/blog/first-post']],
        ]);
    });
}
```

The function runs the first time the panel needs the list (not when the file is loaded), so it may use the site's config and database, and ask the panel for what is there so far (`BlockRegistry::all()`). Every method is optional and can be called more than once.

| Method | Adds | Same name as the site's? |
|---|---|---|
| `blocks([...])` | Block objects or Block class names for every Builder field | The site's `'blocks'` in `config/admin.php` wins (same type) |
| `resources([...])` | Resource classes | A class the site lists is added once; a slug taken by the site is reported |
| `pages([...])` | `AdminPage` classes (custom pages) | A class the site lists in `'admin_pages'` is added once |
| `settings([...])` | `SettingsPage` objects, after the site's `admin/settings.php` | The site's page stays; the package's is reported as declared twice |
| `sitemap(fn () => [...])` | Addresses in `/sitemap.xml` (when the panel's Pages serve it) | — |

Paths starting with `/` are joined to the site's address (`SITE_URI`), like the pages' own; full `http(s)://` addresses are used as they are; anything else is left out. `lastmod` is a timestamp or a date; `alternates` gives one link per language (`x-default` too).

## Mistakes

Nothing a package does here stops the panel. A wrong item (a class that is not a Block, a page that is not a `SettingsPage`), a block whose type is not lower-case letters, digits and `_` (starting with a letter, at most 41) or whose class can't be created, and a function that throws are collected and left out; `php flo admin:doctor` lists them under **Package:**, together with blocks whose form can't be built. A sitemap function that throws is written to the log and the rest of the sitemap is served.

## Blocks as objects

Until 0.42 a block kind was a class: its type, label, icon, category and description were static. Since 0.43 the panel asks the **object**, through instance methods that return the statics by default:

```php
public function blockType(): string        // static::type()
public function blockLabel(): string       // static::label()
public function blockIcon(): string        // static::icon()
public function blockCategory(): string    // static::category()
public function blockDescription(): string // static::description()
```

So one class can stand for many kinds, as a package's components do:

```php
final class ComponentBlock extends \FloCMS\Admin\Blocks\Block
{
    public function __construct(private string $kind) {}

    public function blockType(): string { return 'site_' . $this->kind; }
    public function blockLabel(): string { return ucfirst($this->kind); }
    public function blockIcon(): string { return 'grid'; }

    public function fields(): array { /* the component's fields */ }

    public function render(array $vars): ?string
    {
        // Markup built in PHP; return null to use views/blocks/<type>.html instead.
    }
}
```

Blocks written for 0.42 (with `protected static string $type = …`) keep working unchanged. Don't name a constructor property `$type`, `$label`, `$icon`, `$category` or `$description`: those are the statics of `Block`.

## Tests

`Admin::clearExtensions()` forgets every `Admin::extend()` function; call it in a test's `setUp()` and `tearDown()` when your tests register some.
