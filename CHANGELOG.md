# Changelog

All notable changes to `hostkurd/flocms-admin` are documented here.

## 0.39.4 - 2026-10-30

- **Fixed: `Env::get()` in `config/admin.php` returned its default** since 0.35.0. The panel read the file during Composer's autoload (to know whether to register the sign-in steps of two-factor sign-in and email verification), before the site reads `.env`, and kept that early read. So `'brand' => Env::get('SITE_NAME_ADMIN', 'Admin')`, which `admin:install` writes, showed "Admin", and every other `.env` value in the file was ignored. The early read is no longer kept: the panel reads `config/admin.php` again on first use, after `.env`. Nothing to change in existing files.
- `'two_factor'` and `'email_verification'` can now take their value from `.env` too (`'two_factor' => Env::get('TWO_FACTOR', false)`). The sign-in steps are registered when the panel starts, so the panel also reads these two switches from the file's code (`FloCMS\Admin\Support\ConfigSwitches`); each step then checks the real value per request. A switch that is on but can't be seen when the panel starts (from `.env` in another file merged into `config/admin.php`) stays off everywhere, so the panel never shows a step that sign-in doesn't ask; a warning is logged and `admin:doctor` notes it.

## 0.39.3 - 2026-10-23

- New setting `'site_link'` in `config/admin.php` for the View site link in the top bar, the user menu and the login pages: `true` (this site, as before), `false` (no link: an admin panel without a website, or with an API only; the login page keeps the brand without a link and drops "Back to website"), or an `https://` address (a website hosted elsewhere, such as a panel on `admin.example.com`). `admin:doctor` reports an invalid value, and warns when Pages are on while `site_link` is `false`.

## 0.39.2 - 2026-10-23

- A text cell's hover border now wraps its text: before, the box was 8px too narrow, so a number such as "€ 400.00" ran over its end edge.
- "Saving…" and "Saved" next to an editable cell no longer push the column wider (and back when they go): they float beside the cell. Error messages still show under the cell. On phones they stay under the value.

## 0.39.1 - 2026-10-23

**Quieter editable cells.** Nothing to migrate.

- A select cell no longer gets a dark border on hover: its badge darkens slightly, and keyboard focus shows a soft ring in the panel colour.
- A text cell shows only a soft border on hover (the pencil icon is gone); keyboard focus shows the panel colour.

## 0.39.0 - 2026-10-23

**Wizard forms.** Nothing to migrate. See [Wizard forms](docs/wizards.md).

