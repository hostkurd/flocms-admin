# Changelog

All notable changes to `hostkurd/flocms-admin` are documented here.

## 0.15.4 - 2026-10-17

### Changed

- The language badge of translatable fields follows the page's direction: on Arabic and Kurdish pages it sits on the left, like the clear button of relation fields and other controls. The text typed in the field keeps its language's direction (English stays left to right) and starts clear of the badge.

## 0.15.3 - 2026-10-17

### Changed

- Form dialogs are at least 150 px tall (was 240 px in 0.15.2), enough to keep a one-field dialog from looking squeezed without leaving empty space.
- `make:admin-resource` marks the generated `id` (and, with `--migration`, `updated_at`) column `->toggleable()`, so new resources have a Columns menu. Existing resources are unchanged: add `->toggleable()` to the columns users may hide.

## 0.15.2 - 2026-10-17

### Fixed

- Relation fields in dialogs: the list of choices floats over the dialog instead of being cut off by it and adding a scroll bar. It opens upwards when there is more room above, follows the field while the page or dialog scrolls, and closes when the field scrolls out of sight.
- Notifications: the shadow under each one was cut by its container into grey bands. They now have a lighter shadow with room to show, and appear and leave with a short fade (none with reduced motion).

### Changed

- Success and info notifications close by themselves after 5 seconds; hovering or focusing one pauses it. Warning and danger stay until dismissed. `Notification::make()->duration(10)` sets the seconds, `->persistent()` keeps one open.
- Form dialogs are at least 240 px tall (less on very short windows), so a dialog with one or two fields does not look squeezed.

## 0.15.1 - 2026-10-17

### Fixed

- Right-to-left pages: a relation title in a Latin script ("Turkey") was aligned to the left in the combobox and its list. It now sits on the right like every other field.

### Added

- `php flo admin:doctor` reports MySQL/MariaDB tables whose text columns are not UTF-8 (for example `latin1`, the default of older MariaDB servers), which save Arabic, Kurdish and other non-Latin text as `?`, and prints the `ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4` fix. It checks resource tables and the related tables of relations.
- `php flo make:admin-resource City --form=modal` (or `slide-over`, `page`) sets `$formMode` in the new class. Small resources such as categories, cities and tags read well in a dialog.

## 0.15.0 - 2026-10-17

Relationship fields, relation columns and relation filters. No new dependency, migration or table: relations use the site's own columns and pivot tables. See the [Relationships guide](docs/relationships.md).

### Fields

- `BelongsTo::make('city_id')->resource(CityResource::class)`: the related key in this table's column. `->resource()` takes the table, key, title column, translations and `query()` scope from the related resource; `->table('brands', 'name')` for a table without one; `->titleColumn()`. The key is validated against the related table within its scope; a record is never its own parent; a value that left the scope later stays valid while unchanged.
- `->dependsOn('country_id')`: only related records matching another field (cities of the chosen country), checked on save; a new parent clears a choice that no longer matches.
- `BelongsToMany::make('features')->resource(…)->pivot('property_feature', 'property_id', 'feature_id')`: chips with search; pivot rows written as a difference in the record's save transaction, `->orderColumn('sort')` keeps the chosen order, `->max(n)`; pivot rows removed when the record is deleted (also row-menu and bulk deletes). A form that does not send the field keeps its links.
- A searchable combobox (keyboard, right to left): related tables up to 50 rows are filtered in the page, larger ones searched on the server through `GET /admin/<slug>/lookup/<field>` (declared relations only; create/update permission for fields, view for filters; at most 20 results). Native selects without JavaScript (the first 200 records plus the chosen ones).

### Lists

- `RelationColumn`: related titles in the admin's language, loaded once per page; many-to-many with `->pivot()`, `->limit(3)` and "+n". Records widgets accept one-to-one relation columns.
- `RelationFilter`: the same combobox in the Filters menu (several choices for many-to-many: records having any of them), chips with related titles, in both list modes and bookmarks.

### Other

- A delete refused by a foreign key shows "This City is still used by other records, so it was not deleted." instead of an error page.
- `admin:doctor` checks related and pivot tables and their columns, and suggests a foreign key for each `BelongsTo` without one.
- Extension points: `Field::storesInColumn()`, `Field::saveRelated()`, `Field::submitted()`, `Column::prepareRecords()`, `Column::isDatabaseColumn()`, `Filter::renderFilterInput()`, `Filter::describeFilterValue()`, `Filter::emptyFilterValue()`. `RecordRepository::delete()` runs in a transaction.

## 0.14.0 - 2026-10-17

