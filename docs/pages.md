# Pages

Admin 0.30 adds a ready-made place for the website's pages: **Site → Pages** (`/admin/site-pages`). A page is built from **blocks** (the [Builder](builder.md)), written as **HTML**, or typed as **rich text**. It has an address in each language, can sit under another page, can be the home page, and is a draft, published or scheduled. Visitors see it at its address without a controller of yours.

## Turning it on

Pages need **flocms-core 2.6** (it lets pages answer addresses no controller answers).

```php
// config/admin.php
'pages' => true,
```

then

```
composer require "hostkurd/flocms-core:^2.6" -W
php flo admin:install
php flo migrate
```

`admin:install` writes the migration for the `admin_pages` table. `php flo admin:doctor` checks everything below.

Options, with their defaults:

```php
'pages' => [
    'table' => 'admin_pages',
    'missing' => 'default',   // a page not translated: show the default language's ('default') or answer 404 ('404')
    'sitemap' => true,        // /sitemap.xml
],
```

## Your layout

Two lines in `templates/<template>/layouts/default.html`, in the `<head>`:

```html
{!! page_meta() !!}
{!! blocks_assets() !!}
```

`page_meta()` prints the page's `<title>`, description, canonical address, the other languages (`hreflang`, with `x-default`), Open Graph and Twitter tags, and `noindex` when asked. On the site's other pages it prints nothing, so keep your own `<title>` for them, for example:

```html
@if(current_page()) {!! page_meta() !!} @else <title>My site</title> @endif
```

A menu of the pages marked **Show in menu**, in their order and nesting:

```html
@foreach(pages_menu() as $item)
    <a href="{{ $item['url'] }}" @if($item['current']) aria-current="page" @endif>{{ $item['title'] }}</a>
    {{-- $item['children']: the pages under it, the same way --}}
@endforeach
```

## The home page

**Set as home page** (in a page's row menu) makes it the page at the site's main address. The site's own home controller still answers that address, so it starts with one line (skeleton 1.8.8 has it):

```php
// controllers/PagesController.php
public function index()
{
    if ($page = home_page()) { return $page->response(); }
    // … your home page as before
}
```

Without a home page, or before the line is added, the site's home page shows as before.

## Addresses

A page's address is its parents' addresses and its own: **Services** (`services`) with **Web design** under it is `/en/services/web-design`. Each language has its own (`/ar/خدمات/تصميم`). Leave the address empty and it is made from the title when saved.

Addresses that something else answers can't be taken: the panel's and the framework's (`admin`, `login`, `api`, `flo-admin`, `flo-preview`, `sitemap.xml`, `robots.txt`, `assets`, `uploads`, `public`), the languages, and **your controllers**: a page called `contact` is refused when the site has a `ContactController`, which always wins. Two pages under the same parent can't share an address in one language. `admin:doctor` warns about pages saved before a controller of that name was added.

A page under a draft is not shown, nor is one whose dates have passed, nor one whose parent page was deleted. Pages go at most six levels deep.

## Editing a page

The editor works **one language at a time**: the **Editing** switch above the form picks the language, and counts what is filled in each. Fields that are the same in every language (status, dates, template, parent) are changed in the default language.

- **Content**: the title, the address, and the content as **Blocks**, **HTML** or **Rich text**. Switching between them keeps what was typed in each, also on a page not saved yet (0.32.1); the page shows the one chosen. HTML pages need the `blocks.html` permission, the same as HTML blocks; without it the code is read-only. The HTML editor numbers lines, highlights tags, indents with Tab (Shift+Tab back; Escape, then Tab, leaves the editor) and has **Format**.
- **SEO**: a title and description for search engines (the title is used when empty), a social image, **Hide from search engines**, and previews of the search result and the social card as you type.
- **Settings**: the template, the parent page, **Show in menu**, the order, and a CSS class for the page.
- **Publish**: Draft, Published or Scheduled, with **Publish at** and **Unpublish at**. A published page with a time to come is saved as scheduled, and shows from that time by itself (times are the server's, in PHP's time zone). Only people with the **`pages.publish`** permission (Roles page, System) can publish. For others a new page stays a draft, and a page visitors already see (or the home page) is read-only: it opens on its view page, and can't be deleted, since every change to it would be published at once. A page they restore from the trash comes back as a draft.
- **Preview** opens the page as it is in the editor, saved or not, in a new tab. The link works for 30 minutes and can be sent to someone without an account; search engines are told to stay away.
- The **Page** card has the address with **Copy**, when it was created and changed, and **History** (with the activity log on).

The list has **All / Published / Drafts / Scheduled** tabs, the address under each title, the languages each page is translated into, and **Reorder** for the menu's order. **Duplicate** makes a draft copy (`about-us-copy`).

## Templates

A page is shown with its **template** inside the site's layout. The package's **Default** shows a block page as its blocks, and an HTML or rich text page with its title and the content in a box. Add your own as `views/page-templates/<name>.html` (`full-width.html` is offered as "Full width"); `php flo admin:blocks` copies the default one to `views/page-templates/default.html` for you to change.

```html
<main class="my-page {{ $page->cssClass() }}">
    <h1>{{ $page->title() }}</h1>
    {!! $content !!}
</main>
```

`$page` has `title()`, `content()`, `type()` (`builder`, `html` or `rich_text`), `url()`, `lang()`, `isHome()`, `isFallback()` (the default language standing in), `metaTitle()`, `metaDescription()`, `socialImage()`, `template()`, `cssClass()` and `row()` (the stored row). `$content` is `$page->content()`.

## Languages

A page in a language it has no title in is shown in the default language, and its canonical address points at the default language's page so search engines don't see two copies. With `'missing' => '404'` it is not found instead. `hreflang` lists only the languages a page is translated into.

## Sitemap

`/sitemap.xml` lists every page visitors can see that is not hidden from search engines, in each of its languages, with the other languages as `xhtml:link` alternates. Add it to `robots.txt`:

```
Sitemap: https://example.com/sitemap.xml
```

A site with a `sitemap.xml` file of its own in `public/` keeps it, since the web server usually serves files before FloCMS runs. `'sitemap' => false` turns it off.

## Permissions

- `content.site-pages.view`, `.create`, `.update`, `.delete`: as for any resource.
- `pages.publish`: publish, schedule, unpublish, Set as home page, and change or delete pages visitors see.
- `blocks.html`: HTML pages and HTML blocks.
- `blocks.custom_css` (0.33): a block's own CSS, in its Display tab ([guide](builder.md#custom-css)).
- `settings.blocks_preview` (0.33): **Pages › Blocks preview**, every kind of block in its styles and colours with the website's stylesheet ([guide](builder.md#styling-the-blocks)); the Admin role has it through `settings.*`.

## In code

```php
use FloCMS\Admin\Pages\Pages;

home_page();                 // the live home page in the current language, or null
current_page();              // the page being shown, or null
Pages::find('services/web-design', 'en');   // a live page by its path, or null
Pages::url($row, 'ar');      // a page's address in a language
```

A page answers `GET` and `HEAD` only, after the site's controllers, the panel and the maintenance page; anything your site answers itself is never replaced.
