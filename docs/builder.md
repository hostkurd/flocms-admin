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

The field shows the page's **structure** on the left, one row per block, and the selected block's **panel** on the right. A block's own fields open in a dialog (0.31). Section and Columns are framed cards with their blocks inside, and a Columns block shows its columns side by side, as wide as its layout (0.32).

- **Rows** show the block's name, its kind, a short summary ("3 items" for a slider's slides) and its state ("Draft", "From 1 Nov", "Ended", "Desktop only", "Phones only"); a hidden block looks faded and a block changed since the page was opened has a dot. Pointing at a row (or reaching it with the keyboard) shows its buttons, icons with a name as a tooltip: **Edit**, **Duplicate**, **Copy to language** (with `->translatable()`), **Show / hide on the website** (Published ↔ Draft), **Delete**, **⋯** and **+** (add a block after it). A **+** also appears in the gap between two blocks. In a narrow column a row keeps Edit, Delete and ⋯ in view.
- **⋯** (0.32) lists the row's actions again and **Move to**: every place the block may go (into a Section, a column of a Columns block, or the top level after the container it is in). Arrow keys move through the menu; Escape closes it.
- **Fold** (0.32): the chevron on a Section or Columns folds it to one line, "Services · 4 inside". The page remembers what is folded in this browser; **Collapse all** folds every container and becomes **Expand all**.
- **Columns** (0.32) sit side by side, each with its name and width ("Column 1 · 67%"), and stack when the structure is narrower than 480 px (phones, a narrow window).
- **The panel**: **Edit content**, the same actions, then the settings every block shares:
  - **Name** and **Note**, for editors only, never printed on the website. The name replaces the block's first text in the structure. A Section, Columns and Slider show their own Name here instead; a Section's and a Slider's name also names it for screen readers on the website.
  - **Status** (Published, Draft) and **Show from / Show until**: a draft or a block outside its dates is not printed.