Website settings, panel settings, personal preferences and a Columns button. Two new tables, created by migrations that `php flo admin:install` writes; nothing changes until they are migrated. See the [Settings guide](docs/settings.md).

### Website settings pages

- `SettingsPage::make('site')->label('General')->icon('settings')->description('…')->schema([...])` pages declared in `admin/settings.php` (`settings_path`), with every field type and layout of resource forms. `->permission()` per page (default `settings.manage`), `->beforeSave()`, `->afterSave()`, `->columns()`.
- `/admin/settings`: a vertical list of pages (icon, label, description; a select on phones), one form per page at `/admin/settings/edit/<page>`, Saved/Unsaved state, Ctrl/⌘+S, the leave dialog, and a Settings entry in the sidebar.
- A page-level **Editing: language** switch: translatable fields show the chosen language's input; all languages are saved together; fields that are the same in every language are locked and marked "All languages" while another language is chosen; a failed save opens the language that needs fixing; dots after each translatable label show the filled languages. `->translationBadges()` uses the resource badge and dialog instead.
- Values live in FloCMS's own `settings` table (`param`, `value`, `lang`), shared with core's `Config::getSetting()` and `offline_mode`: one row per key (`param` = `<page>.<field>`, empty `lang`, JSON `value`); core's per-language rows are never touched. Empty values are removed so the declared default applies again. The migration creates the table only when the site lacks it, and rolling back removes only the admin's rows.
- Website helpers `setting('site.title')`, `setting('contact.phone', '—')`, `setting('site.title', lang: 'ar')`, `settings('site')`, and `Settings::translations()`. The first read of a request loads `storage/cache/flocms-admin-settings.php`; saving or changing `admin/settings.php` rebuilds it, `php flo cache:clear` removes it. Without the table they return the defaults.
- Files uploaded on settings pages are public (`public/uploads/settings`, `settings_uploads`), stored through flocms-uploader with random names. `ImageUpload::make('logo')->translatable()` keeps one image per language; a language without its own uses the default language's. Translatable uploads are refused on resource forms.
- New `ColorPicker` field (`#rrggbb`, a swatch beside a text input).
- `php flo make:admin-settings`; `admin:install` writes the settings and preferences migrations once (and adds them on existing installations); `admin:doctor` checks the pages, tables, a foreign `settings` table, undeclared stored keys and the uploads folder; `admin:list` lists the pages and their permissions.

### Admin panel settings

- Built-in **Admin panel** page (`settings.panel`): panel name and logo (per language), brand colour, default theme, sidebar style, content width and table density. A saved value overrides `config/admin.php`; an empty one falls back to it. `'panel_settings' => false` turns the page off. The login page follows the same values.
- New table density (`density`: `comfortable`, `compact`).
- A newly saved brand colour applies at once during panel navigation.

### My preferences

- `/admin/profile` gets two tabs: Account and **My preferences** (theme, panel language, table density), kept per user in `admin_user_preferences`, or in the session until that table exists.
- A preferred panel language opens panel pages in that language; choosing a language from the top-bar menu (links now carry `?switch_language=1`) updates the preference.
- `Resource::afterDelete(object $record)` hook; deleting a user deletes their preferences.

### Columns button

- `Column::toggleable(hiddenByDefault: false)`; a **Columns** popover beside Filters in the Reactive and full-page lists. Choices are a personal preference stored as changes from the defaults; the title column can never be hidden; sorting by a hidden column falls back to the default sort.

## 0.13.1 - 2026-10-16

Fixes for resizing and positioning media in the rich text editor.

- Fix: an image, video or embed placed after a wrapped (floated) one ran underneath it in the editor, so its frame and grips covered both and dragging resized the wrong one. Each media block now sits beside or below a wrapped one with its own frame. The website stylesheet does the same for figures, videos and embeds.
- Fix: videos ignored their width unless they were wrapped, and embeds always filled the column; both now take 25/50/75/100 % and dragged widths in every position. Wrapped media no longer jump while dragging past half the column.
- Embeds show their real YouTube or Vimeo player in the editor (16:9, sandboxed), under a transparent shield so a click selects the embed instead of playing it. Sites that send a Content-Security-Policy allow `frame-src https://www.youtube-nocookie.com https://player.vimeo.com` for the admin too.
- The toolbar's Image button always adds an image (after a selected one); a selected image is edited from its own toolbar.

## 0.13.0 - 2026-10-16

Layout tools for the rich text editor. Existing content and toolbars keep working; new buttons appear in the default toolbar. No new dependency or migration.

### Images, videos and embeds