- **`Wizard` and `Step` layouts** (`FloCMS\Admin\Form\Layout`): a long form shown as steps, one at a time, with Back and Next and a final Create or Save.
  - `Wizard::make()->summary()` (a last Review step listing the answers, each group with an Edit button), `->startOn()`, `->submitLabel()`.
  - `Step::make()->label()`, `->description()`, `->icon()`, `->columns()`, `->visibleWhen()` (a step shown only for some answers; skipped, not checked and not saved otherwise) and `->afterValidation()` (extra checks after the step's rules).
- **Next checks only the shown step** on the server, with the form's own rules; Back and the step buttons never check or save; nothing is saved before the final submit, which checks every shown step and opens the first one with an error ("Please check: Price (Pricing)").
- On a new record, steps go in order (back to any step reached); **the edit page** opens any step from the header and has Save on every step. Enter means Next on a new record and Save when editing.
- **Everywhere forms go**: create and edit pages, create and edit dialogs and slide-overs (and so relation managers), action forms on their own page or in a dialog, bulk action forms, custom page forms and their header actions. The view page shows each step as a card.
- **Without JavaScript** every move is a form submit (`_wizard` = `next`, `back`, `go:<step>`, with `_wizard_step` and `_wizard_reached`); with it the panel and dialogs move without a reload and focus goes to the new step's heading. Files chosen on an early step are kept as pending uploads; translatable fields work with badges and with the page-level language switch.
- **Phones**: "Step 2 of 5 · Location" with a progress bar and the step list in a menu; Back and Next fixed at the bottom.
- Rules checked when the form is built: a wizard is the whole form (`aside()` fields may sit beside it), steps only in a wizard, wizards never nested, passwords only on the last step, a step's `visibleWhen()` names a field of an earlier step; settings pages and custom pages' filters can't be wizards.
- `Form::wizard()`, `stepOf()`, `stepFields()`; `Form::errorSummary()` names a field's step (`$steps = false` leaves it out); the record page, the action page, the bulk page and dialogs hide their final button until a wizard's last step.
- The bulk action page has a hidden default button, so Enter submits the action rather than the first button in the form, and a repeater's Add/Remove button there changes the list instead of running the action.
- A custom page form's live fields refresh the form instead of saving it.
- New words in `en`, `ar` and `ku`: `wizard.*`.

## 0.38.0 - 2026-10-23

**Editable cells, totals and grouped lists.** Nothing to migrate. See [Editable cells, totals and grouped lists](docs/tables.md).

- **Editable cells**: `ToggleColumn` (a switch that saves at once; `->values('published', 'draft')` for other stored values), `SelectColumn` (saves on change, badge colours with `->colors()`) and `TextInputColumn` (click or Enter opens an input; Enter or leaving saves, Escape cancels, Tab moves on; `->numeric()`, `->prefix()`, `->suffix()`, `->limit()`).
  - The value goes through the form field of the same name (its rules, options and stored shape), or the column's own `->rules()` / `->options()`.
  - The same checks as the edit page: the `update` permission and `canEdit()` (`Resource::mayEdit()`), only the list's own editable columns, `beforeSave()` / `afterSave()`, `updated_at`, one "updated" activity entry, and fields with `requiredWhen()` / `visibleWhen()` on the column checked with the record's values.
  - A change somebody made to the same column since the list was drawn is never overwritten: the cell shows the current value and a message.
  - `->requiresConfirmation()` (a dialog, or a confirm page without JavaScript), `->beforeUpdate()` to write other columns too, `->afterUpdate()`.
  - Viewers, rows people may not edit, read-only relation managers, fields a manager hides, disabled fields and fields `visibleWhen()` hides for the record show the value only. Translatable columns can't be edited in the list. Custom pages' tables stay read-only (an editable column there is refused).
  - Without JavaScript every cell is a small form with a Save button. New endpoint `POST /admin/<slug>/cell/<id>`.
- **Totals**: `->summarize([Sum::make(), Average::make(), Count::make(), Min::make(), Max::make()])` on a column, each with `->label()`, `->decimals()`, `->prefix()`, `->suffix()`, `->formatUsing()`; `Count::make()->where(1)`.
  - "This page" (from the rows shown) and "All rows" (one query over the list's rows: `query()`, a relation manager's parent, tabs, filters and search), the same on MySQL, MariaDB and SQLite. `Table::summaries('all'|'page')`.
  - In the standard and Reactive lists, relation managers and custom pages' tables; a card on phones; updated after a cell is saved. CSV exports have no total row.
- **Grouping**: `$table->groups([Group::make('city_id'), Group::make('status'), Group::make('created_at')->date('month')])`, `->defaultGroup()`, `->groupingRequired()`; `Group` takes `->label()`, `->titleUsing()`, `->date('day'|'month'|'year')`, `->direction()`, `->collapsible(false)`.
  - A **Group by** menu (remembered per person, and in the address as `?group=`); the group sorts first, then the clicked column.
  - Headings with the group's row count and subtotals over all its rows, "continued" when a group goes on from the previous page, collapse / expand (and all), a checkbox that selects the group's rows on the page. Titles from the related record, the Select's labels and colours, or month names in the panel language.
  - Reorder is off while grouped; CSV export ignores grouping.
- `FormSubmission::save()` takes `$extra` columns (used by `beforeUpdate()`); `RecordRepository::filteredQuery()`; `BadgeColumn::getColors()` / `getLabels()`.
- The panel's confirmation dialog takes `data-confirm-tone="primary"` for a blue button.
- New words in `en`, `ar` and `ku`: `numbers.*`, `cells.*`, `totals.*`, `groups.*`.

## 0.37.0 - 2026-10-23

**Checkbox lists, tags and times.** Nothing to migrate. See [Checkbox lists, tags and times](docs/choice-fields.md).

- **`CheckboxList`**: several choices from a fixed list, stored as a JSON list of option keys in one column, in the order of the options. `->columns(1–4)`, `->selectAll()` (Select all and Clear), `->searchable()` (a filter box, on by default above 12 options), `->min()`, `->max()` (the other boxes wait at the maximum), `->whenMissing()`. A count ("4 of 9 chosen") next to the label. An option removed from `options()` stays saved in records that have it, with a notice, until it is unticked (also through imports and saves without the field); it doesn't count towards `min()`/`max()`; a key the record doesn't have is refused.
- **`TagsInput`**: free words as chips. Enter or a comma adds one (the Arabic comma too); a pasted list becomes tags; Backspace in the empty box selects the last tag and removes it on the second press; arrow keys move between tags (mirrored on right-to-left pages). `->suggestions()`, `->onlySuggestions()` (a suggestion removed since stays valid on records that have it), `->max()`, `->maxLength()`, `->separator()`. Tags are trimmed, spaces collapsed and duplicates removed regardless of capitals. Without JavaScript: one text box.
- **`TimePicker`**: the browser's time input, stored as `HH:MM:SS`. `->minTime()`, `->maxTime()`, `->step()` (minutes), `->seconds()`; all checked again on the server.
- **List columns** `TagsColumn` (chips, `->limit()`, `->options()` for labels; "+2" for the rest) and `TimeColumn`; **filters** `CheckboxListFilter` (one option, or `->multiple()` for any of several), `TagsFilter` (whole tags, any capitals, any script, accents count; the same on MySQL, MariaDB and SQLite) and `TimeRangeFilter`.
- Everywhere else the fields go: the view page (chips, times), the activity log ("Features · Added: Parking"; the same tags in another order are no change), CSV export (`pool | parking`, labels in the readable export) and import (keys or labels; times like `9:30 PM` or a spreadsheet's 0.3958), repeater items, block forms, settings pages and custom page forms (lists arrive as arrays).
- A plain-text column holding "pool, parking" can become a CheckboxList or TagsInput without a migration: it is read as a list and saved as JSON next time.
- New config `'time_format'` (default `'H:i'`; `'g:i A'` for 12-hour) for times in lists, view pages and the activity log.
- Website helpers `list_values($value)` and `option_labels($value, $options)`.
- `make:admin-page --table` shows `TIME` columns with `TimeColumn` (they were taken for dates).
- New words in `en`, `ar` and `ku`: `checks.*`, `tags.*`, `validation.list_*`, `validation.tag*`, `validation.time*`, `filters.any_tag`, `table.more_items`.

## 0.36.2 - 2026-10-22

**`make:admin-page` reads the table it lists.** See [Custom pages: making a page](docs/custom-pages.md#making-a-page).

- **`--table=listings`**: the page lists that database table. The generator checks it exists (a misspelt name stops with "Did you mean --table=listings?") and writes up to six columns that fit it:
  - text searchable and sortable, numbers sortable, dates as `DateColumn`, true/false as `BooleanColumn`;
  - columns holding the panel's translations found from their values and marked `->translatable()`;
  - the key as the default sort.
- It adds From/To filters on a date column and a select filter for a `status` / `type` / `category` column with a few values. It leaves out long text, binary data, `_id` keys and secret-looking columns, and lists what it left out.
- **Plain `--table`** shows the database's tables to pick one; without a terminal it names them and stops. 0.36.1 used `users` without asking.
- **`--resource=Listing`**: the page lists a resource's table with its list's columns, labels and translatable setting, and rows open the record's edit page. Relation, media and count columns are listed to add by hand.
- **Custom pages' tables**: `defaultSort()` on a column the table doesn't show (the key) now orders the rows (it was ignored), and decides between rows with equal values when sorting by a column.

## 0.36.1 - 2026-10-22

- **Custom pages**: a part of a page that throws (filters, widgets, view, table, form) no longer breaks the whole page with `APP_DEBUG=true`; the rest of the page works.
- The part now says what went wrong in plain words, for everyone: *The table "bookings" was not found in the database. Check table() in admin/Pages/SalesReport.php.*, a missing column, no database connection, the panel's own setup messages, or else the method and file to check. With `APP_DEBUG` it also shows the error, its file and line.
- `php flo make:admin-page --table` makes a table of the site's `users` (Name, Email, with Name and Status filters), so a new page works at once. It used an example `bookings` table that sites don't have.
- New words in `en`, `ar` and `ku`: `pages_api.unavailable_title`, `pages_api.problem_*`.

## 0.36.0 - 2026-10-22

**Custom pages: a site's own reports, tools and help pages inside the panel.** Nothing to migrate. Pages are listed in `'admin_pages'` in `config/admin.php`. See [Custom pages](docs/custom-pages.md).

- **`FloCMS\Admin\AdminPage`**: one class per page, in `admin/Pages/` (namespace `App\Admin\Pages`, loaded by the panel), at `/admin/<slug>`. It gets the panel's layout, sidebar, breadcrumbs, permissions, search, RTL, dark mode and phone layout. Every part is optional and shows in this order: a header with `actions()`, `filters()`, `widgets()`, `view()` with `data()`, a read-only `table()`, and a `form()` with Save.
- **Filters** kept in the address (`?filter[city]=erbil`), so a filtered page can be bookmarked and shared. Their checked values reach `data()`, the table's query, widgets (`$this->pageFilters()`), actions and routes (`$page->filterValues()`).
- **A read-only table from any query**: `Table::query(fn (Database $db, array $filters) => …)`, with the list's search, sort, pages, Columns button and **Download CSV** (`->exportable(false)` removes it), and row links with `->url(fn ($row) => …)`.
- **Widgets on any page**: the dashboard's widget classes, with their permission checks.
- **Tool pages**: `form()`, `fill()` and `submit(array $data)`, with the resources' fields, checks, error summary and unsaved-changes warning. `submit()` may throw a `ValidationException` or return a message. Saves are logged as **Page saved** with the changed fields (`$activity = false` turns that off).
- **Header actions** with the Actions API: a form or a confirmation on the action's own page, or one click with `->requiresConfirmation(false)`. The callback is `fn (array $data, AdminPage $page)` and may return a message or a `Response` (a download). Header actions open their own page in 0.36, not a dialog.
- **Routes**: `$routes = ['chart' => 'chartData']` makes `/admin/<slug>/route/chart` call the method, with the page's permission; more parts of the address are cast to its `int`, `float` or `bool` parameters (404 otherwise); arrays are sent as JSON.
- **Sidebar groups** for the first time: `$group`, `$sort`, `$icon`, `$navigation = false`, and `badge()` for a count on the item. Pages without a group come under Content after the resources.
- **Permissions**: `panel.<slug>` by default (or `$permission`), plus `canAccess()`. The Roles page has a **Panel pages** group with an **All panel pages** switch (`panel.*`).
- **Ctrl+K search** finds pages by label, group and description.
- **Home page**: `'home' => SalesReport::class` makes `/admin` open that page for people who may open it.
- **Look**: `$width` (`default`, `full`, `narrow`), and UI pieces for a page's own view (`fa-card`, `fa-stat-row` / `fa-stat-tile`, `fa-split`, `fa-empty`) that follow the theme, RTL and dark mode without inline styles.
- **`php flo make:admin-page SalesReport`** with `--view`, `--form`, `--table` and `--group=`. It registers the page, and adds the `'admin_pages'` list to an older `config/admin.php`. `admin:doctor` and `admin:list` list the pages and their problems: a missing class or view, a slug used by a resource, by a panel page or by the site's own controller, a broken definition, or a `'home'` that isn't a page.
- A part of a page that fails is logged and shows "could not be shown"; the rest of the page still works.
- For developers: `Widget::pageFilters()`, `Dashboard::items()`, `Notifications::sentDuringCapture()`, `Icons::exists()`, `Table::query()`, `Table::url()`, `Table::exportable()`, `Admin::panelPages()`, `Admin::homePage()`.
- New words in `en`, `ar` and `ku`: `pages_api.*`, `roles.group.panel_pages(_help)`, `roles.all_panel_pages(_help)`, `activity.what.page_saved`, `activity.badge.page_saved`.

## 0.35.0 - 2026-10-22

**Login security: two-factor sign-in with an authenticator app, and email verification.** Both are off until `'two_factor' => true` / `'email_verification' => true` in `config/admin.php`; they need flocms-core 2.7, `php flo admin:install` and `php flo migrate`. See [Login security](docs/login-security.md).

- **Two-factor sign-in**: people set it up under **Profile → Security** (password, then a QR code or key for any authenticator app, then the first code); 10 single-use **backup codes** shown once with Download, Copy and Print; **New backup codes**; **Turn off** with the password or a code.
- **The sign-in step** (`/admin/two-factor`): after the password a fresh sign-in is held by flocms-core 2.7 (`Auth::holdSessionsUsing()`) until a code from the app or a backup code; until then the person counts as signed out everywhere. A code works once; five wrong codes end the sign-in; ten within an hour pause the account's codes for 15 minutes and email the owner. **Use another account** signs out.
- **Remember this device** for 30 days (`'remember_days'`, 0 turns it off): only a keyed hash of the browser's token is stored; a password change, a reset or turning it off forgets every device; the Security tab lists them with **Forget**.
- **Roles can require it**: **Require two-factor** on a role's page (roles up to your own level), or `'required_roles'`; people with such a role set it up at their next sign-in before anything else and can't turn it off. The role page counts who still has to.
- **Reset two-factor** on Users (**More**, `users.manage`, the same rules as Suspend) with an optional notice email, and `php flo admin:two-factor-reset <email>` for the server.
- **Email verification**: **Send an invitation** on the new-account form keeps the account Pending until its link is opened and a password chosen; a banner asks people with an unconfirmed address to confirm it; a new address on the profile applies only after its link is opened (the old address gets a notice), with **Send again** and **Cancel change**; **More → Send verification email** on Users; an admin's change of an address marks it unconfirmed; a password reset confirms the address. With `'required' => true`, unconfirmed people wait at **Check your email** after signing in.
- Safe defaults: turning two-factor on or off emails the owner; five wrong passwords or codes on the Security tab pause it for 15 minutes; wrong codes are counted in one statement, so parallel guesses all count; when two-factor is on but not working (lost `APP_KEY`, unreadable tables) people who use it can't sign in rather than skipping the code; email links confirm only with a **Confirm** button (mail scanners open links), stop working if the account was suspended or its address changed meanwhile, and a confirmed new address ends the links sent to the old one; backup codes are shown once and not kept in the session.
- Secrets are encrypted with `APP_KEY` (AES-256-GCM, at least 32 characters); backup codes, device tokens and link tokens are stored as hashes. The TOTP codes (RFC 6238) and the QR code (SVG) are written in the package, without a third-party package.
- New tables `admin_two_factor`, `admin_two_factor_roles`, `admin_trusted_devices` and `admin_email_tokens` (one migration, `login_security`). The `users` table is not changed.
- `admin:doctor` checks core 2.7, `APP_KEY`, the user loader, the tables, mail and who still has to set up two-factor sign-in for a role that requires it.
- Activity log entries for turning two-factor on and off, resets, new backup codes, a backup code used, paused codes, the role rule, verification links, invitations and email changes.
- New words in `en`, `ar` and `ku`: `two_factor.*`, `security.*`, `verify.*`, `mail.verify_*`, `mail.invite_*`, `mail.change_*`, `mail.change_notice_*`, `mail.two_factor_locked_*`, `mail.two_factor_reset_*`, `mail.two_factor_on_*`, `mail.two_factor_off_*`, `users.invite*`, `users.send_verification`, `users.verify_*`, `users.reason_verified`, `users.reset_two_factor*`, `users.two_factor_reset_one`, `users.reason_two_factor_off`, `roles.signin`, `roles.two_factor_*`, and `activity.badge.*` / `activity.what.*` for the new entries.

## 0.34.0 - 2026-10-22

**Six new blocks, and better Video, Hero and Gallery blocks.** Nothing to migrate: stored blocks don't change. See [Builder: the blocks of 0.34](docs/builder.md#the-blocks-of-034).

- **Contact and map** (`contact`, Your site): address, phone (`tel:`), email (`mailto:`), WhatsApp (`wa.me`), Get directions, opening hours, and a map (Google Maps by address, OpenStreetMap by coordinates) that loads only when the visitor clicks **Show map** (`contact.js`), unless **Load the map at once**.
- **FAQ** (`faq`, Text): questions on the browser's own `<details>`, the first open, one open at a time (`name`), a link opens a question (`faq.js`), FAQPage data for search engines. Answers are plain text with paragraphs and automatic links.
- **Testimonials** (`testimonials`, Marketing): a grid, a carousel (the slider's script) or one large quote; ratings read out as "Rated 5 out of 5"; initials without a photo.
- **Stats** (`stats`, Marketing): numbers with a prefix, suffix and label; counting up once in view (`stats.js`), in the page's digits; still for "reduce motion"; the number always in the HTML.
- **Team** (`team`, Marketing): photo, name, role, short bio, email, phone and three links as labelled icon buttons.
- **Logos** (`logos`, Marketing): a grid or a row, logo height, grey until pointed at.
- **Video**: a shape for YouTube and Vimeo (16:9, 4:3, 1:1, 9:16, 21:9), and **click-to-load**: a YouTube or Vimeo video without autoplay shows its cover (or a dark frame) and loads only when clicked (`video.js`), on existing pages too. With autoplay it loads at once, as before.
- **Hero**: a 3 × 3 **Text position** replaces Start / Centre; a hero saved with Start or Centre shows bottom-start or bottom-center, as before. On phones the text is aligned to the start.
- **Gallery**: **Show captions**, each image's title from the media library, also in the larger view.
- **Toasts**: warnings and errors close after 12 seconds (`Notification::LONG_DURATION`), with a line showing the time left; hovering or focusing one pauses it; `->persistent()` keeps one. Errors beside a form's fields always stay.
- New blocks print their columns as classes (`is-cols-3`), so a strict Content-Security-Policy keeps them; each has `sample()` content for the Blocks preview.
- For developers: `Hero::position()`, `Faq::answerHtml()`, `Contact::coordinates()`, `Stats::number()`, `Team::network()`, `Testimonials::initials()`; site scripts `contact.js`, `faq.js`, `stats.js`, `video.js`; icon `help-circle`.
- New words in `en`, `ar` and `ku`: `blocks.contact*`, `blocks.faq*`, `blocks.testimonials*`, `blocks.stats*`, `blocks.team*`, `blocks.logos*`, `blocks.layout_*`, `blocks.shape_*`, `blocks.person_*`, `blocks.photo`, `blocks.intro`, `blocks.per_row`, `blocks.video_aspect(_help)`, `blocks.video_play`, `blocks.video_plays_from`, `blocks.gallery_captions(_help)` and `blocks.site_*` for the website.

## 0.33.0 - 2026-10-22

**A block's Display tab for frontend developers: styles, theme colours, phone spacing, hooks, scoped custom CSS and a preview of every block.** Nothing to migrate: stored blocks don't change. See [Builder: styling the blocks](docs/builder.md#styling-the-blocks).

- **Display tab in three groups**: **Look** (style, background, text colour), **Layout** (show on, width, spacing) and **Advanced** (anchor, CSS class, custom CSS); a group with nothing in it is left out.
- **Spacing**: "Choose…" is gone; the first option names the block's default, **Default (Medium)** (or None for full-width blocks), and keeps following it. New size **Extra large**. **Space above / below on phones** (Same as on desktop unless set): `flo-space-above-phone-<size>` below 768 px. The sizes can be set with theme variables, `--flo-space-sm` … `--flo-space-xl`, by default today's fractions of `--flo-block-gap`, so pages look the same.
- **Block styles**: developers list named styles per block type (`'block_styles'` in `config/admin.php`, `BlockStyles::add()`, or `Block::styles()`); editors pick one (**Style**: Default, then the styles); the wrapper gets `is-style-<name>`. A style removed later is ignored by the website, pointed out in the dialog and dropped when the page is saved.
- **Theme colours**: every block has a **Background** swatch from the theme's palette and a **Text colour** (Automatic, Light, Dark); the wrapper gets `has-bg has-bg-<name> has-text-light|dark` and space inside (`--flo-bg-padding`). The palette is `'block_colours'` in `config/admin.php`, or the four colours of `blocks.css` without it. A Section's Background offers the same colours.
- **Hooks**: every block wrapper has `data-block="<type>"` and `data-block-id="<id>"`.
- **Custom CSS** for people with the new **`blocks.custom_css`** permission: `&` is the block, rules without it apply inside it, `@media` works. Checked on save (with the line), scoped to `.flo-block[data-block-id="…"]`; `&` only at the start of a selector (never followed by `+` or `~`); `url()` and other functions that load files, `@import` and other at-rules, backslashes, `<`, control characters, strings that don't close on their line and brackets that don't close in order are refused. Everyone else's saves keep a block's CSS unchanged. On the website one cached stylesheet per page, `/flo-admin/asset/block-styles-<hash>.css`, linked by `blocks_assets()` (a `<style>` tag, with `'block_style_nonce'`, when it can't be written).
- **Pages › Blocks preview** (`/admin/blocks-preview`, permission **`settings.blocks_preview`**, which the Admin role has through `settings.*`): every kind of block in each style and colour, with the website's layout and stylesheets, at desktop, tablet and phone widths and in each language, each labelled with its selector and **Copy selector**. Core blocks show samples (with the newest images of the media library); a site block shows `Block::sample()`.
- For developers: `Select::swatches()` and `Select::whenMissing()`, `CodeEditor::language('css')` (highlighting, no Format), `Section::group()` for a light group inside a tab, `Block::styles()` and `Block::sample()`; `blocks_data()` gives `style`, `bg` and `text` in a block's settings; icons `alert-triangle`, `monitor`, `tablet`, `smartphone`. A checkbox with `data-admin-autosubmit` submits its form, as a select does.
- `admin:doctor` names unusable `block_styles` and `block_colours` entries, and a copied `public/assets/flo-blocks/blocks.css` from before 0.33.
- New words in `en`, `ar` and `ku`: `blocks.group_look`, `blocks.group_layout`, `blocks.style`, `blocks.style_default`, `blocks.style_help`, `blocks.style_gone`, `blocks.bg_help`, `blocks.bg_muted`, `blocks.text_help`, `blocks.space_xlarge`, `blocks.space_default`, `blocks.space_above_phone`, `blocks.space_below_phone`, `blocks.custom_css`, `blocks.custom_css_help`, `blocks.css_*` (with `css_string`, `css_brackets`, `css_ampersand`), `blocks.preview_*`, `roles.perm.blocks_custom_css(_help)`, `roles.perm.blocks_preview(_help)`.

## 0.32.2 - 2026-10-21

- **Builder**: a Columns block had no space below its Add block buttons in the editor; it now keeps the same space from the card's bottom edge as a Section.

## 0.32.1 - 2026-10-21

- **Builder**: a Rich text block added to a page had a plain text box instead of the editor until the page was saved; it has the editor at once.
- **Builder**: **Show on**, **Width**, **Space above / below**, **Anchor** and **CSS class** moved from the block panel to a **Display** tab in the block's dialog (a block with no fields of its own keeps them in the panel). The panel keeps the name, note, status and dates.
- **Builder**: the block dialog is at least 560 px tall (less on a short window), so a block whose lists are still empty (Cards, Slider) doesn't open small and then grow.
- **Pages**: switching a page between Blocks, HTML and Rich text no longer loses what was typed in the others (on a new page it lost everything); the switch happens in place, without reloading the form. All three are saved with the page.
- Docs: "Styling the blocks" in the Builder guide.
- New words in `en`, `ar` and `ku`: `blocks.tab_display`.

## 0.32.0 - 2026-10-21

**The Builder: saved as one value, a real canvas, drag and drop that goes anywhere, and better blocks.** Nothing to install or migrate: stored blocks don't change. See [Builder: blocks and sliders](docs/builder.md).

- **Saved as one value**: the editor sends all of a Builder's fields as one JSON value, so `max_input_vars` no longer limits a page, however many blocks it has. The server reads the same names and values as before (`Builder::unpack()`) and checks every block the same way; a value it can't read is refused and nothing is saved. Without JavaScript the fields are sent one by one, as before. `admin:doctor` no longer warns about `max_input_vars` for this.
- **Columns side by side**: a Columns block shows its columns next to each other, as wide as its layout (67/33, thirds, quarters…), each with its name and width; they stack when the structure is narrower than 480 px.
- **Framed containers that fold**: Section and Columns are cards with their blocks inside; a chevron folds them ("Services · 4 inside"), remembered per page in the browser; **Collapse all** folds them all.
- **Drag and drop, rebuilt**: the block stays in place while a line shows where it will land; every Section and column is a target as a whole, so blocks go into, out of and between columns. A place it may not go shows a red line; Escape cancels; the page scrolls near the edges; a folded container opens when a block is held over it; on touch screens press and hold a row.
- **⋯ on every row**: the row's actions again, **Move to** (every place the block may go) and Delete. A **+** in the gap between blocks adds one there.
- **The edit dialog** edits one block (Previous / Next are gone) and keeps one height while it is open: its tallest tab, 360–760 px, the rest scrolling inside.
- **Blocks, fixed**: options that matter for one choice only appear when it is chosen (a slider's pixel heights and interval, a Section's colour and image, Columns' "last column first"); empty Slider, Section and Columns blocks print nothing; a new Slider opens on its Slides tab and an empty required list says "Nothing added yet"; a button's text and link need each other (Hero, slides); Image's Wide and Full sizes have styles and an optional **Alt text**; a Video needs a file or a YouTube / Vimeo address, and Vimeo autoplay works; background and autoplaying videos stay still for "reduce motion"; help texts corrected; Hero moves to Marketing; Gallery has its own icon.
- **Blocks, new options** (defaults keep pages as they were): Hero heading level (H1 / H2) and height on phones; Heading "End" alignment and a subtitle; Columns 75/25, 40/60, 60/40, and quarters two by two on tablets; **Open in a new tab** on every link; Cards open as a whole, with an optional button text; Section text colour and image overlay.
- Rich text: after a failed save, the invalid editor keeps the focus on fast and slow machines (focus was sometimes restored to the Save button a moment later); for two seconds, focus that moves without a click or a key press comes back to it.
- For developers: `Field::shownWhen($field, $values)` (shown in the browser without a reload, always sent); `Block::check(array $fields)` for checks across fields and `Block::buttonPair()`; the site script `motion.js`; icon `gallery`.
- New words in the panel's `en`, `ar` and `ku` files: `blocks.fold`, `blocks.unfold`, `blocks.inside_count`, `blocks.moved_column`, `blocks.not_here`, `blocks.more`, `blocks.move_to`, `blocks.move_top`, `blocks.needs_link`, `blocks.needs_button`, `blocks.new_tab`, `blocks.overlay_help`, `blocks.hero_level_help`, `blocks.height_on_phones`, `blocks.same_as_desktop`, `blocks.size_small`, `blocks.alt_text*`, `blocks.video_url_unknown`, `blocks.video_missing`, `blocks.align_end`, `blocks.subtitle*`, `blocks.card_link_help`, `blocks.card_button_help`, `blocks.tone_auto`, `blocks.summary_empty`, `validation.builder_unreadable`; changed: `blocks.slider_overlay`, `blocks.slider_name_help`, `blocks.section_name_help`, `blocks.hero_help`, `blocks.cards_help`; removed: `blocks.previous_block`, `blocks.next_block`, `blocks.bg_colour_help`, `blocks.bg_image_help`.

## 0.31.0 - 2026-10-21

**The Builder editor, reworked.** Nothing to install or migrate: every Builder gets the new editor, and stored blocks don't change. See [Builder: blocks and sliders](docs/builder.md).

- **Row buttons**: point at a row (or reach it with the keyboard) for icon buttons with tooltips: **Edit**, **Duplicate**, **Copy to language**, **Show / hide on the website**, **Delete** and **+** (add after). Rows also show a short summary ("3 items"), "Desktop only" / "Phones only", a faded look when hidden and a dot when changed since the page was opened.
- **Edit in a dialog**: a block's own fields open in a large dialog with its tabs, **Previous / Next block** and **Done**. A new block opens there straight away; a block with an error opens with it after saving.
- **The block panel** beside the structure holds what every block shares: **Edit content**, the actions, a **Name** and a **Note** for editors (never printed on the website), Status, Show from / until, Show on, and **Advanced** (width, spacing, anchor, CSS class). Section, Columns and Slider show their own Name there.
- **Add block is a dialog**: search, categories, **Recently used**, where the block will go ("After “Hero”"), a close button, Escape and a click outside.
- **Delete asks first**, naming the blocks inside a Section or Columns; Undo still brings it back until you save.
- **Copy and paste blocks** between pages: Ctrl+C on a row, Ctrl+V on a row, or **Paste** in Add block. The copy stays in the browser.
- **Keyboard**: Enter edits, Delete deletes, Ctrl+D duplicates, Ctrl+C / Ctrl+V copy and paste; Alt + arrows move blocks as before.
- **Phones**: a tap on a row opens its panel with every action; the dialogs fill the screen.
- A list inside a block (a slider's slides, cards) is edited in the dialog as a normal list; the one-item-at-a-time view of the narrow side panel is gone.
- For developers: the shared settings `_label` and `_note` (stored under `settings`, left out of templates' `$block['settings']` and `blocks_data()`), `Block::nameField()`, `Builder::contentForm()`, `Builder::settingsForm()`, `Builder::hasContent()`; new icons `eye-off` and `clipboard`.
- New words in the panel's `en`, `ar` and `ku` files: `blocks.label`, `blocks.note`, `blocks.advanced`, `blocks.edit_*`, `blocks.view_*`, `blocks.show_block`, `blocks.hide_block`, `blocks.delete*`, `blocks.add_where_*`, `blocks.paste`, `blocks.copied_block` and others.

## 0.30.1 - 2026-10-21

- **More on the view page opened outside the window.** With the sidebar shown, its menu appeared about the sidebar's width to the right of the button, partly off-screen, and gave the page a horizontal scrollbar. Menus now open under their button even inside the page's blurred, sticky header.

## 0.30.0 - 2026-10-21

**Pages.** The website's pages in the panel, at **Site → Pages** (`/admin/site-pages`). Needs **flocms-core 2.6**. Turn on with `'pages' => true` in `config/admin.php`, then `php flo admin:install` and `php flo migrate`. See [Pages](docs/pages.md).

- **A page** is built from **blocks** (the Builder), written as **HTML** or typed as **rich text**, and is shown at its address without a controller: `/en/about-us`, `/en/services/web-design` under a parent page, `/ar/من-نحن` in another language. Addresses are made from the title, are checked against the panel's, the framework's and the site's controllers, and are unique under each parent.
- **The editor** works one language at a time (the "Editing" switch). Tabs **Content**, **SEO** (title and description for search engines, image when shared, Hide from search engines, live previews of the search result and the social card) and **Settings** (template, parent page, Show in menu, order, CSS class). A **Publish** card with Draft, Published or Scheduled, Publish at and Unpublish at, **Preview** (the page as edited, saved or not, in a new tab; a link that works for 30 minutes without an account) and View on site; a **Page** card with the address and Copy, the dates and History.
- **The list**: All / Published / Drafts / Scheduled tabs, the address under each title, the translations, **Reorder**, **Duplicate** (a draft copy) and **Set as home page**.
- **The home page**: one line at the start of the site's home controller shows it: `if ($page = home_page()) { return $page->response(); }` (skeleton 1.8.8 has it).
- **On the website**: `{!! page_meta() !!}` in the layout's `<head>` prints the title, description, canonical address, `hreflang` with `x-default`, Open Graph and robots; `pages_menu()` gives the menu with nesting and the current page; `current_page()`; **`/sitemap.xml`** with the other languages as alternates. A page not translated shows the default language's, with the canonical pointing there, or a 404 with `'missing' => '404'`.
- **Templates**: `views/page-templates/<name>.html`, offered by their names; `php flo admin:blocks` copies the default one there.
- **Permissions**: `content.site-pages.*`, **`pages.publish`** (publish, schedule, Set as home page, and change or delete live pages; without it people write drafts and live pages are read-only) and `blocks.html` for HTML pages.
- **`CodeEditor`** field: line numbers, highlighting, Tab and Shift+Tab, Escape then Tab to leave, **Format**. Used by HTML pages; usable in any form.
- **Block styles and scripts** from the package (before `php flo admin:blocks` copies them) are now linked as files at `/flo-admin/asset/…` instead of printed inline, so they work with a strict Content-Security-Policy and are cached.
- `admin:doctor` checks pages: flocms-core 2.6, the table, addresses that something else answers, more than one home page, the home page line, `page_meta()` in the layout, templates.
- For developers: `App::fallbackUsing()` from core 2.6 is used by `PageFallback`; `Pages`, `Page`, `PageRenderer`, `Templates`, `Sitemap`; `FormSubmission::values()`; `BlockRenderer::evaluate()`; `Assets::blockUrl()`.
- New words in the panel's `en`, `ar` and `ku` files: `pages.*`, `code.format`, `roles.perm.pages_publish*`.

## 0.29.0 - 2026-10-21

**Builder: blocks and sliders.** Nothing to install: add a `Builder` field and a JSON (or LONGTEXT) column. See [Builder: blocks and sliders](docs/builder.md).

- **`Builder::make('content')`** keeps a list of blocks of different kinds in one JSON column. Twelve kinds come with the panel: **Slider**, **Hero**, **Image**, **Gallery**, **Video** (a library file, or YouTube or Vimeo), **Heading**, **Rich text**, **Cards**, **Call to action**, **HTML**, and the layout blocks **Section** (a band with a background and a content width: full, boxed or narrow) and **Columns** (two to four, the layout chosen from pictures). `->blocks([...])` and `->only([...categories])` choose which, `->maxBlocks()` limits them, `->translatable()` keeps one list per language.
- **The editor**: the page's structure on the left (nested rows, drag and drop, Alt + arrow keys, Collapse all), the selected block's fields on the right (its own tabs, then Settings; a slider's slides one at a time). A **block picker** with search and categories; **+** on a row adds after it; **Add to column 2** adds into a column. Duplicate, Remove with Undo, **Copy to another language** and **Copy all from English**. A Section sits at the top only, Columns at the top or in a Section. Fewer columns move their blocks into the last one. On phones a block opens over the structure. Works without JavaScript.
- **Shared settings** for every block: Status (Published, Draft), Show from / until (shown on the row: "Draft", "From 1 Nov", "Ended"), width (boxed or full), space above and below, show on all screens, desktop or phones, an anchor and a CSS class.
- **The slider**: full screen, a fixed height (and one for phones) or the first image's shape; fade or slide; autoplay that pauses on hover, focus and in a hidden tab, with a pause button and none with "reduce motion"; arrows, dots, swipe and arrow keys; per slide an image or video, heading, text, button, a 3 × 3 text position, light or dark text and **Show this slide**. The first slide loads at once, the others lazily. The script is about 4 KB and only on pages with a slider. **`Slider::make('photos')`** is the same slides as a field of its own, printed with `slider()`.
- **On the website**: `{!! blocks($page->content) !!}` prints the blocks shown now with **`views/blocks/<type>.html`**, or the package's default templates; `{!! blocks_assets() !!}` in the `<head>` adds the base styles and only the scripts the page needs; `blocks_data()` gives the same as arrays for sites that render blocks themselves. The printed HTML is cached until the content or a template changes or a block's dates start or end. The default styles use CSS variables (`--flo-container`, `--flo-brand`, `--flo-block-gap`…).
- **`php flo admin:blocks`** copies the templates to `views/blocks/` and the styles and scripts to `public/assets/flo-blocks/`, never replacing a file (`--force=slider.html` replaces one). **`php flo make:admin-block LatestProjects`** writes a block class and its template and registers it under `'blocks'` in `config/admin.php`; every Builder then offers it.
- **HTML blocks** need the new **`blocks.html`** permission (Roles page, System). Without it an HTML block is read-only and keeps its code.
- Errors say where they are ("Page content → Hero: Heading") and open the block. The activity log names blocks ("Page content · Added: Call to action", "… · Hero · Heading: Welcome → Hello", "Order changed"). The view page shows the structure. Media in blocks count as used in the library. Export writes the JSON as it is; import checks each block as if typed in the editor (kinds that no longer exist, and HTML without `blocks.html`, are left out). Settings pages can use a Builder too. A form with a Builder opens as a page (in relation managers it stays a dialog). A request cut short by PHP's `max_input_vars` is refused instead of saved half (see the guide). Links in blocks are printed only when they are http(s), mailto, tel or on the site.
- **Fixed**: `setting()` returned an empty string for a repeater or key-value field on a settings page; it now returns the list as stored, and a Builder's blocks the same way, for `blocks(setting('home.blocks'))`.
- `admin:doctor` checks the site's blocks and warns about Builder columns that are too small (TEXT on MySQL holds 64 kB).
- For developers: `Block` (`fields()`, `data()`, `title()`, `render()`, `assets()`, `allowedIn()`, `defaultWidth()`, `cacheable()`), `BlockRegistry`, `BlockRenderer::rendering()`, `Select::segmented()`, `->pictures([...])` and `->positions()` (a select shown as buttons, pictures or a 3 × 3 grid), and new icons (`slides`, `hero`, `heading`, `text`, `section`, `cards`, `arrow-right`, `square`).
- New words in the panel's `en`, `ar` and `ku` files: `blocks.*`, `select.top_start` … `select.bottom_end`, `validation.builder_*`, `roles.perm.blocks_html*`.

## 0.28.2 - 2026-10-20

- **Row menus no longer slide when they open.** On a short page with a desktop scrollbar (Windows, Linux), the open menu briefly brought up the page's scrollbar, opened about 15 px too far to the side and then jumped into place. It now stays hidden for that first frame and appears where it belongs.

## 0.28.1 - 2026-10-20

**Users, polished after review.** Run `php flo admin:install`, then `php flo migrate` for the new optional `bio` column. See [Users: photos, actions and emails](docs/users.md).

- **The user page** is laid out in sections: **Profile** (name, email, login, phone, **bio**) and **Password** in the main column, and **Photo**, **Access** (role, status) and **Quick actions** (Send reset link, Send email, Activate, Suspend) in the sidebar. A quick action comes back to the user. The Profile page has the same layout.
- **Bio**: `admin:install` writes a migration that adds an optional `bio` text column to your site's `users` table (only if it isn't there); the field shows once the column exists.
- **To field** in the composer works like an email program: people as chips, found by typing a name or email (arrow keys, Enter, Backspace), or one group from **Group** (everyone, a role, a status). The count, the Send button and the page title follow at once. Works without JavaScript.
- **Greyed-out menu items** show only their label; the reason is a tooltip on hover, keyboard focus or a tap, and screen readers read it.
- **Row menus** keep 12 px from the window's edge (they could touch it). The status tabs no longer show a small scrollbar.
- For developers: `Placeholder::html()`; a section with nothing to show is left out; a section holding one field of the same name doesn't repeat its label; an action page returns to `?back=` (the list, or the record's edit or view page); `ActionRenderer::links()`.
- New words in the panel's `en`, `ar` and `ku` files: `users.section_*`, `users.bio`, `users.bio_help`, `actions.quick`, `mailings.remove`, `mailings.to_placeholder`, `mailings.group`, `mailings.no_match`.

## 0.28.0 - 2026-10-20

**Users: photos, actions and emails.** Run `php flo admin:install`, then `php flo migrate` for the mailing tables. See [Users: photos, actions and emails](docs/users.md).

- **Photos**: each user can have one, set on Users and on the Profile, cropped to 256 × 256 and stored in `public/uploads/admin` (`public_uploads`). Without one, the first letter of the name on a colour of its own. Shown in the Users list, the top bar, Activity, History, Changes and the Trash.
- **A people list**: the photo, name and email in one cell (the email has a tick when verified; search also finds email, login and phone), Role and a Status dot. The ID column is gone. **All / Active / Pending / Suspended** tabs with counts, also without JavaScript.
- **Row actions** in the ⋯ menu: **Send reset link** (optionally signing them out everywhere), **Send email**, **Activate**, **Suspend** (with a reason for the activity log) and Delete. Activate and Suspend can tell the user by email. An action that doesn't apply is greyed out with its reason ("Already active", "You can't do this to your own account") and refused by the server.
- **Bulk actions**: Activate, Suspend, Send reset link, Send email and Delete. Users an action doesn't apply to are skipped and counted ("2 are skipped: Already active (1), …").
- **Emails**: a composer with a preview as one of the recipients, **Send me a test** and `{name}`, `{first_name}`, `{email}`, `{site}`. A **direct email** goes at once to up to 20 chosen users (`users.email`); a **mailing** goes to everyone, a role, a status or chosen users (`users.mailing`, and `APP_KEY` in `.env`) in batches, with an unsubscribe link, Pause, Resume, Cancel and Retry failed, and a page showing its progress. **Mailings** lists them. `php flo admin:mailings` in cron sends them with no page open, at most `per_hour` (200) an hour.
- Two new permissions on the Roles page: `users.email` and `users.mailing`.
- Activity log: `user_suspended`, `user_activated`, `password_link_sent`, `email_sent`, `mailing_started`, `mailing_paused`, `mailing_cancelled`, `mailing_finished`.
- For every resource: `Action::disabled()`, `Action::url()`, `->group()`, `->modalHeading()`, `->modalDescription()`; `BulkAction::skipUnavailable()`, `->bulkUrl()`, `->completedTitle()`; `TabsFilter` with `Table::tabs()`; `TextColumn::avatar()`, `->description()`, `->descriptionMark()`; `Column::searchAlso()`; `BadgeColumn::dot()`; `ImageUpload::avatar()`, `->square()`; `FileUpload::public()`; `TextInput::generate()`; `Message` takes `Reply-To`, `List-Unsubscribe` and `Precedence` headers.
- New words in the panel's `en`, `ar` and `ku` files: `users.*`, `mailings.*`, `mail.activated_*`, `mail.suspended_*`, `bulk.skip*`, `roles.perm.users_email*`, `roles.perm.users_mailing*`, `activity.*`.

## 0.27.0 - 2026-10-20

**The view page.** Every record can be opened without editing it, at `/admin/<slug>/view/<id>`. Nothing to install. See [The view page](docs/view-page.md).

- Built from the resource's form: the same sections, tabs and sidebar, each field shown as its value. Badges for options (the list's colours), Yes/No for toggles, email, phone and web addresses as links with a copy button, dates in the panel's language, colour swatches, related records as links, media thumbnails, files with their size and **Download**, repeaters as cards or a table, key-value lists as a table, rich text as it reads on the site. Passwords and `Hidden` fields are never shown.
- The header shows who changed the record last and when, and **History**, **Edit** and **More** (the record's actions, **Print**, **Delete**), each following the person's permissions.
- **Showing: EN · AR · KU** for translatable fields, starting in the panel's language. A field without text says **Not translated** (no other language stands in), and a dot marks languages with something missing. `?showing=ar` opens one directly.
- **Print** (and Ctrl+P) prints the values only, with "Printed <date> by <name>" at the top.
- **Related records** below, as on the edit page; someone who may view the parent but not edit it sees each manager as a plain table without buttons.
- **The row title is now a link** in every list: to Edit for people who may edit the record, else to the view page. Rows get **View** in their menu. `$rowOpens = 'view'` opens the view page first, for editors too. Search, recent records, the Activity page, related fields and relation managers link the same way. "May edit" is the edit page's own check: the list's Edit action (its permission, `visible()`, `authorize()`) and `canEdit()`; a list without an Edit action opens the view page.
- People with `content.<slug>.view` but not `update` can now open records, download their files, and (with `activity.view`) see their History.
- On phones the buttons sit in a bar at the bottom, as on the edit page, and More opens upwards. Works without JavaScript (Print and Copy need it).
- For resources: `$viewPage` (false turns the page off), `$rowOpens`, `canView($record)`, `view(Form $form)`, `recordUrl($record)`, `viewUrl($record)`. For fields: `->hiddenOnView()` and `->formatOnView(fn ($value, ?object $record): string)`.
- New words in the panel's `en`, `ar` and `ku` files: `view.*`.

## 0.26.0 - 2026-10-19

**Forgot password.** Needs flocms-core 2.5. New installations have it on; an existing site adds `'password_reset' => true` to `config/admin.php`, then runs `php flo admin:install` and `php flo migrate`. See [Forgot password](docs/password-reset.md).

- **Forgot password?** on the panel's login page (with `login_theme`) opens `/admin/password/forgot`. The answer is the same for every address ("If an account exists for … we've sent it a link"), and the email goes out after the page has been answered, so the form doesn't tell who has an account.
- A **one-time link** to `/admin/password/reset/<token>`, valid 60 minutes (`expires`); a newer link replaces older ones, and only the token's hash is stored. The page asks for the new password twice (at least 8 characters, with show/hide) and sends the person to the login page with "Your password has been changed".
- **Limits**: 3 requests per address and 10 per IP address an hour (`per_email`, `per_ip`; IPv6 per /64), and wrong links per IP address. The session is saved and the answer sent before the email goes out, so a known address doesn't answer more slowly than an unknown one. Addresses and IP addresses are stored only as keyed hashes and deleted after a day. Only active accounts get an email.
- **Other sessions end** when a password changes: by a reset link, on the profile page or by an admin on Users. The person who changed their own password stays signed in. Uses `Auth::checkSessionsUsing()` (core 2.5).
- **Email** without a third-party package: PHP `mail()` (default), SMTP with STARTTLS or TLS and AUTH PLAIN/LOGIN (never without TLS except to `localhost`), or `log` for a computer without mail, set with `MAIL_*` in `.env`. Two emails, in the page's language and the panel's colours, with a plain-text part: the link, and a "your password was changed" note. `'mail' => ['send' => callable]` in `config/admin.php` replaces sending (PHPMailer, an API).
- The pages are whole documents like the login page, in every panel language and right-to-left, fit phones, work without JavaScript, and are sent with `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` and `X-Frame-Options: DENY`.
- Activity log: `password_reset_requested`, `password_reset_limited` and `password_reset`, credited to the account.
- `php flo admin:mail-test you@example.com`; `admin:install` writes the migration of `admin_password_resets`; `admin:doctor` checks the table, core 2.5, the email settings, `APP_URL`, and warns about `MAIL_TRANSPORT=log` in production.
- The slug `password` is reserved for the panel's pages.
- More reliable focus: after a failed save the invalid rich text editor takes focus again once the update has settled (the browser test `editor.spec.ts` "survives validation errors" failed now and then on CI).
- New words in the panel's `en`, `ar` and `ku` files: `login.forgot`, `password.*`, `mail.*`, `activity.badge.password_*`, `activity.what.password_*`.
- For developers: `FloCMS\Admin\Mail\{Mailer, Message, MailException}`; `Actor::as()` credits work to someone who is not signed in.

## 0.25.1 - 2026-10-19

Rich text can be imported.

- `RichEditor` fields now have import columns (`body.en`, `body.ar`), so an exported file imports back with its descriptions, images and videos included. HTML is cleaned with the editor's own allow-list when the row is saved, as if it were typed. A cell without HTML (no tag that opens and closes, no `<br>` or `<img>`) is text and becomes paragraphs: a blank line starts a new one, a line break stays a line break, and `Use <b> for bold` stays text. Imported images count as uses in the media library; an empty paragraph counts as emptying the field in the Preview's warning.
- Files whose cells hold HTML or JSON were refused at upload as "not a CSV file" (the server's type check saw `text/html` or `application/json`); they are accepted now.
- The upload page's tip says that rich text is imported and image and file fields are not.
- Changed words in the panel's `en`, `ar` and `ku` files: `import.tip_media`.

## 0.25.0 - 2026-10-19

**CSV import and export.** Run `php flo admin:install` and `php flo migrate` for the import tables (export works without them). See [CSV import and export](docs/import-export.md).

- **Export** menu in every list's toolbar: the records the list shows (search, filters and sort, all pages), with **Readable headings** (labels, option names, Yes/No) or field names that import back as they are, and an **Empty template for import**. **Export selected** in the bulk bar. UTF-8 with a BOM and CRLF so Excel opens Arabic and Kurdish correctly; one column per language (`title.en`, `title.ar`); `BelongsTo` as the id plus `city_id.name`, `BelongsToMany` as `1|4` plus `features.names`; media as URLs; passwords never. Cells that start with `=`, `+`, `-`, `@` or a tab get an apostrophe so spreadsheets never run them as formulas (plain negative numbers stay numbers); the import takes it off. Streamed 500 rows at a time, at most 50,000 rows (`'export' => ['limit' => …]`). Noted in the activity log.
- **Import** button beside New: four steps.
  1. **Upload**: drag and drop, sent in parts with a progress bar; comma, semicolon, tab or `|`; CRLF, LF or CR line ends; UTF-8, UTF-16 and Windows-1256 (Windows-1252, also when non-UTF-8 bytes only appear late in the file), with **Letters look wrong?** to read it again. Up to 10 MB and 10,000 rows by default.
  2. **Match columns** by field name (`title.ar`, `title[ar]`), label in any panel language or `->importAliases()`, with Matched/Chosen/Skipped badges; **Create new ones only**, **Create new and update existing** or **Update existing only**, found by id or `Import::key()`; day-first or month-first dates; a check that every required field has a column.
  3. **Preview**: every row checked with the form's own rules, nothing saved; ready, new, updated and error counts, the errors by row, field, value and problem, **Download rows to fix** (the lines plus an Errors column), and a warning when empty cells would clear values of existing records.
  4. **Import** in batches of 100 (one transaction each) with a progress bar; leave and **Continue** later, or **Cancel**. The result counts created, updated and skipped rows, with **Download skipped rows**.
- **Undo import** for 7 days (`undo_days`): created records go to the trash as one batch (deleted when the trash is off), updated ones get their earlier values and many-to-many links back; refused, with the records named, when one was edited since. Undoing created records needs `delete` and `canDelete()`; a record that can't go back is kept and counted.
- Values the import reads: numbers with thousands separators, decimal commas in semicolon files and currency signs; yes/no in English, Arabic and Kurdish; ISO, `d/m/Y`, `m/d/Y`, `d.m.Y` dates and Excel day numbers; options by key or label; relations by key (ids, slugs) or title in any language; `KeyValue` as `Parking: 2 | Pool: yes`. Uploads, media fields, rich text, passwords, hidden and disabled fields are not imported.
- **Site → Imports** (`/admin/imports`): the imports of the last 30 days of the resources you may import into, with status, counts, Continue and Undo. Also found by Ctrl+K.
- Per resource: `$exportable`, `$importable`, `export(Export $export)` (`->except()`, `->column()`, `->limit()`) and `import(Import $import)` (`->key()`, `->defaults()`, `->beforeRow()`, `->afterImport()`). Per field: `->hiddenFromExport()`, `->notImportable()`, `->importAliases([...])`.
- Permissions `content.<slug>.export` and `content.<slug>.import` (import also needs create, and update to change existing records); the Roles grid gets Export and Import columns, and ticking them ticks View.
- Commands: `admin:export`, `admin:import` (with `--dry-run`), `admin:imports`, `admin:import-undo` and `admin:import-purge`. Uploaded files are kept in `storage/admin-imports/` and removed after `keep_hours`; panel pages purge at most once an hour.
- `admin:install` writes the migration of `admin_imports` and `admin_import_rows`; `admin:doctor` checks them, the folder, and that each resource's import key is unique.
- Activity log entries `exported`, `imported` and `import_undone`; the rows an import saves are not logged one by one (`Activity::quietly()`).
- New words in the panel's `en`, `ar` and `ku` files (`export.*`, `import.*`, `imports.*`, `nav.imports`, `roles.ability.export`, `roles.ability.import`, `activity.*`).

## 0.24.1 - 2026-10-19

Polish for row lists (`->table()` and `KeyValue`) and the tables' row menus.

- Opening a row's **More** menu no longer flashes the table's scrollbar: the menu is opened and pinned next to its button in the same frame, instead of being drawn inside the table once first.
- The first row sits square under the column heads, without rounded top corners.
- A list without rows shows only its dashed Add button, like card lists, instead of column heads with the button stuck under them; the heads come back with the first row. Undo sits above Add while the list is empty.
- On phones (no column heads) the rows keep their rounded top and join the Add row below without a gap in the corners.

## 0.24.0 - 2026-10-18

**Repeater and key-value fields**: a short list inside a record (a flat's rooms, a tour's days, opening hours, a property's specs), stored as JSON in one column. No migration; add a `TEXT` or `JSON` column for each list you declare. See [Repeaters and key-value lists](docs/repeaters.md).

- `Repeater::make('rooms')->schema([...])` with `->itemLabel()`, `->addLabel()`, `->minItems()`, `->maxItems()` (default 50, at most 200), `->columns(1–4)`, `->collapsed()`, `->table()` and `->distinct()`. Item fields: `TextInput`, `Textarea`, `Select`, `Toggle`, `Date`, `DateTime`, `ColorPicker`, `Hidden` and `MediaPicker`, translatable where they usually are; other fields (passwords, unique, dependent fields, `Slug`, `RichEditor`, uploads, relationships, nested repeaters) are refused when the form is built.
- `KeyValue::make('specs')` with `->keyLabel()`, `->valueLabel()`, `->translatableValues()` and fixed keys `->keys([...])`. Keys are required and distinct.
- The editor: cards with number, title, a summary when folded, and Move up, Move down, Duplicate, Remove and Fold; rows for `->table()` and `KeyValue`. Drag the handle, or focus it and press the arrow keys; moves are read out to screen readers. Remove with **Undo** until the form is saved. Phones stack the fields and hide Duplicate.
- Without JavaScript the Add, Up, Down and Remove buttons send the form and the page returns with the list changed, not saved and not checked.
- Errors per item: the card and field are marked, the summary names the place ("Please check: Rooms → item 3: Name"), plus distinct, minimum and maximum checks and a size limit of about 48 KB per list (so it fits in a dialog). Empty items are dropped on save.
- Page-level translation: the items' translatable fields follow the "Editing" switch and count in it; outside the default language the list is locked (no add, remove or move) and only the texts are translated. An error in an item's translation opens its language.
- Dialogs, relation managers and settings pages support both fields; on a settings page the list is stored as the setting's value (`setting('contact.links')` is an array).
- History: changes inside items are listed per item ("Rooms · Kitchen · Size"), with lines for added and removed items and a new order. `MediaPicker` ids inside items count as uses in the media library.
- Website helpers `repeater_items($value, ?$lang)` and `key_values($value, ?$lang)`.
- `admin:doctor` warns when a list's column is not `TEXT` or `JSON`.
- New words in the panel's `en`, `ar` and `ku` files (`repeater.*`, `validation.repeater_*`, `activity.item_added`, `activity.item_removed`, `activity.order_changed`).
- Create/Edit dialogs and action pages start their forms with the hidden default submit button, so Enter in a field saves (never a list button).
- For developers: `FormRenderer` takes a `namePrefix`; `Form::applyRepeaterOperation()`; `SettingsEditor::operate()`; the dialog draft is read from any input name with up to four `[…]` parts, so nested names keep their structure; icons `copy`, `arrow-up`, `arrow-down`.

## 0.23.1 - 2026-10-18

Polish for page-level translation.

- Settings pages use the same "Editing" switch as record pages (segmented buttons for up to four languages, a select above), under the page title, instead of their own select.
- Empty inputs are dashed only while translating (another language than the default one); a new record in the default language looks as before.
- The rich text toolbar keeps its rounded top corners when the switch hides the editor's language tabs.

## 0.23.0 - 2026-10-18

**Page-level translation**: an **Editing: English · Arabic 7/9 · Kurdish 0/9** switch shows the whole form in one language, so a translator works through a record from top to bottom. Storage is unchanged (one JSON object per translatable column): no migration, the website is the same, and a resource can move between modes at any time. See [Forms](docs/forms.md#page-level-translation-023).

- Two modes: `'translation_mode' => 'page'` or `'field'` in `config/admin.php`, and `protected static string $translationMode` on a resource. A missing key means `'field'`, so existing sites keep the language badges; `admin:install` writes `'page'` into a new config. A site with one language sees neither.
- The switch sits in the record's head (a full-width row on phones): segmented buttons for up to four languages, a select for more, each with its count of filled translatable fields and a full or partial ring. **Next missing** jumps to the next empty field in the language (opening a tab or folded section on the way); counts and the labels' dots update as you type.
- Under an empty input in another language, the default language's text in grey ("English: Sea view villa"; rich text: 200 characters and Show all), kept in step with what is typed.
- Fields that are the same in every language stay visible but read-only outside the default language ("Same in every language. Change it in English."), with a note above the form.
- Each language pane takes its own direction: Arabic right to left inside an English panel, English left to right inside an Arabic one. Rich text editors follow the switch.
- Errors: a language with errors gets a red count in the switch; after a failed save the form opens on the first error's language; the message names it, "Please check: Title (العربية)" (in field mode too; the language's name comes from the site's `lng.<code>` key, else its own name).
- The language you last saved in is remembered per user; `?editing=ar` opens a language and the switch keeps the address in step. A new record starts in the default language.
- Create and Edit dialogs (relation managers too) and custom action forms show the same switch; a dialog opened from a record being translated starts in its language. Bulk forms keep the badges.
- `TranslationsColumn::make('translations')`: EN 4/4 · AR 3/4 · KU 0/4 per record, from the same JSON columns, with a **Missing in: language** filter (`MissingTranslationFilter`, also usable on its own; `->withoutFilter()`).
- Settings pages keep their select and get the counts, Next missing and the reference lines; a label's dots now show only the language being edited.
- An empty rich text editor (`<p></p>`) is saved as nothing, like other empty fields.
- `admin:doctor` reports each resource's mode and checks `translation_mode` / `$translationMode` values.
- For developers: `Resource::getTranslationMode()`, `Resource::usesLanguageSwitch()`, `AdminConfig::translationMode()`, `FormRenderer` arguments `editing`, `switchActive`, `sameNote` and `languageStats()`, `LanguageSwitch`, `EditingLanguage`, `Table::prepare()`. The Settings switch script moved into the shared `translations.ts`.

## 0.22.0 - 2026-10-18

**Relation managers**: a record's related records on its edit page (a property's units, a country's cities), with search, filters, sorting and pages, and New, Edit and Delete in dialogs, without leaving the page. No migration. See the [Relation managers guide](docs/relation-managers.md).

- Declared on the parent: `relations()` returns `RelationManager::make(UnitResource::class, 'property_id')`. The child resource brings its table, form, permissions, `query()`, trash and activity log; has-many relations only.
- Below the record's form, full width, in one card; tabs with counts when there are two or more. The count follows each change. The create page says the managers come after saving.
- Every record in a manager belongs to the parent: the list, Edit, Delete, bulk and custom actions are limited to `key = parent` (a forged id of another parent is not found), and the server sets the key on every create and edit; the key field is left out of the form. The parent is in the signed state and each request checks again that you may edit it; to see a manager you need edit rights on the parent and view rights on the child.
- Options: `->label()`, `->icon()`, `->columns([...])`, `->readOnly()`, `->canCreate(false)`, `->perPage()`, `->defaultSort()`, `->emptyText()`, `->hideFields([...])`, `->name()`.
- Without JavaScript (or with Reactive off) a manager links to the child's list limited to the parent (`?parent=properties:5:units`); its New and Edit pages keep the parent and Back returns to it.
- `protected static bool $navigation = false;` keeps a resource out of the sidebar (its pages, permissions, trash and search still work; Ctrl+K offers no "New …" for it, and its edit page links back to its parent).
- `CountColumn::make('units')->relation(UnitResource::class, 'property_id')`: related counts in the parent's list, one grouped query per page.
- The parent's History also lists its related records' changes ("Units · Flat 3 — created this record"), for people who may view them. The delete confirmation of a parent says how many related records it has.
- `admin:doctor` checks each manager (child registered, key columns present) and reports resources without a sidebar entry.
- For developers: `Resource::relations()`, `Resource::parentScope()`, `Field::omit()`, `Activity::parents()`.

## 0.21.0 - 2026-10-18

**Roles and permissions**: change what each role may do, and add roles, on **Site → Roles** (`/admin/roles`, permission `roles.manage`). Needs flocms-core 2.4 and one migration (`admin_roles`). See the [Roles guide](docs/roles.md).

- `config/config.php` stays the default. Changing a config role stores it in `admin_roles` ("From config · changed"); **Reset to default** brings the config values back. Roles added on the page get the next free number and any level below yours ("Custom").
- The panel gives the roles to flocms-core with `Auth::loadRolesUsing()` (core 2.4), so the whole site sees them: the panel, the skeleton's login, your controllers' `$actionPermissions`, `Auth::can()` in templates. Changes apply on each person's next request.
- The list: level, panel access, users, permissions and source of each role; the top role is locked.
- The role page: a permission grid (one row per resource with View / Create / Edit / Delete, **All content** = `content.*` covering new resources and the media library, the media library, each settings page with **All settings pages**, users, the panel's own pages, actions and widgets with their own permission, the site's named permissions, and **Other** for any other name), the name per language, level, panel access, a summary and the users holding it. Works without JavaScript (turning a switch off then keeps what it covered); on phones each resource is a card.
- Safety: the highest-level role holding `*` is locked; you change only roles below your own level (never your own); nobody gives or takes away a permission they don't hold; levels stay below yours; a save, reset or delete that would leave no active user with panel access and `roles.manage` (or that can't be checked) is refused; Reset is offered only when you could set the config values yourself. The server enforces all of it.
- Deleting a role added in the panel moves its users to a role below your level you choose, in one transaction; a new role never reuses a number users still hold. Config roles are reset, not deleted.
- Every change is in the activity log ("Role changed": name, level, panel access, then **Given** / **Taken away** in words). Ctrl+K finds roles by name.
- `'roles' => ['permissions' => ['reports.export' => 'Export reports']]` gives the site's own permissions a row; `'roles' => false` switches the page off and the config roles apply again (the emergency switch, no shell needed).
- Commands `php flo admin:roles` (the roles in force) and `php flo admin:roles-reset [role]`. `admin:install` writes the migration; `admin:doctor` checks the table.
- The Users page lists roles added on the Roles page and compares levels (`Auth::canAssignRole()` / `canManageUser()`).
- For developers: `Action::getPermission()`, `Widget::permission()`; icons `crown`, `lock`, `check`, `info`.
- Requires flocms-core ^2.4.0.

## 0.20.0 - 2026-10-18

**Ctrl+K search**: one box, from any page of the panel, that finds records, pages, actions, settings and media. Nothing to install. See the [Search guide](docs/search.md).

- A search field in the top bar (an icon on phones). **Ctrl+K**, **⌘K** or `/` open the search box from anywhere; without JavaScript the field opens a results page.
- Results arrive as you type (after a 200 ms pause, from 2 characters or 1 digit), grouped: Pages and actions ("New post"), each resource in sidebar order, Settings, Media, then the site's own groups. 5 per group (`'search' => ['per_group' => 5]`) and **See all in Posts**, which opens the list already searched.
- Records are found by the columns their table marks `->searchable()`, in every language; a title that starts with the words comes first; `#42` finds record 42.
- Before typing: the last 8 records opened (kept in the session) and the panel's pages.
- Only what the person may open: the resource's `view` permission and `query()`, `canEdit()` for the edit page (else the list searched for it), settings and media permissions.
- Keyboard and screen readers: the ARIA combobox pattern (↑ ↓ wrap, Enter, Ctrl/⌘+Enter for a new tab, Esc), a live count of results; right to left in Arabic and Kurdish.
- For developers: `protected static bool $globallySearchable = false;`, `Resource::searchDetails()`, `Resource::searchUrl()`, and `SearchSource` classes in `'search' => ['sources' => [...]]`. `'search' => false` switches it off; `'shortcut' => false` keeps Ctrl+K for the browser.
- Groups are asked one after another within 1.5 s, so one slow table never blocks the box.

## 0.19.1 - 2026-10-18

- Fix: form and action dialogs kept an empty space of up to 150 px under their content (a minimum height on the dialog body). The body now fits its content, and a confirmation without fields has no body at all.
- New: `'dialog_min_height' => 240` in `config/admin.php` (pixels or a CSS length such as `'20rem'`) keeps room in every dialog's body; `->modalMinHeight(320)` does it for one action. The height never goes past the window; `admin:doctor` reports an invalid value.
- Fix: a bulk confirmation for one record reads "Delete the selected record?" instead of "Delete the 1 selected records?" (en, ar, ku).

## 0.19.0 - 2026-10-17

Deleted records go to a **trash** and can be restored; every change in the panel is written to an **activity log**. One migration (`admin_trash` and `admin_activity`); until it has run, deleting works as before and nothing is logged. See the [Trash and activity log guide](docs/trash-and-activity.md).

### Trash

- Delete (a row, **Delete selected**, the record page, a Delete action) moves the record to the trash: the row leaves its table at once (the website stops showing it) and a copy waits with its many-to-many links, upload files and media usage.
- A toast "Post deleted." offers **Undo** for 10 seconds, with a bar counting down (hovering or focusing it pauses it). A bulk delete undoes as one.
- **Trash (n)** in each list's toolbar opens that resource's trash with the list's columns, who deleted each record, when, and the days left. **Restore**, **Delete for good**, bulk selection and **Empty trash**. **Site → Trash** shows every type, with a tab each.
- Restore puts the record back with the same id. It is refused with a reason when a unique value (a slug) is now taken, a parent is gone or the id is used again; the record then stays in the trash.
- Upload files and media usage are kept while the record waits ("Listings · Villa (in trash)") and removed when it is deleted for good.
- Records are kept 30 days (`'trash' => ['days' => 30]`, `0` = until emptied), purged by panel pages at most hourly and by `php flo admin:trash-purge`.
- Restoring needs the resource's `create` permission, deleting for good its `delete` permission and its `canDelete()` rule.
- `protected static bool $trash = false;` deletes a resource's records at once. Users are logged, not trashed.
- Delete confirmations say where the record goes ("It goes to the trash for 30 days and can be restored from there.").
- A resource that narrows `query()` shows each person only the records they deleted, unless it defines `canSeeInTrash()`.
- Hooks `Resource::beforeRestore()` and `afterRestore()`; `Trash::restore()`, `Trash::purge()` for scripts.

### Activity log

- Create, edit (each changed field, before → after), delete, restore, delete for good, custom and bulk actions, reorder, settings saves, media uploads, edits, replacements, moves and deletes, users and profile changes. Sign-ins with `'activity' => ['logins' => true]`.
- Passwords are never recorded; `->hiddenFromActivity()` or `Resource::$activityExcept` record a change without its value. Rich text and long text by their length, relations by name, media by title, choices by label, translations per language.
- **History** on each record's page opens a side panel: its entries by day, the first changes inline, **Show changes** for the whole before → after table (a dialog). Anyone who can edit the record sees it.
- **Site → Activity** (permission `activity.view`): filters for the person, type, period and action, and a search over titles and changed values.
- Kept 365 days (`'activity' => ['days' => 365]`), purged by panel pages and by `php flo admin:activity-purge`. No IP addresses.
- `Activity::log('published', $record, ['reason' => …], ListingResource::class)` writes your own entries.

### Also

- `Notification::action($label, $url)`: a button that POSTs to a panel URL; with a duration, a countdown bar. `->neutral()` and `->icon($name)`.
- Confirmation dialogs take their title, text and button from `data-confirm-title`, `data-confirm-text` and `data-confirm-button`, and a submit button with `data-admin-confirm-submit` asks before its own action only.
- `admin:install` writes the migration; `admin:doctor` checks both tables and warns about `ON DELETE CASCADE` foreign keys the trash can't undo.
- Lang keys under `trash.`, `activity.` and `dates.`; `actions.close`, `delete.text_trash`, `bulk.confirm_trash`, `bulk.confirm_delete`.

## 0.18.1 - 2026-10-17

No migration.

### Fixed

- Rich text: resizing an image or video follows the pointer smoothly; the size it keeps is still the nearest 5 % step (shown while dragging), which it settles on when released.
- A failed save names the fields and their tabs in its message ("Please check: Price (Details)."), and every tab holding an error has a red mark, so an error in a tab not shown is not missed.

## 0.18.0 - 2026-10-17

The media library becomes a file manager, and every file input becomes a drop area. One migration (a `media_folders` table and four columns on `media`); the library is off until it has run. See the [Media library guide](docs/media.md).

### Media page

- Three columns: **folders**, the items, and a **details panel** for the selected item. The header shows the number of items, images and videos, the space used and how many images have no alt text (a link to them), with **New folder** and **Upload**.
- **Folders**: categories shown as folders (nothing moves on disk), nested up to four levels. Create, rename, move and delete them from the ⋯ menu; drag items, or folders, onto a folder. Deleting a folder moves what is in it up one level.
- **Selection** like a file manager: Ctrl/⌘+click, Shift+click, a tick on each tile, the arrow keys, Space, Shift+arrows, Ctrl/⌘+A, Delete. **Batch actions**: Move to folder, Edit details, Copy addresses, Delete (items in use are kept, and the message says how many).
- **Search, filters and sort** without reloading: file name, title or alt text, in all folders or **This folder only**; type; Not used / Without alt text; newest, oldest, by name, largest. Grid or list view (remembered). The address follows, so a view can be bookmarked.
- **Details panel**: preview, focal point, Replace file, copy the address, the title, the alt text in every language, the folder, the facts and **Used in**.
- **Edit details** for several items, one after another ("3 of 8", Save and next, Ctrl/⌘+Enter), with the number that have alt text and an option to reuse the last title.
- **Upload list** in the corner: every file in one list, uploaded one after another, with progress, Cancel and Retry. Drop files anywhere on the items; they go into the open folder.
- **Duplicate check**: the SHA-256 fingerprint of each upload is stored; a file already in the library is held and the list asks **Use “…”** or **Upload anyway**. Media fields and the picker use the existing item without asking.
- **Focal point** for images: click the spot to keep in view when the image is cropped (or move it with the arrow keys).
- Without JavaScript: plain links and forms for folders, search, filters, upload, moving and deleting.

### Picker

- The same browser in a dialog, CKFinder-style: folders, search, filters, sort, views, upload into the open folder, the details of the focused item, and a footer with the chosen items. Click and **Choose**, or double-click; several with **Use selected (n)**. Items already in the field are marked **Added**; the dialog says how many more fit. Used by media fields and the rich text editor (`window.FloAdminMedia.pick`).

### Media fields

- A framed card of their own. One item: a large preview (with the item's focal point) with Change, Edit details and Remove, its name, size and dimensions, and a **No alt text** badge that opens the details. Several: numbered tiles to drag by a grip (mouse, touch, pen) or move with Alt + arrow keys, a ⋯ menu (Edit details, Set focal point, Replace from the library, Move earlier/later, Remove), and an **Add media** tile. Empty: a drop area.
- Files dropped on a field (or chosen with **Upload**) go into the library and are used at once.
- `MediaPicker::folder('Listings/Covers')` (up to four levels): uploads from the field and its picker go to that folder (made when missing, for editors with `content.media.update`) and the picker opens there. `->aspectRatio('16:9')`: the shape of the preview. A cover fits in the record page's side column (`Form::aside()`).

### FileDrop

- `FileDrop::make($name)`: our own file chooser (no third-party package) for every upload of the panel: upload fields, settings pages, the Media page. Drop or browse; "JPEG, PNG, WebP · up to 2 MB"; a preview of the chosen or current file with its size; Cancel; Remove with Undo; progress in dialogs; the type and size checked in the browser before sending (and on the server, as before). A real `<input type="file">`, so forms and pages without JavaScript work as before. Options: `accept()`, `maxKb()`, `multiple()`, `large()`, `current()`, `removable()`, `hint()`, `title()`, `disabled()`, `inputAttributes()`. See the [Uploads guide](docs/uploads.md).

### Website

- `media_folder('Homepage slider')` (or a path, `'Listings/Villas'`, and `'name'`, `'newest'`, `'oldest'` or `'largest'`): the items of a folder, so editors can manage a slider from the Media page.
- `MediaItem::img()` adds `object-position` for an image's focal point; `focal()` gives it as CSS. `MediaItem` has `folderId`, `focalX`, `focalY`.

### Commands and config

- `admin:install` writes the 0.18 migration (`*_create_media_folders_table.php`). `admin:media-import` adds fingerprints to items uploaded before 0.18. `admin:doctor` checks the folders table and the new columns.
- `'media_library' => ['folders_table' => …]` renames the folders table (default `media_folders`).

### Fixed

- Forms that a script sends itself (`data-admin-local`) are no longer taken over by the panel's page navigation.

## 0.17.0 - 2026-10-17

The media library. One migration (two tables, `media` and `media_usage`); nothing changes for existing forms. See the [Media library guide](docs/media.md).

### Media library

- **Content → Media** (`/admin/media`): a grid of the site's images and videos, newest first, with search (file name, title, alt text), an Images / Videos filter and a **Not used** view. Upload by choosing or dropping files, with progress for each; videos are sent in 1 MB parts.
- Images (JPEG, PNG, WebP, GIF) are stored through flocms-uploader as a web version of at most 1920 px and a 400 px thumbnail; videos (MP4, WebM) as uploaded. Files go to `library/<year>/<month>/` in the public media folder.
- Each item's page: preview, size, dimensions and address (with a copy button), a **title** and an **alt text per language**, **Used in** (every record and settings page using it, with links), **Replace file** (same item, new file) and **Delete** (only when unused; several unused items can be deleted at once).
- Usage is recorded on every save and delete in the panel (records and settings pages), inside the same transaction; rich text counts an item when it contains its address. A save is refused when a chosen item was deleted by another editor meanwhile, and an item is deleted only if nothing started using it in between.
- Permissions `content.media.view`, `.create`, `.update`, `.delete` (with the configured prefix), listed by `admin:list --permissions`.
- `'media_library'` in `config/admin.php`: `true` (default), `false`, or `['table', 'usage_table', 'max_image_kb', 'max_video_mb']`. The tables are generic (`media`, not `admin_media`) so the website and apps can read them. The migration stops with a message when a `media` table that isn't the library's already exists: rename the library's tables in the config.

### Forms and lists

- `MediaPicker` field: one item (an id in an `INT` column) or, with `->multiple()`, several in order (a JSON list in a `TEXT` column), `->images()` (default), `->videos()`, `->any()`, `->max(n)`. Thumbnails with Change / Remove, a picker dialog with search, filters and upload, reordering by dragging (also right to left) or with arrow buttons. The server checks every id and kind. Without JavaScript: a select of the newest 200 items.
- `MediaColumn`: thumbnails in lists (`->limit(3)` then "+n"), loaded in one query per page.

### Rich text

- **Image → Choose from the library** inserts a library image with its alt text in the field's language.
- With the library set up, images and videos uploaded in the editor become library items (for users with `content.media.create`; others upload for the field as before).

### Website

- `media($id)` returns a `MediaItem` (or `null`); `media_list($ids)` the items of a gallery in order; `media_preload([...])` loads many in one query.
- `MediaItem::img()` prints `<img>` with `alt`, `width`, `height`, `srcset` with the thumbnail, `loading="lazy"` and `decoding="async"`; `video()` and `html()` likewise. `url()`, `url('thumb')`, `alt($lang)`, `label()`.

### Commands

- `admin:install` writes the media library migration.
- `admin:media-import [--dry-run]` adds the images and videos already in the media folder to the library, then rebuilds the usage.
- `admin:media-usage` rebuilds where items are used, from scratch and in one transaction (rows of removed resources, fields and settings pages go away).
- `admin:doctor` checks the library's tables and columns, their character set, the folder, GD and the permissions.

### Fixed

- Rich text fields: after a failed save, focus goes into the invalid editor even when it was lost on the way (another update ran first, or the page lost focus before the editor started). Before, the editor took focus only when the hidden field it replaces still had it, so on slow devices (and once in a while on CI) focus was left nowhere.
- The upload list shows every chosen file at once ("Waiting: …"), then uploads them one after another.

## 0.16.0 - 2026-10-17

Drag-and-drop ordering. No new dependency, migration or table: the order is an integer column of the resource's own table. See the [Reordering guide](docs/reordering.md).

### Lists

- `Table::reorderable('sort')`: records keep an order chosen by editors. The list follows it when the table has no `defaultSort()` (`ORDER BY sort, id`); sortable column headers still sort.
- A **Reorder** button (update permission, `Resource::canReorder()`) opens Reorder mode at `/admin/<slug>/reorder`: every record of the current filters on one page, in their saved order, with a grip handle per row. Drag with the mouse, a finger or a pen (the page scrolls near its edges), or with the keyboard: Space/Enter picks a row up, the arrow keys move it, Space/Enter drops it, Escape puts it back; a screen reader hears each step. **Done** returns to the list with its filters.
- Each drop is saved at once (`POST /admin/<slug>/reorder`): the moved rows hand out the positions they already had, so ordering one filtered group (one menu, one FAQ category) never moves the others. When positions among the reordered rows repeat or are empty (a column just added with `DEFAULT 0`), the scope is numbered 1, 2, 3… first. Only the order column is written: `updated_at` and the save hooks are untouched. A failed save puts the rows back; when another editor changed the list meanwhile, the page reloads and says so.
- Without JavaScript, each row has ↑/↓ buttons that move it one place.
- Reorder asks the update permission and `canReorder()`, not `canEdit()` per row.
- New records go to the end of the order (`->newRecordsFirst()`: the start). Reorder mode takes up to 500 rows (`->reorderLimit()`, at most 2000); above that it asks to filter first.
- `Resource::afterReorder(array $ids)` runs inside the transaction after positions changed (e.g. to clear a cached menu).

### Other

- `make:admin-resource --reorderable` adds the `sort` column (with an index) to the migration and `->reorderable('sort')` to the resource; without `--migration` it prints the `ALTER TABLE` to run.
- `admin:doctor` reports a missing or non-integer order column.
- Icons: `arrows-up-down`, `chevron-up`, `grip`.

## 0.15.5 - 2026-10-17

### Changed

- Translatable fields on right-to-left pages: the text sits on the right like in every other field, also when the default language is written left to right. It keeps its own direction, so punctuation stays in place. On left-to-right pages it sits on the left.
- The room kept between the text and the language badge is the badge's own width plus a small gap, measured in the browser (also inside dialogs), instead of a fixed 96 or 140 px.

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
