# Builder: blocks and sliders

Admin 0.29 adds the **Builder** field: a list of blocks of different kinds (a slider, a heading, rich text, columns…) in one record, edited in the panel and printed on the website by templates the site owns. Nothing to install: add the field to a resource and a JSON (or LONGTEXT) column to its table.

```php
use FloCMS\Admin\Form\Fields\{Builder, TextInput};

public function form(Form $form): Form
{
    return $form->schema([
        TextInput::make('title')->translatable()->required(),
        Builder::make('content')
            ->label('Page content')
            ->blocks(['slider', 'hero', 'heading', 'rich_text', 'cards', 'section', 'columns', LatestProjects::class])
            ->maxBlocks(40)
            ->translatable(),   // one list of blocks per language
    ]);
}
```

```html
<!-- views/layouts/default.html, in <head> -->
{!! blocks_assets() !!}

<!-- the page's view -->
{!! blocks($page->content) !!}
```

## The editor

The field shows the page's **structure** on the left, one row per block, and the selected block's fields on the right.

- **Add block** opens a picker with search and categories (Media, Text, Layout, Marketing, Your site). **+** on a row adds a block after it; **Add to column 2** or **Add a block inside** add one into a Section or Columns.
- **Drag** a block by its handle anywhere it may sit, or move it with **Alt + ↑ ↓**. A Section sits at the top only; Columns sit at the top or in a Section; other blocks sit anywhere.
- A block's own tabs come first (a slider has **Content** and **Slides**), then **Settings**, shared by every block:
  - **Status** (Published, Draft) and **Show from / Show until**: a draft or a block outside its dates is not printed. The row says "Draft", "From 1 Nov" or "Ended".
  - **Width** (Boxed in the site's container, or Full width), **Space above / below**, **Show on** (all screens, desktop only, phones only).
  - **Anchor** (`team` makes `#team` link to the block) and an extra **CSS class**.
- **Duplicate**, **Remove** (with Undo until you save) and, with `->translatable()`, **Copy to another language**. An empty language offers **Copy all from English**.
- Changing a Columns layout to fewer columns moves the blocks of the columns that go into the last one.
- Errors open the block that has them; the message above the form says where: "Page content → Hero: Heading".
- Without JavaScript every block's fields are shown one after another and the buttons send the form, as with [repeaters](repeaters.md).
- A form with a Builder always opens as a page, never in a dialog.
- On phones a block's fields open over the structure, with Close at the top.

`->translatable()` keeps **one list per language**, so each language may have different blocks. Without it there is one list for every language; the block fields themselves are never translatable.

## Blocks of the panel

| Type | Category | Fields |
| --- | --- | --- |
| `slider` | Media | Name, Height (Full screen, Fixed, Like the first image), heights on desktop and phones, Effect (Fade, Slide), Next slide after, Play automatically, arrows, dots, Fill or Fit, Darken images; **Slides**: image or video, heading, text, button, link, text position (3 × 3), text colour, Show this slide |
| `hero` | Media | Background image or video, heading, text, two buttons, height, alignment, darken |
| `image` | Media | Image, caption, link, size (text width, wide, full) |
| `gallery` | Media | Heading, images (up to 60), columns, open larger on click |
| `video` | Media | A file from the library, or a YouTube or Vimeo link (played from youtube-nocookie), cover, caption, autoplay (muted), loop |
| `heading` | Text | Text, level (H1–H4), alignment |
| `rich_text` | Text | The rich text editor (cleaned when saved) |
| `cards` | Text | Heading, cards per row; **Cards**: image, title, text, link |
| `call_to_action` | Marketing | Heading, text, button, link, style |
| `html` | Other | Raw HTML, printed as written: see below |
| `section` | Layout | Name, content width (Full, Boxed, Narrow), background (none, light, dark, brand, a colour, an image), inner space; holds blocks |
| `columns` | Layout | Name, layout (50/50, 67/33, 33/67, 25/75, thirds, 25/50/25, quarters, from pictures), space between, vertical alignment, stack on phones, last column first on phones; holds blocks |

`->blocks([...])` offers only the kinds named, in that order (types or classes); `->only(['media', 'text'])` offers those categories. Without either every kind is offered, the site's own included.

### HTML blocks

An HTML block prints its code on the website as written, so only people with the **`blocks.html`** permission (Roles page, System) may add or change one. For everyone else an existing HTML block is read-only: it can be moved or removed, and its code is kept exactly (a signed copy travels with the form, so it cannot be swapped).

## On the website

`blocks($value)` prints the blocks shown now (published, inside their dates) in the page's language. With one list per language, a language without blocks shows the default language's. Each block is wrapped in

```html
<div class="flo-block flo-block-heading is-boxed flo-space-above-medium flo-space-below-medium" id="team">…</div>
```

and the inside comes from **`views/blocks/<type>.html`** in the site, or the package's default template. A template gets the block's fields as variables (every field is there, empty when not filled; toggles are `'1'` or `'0'`), `$block` (`id`, `type`, `settings`, `lang`, `anchor`), and: `$content` for a Section (the HTML of the blocks in it) and `$columns` for Columns (each column's `share` of 12 and `html`). Use `media($id)`, `media_list($ids)` and `repeater_items($list)` as anywhere else. A slider's `$slides` holds only the slides shown, each with `item` (its media item).

```html
@php /* views/blocks/heading.html */ $tag = in_array($level, ['h1', 'h2', 'h3', 'h4'], true) ? $level : 'h2'; @endphp
<{{ $tag }} class="flo-heading is-align-{{ $align }}">{{ $text }}</{{ $tag }}>
```

- **`php flo admin:blocks`** copies the default templates into `views/blocks/`, and the styles and the slider and gallery scripts into `public/assets/flo-blocks/`. It never replaces a file that is there; `--force=slider.html` replaces that one file. From then on they are yours: panel updates don't change them.
- **`{!! blocks_assets() !!}`** in the layout's `<head>` adds the base styles and only the scripts the page needs (the slider's about 4 KB where there is a slider), from `public/assets/flo-blocks/` with a version in the address, or inline from the package before you copy them. The styles use CSS variables, so a theme restyles them without editing the files: `--flo-container`, `--flo-narrow`, `--flo-gutter`, `--flo-block-gap`, `--flo-radius`, `--flo-brand`, `--flo-light`, `--flo-dark`, `--flo-slider-overlay`…
- On a **settings page**, a `Builder::make('blocks')` is printed with `{!! blocks(setting('home.blocks')) !!}`.
- **`blocks_data($value)`** returns the blocks shown now as arrays (`id`, `type`, `fields`, `settings`, `children`), for a site that renders them in JavaScript or an API.
- **Caching**: the printed HTML of a list is kept in `storage/cache/blocks` until its content, a template or the language changes, a block's dates start or end, or six hours pass. Blocks with their own `data()` (which may read the database) are not cached unless they say so.
- The slider: the first slide loads at once, the others lazily; arrows, dots, swipe and arrow keys; autoplay pauses on hover, on focus and in a hidden tab, has a pause button, and is off with "reduce motion". Right-to-left pages slide the other way.

## A slider on its own

`Slider` is also a plain field, for a record that needs only slides (a property's photos, a home page setting):

```php
use FloCMS\Admin\Form\Fields\Slider;

Slider::make('photos'),
```

```html
{!! slider($property->photos, ['height' => 'fixed', 'height_px' => 480, 'autoplay' => false]) !!}
```

It is stored like a repeater and printed with the same slider template.

## Blocks of your own

`php flo make:admin-block LatestProjects` writes `admin/Blocks/LatestProjects.php` and `views/blocks/latest_projects.html`, and adds the class to `'blocks'` in `config/admin.php`, so every Builder offers it.

```php
final class LatestProjects extends Block
{
    protected static string $type = 'latest_projects';
    protected static string $category = 'site';
    protected static string $icon = 'grid';
    protected static ?string $label = 'Latest projects';

    public function fields(): array
    {
        return [
            Tab::make('content')->schema([
                TextInput::make('heading')->required(),
                Select::make('count')->options(['3' => '3', '6' => '6'])->default('3'),
            ]),
        ];
    }

    /** Extra variables for the template, read when the page is shown. */
    public function data(array $fields): array
    {
        return ['projects' => Project::latest((int) $fields['count'])];
    }
}
```

- `fields()` returns fields, or `Tab`s for several tabs. Any field works except uploads, many-to-many relations and another Builder; repeaters, media pickers and rich text work. Field names may not start with `_`.
- `title(array $fields)` names the block in the structure (by default its first heading, name, text…), `defaultWidth()` (`'boxed'` or `'full'`), `allowedIn(string $parentType)` where it may sit, `assets()` the files in `public/assets/flo-blocks/` it needs, `cacheable()`, and `render(array $vars): ?string` to build the HTML in PHP instead of a template.
- A block with the type of one of the panel's replaces it everywhere.
- `BlockRenderer::rendering(fn (array $vars, Block $block): ?array => …)` runs before each block is printed, for sites that add data.
- `php flo admin:doctor` checks the classes in `'blocks'` and the Builder columns' types.

## Stored value

```json
[
  {"id": "b7k2a91c", "type": "section", "data": {"name": "Services", "width": "boxed"}, "settings": {"status": "published"},
   "children": [
     {"id": "b0c41d2e", "type": "columns", "data": {"layout": "50-50"}, "children": [
       {"id": "b5e9a013", "type": "rich_text", "data": {"body": "<p>Design</p>"}},
       {"id": "b8f2c7d4", "type": "rich_text", "column": 1, "data": {"body": "<p>Development</p>"}}
     ]}
   ]}
]
```

With `->translatable()`: `{"en": […], "ar": […]}`. A block's `id` stays the same when it moves. A block whose kind no longer exists is kept as stored and shown as "Unknown block" until someone removes it. The activity log names blocks ("Page content · Added: Call to action", "Page content · Hero · Heading: Welcome → Hello", "Order changed"), the view page shows the structure, media in blocks count as used in the library, and import and export carry the JSON as it is.