- **Display** (0.32.1), the last tab of the block's dialog, in three groups (0.33):
  - **Look**: **Style**, one of the styles the theme defines for this kind (shown only when there are some; see [Styles and colours](#styles-and-colours)), **Background**, a swatch of the theme's colours, and **Text colour** (Automatic, Light, Dark) once there is a background. A Section keeps its own Background field, with the same colours.
  - **Layout**: **Show on** (all screens, desktop only, phones only), **Width** (Boxed in the site's container, or Full width), **Space above / below** (Default, None, Small, Medium, Large, Extra large; **Default (Medium)** names the block's default and follows it if it changes) and the same **on phones** (**Same as on desktop** unless set).
  - **Advanced**: **Anchor** (`team` makes `#team` link to the block), an extra **CSS class**, and **Custom CSS** for people with `blocks.custom_css` (see [Custom CSS](#custom-css)).

  A group with nothing in it is left out. A block with no fields of its own has no dialog; it keeps these in the panel, under **Advanced**.
- **Edit** (or a double click on the row, or Enter) opens the block's own fields in a dialog, in its tabs (a slider has **Content** and **Slides**), with **Done**. The dialog edits one block (0.32) and keeps one height while it is open: its tallest tab, 560–760 px (less on a short window); what doesn't fit scrolls inside it. Changes are kept until the page is saved. A new block opens there straight away; a new Slider opens on its Slides tab, and a block whose required list is still empty says "Nothing added yet" on its row. Options that matter for one choice only (a slider's pixel heights with Fixed, a Section's colour with a Colour background) appear when it is chosen.
- **Add block** opens a dialog with search, categories (Media, Text, Layout, Marketing, Your site, and **Recently used**) and where the block will go ("After “Hero”", "In “2 columns”, column 2"). It closes with ×, Escape or a click outside. **+** on a row adds a block after it; **Add to column 2** or **Add a block inside** add one into a Section or Columns.
- **Delete** asks first ("Delete “Services”? The 4 blocks inside it go too."), then **Undo** brings it back until you save.
- **Copy and paste**: **Ctrl+C** on a row keeps the block (with the blocks inside it) in this browser; **Ctrl+V** on a row pastes it after that row, on this page or another, and **Add block** offers **Paste** where the block may sit. An HTML block pasted by someone without `blocks.html` arrives empty.
- **Keyboard**: Enter edits, Delete deletes, Ctrl+D duplicates, Ctrl+C / Ctrl+V copy and paste; **Alt + ↑ ↓** moves a block, **Alt + → ←** moves it into the Section or Columns before it or out of its container (mirrored right to left). **Drag** a block by its handle anywhere it may sit (0.32): it stays in place while a line shows where it will land; every Section and column is a target as a whole, so blocks go into, out of and between columns. A place it may not go shows a red line and nothing moves; Escape cancels; the page scrolls near the window's edges; a folded container opens when a block is held over it. On touch screens drag by the handle, or press and hold a row. A Section sits at the top only; Columns sit at the top or in a Section; other blocks sit anywhere.
- With `->translatable()`, **Copy to language** adds the block at the end of another language's list; an empty language offers **Copy all from English**.
- Changing a Columns layout to fewer columns moves the blocks of the columns that go into the last one, and says so.
- Errors open the block that has them (in the dialog when the error is in its own fields); the message above the form says where: "Page content → Hero: Heading".
- Without JavaScript every block's fields and settings are shown one after another and the buttons send the form, as with [repeaters](repeaters.md).
- A form with a Builder opens as a page, never in a dialog (inside a [relation manager](relation-managers.md) it still opens over the parent's page).
- **Large pages** (0.32): the editor sends all of a Builder's fields as **one value** (JSON), so `max_input_vars` no longer limits a page, however many blocks it has. The server reads the same names and values as before and checks every block the same way; a value it can't read is refused, and nothing is saved. Without JavaScript the fields are sent one by one, as before: then PHP drops what goes past `max_input_vars` (1000 by default), the page notices and saves nothing, and pages of more than about 40 blocks need `max_input_vars = 5000`.
- On phones a tap on a row opens its panel over the structure (with Edit content and every action), and the dialogs fill the screen.

`->translatable()` keeps **one list per language**, so each language may have different blocks. Without it there is one list for every language; the block fields themselves are never translatable.

## Blocks of the panel

| Type | Category | Fields |
| --- | --- | --- |
| `slider` | Media | Name, Height (Full screen, Fixed, Like the first image), heights on desktop and phones (with Fixed), Effect (Fade, Slide), Play automatically, Next slide after (with autoplay), arrows, dots, Fill or Fit, Image overlay; **Slides**: image or video, heading, text, button, link, open in a new tab, text position (3 × 3), text colour, Show this slide |
| `hero` | Marketing | Background image or video, heading, heading level (H1, H2), text, two buttons, open in a new tab, height, height on phones, alignment, image overlay |
| `image` | Media | Image, alt text, caption, link, open in a new tab, size (text width, wide, full) |
| `gallery` | Media | Heading, images (up to 60), columns, open larger on click |
| `video` | Media | A file from the library, or a YouTube or Vimeo link (played from youtube-nocookie), cover, caption, autoplay (muted), loop; one of the two is needed |
| `heading` | Text | Text, level (H1–H4), alignment (start, centre, end), subtitle |
| `rich_text` | Text | The rich text editor (cleaned when saved) |
| `cards` | Text | Heading, cards per row; **Cards**: image, title, text, link (the whole card opens it), button text, open in a new tab |
| `call_to_action` | Marketing | Heading, text, button, link, open in a new tab, style |
| `html` | Other | Raw HTML, printed as written: see below |
| `section` | Layout | Name, content width (Full, Boxed, Narrow), background (none, the theme's colours, a colour, an image), text colour and image overlay (with a colour or image), inner space; holds blocks |
| `columns` | Layout | Name, layout (50/50, 67/33, 33/67, 25/75, 75/25, 40/60, 60/40, thirds, 25/50/25, quarters, from pictures), space between, vertical alignment, stack on phones, last column first on phones; holds blocks |

A button's text and its link need each other: one without the other is an error. Empty Slider, Section and Columns blocks print nothing on the website.

`->blocks([...])` offers only the kinds named, in that order (types or classes); `->only(['media', 'text'])` offers those categories. Without either every kind is offered, the site's own included.

### HTML blocks

An HTML block prints its code on the website as written, so only people with the **`blocks.html`** permission (Roles page, System) may add or change one. For everyone else an existing HTML block is read-only: it can be moved or removed, and its code is kept exactly (a copy signed for that block travels with the form, so it cannot be changed or copied into another block). Nothing the browser sends is stored as it is: every block goes through its fields' own checks (rich text is cleaned), also for imports, where HTML blocks are kept only for people with the permission.

## On the website

`blocks($value)` prints the blocks shown now (published, inside their dates) in the page's language. With one list per language, a language without blocks shows the default language's. Each block is wrapped in

```html
<div class="flo-block flo-block-heading is-boxed flo-space-above-medium flo-space-below-medium" data-block="heading" data-block-id="b8f2c1" id="team">…</div>
```

`data-block` is the block's kind and `data-block-id` its id, which stays the same when the block is moved, edited or saved again (a duplicate gets a new one): hooks for scripts, tests and the block's own CSS (0.33). With a style, a colour or phone spacing the wrapper also gets `is-style-<name>`, `has-bg has-bg-<name> has-text-light|dark` and `flo-space-above-phone-<size>`.

and the inside comes from **`views/blocks/<type>.html`** in the site, or the package's default template. A template gets the block's fields as variables (every field is there, empty when not filled; toggles are `'1'` or `'0'`), `$block` (`id`, `type`, `settings`, `lang`, `anchor`), and: `$content` for a Section (the HTML of the blocks in it) and `$columns` for Columns (each column's `share` of 12 and `html`). Use `media($id)`, `media_list($ids)` and `repeater_items($list)` as anywhere else. A slider's `$slides` holds only the slides shown, each with `item` (its media item).

```html
@php /* views/blocks/heading.html */ $tag = in_array($level, ['h1', 'h2', 'h3', 'h4'], true) ? $level : 'h2'; @endphp
<{{ $tag }} class="flo-heading is-align-{{ $align }}">{{ $text }}</{{ $tag }}>
```

- **`php flo admin:blocks`** copies the default templates into `views/blocks/`, and the styles and the slider and gallery scripts into `public/assets/flo-blocks/`. It never replaces a file that is there; `--force=slider.html` replaces that one file. From then on they are yours: panel updates don't change them.
- **`{!! blocks_assets() !!}`** in the layout's `<head>` adds the base styles and only the scripts the page needs (the slider's about 4 KB where there is a slider), from `public/assets/flo-blocks/` with a version in the address, or inline from the package before you copy them. The styles use CSS variables, so a theme restyles them without editing the files: `--flo-container`, `--flo-narrow`, `--flo-gutter`, `--flo-block-gap`, `--flo-radius`, `--flo-brand`, `--flo-light`, `--flo-dark`, `--flo-slider-overlay`…
- On a **settings page**, a `Builder::make('blocks')` is printed with `{!! blocks(setting('home.blocks')) !!}`.
- **`blocks_data($value)`** returns the blocks shown now as arrays (`id`, `type`, `fields`, `settings`, `children`), for a site that renders them in JavaScript or an API.
- **Caching**: the printed HTML of a list is kept in `storage/cache/blocks` until its content, a template or the language changes, a block's dates start or end, or six hours pass. Blocks with their own `data()` (which may read the database) are not cached unless they say so, and nothing is cached while a `BlockRenderer::rendering()` listener is set. Links typed in blocks are printed only when they are http(s), `mailto:`, `tel:` or an address on the site.
- The slider: the first slide loads at once, the others lazily; arrows, dots, swipe and arrow keys; autoplay pauses on hover, on focus and in a hidden tab, has a pause button, and is off with "reduce motion", which also keeps its videos still. Right-to-left pages slide the other way.
- Videos that play by themselves (a Hero's background, a Video block with autoplay) stay still for visitors who ask for less motion (`motion.js`, 0.32); a Video block then shows its controls.
- Quarters show two by two below 900 px (tablets).

### Styling the blocks

A site styles its blocks with its own CSS, in four ways, from the broadest:

1. **The CSS variables** of `blocks.css`, set in the theme's stylesheet: `--flo-container`, `--flo-narrow`, `--flo-gutter`, `--flo-block-gap`, `--flo-radius`, `--flo-brand`, `--flo-brand-text`, `--flo-light`, `--flo-dark`, `--flo-muted`, and (0.33) the spacing sizes `--flo-space-sm`, `--flo-space-md`, `--flo-space-lg`, `--flo-space-xl` (by default a third, half, all and one and a half of `--flo-block-gap`) and `--flo-bg-padding`, the space inside a block with a background. They change every block at once, without touching the panel's files.
2. **The classes every block prints**: the wrapper `flo-block flo-block-<type>` (`.flo-block-cards`, `.flo-block-hero`), its width (`is-boxed`, `is-full`), spacing (`flo-space-above-large`, `flo-space-above-phone-small`), devices, style (`is-style-shadow`) and colour (`has-bg-brand`); inside, each template's own classes (`.flo-card`, `.flo-hero-heading`, `.flo-section.is-bg-dark`). Rules for these go in the site's stylesheet, loaded after `{!! blocks_assets() !!}`.
3. **Styles** (0.33): named looks a developer defines and editors pick from a list; better than asking editors to type class names. See below.
4. **The CSS class** in a block's Display tab, for one block: `is-highlight` there, and `.flo-block.is-highlight { … }` in the stylesheet. The anchor gives one block an `id` too.
5. **Custom CSS** (0.33), for one block, by people with `blocks.custom_css`. See below.
6. **The templates and styles themselves**: `php flo admin:blocks` copies them into the site (`views/blocks/`, `public/assets/flo-blocks/`), and from then on they are the site's to change, markup included. A site block from `php flo make:admin-block` has its own template and its `assets()` files.

**Pages › Blocks preview** (0.33) shows every kind of block in each of its styles and colours with the website's own layout and stylesheets, at desktop, tablet and phone widths and in each language, each one labelled with its selector and a **Copy selector** button. It is a page preview in a frame, so nothing is saved. The core blocks show sample content, with the newest images of the media library; a site block shows its `sample()` (an array of its fields as stored), or "No sample yet". It needs the `settings.blocks_preview` permission, which the Admin role has through `settings.*`, and `'pages' => true`.

### Styles and colours

**Styles** are listed in `config/admin.php` per block type (`'*'` for every kind), or from PHP; a site block can also return its own from `styles()`:

```php
'block_styles' => [
    'cards' => ['shadow' => 'With shadow', 'plain' => 'Plain'],
    '*'     => ['highlight' => ['en' => 'Highlighted', 'ar' => 'مميّز']],
],
```

```php
\FloCMS\Admin\Blocks\BlockStyles::add('cards', 'shadow', 'With shadow');   // a package, a service provider
public function styles(): array { return ['outline' => 'Outline']; }       // in a Block class
```

A name is lower-case letters, digits and `-`; a label is words, a translation key or one text per language. The editor picks one in the Display tab (Default, then the styles: a row of buttons for up to four, a list for more) and the wrapper gets `is-style-<name>`: write `.flo-block-cards.is-style-shadow .flo-card { box-shadow: … }` in the theme. A style removed from the list later is ignored by the website; its block says "The style “shadow” no longer exists" and the style goes when the page is saved. `php flo admin:doctor` names entries it can't use.

**Colours**: every block (but a Section, which has its own Background with the same colours) can have a background from the theme's palette, and a text colour (Automatic picks light or dark for the background). Without `block_colours` the palette is the four colours of `blocks.css`: Brand colour, Light, Dark and Muted, styled with its variables. With it, the palette is the site's own:

```php
'block_colours' => [
    'brand' => ['label' => 'Brand', 'value' => '#0e6e8c', 'text' => 'light'],
    'sand'  => ['label' => 'Sand',  'value' => '#f4ede1'],          // text: light or dark, worked out when left out
],
```

The panel then prints each colour's rule (`.has-bg-sand { background-color: #f4ede1; color: … }`) in the page's block stylesheet (below), so a theme needs no CSS for them; it can add its own for `.has-bg-sand` all the same. A block with a background gets `--flo-bg-padding` (40 px, 24 px on phones) inside. Names are lower-case letters, digits and `-`, and not `none`, `colour`, `image` or `auto`.

### Custom CSS

People with the **`blocks.custom_css`** permission (Roles page, System; Super admin has it through `*`) see a **Custom CSS** box at the end of a block's Display tab. It applies to that block only:

```css
& { border-radius: 24px; }                     /* & is the block */
.flo-card { gap: 2rem; }                       /* rules without & apply inside it */
@media (max-width: 767px) { & { padding-inline: 0; } }
```

The panel checks it when the page is saved and scopes every rule to `.flo-block[data-block-id="b8f2c1"]`. It refuses, with the line: braces that don't match, a rule inside a rule, `url()` and the other functions that load files (`image-set()`, `image()`, `src()`…), `@import` and any at-rule but `@media`, `@supports`, `@container` and `@keyframes`, backslashes, `<`, `javascript:`, `expression()`, `behavior` and `-moz-binding`, and more than 5,000 characters. A background image is set with a Section instead.

For everyone else the box is hidden and the block's CSS is kept as it is (a copy signed for that block travels with the form); a block they duplicate or paste has none. Changes show in the activity log with the page.

On the website the CSS of the blocks on a page, and the rules of the theme's colours, are one small stylesheet named by its content, `/flo-admin/asset/block-styles-<hash>.css`, cached for good and linked by `{!! blocks_assets() !!}` after the base styles. Being a file, it works under a strict Content-Security-Policy. When `storage/cache/blocks` can't be written it is a `<style>` tag instead; give it your policy's nonce with `'block_style_nonce' => fn () => $nonce` in `config/admin.php`. Stylesheets no page has used for 30 days are removed.

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

- `fields()` returns fields, or `Tab`s for several tabs. Any field works except uploads, many-to-many relations and another Builder; repeaters, media pickers and rich text work. Field names may not start with `_` (the shared settings use it: `_label`, `_note`, and since 0.33 `_style`, `_bg`, `_text` and `_css`), nor be one of the other shared settings (`status`, `starts`, `ends`, `width`, `devices`, `space_above`, `space_below`, `space_above_phone`, `space_below_phone`, `anchor`, `class`).
- `styles()` (0.33) returns the block's own styles, `['outline' => 'Outline']`, besides those in `block_styles`; `sample()` returns content for the blocks preview, its fields as stored (`['heading' => 'Our projects', 'count' => '3']`; a Section-like block may add `'children' => [['type' => 'heading', 'data' => [...]], …]`).
- `check(array $fields): array` (0.32) returns errors that need more than one field, `['link' => 'Add a link…']` (or `'items.0.link'` inside a list); `self::buttonPair($fields, 'button', 'link')` is the panel's own button-and-link check.
- A field's `->shownWhen('height', 'fixed')` (0.32) shows it only while another field of the block has one of those values, in the browser, without reloading the dialog. Unlike `visibleWhen()`, it is always sent and its rules always apply, so give it a valid default.
- `title(array $fields)` names the block in the structure (by default its first heading, name, text…; an editor's Name comes first), `nameField()` a field of its own shown as the panel's Name (Section, Columns and Slider return `'name'`), `defaultWidth()` (`'boxed'` or `'full'`), `allowedIn(string $parentType)` where it may sit, `assets()` the files in `public/assets/flo-blocks/` it needs, `cacheable()`, and `render(array $vars): ?string` to build the HTML in PHP instead of a template.
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

With `->translatable()`: `{"en": […], "ar": […]}`. A block's `id` stays the same when it moves. Settings left on their default are not stored; those of 0.33 are `"_style": "shadow"`, `"_bg": "sand"`, `"_text": "light"`, `"space_above_phone": "small"` and `"_css": "& { … }"` (`blocks_data()` gives them as `style`, `bg` and `text`, never the CSS). A block whose kind no longer exists is kept as stored and shown as "Unknown block" until someone removes it. The activity log names blocks ("Page content · Added: Call to action", "Page content · Hero · Heading: Welcome → Hello", "Order changed"), the view page shows the structure, media in blocks count as used in the library, export writes the JSON as it is, and import reads it like a submitted form: each block is checked as if typed in the editor, so a block whose kind no longer exists, or an HTML block imported by someone without `blocks.html`, is left out, and custom CSS imported by someone without `blocks.custom_css` is dropped.