- Select one to get **corner and side grips**: drag to resize in 5 % steps of the text column (proportions kept, a width label while dragging).
- A **floating toolbar** above the selected item: Left, Centre, Right, text wrapping on either side, 25/50/75/100 % widths, and for images **Edit image**, **Replace image** (keeps description, caption, link, size and position) and **Remove**.
- Images can have a **caption** and a **link** (new fields in the image dialog), saved as `<figure class="rt-figure"><a><img></a><figcaption>`.
- Saved as classes, not styles (`rt-w-60`, `rt-align-center`, `rt-wrap-left`), so the website works under a strict CSP. New `RichText::stylesheet()` links the package's `rich-text.css` (served at `/flo-admin/asset/rich-text-v0.13.0.css`) for the website; it also gives embeds a responsive 16:9 frame.
- An empty paragraph always follows the last image or embed, so typing can continue below it.

### Paragraphs

- New toolbar buttons (also in `RichEditor::BUTTONS`): **Text alignment** menu (left, centre, right, justify), **Switch text direction** (a right-to-left paragraph in left-to-right text, or the reverse), **Increase / Decrease indent** (lists nest; paragraphs indent up to four steps), **Clear formatting** (Ctrl/⌘+\\).
- Pasting from Word or other office programs drops their hidden markup, styles and list bullets, and keeps alignment.

### Server

- `RichText::clean()` keeps only the presentation classes of `rich-text.css` (one width and one position per media element; alignment and indent on paragraphs and headings), `dir="rtl|ltr"` on blocks, and figures with one image, a checked link and a plain-text caption. Everything else is still removed.
- New strings `editor.text_align`, `editor.direction`, `editor.indent`, `editor.outdent`, `editor.clear_formatting`, `editor.align_*`, `editor.caption`, `editor.caption_help`, `editor.image_link`, `editor.image_link_help`, `editor.media_*`, `editor.wrap_*`, `editor.width_to`, `editor.width_now`, `editor.resize`, `editor.image_replace` in en/ar/ku.

## 0.12.0 - 2026-10-16

Full-page forms for complex records, dialogs for small ones, and a full-width panel. No new dependency or migration.

### Forms

- Create and Edit now open as a **full page** by default instead of a dialog over the list. New `Resource::$formMode`: `'page'` (default), `'modal'` or `'slide-over'` (a panel from the side of the window); per action `->page()`, `->modal()`, `->slideOver()`. Delete and custom actions stay dialogs. See [docs/forms.md](docs/forms.md#pages-dialogs-and-slide-overs-012).
- The record page has a sticky header with the title, **Delete**, **Cancel**, **Save** (stays on the record), **Save and close** (back to the list with its search, filters, sort and page) and, on new records, **Save and create another**. Enter in a field and Ctrl/⌘+S save. On phones the buttons sit in a bar at the bottom of the screen.
- Unsaved changes: the header shows "Unsaved changes", and leaving through a panel link, the Back button or closing the tab asks first (new leave dialog). A form shown again after errors counts as unsaved.
- New `Form::aside([...])`: secondary fields in a sidebar on the end side of the page (below the main fields on narrow screens and in dialogs). Top-level sections are drawn as cards.
- New `Section::collapsible()` and `Section::collapsed()`: folding sections (native `<details>`); a section with an error opens.
- The default full-page save (the first submit button) now stays on the record; `_close` returns to the list and `_another` opens a new form. `_continue` still works.
- `make:admin-resource` writes `$formMode = 'page'` into new resources; `admin:doctor` reports an unknown `$formMode` or `content_width`.

### Panel

- New `content_width` setting: `'full'` (default, the whole window) or `'boxed'` (centred, at most 1280 px). Pages were boxed before.
- The language badge in translatable fields is larger (15 px text, bigger dots), and fields leave room for it.
- New strings `actions.save_close`, `actions.save_another`, `form.leave_title`, `form.leave_text`, `form.leave_stay`, `form.leave_go`, `form.unsaved_state`, `form.save_shortcut` in en/ar/ku.

## 0.11.0 - 2026-10-16

A more professional editor, a page progress bar and widget commands. Additions only; no resource, field or configuration API changed, and no new dependency.

### Rich text editor

- Links, images from a link and YouTube/Vimeo videos open in **modal dialogs** above the form (or the record dialog) instead of panels inside the editor. Enter applies, Escape or a click outside closes only that dialog, and focus returns to the text. Ctrl/⌘+K opens the link dialog; on an existing link it shows **Edit link** with **Remove link**.
- The **Image** button opens a small menu: **Upload an image** (opens the file picker straight away) or **Image from a link**. Without an upload address it opens the link dialog directly. The **Video** button opens the file picker directly.
- Images and videos can also be **pasted or dropped** into the text; they upload through flocms-uploader like the buttons.
- Double-click an image (or select it and press Image) to edit its address and description (alt text).
- Uploads show their progress in a footer strip with **Cancel**, then "Upload complete." or the error. The footer also counts words for the current language.
- Placeholder text ("Start writing…", or the field's `placeholder()`), and keyboard shortcuts in the button tooltips.
- New toolbar icons from Lucide (ISC, see `THIRD_PARTY_NOTICES.md`), drawn on a 24 px grid with a 2 px stroke and shown at 20 px, so they are sharp; H2/H3 are icons now.
- Fix: the language tabs showed a vertical scroll bar.
- New strings `editor.link_title`, `editor.link_edit_title`, `editor.image_title`, `editor.image_edit_title`, `editor.image_from_link`, `editor.image_from_link_help`, `editor.embed_title`, `editor.save`, `editor.placeholder`, `editor.words`, `editor.cancelled`, `editor.uploaded` in en/ar/ku.

### Panel

- A thin **progress bar** along the top of the window replaces the "Loading…" box while a page loads or a Reactive update runs. It appears only after 150 ms, so fast pages show nothing, takes the brand colour and respects reduced motion. Screen readers still hear the loading status. Small loading indicators inside the panel are now spinners.

### Commands

- New `php flo make:admin-widget Name [--type=stats|records|chart] [--resource=Post] [--force]`: writes a working widget to `admin/Widgets/` (or `widgets_path`) and adds it to `'widgets'` in `config/admin.php`. When it cannot safely edit that list it prints the line to add instead.
- New `php flo admin:list [--permissions]`: the resources (URL, table, permissions, class) and dashboard widgets the panel loads; `--permissions` prints every permission name, ready to paste into a role.
- Clearer widget errors in `admin:doctor`: a class that is not found now names the file it expected (for example `admin/Widgets/ListingStats.php`), points out a misnamed file such as `ListingStats.php.txt`, and says when a class is outside `widgets_namespace`. Other errors say whether the class is abstract or does not extend a widget type.

## 0.10.0 - 2026-10-16

Rich text. Additions only; existing resources, fields and settings are unchanged. Requires `hostkurd/flocms-reactive` **^0.5.1** (for `data-rx-ignore`) and `hostkurd/flocms-uploader` **^1.2.1** (its Imagick driver padded resized images to a square).

- New `RichEditor` field: headings, bold/italic/underline/strike, links (checked, optional new tab), lists, quotes, code blocks, dividers, undo/redo, images, videos and YouTube/Vimeo embeds. `toolbar()`, `images()`, `videos()`, `embeds()`, `maxImageSize()`, `maxVideoSize()`, `maxLength()`. See [docs/rich-text.md](docs/rich-text.md).
- Translatable rich text shows language tabs (EN · AR · KU) with filled/empty/invalid dots; each language has its own editor and writes in its own direction. Single-language sites get no tabs.
- Clean HTML: `RichText::clean()` removes scripts, event handlers, styles, unknown tags and unsafe URLs on the server before validation and saving; embeds are rebuilt from the video ID as sandboxed, lazy `youtube-nocookie.com` / `player.vimeo.com` iframes. `RichText::plain()` for excerpts. Empty formatting counts as empty for `required`.
- Media uploads through flocms-uploader to a public folder (new `media` setting, default `public/uploads/editor` at `/uploads/editor`): images stored as one web version of at most 1920 px; MP4/WebM videos sent in 1 MiB parts (not limited by `upload_max_filesize`), owned by the uploading user and session. New CSRF-protected endpoint `/admin/<resource>/media/<field>/…` for people who may create or edit the resource.
- The editor script (`admin-editor-v0.10.0.js`, Tiptap 3.31.4, MIT; see `THIRD_PARTY_NOTICES.md`) loads only on pages with an editor and works under a strict CSP. In the admin, embeds show as cards instead of third-party frames. Keyboard: one tab stop toolbar with arrow keys, Enter applies and Escape closes insert panels.
- In Reactive dialogs the editor keeps its content and undo history while the dialog updates; new errors for the field rebuild it from the submitted text and move focus into it.
- `admin:doctor` checks that the media folder is writable and that GD or Imagick is available when a resource uses rich text media.
- New icons for the toolbar; new strings `editor.*` and `validation.rich_text_long` in en/ar/ku.

## 0.9.0 - 2026-10-16

The panel redesign, matching the approved mockup. Additions only (new optional settings, `Resource::$description`, icons and strings); no existing resource, form, table, action or configuration API changed, so existing sites keep working and only look different. Browser tests or scripts that clicked row actions, filters or the bulk select may need updating (see the List page section).

### Foundation

- Design tokens: every colour, radius and shadow in `admin.css` is now a CSS custom property (`--fa-*`). The 70 hard-coded colour values outside the token definitions are gone (white text on solid buttons excepted).
- New `theme` setting in `config/admin.php`: `'light'` (default), `'dark'`, or `'auto'` (follows the operating system). Applies to the panel, the optional login page and toasts. Unknown values fall back to light; `admin:doctor` reports them.
- Shipped fonts: Instrument Sans (Latin) and Noto Sans Arabic (Arabic, Kurdish), SIL OFL 1.1, served by the asset route with immutable caching. Kurdish and Arabic no longer fall back to Tahoma or Segoe UI on Windows.
- One focus ring everywhere (2 px, theme-aware) instead of three different styles; stronger focus state on inputs.
- Refreshed buttons, inputs, cards, badges, alerts, tables, pagination, tabs, dialogs, toasts, login page and dashboard widgets on the tokens (radius, spacing, weights, softer borders).
- Fix: Reactive's own dialog and status styles, which load after the admin's, no longer force a white dialog background or a fixed red status colour inside the panel.
- Fix: toasts used their own font stack and colours; they now use the panel's font and theme.
- The browser fixture accepts `FLO_ADMIN_THEME=dark|auto` for local testing.

### List page
- One toolbar: search with its icon and a clear button, table actions, and a **Filters** button showing the number of active filters. Filters open in a popover (a native `<details>`, so it works without JavaScript) and re-open by themselves when a value is invalid.
- Active filters appear as chips under the toolbar; each chip removes its own filter, and **Clear filters** removes all.
- The bulk bar appears only while rows are selected and offers each bulk action as a button (danger actions in red outline). Without JavaScript it stays visible and its buttons submit the same request as before. Selected rows are highlighted.
- Row actions: Edit is a quiet icon button; the other actions are in a **More actions** (`⋯`) menu, destructive ones last after a divider. The menu works without JavaScript; with it, it closes on Escape or an outside click, follows its button while scrolling instead of being clipped by the table, and returns focus to its button when an action's dialog closes.
- Numbered pagination with previous/next arrows (mirrored in right-to-left languages) in both the Reactive and standard lists.
- New icons: `pencil`, `trash`, `more`, `filter`, `x`, `chevron-left`, `chevron-right`. New strings `table.more_actions`, `table.page`, `filters.remove` in en/ar/ku.
- Fix: the Reactive list's search field was missing its search icon.

### Modal and forms
- Dialogs end with Cancel followed by a filled primary button (Save changes, Create, or the action's own colour) on the end side; the close button is a proper icon. Delete confirmation uses the same order.
- Standard (full-page) forms keep the primary button first so Enter still submits rather than cancels; their actions sit in a sticky bar at the bottom of the card. Standard action and bulk confirmation pages use the primary style too.
- `Toggle` fields render as switches (the same checkbox underneath, so submitted values and no-JS behaviour are unchanged), mirrored in right-to-left languages.
- The translation badge joins its input like an add-on; for long text it sits on the label line. The translation dialog is a compact panel whose rows start with the language code, and ends with Cancel, then Save.
- Section descriptions sit directly under their heading, and consecutive sections are separated by a line.

### Sidebar and topbar
- Light sidebar by default (`'sidebar' => 'dark'` keeps a dark one), with a brand mark (the brand's first letter, or `logo`) and "Admin" under the brand name.
- Topbar: breadcrumbs (Content / Posts / New post) instead of a repeated page title; a language switcher that opens the same admin page in another site language; an account menu (avatar initial, name) with My profile, View site and Log out. Full-page forms lose their in-page breadcrumb link, which the topbar now carries.
- New settings in `config/admin.php`: `primary_color` (`#rrggbb`; shades for hover, highlights and the dark theme are derived from it and served as `theme-<hash>.css`, immutable and CSP-safe), `logo` (absolute URL or `/path`), `sidebar`. Invalid values fall back to the defaults; `admin:doctor` reports them and warns when white button text on `primary_color` is below 4.5:1 contrast.
- Optional `Resource::$description`, shown under the list page title. The "Content" eyebrow above list titles is gone (the breadcrumb carries it).
- 22 more icons for resources and actions (`newspaper`, `folder`, `map-pin`, `cart`, `credit-card`, `chart`, `message`, `bell`, `key`, `shield`, `layers`, `briefcase`, `book`, `camera`, `video`, `heart`, `link`, `phone`, `truck`, `grid`, `globe`, `user`), plus `chevron-down` and `external` for the topbar.
- New strings `nav.admin`, `nav.breadcrumb`, `nav.language`, `nav.account` in en/ar/ku.

### Phones, dashboard and translations
- Phones (640 px and narrower): list rows become cards. The first searchable column (else the first column) is the card's title next to the row actions, and the other columns follow as label / value pairs. "Select this page" stays visible above the cards. Dashboard widget tables are unaffected.
- Breakpoints reduced from seven to three: 640, 900 and 1024 px.
- Charts: labels are HTML text at a fixed 12 px (they used to scale with the widget's width, from about 8 px to 18 px), with dashed gridlines and a middle value. Bars and lines stretch to the widget's width; lines and points keep their thickness. Still free of inline styles.
- Stat tiles with a coloured accent bar, a larger value and a hover state when linked; a smaller Refresh dashboard button with an icon, aligned to the end.
- The translation badge sits inside the input's end edge (the top corner of a textarea) and shows only the default language plus one dot per other language: filled when translated, hollow when empty, red when invalid. Its accessible label names the missing languages and updates as you type. The badge follows the content language's direction, so it never covers the start of the text on a page in the other direction.
- Sites with a single language: translatable fields render as plain inputs, with no badge or translation dialog. Values are still saved as `{"en": "…"}`, so adding a language later needs no migration.

Ship `admin-v0.9.0.css` and `admin-v0.9.0.js`.

## 0.8.1 - 2026-10-16

- Remove the browser's default 8 px page margin around the panel when the site's admin layout places the panel directly in `<body>` (the skeleton's layout does). Pages that set their own body margin are unaffected.
- Ship `admin-v0.8.1.css` and `admin-v0.8.1.js`; the JavaScript is unchanged apart from its file name.
- Documentation: the 0.6.0 entry carries its release date, release headings use one dash style, and the Reactive Admin guide refers to Reactive 0.4 and the released Widgets.

## 0.8.0 - 2026-10-15

- Add opt-in StatsWidget/Stat, RecordsWidget and single-series SVG ChartWidget (bar/line), explicit class registration, responsive column spans, ordering, translations and accessible chart data tables.
- Add immutable scoped read helpers with bound filters, counts/sums and bounded records. Enforce registered source resources and view permissions before providers run, with optional extra widget permission and visibility checks.
- Add a signed, CSRF-protected dashboard refresh component that rechecks current configuration/permissions; retain ordinary GET refresh for no-JavaScript and Reactive-disabled projects, and keep automatic resource cards when no widgets are configured.
- Isolate provider failures, log private diagnostics, validate widget configuration through admin:doctor without invoking providers, and bound widgets, points, rows, stats and query counts.
- Add runnable fixtures, PHP/browser regression coverage, a Widgets guide, upgrade notes and admin-v0.8.0 assets. Existing Core, Reactive, Uploader and standalone skeleton requirements remain unchanged.

## 0.7.0 - 2026-10-15

- Add single-file FileUpload and raster ImageUpload fields to resource CRUD forms, with MIME/extension/actual-size/dimension validation and private local storage through flocms-uploader ^1.2.0.
- Add CSRF-protected multipart transport for Reactive dialogs, session/actor/record/field-bound pending tokens, permission-checked downloads/previews, and multipart standard/async forms including no-JavaScript submissions.
- Preserve pending selections on validation failure, compensate newly written files on transaction rollback, and clean up replaced/removed/deleted managed files only after commit. Bound pending lifetime, count and bytes; expose scheduled stale-upload pruning.
- Add English/Arabic/Kurdish controls, mobile RTL preview styling, runnable fixtures, regression coverage, the Uploads guide and upgrade notes. Ship admin-v0.7.0 assets.
- Keep Core and standalone skeleton unchanged; Reactive remains ^0.4.0. Upload fields are limited to resource CRUD forms and private local storage in this release.

## 0.6.0 - 2026-10-15

### Added
- Sections, tabs and responsive 1–4 column grids with flat, unique field names shared by CRUD and action forms.
- Accessible keyboard/RTL tabs, error-tab selection, and visible section fallback without JavaScript.
- Server-resolved dependent select options and conditional visibility/required rules; hidden values cannot bypass validation or overwrite stored columns.
- Authorized, non-writing dependency refreshes for Reactive, standard, custom and bulk action forms, preserving unrelated drafts and existing operation guards.
- Runnable Properties example and [Forms guide](docs/forms.md), including database, callback, password and no-JavaScript contracts.

### Changed
- Requires Reactive `^0.4.0` for larger bounded scalar state. Modal drafts now allow 64,000 UTF-8 JSON bytes, with full-page recovery above that size.
- Assets use complete release filenames `admin-v0.6.0.js` and `admin-v0.6.0.css`. Core and skeleton dependencies are unchanged; no application migration is performed automatically.

## 0.5.0 - 2026-10-14

### Added
- Select, toggle and inclusive date-range table filters with allowlisted values, bound queries, bookmarks and no-reload back/forward restoration.
- Explicit selection across list pages, selected counts, select-this-page and PHP-defined bulk Actions with validated forms; maximum 50 selected IDs.
- Atomic bulk dispatch with current permission/scope/policy checks for every row, stale-row locking, session-bound one-use operations and commit-only summary notifications.
- Bulk Publish/Unpublish/Delete examples, JavaScript-disabled fallback, keyboard/mobile RTL browser coverage and configured-database regressions.
- Optional localized Admin login presentation using the skeleton's authentication and optional rendering hook; password visibility toggle, CSRF, feedback and signed-in nonadmin logout.
- [Table and login API guide](docs/tables-and-login.md), compatibility diagnostics and upgrade instructions for existing application views.

### Changed
- Full release-version assets are `admin-v0.5.0.js` and `admin-v0.5.0.css`. No Core/Reactive dependency change or automatic application schema change.
- New Admin configurations enable the login theme; existing installations opt in. Standalone FloCMS remains independent.

## 0.4.1 - 2026-10-14

### Fixed
- Modal and full-page action forms use the same default-language input and language badge, including translated textareas. Translation Save/Cancel/Escape, keyboard focus, RTL and validation work inside the resource editor and after Reactive updates.
- Remove the normal "Open standard view" switch. Full-page URLs remain supported; a recovery link appears for oversized drafts or when JavaScript is disabled.

### Changed
- Ship full release-version assets `admin-v0.4.1.js` and `admin-v0.4.1.css`. No database migration, resource rewrite or dependency upgrade is required.

## 0.4.0 - 2026-10-14

### Added
- PHP-defined Actions for resource page headings, table toolbars and rows, with labels/icons/colors, permissions, current-record policies, confirmation and independent validated forms.
- Shared `CreateAction`, `EditAction`, `DeleteAction` defaults and a common renderer/executor for Reactive dialogs and standard action forms, including JavaScript-disabled operation.
- Session-bound action operations, conditional row locks before callbacks, guarded `ActionContext` updates/deletes, and same-connection transactional execution. Record locks do not fire UPDATE triggers.
- Text-only success, warning, danger and info Notifications, with localized accessible dismissal, response IDs, bounded delivery and persistence across panel navigation without refresh/replay duplication. Failed action transactions discard staged messages.
- Working Publish/Unpublish example, page/toolbar examples, request/notification/record-lock regressions and browser coverage.
- [Actions and Notifications guide](docs/actions-and-notifications.md) and 0.4 upgrade notes.

### Changed
- Core flash messages use the Notification presentation. Built-in CRUD actions retain resource normalization, validation, hooks, password handling and translation preservation.
- Compiled assets are `admin-v0.4.0.js` and `admin-v0.4.0.css`. No hosting-side build or Core/Reactive dependency bump is needed.
- Filters, bulk operations, richer Forms and Widgets remain later milestones.

### Fixed
- Preserve action drafts on conflicts/deleted records and keep native dialogs modal while notification deliveries are moved during DOM updates.
- Load Core's validation exception declaration for guard paths independently of test execution order.

## 0.3.0 - 2026-10-13

### Added
- Reactive resource lists with live search, sorting, pagination, create/edit dialogs, translated validation, delete confirmation and success feedback. Existing PHP resource definitions use the shared adapter.
- Panel navigation and standard/profile form submissions without full document reloads; browser history, title, language and focus follow the visible page.
- Current permission/record-scope checks, sealed active record context, one-use dialog operations, transactional saves and atomic stale-row protection for modal updates/deletes.
- External `admin-v0.3.0.js` / `admin-v0.3.0.css` assets, compiled with TypeScript/Vite and shipped in Composer distributions. No hosting-side Node build.
- Keyboard mobile menu, accessible modal errors and inline translations, EN/AR/KU transport messages, browser tests including RTL/CSP, and configured database concurrency regressions.
- [Reactive Admin guide](docs/reactive-admin.md) and [upgrade notes](UPGRADING.md).

### Changed
- Admin now requires Core `^2.3.2` and Reactive `^0.3.0`. Core and the skeleton still have no dependency on either optional package.
- Reactive navigation is enabled by default. Set `reactive => false` for the full-page behavior; the standard resource view is also available for larger forms.
- Forms use the same normalization/validation/transaction service in both request paths, retaining the 0.2.1 correctness fixes.
- Filters, uploads, bulk operations and widgets remain later milestones; this release establishes the Reactive integration.

## 0.2.1 - 2026-10-13

### Fixed
- Validate email-derived login values before saving accounts or profiles; exclude the current account and preserve password validation, hashing and blank-edit behavior.
- Check translatable unique fields against the same stored language, with exact case-sensitive equality, bound values and legacy plain-text support.
- Roll back resource writes and same-connection `beforeSave()` / `afterSave()` database writes when a save hook throws; nested transactions use Core savepoints.
- Restore trusted record context for read-only fields on invalid edit submissions.
- Preserve stored translations for languages absent from configuration; ignore submitted hidden-language keys while allowing configured languages to be cleared.
- Skip unconfigured MySQL tests without accessing an uninitialized database during teardown.

### Added
- `Resource::normalizeState()` for editable-state normalization before validation and dehydration.
- Request regressions for all save-path findings, shared SQLite/MySQL/MariaDB uniqueness and nested-transaction coverage, and configured-database execution of feature requests.
- [Upgrade notes](UPGRADING.md), including hook side effects and duplicate-data checks. No schema migration or new package dependency.

## 0.2.0 - 2026-10-12

The panel becomes the site's admin interface: dashboard, users and profile.

### Added
- **Dashboard** at `/admin/dashboard`: one card per resource the user may view, with its number of records. The package sets `Config 'admin.home'`, so `/admin` opens it on skeleton 1.8+; a site can override the setting in `config/config.php`.
- **Users management** at `/admin/accounts` for the skeleton's `users` table, with the skeleton's rules: `users.manage` for every action, roles up to your own (`Auth::canAssignRole()`), only lower roles editable unless `users.manage_any` (`Auth::canManageUser()`), no deleting, demoting or suspending yourself. Passwords hashed as by `php flo user:create`.
- **Profile** at `/admin/profile`: your own name, email, login and password; the current password is needed to change the email or the password.
- Resource API: `canCreate()`, `canEdit($record)`, `validate($state, $record)`; fields: `->requiredOnCreate()`.
- `'users' => true|false` in `config/admin.php`.
- `admin:doctor` checks the panel's pages and the users table, and says whether `/admin` can open the dashboard.

### Changed
- The URLs `dashboard`, `accounts` and `profile` are reserved for the panel; a resource using one is skipped and reported.
- The sidebar's Dashboard and Users links open the panel's pages; the user name in the top bar opens the profile.
- Filters, uploads, bulk delete and stat widgets move to 0.3.

## 0.1.0 - 2026-10-12

First release. Requires flocms-core 2.3+.

- Resources declared in PHP (`FloCMS\Admin\Resource`): table, slug, labels, icon, navigation order, timestamps, permission names, `query()` / `beforeSave()` / `afterSave()` / `canDelete()` hooks.
- Clean URLs through core 2.3's controller resolvers: `/admin/<slug>`, `/create`, `/edit/<id>`, POST `/delete/<id>`. Application controllers always win; colliding slugs are skipped and reported.
- List page: search, sorting, pagination, Edit and Delete (with a confirmation dialog).
- Form fields: `TextInput` (text, email, URL, tel, number, password), `Textarea`, `Select`, `Toggle`, `Date`, `DateTime`, `Slug`, `Hidden`, `Placeholder`.
- Table columns: `TextColumn`, `BooleanColumn`, `DateColumn`, `BadgeColumn`.
- Translatable fields stored as one JSON object per column, behind a `TranslationStore` interface; language badge and `<dialog>` editor; plain-text values already in a column are read as the default language. Website helper `localized()`.
- Validation with FloCMS's rules, applied to the default language (`required`) and to every filled language (the other rules); field type checks; `unique`; messages in the admin's language.
- Permissions per action (`content.<slug>.view|create|update|delete` by default) through `$actionPermissions`.
- RTL admin (logical CSS only), `dir` per language input, admin strings in English, Arabic and Kurdish (Sorani), overridable from the site's lang files.
- Commands: `admin:install`, `make:admin-resource` (`--migration`), `admin:doctor`.
