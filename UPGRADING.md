# Upgrading to Admin 0.30.1

Update with `composer require "hostkurd/flocms-admin:^0.30.1" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

---

# Upgrading to Admin 0.30.0

Update with `composer require "hostkurd/flocms-admin:^0.30.0" "hostkurd/flocms-core:^2.6" -W`, run `php flo view:clear` and reload open Admin pages. Admin 0.30 needs **flocms-core 2.6**.

- **Nothing changes until you turn pages on.** Add `'pages' => true` to `config/admin.php`, then run `php flo admin:install` and `php flo migrate` for the `admin_pages` table. Pages are at **Site → Pages** (`/admin/site-pages`).
- **Your layout**: put `{!! page_meta() !!}` in the `<head>` of `layouts/default.html` (it prints nothing on your other pages, so keep your own `<title>` for them) and, for a menu, loop over `pages_menu()`. See [Pages](docs/pages.md).
- **The home page**: to show a page at the main address, start your home controller's `index()` with `if ($page = home_page()) { return $page->response(); }`.
- **Your controllers win**: a page can't take an address your site's controllers answer (a page called `contact` next to a `ContactController`). `php flo admin:doctor` lists any that clash.
- **`/sitemap.xml`** is answered by the panel when pages are on. A `sitemap.xml` file in `public/` keeps working; `'pages' => ['sitemap' => false]` turns the panel's off.
- **Tick `pages.publish`** on the Roles page (System) for the people who may publish pages. Without it they can write drafts, and pages visitors see are read-only for them. Super Admins have it (`*`).
- **Block styles and scripts**, when the site hasn't copied them with `php flo admin:blocks`, are now linked as files from `/flo-admin/asset/` instead of printed in the page. Nothing to do; a strict Content-Security-Policy no longer blocks them.
- **Translations**: the new words in Arabic and Kurdish need a read by a native speaker.

---

# Upgrading to Admin 0.29.0

Update with `composer require "hostkurd/flocms-admin:^0.29.0" -W`, run `php flo view:clear` and reload open Admin pages. No migration: the Builder needs only a column on the tables that use it.

- **Nothing changes until you add a `Builder` or `Slider` field.** Give its column a type that holds large JSON: `JSON`, `LONGTEXT` or `MEDIUMTEXT` on MySQL and MariaDB (`TEXT` stops at 64 kB), `TEXT` on SQLite and PostgreSQL. `php flo admin:doctor` warns about a column that is too small.
- **Printing blocks**: put `{!! blocks_assets() !!}` in your layout's `<head>` and `{!! blocks($record->content) !!}` where they go. Run `php flo admin:blocks` when you want to change the templates, styles or slider script: they are copied into your site and never replaced by updates.
- **`max_input_vars`**: every block's fields are sent with the form (about 20 per block). Pages of more than about 40 blocks need `max_input_vars = 5000` in `php.ini` or `.user.ini`; a request cut short is refused, not saved half.
- **Tick `blocks.html`** on the Roles page (System) for the people who may add or change HTML blocks. No existing role gets it, Super Admins aside (`*`).
- **`Select` shown as buttons**: `->segmented()`, `->pictures()` and `->positions()` are new; a select without them is drawn as before. The field's label now has an id (`<id>-label`).
- **`make:admin-widget`** shares its config code with the new `make:admin-block`; it behaves as before.
- **Translations**: the new words in Arabic and Kurdish need a read by a native speaker.

---

# Upgrading to Admin 0.28.2

Update with `composer require "hostkurd/flocms-admin:^0.28.2" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

---

# Upgrading to Admin 0.28.1

Update with `composer require "hostkurd/flocms-admin:^0.28.1" -W`, run `php flo view:clear` and reload open Admin pages.

- **Run `php flo admin:install`, then `php flo migrate`** to add the optional **`bio` column to your `users` table** (added only if it isn't there; `php flo migrate:rollback` removes it again). Without it the user page simply has no Bio field.
- **The user form is laid out in sections with a sidebar.** A site that extends `UsersResource::form()` should check its fields still sit where it wants them.
- **Translations**: the new words in Arabic and Kurdish need a read by a native speaker.

---

# Upgrading to Admin 0.28.0

Update with `composer require "hostkurd/flocms-admin:^0.28.0" -W`, run `php flo view:clear` and reload open Admin pages.

- **Run `php flo admin:install`, then `php flo migrate`** for the mailing tables (`admin_mailings`, `admin_mailing_recipients`, `admin_mail_optouts`). Until then Users works as before and **Email users** says the tables are missing.
- **Mailings need `APP_KEY`** in `.env` (at least 16 characters, as most FloCMS sites already have): it signs the unsubscribe links. Without it direct emails still work.
- **Tick the new permissions** on the Roles page: `users.email` (direct emails to up to 20 chosen users) and `users.mailing` (mailings). No existing role gets them, Super Admins aside (`*`).
- **Optional cron** so mailings go on with no page open: `* * * * * cd /path/to/site && php flo admin:mailings`. Check `per_hour` against your host's limit (`'mailings' => ['per_hour' => 200]`).
- **Photos are stored in `public/uploads/admin`**, so the folder must be writable. Existing values of `users.image` (a path under `public/` or an address) are shown as they are.
- **The ID column is gone** from Users (the list is sorted as before). The row title still opens the user.
- **Translations**: the new words in Arabic and Kurdish need a read by a native speaker.

---

# Upgrading to Admin 0.27.0

Update with `composer require "hostkurd/flocms-admin:^0.27.0" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- **People with View permission can now open records.** Until now `content.<slug>.view` without `update` showed the list only; now the row title opens the record's view page, which shows every field except passwords, `Hidden` fields and fields marked `->hiddenOnView()`. Check your roles: if a field must stay out of sight of viewers, add `->hiddenOnView()`, refuse whole records with `canView()`, or turn the page off with `protected static bool $viewPage = false;`.
- **Viewers can download a record's files** (`FileUpload` fields not marked `hiddenOnView()`), and see related records on the view page as a plain table.
- **History** on a record is now also open to people with `activity.view` who may view the record (before: only to people who may edit it).
- **The row title is a link now** (to Edit, or to the view page). A column that already renders its own link or button is left as it is. If your own JavaScript or tests click the title cell, they now follow the link.
- **Links to records** in Ctrl+K search, recent records and the Activity page go to the view page for people who may not edit. A resource with `searchUrl()` keeps its own link.
- **Translations**: the new `view.*` words in Arabic and Kurdish need a read by a native speaker.

---

# Upgrading to Admin 0.26.0

Update with `composer require "hostkurd/flocms-admin:^0.26.0" -W` (this also brings **flocms-core 2.5**), run `php flo view:clear` and reload open Admin pages.

- **Forgot password is off on existing sites** until you add it to `config/admin.php`:

  ```php
  'password_reset' => true,
  ```

  Then **run `php flo admin:install`, then `php flo migrate`** for the `admin_password_resets` table. The login page shows **Forgot password?** once the table exists (with `'login_theme' => true`).
- **Email settings** in `.env`: on most cPanel hosts PHP `mail()` works with nothing more than `PRIMARY_EMAIL` (the sender). For SMTP add `MAIL_TRANSPORT=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD` and optionally `MAIL_FROM` / `MAIL_FROM_NAME`. `APP_URL` must be the site's full address (`https://…`). Check with `php flo admin:doctor` and `php flo admin:mail-test you@example.com`.
- **Other sessions now end when a password changes**, also on the profile page and when an admin changes a user's password on Users. Each session gets its fingerprint on its next admin request, so nobody is signed out by the upgrade itself.
- **Your own `PasswordController`** (if the site has one) keeps the URL `/admin/password`; the panel's pages are then not shown. A resource with the slug `password` is refused (`admin:doctor` says so).
- **Translations**: the Arabic and Kurdish words of the new pages and emails need a read by a native speaker; override any of them in your `lang` files as usual.

---

# Upgrading to Admin 0.25.1

Update with `composer require "hostkurd/flocms-admin:^0.25.1" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- **Rich text is imported now.** The Match step offers `RichEditor` fields (one column per language). If a resource must not take rich text from a file, mark the field `->notImportable()`.
- **Templates**: the empty template and exported files of resources with rich text already had those columns; the import now reads them instead of skipping them.
- **Translations**: `import.tip_media` changed; the Arabic and Kurdish words need a native read.

---

# Upgrading to Admin 0.25.0

Update with `composer require "hostkurd/flocms-admin:^0.25.0" -W`, run `php flo view:clear` and reload open Admin pages.

- **Run `php flo admin:install`, then `php flo migrate`**: this adds the `admin_imports` and `admin_import_rows` tables. Until then lists show the Export menu but no Import button. `php flo admin:doctor` checks the tables and the folder `storage/admin-imports/` (keep it out of the public folder, as `storage/` already is).
- **New permissions** `content.<slug>.export` and `content.<slug>.import`. Roles with `content.*` or `*` (Editors and Super Admin in the skeleton) get both at once; to keep import for some roles only, list their permissions per resource, or untick the new **Export** and **Import** columns on Site → Roles. Import also needs `create`, `update` to change existing records, and `delete` to undo an import that created records.
- **Turning it off**: `'export' => false` / `'import' => false` in `config/admin.php` for the whole panel, or `protected static bool $exportable = false;` / `$importable = false;` on a resource. Use `->hiddenFromExport()` on fields with private data (phone numbers, internal notes): every field of the form except passwords is exported by default.
- **Lists with bulk actions** show **Export selected** in the bulk bar. Lists' toolbars gain the Export button; tests that count the toolbar's buttons may need a look.
- **Cron** (optional): `php flo admin:import-purge` once a day removes old upload files and Undo data; panel pages do the same at most once an hour.
- **Activity log**: an import is one `imported` entry, not one entry per row. Exports are logged as `exported`.
- **Translations**: the Arabic and Kurdish words of the import and export pages need a read by a native speaker; override any of them in your `lang` files as usual.

---

# Upgrading to Admin 0.24.1

Update with `composer require "hostkurd/flocms-admin:^0.24.1" -W`, run `php flo view:clear` and reload open Admin pages. No migration, no code changes: row lists (`->table()` and `KeyValue`) look tidier, and a table's row menu opens without a scrollbar flash.

---

# Upgrading to Admin 0.24.0

Update with `composer require "hostkurd/flocms-admin:^0.24.0" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- **Nothing changes until a form declares a `Repeater` or `KeyValue`.** Each needs a `TEXT` or `JSON` column of its own (for example `ALTER TABLE flats ADD rooms JSON NULL`); `php flo admin:doctor` warns about a smaller type. See [Repeaters and key-value lists](docs/repeaters.md).
- **On the website** read a list with `repeater_items($flat->rooms)` or `key_values($flat->specs)`; both return `[]` for an empty column.
- **Choosing between a Repeater and a relation manager**: the guide has a table; in short, a Repeater for a few small items that only live inside the record, a relation manager for records of their own.
- **Dialog drafts**: the browser now keeps nested input names (`rooms[k1][name][en]`) as nested values in the draft. Flat names and `title[en]` arrive exactly as before. Inputs of your own named with two to four `[…]` parts (`extra[a][b]`), which the draft used to skip, are now sent as nested arrays; names ending in `[]` are still skipped.
- **Enter in dialogs and action pages** now always saves: their forms start with a hidden `.fa-default-submit` button (as record and settings pages already did), so Enter never presses a list's Add or Up button. A test that clicked `dialog button[type=submit]` should use `.fa-dialog-foot button[type=submit]` or the button's name.
- **A disabled `Repeater`** is shown read-only, without its buttons.
- **Translations**: the Arabic and Kurdish words for the new buttons and messages need a read by a native speaker; override any of them in your `lang` files as usual.
- **Templates and tests**: a list is `[data-repeater]` with `data-name`; each item is `[data-repeater-item]` with `data-key`, its inputs are named `rooms[<key>][<field>]` (and `[<lang>]`) with ids `rooms-<key>-f-<field>`; the buttons are `[data-repeater-add]` and `[data-repeater-action="up|down|duplicate|remove|toggle"]`, and without JavaScript they post `_repeater=rooms:add` (or `rooms:up:<key>`).

---

# Upgrading to Admin 0.23.1

Update with `composer require "hostkurd/flocms-admin:^0.23.1" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- **Settings pages** show the same language switch as record pages, under the title (buttons for up to four languages). Tests that chose a language with the select `[data-settings-language]` should click `#fa-settings-switch .fa-seg-btn[data-lang="ar"]` instead; with five or more languages the select (and its `data-settings-language` attribute) stays.

---

# Upgrading to Admin 0.23.0

Update with `composer require "hostkurd/flocms-admin:^0.23.0" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- **Nothing changes until you choose page mode**: without `'translation_mode'` in `config/admin.php` every resource keeps the language badges.
- **Turning it on**: add `'translation_mode' => 'page',` to `config/admin.php` for the whole panel, or `protected static string $translationMode = 'page';` to one resource (and `'field'` to keep a resource on badges). `php flo admin:doctor` lists the mode of each resource. See [Forms](docs/forms.md#page-level-translation-023).
- **What still needs translating**: add `TranslationsColumn::make('translations')` to a table; it brings a **Missing in** filter (named `missing_in`; a filter of your own with that name keeps it).
- **Error messages** now name the language of a translation's error in both modes: "Please check: Title (العربية)" (the site's `lng.ar` name, else the language's own name). Tests that compare that text need the new wording.
- **Settings pages**: the language select is the same control, now with counts in its options, Next missing and reference lines; the "All languages" note keeps its place. Code that hooked `[data-settings-language]` keeps working.
- **Rich text**: an empty editor (`<p></p>`, `<p>&nbsp;</p>`) is now saved as nothing, so it no longer counts as filled; existing empty values are cleaned the next time the record is saved.
- **Preferences**: the last language saved in is kept as the `editing_language` preference (in `admin_user_preferences`, or the session before that table exists).
- **Templates and tests**: a page-mode form is `[data-lang-scope]` with `data-editing`, a hidden `_editing` input and a `.fa-lang-switch`; each language input sits in a `.fa-lang-pane`; shared fields have `data-same-all`. Field-mode markup is unchanged.

---

# Upgrading to Admin 0.22.0

Update with `composer require "hostkurd/flocms-admin:^0.22.0" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- **Nothing changes until a resource declares `relations()`**: edit pages look as before.
- **Adding managers**: return `RelationManager::make(ChildResource::class, 'parent_id')` from the parent's `relations()`. The child must be registered in `config/admin.php`; `php flo admin:doctor` checks it and its key column. See the [Relation managers guide](docs/relation-managers.md).
- **Children in the sidebar**: a child managed only inside its parent can set `protected static bool $navigation = false;`. Its URLs keep working.
- **Your own child resources**: in a manager, the child's `query()` still applies, and the server adds `parent_id = <parent>`. `beforeSave()` runs as usual; the key is set after it, so it can't move a record to another parent from a manager.
- **The parent's History** now includes the related records' entries. Entries written before 0.22 have no parent note and stay only on the child.
- **Templates and tests**: the record page has a `<section class="fa-managers">` after the form, each manager a Reactive component `rx-rel-<name>`; the delete confirmation of a parent ends with "This property has …". A resource with `$navigation = false` has no sidebar link.

---

# Upgrading to Admin 0.21.0

Update with `composer require "hostkurd/flocms-admin:^0.21.0" -W` (this also installs flocms-core 2.4), then:

```bash
php flo admin:install   # adds the roles migration (nothing is overwritten)
php flo migrate         # creates admin_roles
php flo admin:doctor    # checks it
php flo view:clear
```

Reload open Admin pages.

- **Nothing changes until someone saves on the Roles page**: without rows in `admin_roles` the roles of `config/config.php` apply exactly as before.
- **Who sees the page**: `roles.manage`. Super Admin has it through `*`. Give it to other roles only on purpose.
- **The page needs** the `permissions` and `admin_access_roles` maps in `config/config.php` (the skeleton 1.8+ has both). Without them it stays off and `admin:doctor` says so.
- **Once roles are changed on the page, `config/config.php` is no longer the whole story**: edits to a config role there only apply while the role has no changes on the page (Reset to default, or `php flo admin:roles-reset`). `php flo admin:roles` prints the roles in force.
- **Emergency switch**: `'roles' => false` in `config/admin.php` makes the config roles apply again at once, without deleting anything.
- **Your own permission checks**: code that compares role numbers to rank users can use `Auth::level($role)` (core 2.4) so it agrees with roles added on the page; `Auth::roles()` lists every role's name. Name your own permissions in `'roles' => ['permissions' => [...]]` to give them a row in the grid.
- **Skeleton 1.8.7** (optional) asks core for admin access at login, so a new role can sign in at once; older skeletons get there one redirect later.
- **`/admin/roles`**: a site resource or application controller with the slug `roles` keeps it; the panel's page is then not shown.
- **Templates and tests**: the sidebar's Site group has a Roles link for people with `roles.manage`, and Ctrl+K a Roles group.

---

# Upgrading to Admin 0.20.0

Update with `composer require "hostkurd/flocms-admin:^0.20.0" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- **A search field in the top bar**, and Ctrl+K, ⌘K and `/` open the search box on every panel page. `'search' => false` in `config/admin.php` switches it off; `'search' => ['shortcut' => false]` keeps the field but leaves the keys to the browser.
- **What is found** is what each list's search box finds: the columns marked `->searchable()`. Check that the first searchable column of each resource is its title (it titles the result). A resource that should stay out (a large log table): `protected static bool $globallySearchable = false;`.
- **`/admin/search`**: a site resource or application controller with the slug `search` keeps it; the panel's search is then off.
- **Templates and tests**: the top bar has a `<form class="fa-topsearch">` and the panel a `<dialog id="fa-search-dialog">`; site tests that select `input[type=search]` or the page's only `form`, or count top-bar links or dialogs, should look inside `.fa-content` (the browser tests here do). `PanelView::pageUrls()` is new; `activityUrl()` and `trashUrl()` are public.

---

# Upgrading to Admin 0.19.1

Update with `composer require "hostkurd/flocms-admin:^0.19.1" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- Form and action dialogs are now as tall as their content (before, their body was at least 150 px). To keep room, for example for a form whose fields appear as you choose, set `'dialog_min_height' => 240` in `config/admin.php`, or `->modalMinHeight(320)` on one action.
- Bulk confirmations for a single record use new keys (`bulk.confirm_one`, `bulk.confirm_trash_one`, `bulk.confirm_trash_kept_one`, `bulk.confirm_delete_one`). A site that translates or changes `admin.bulk.confirm*` in its own lang files keeps its wording until it adds the `_one` keys too. Site tests that looked for "1 selected records" should look for "the selected record".

---

# Upgrading to Admin 0.19.0

Update with `composer require "hostkurd/flocms-admin:^0.19.0" -W`, then:

```bash
php flo admin:install   # adds the trash and activity log migration (nothing is overwritten)
php flo migrate         # creates admin_trash and admin_activity
php flo admin:doctor    # checks them
php flo view:clear
```

Reload open Admin pages.

- **Until the migration has run** deleting works as before (at once, no Undo) and nothing is logged.
- **Deleting no longer erases at once**: deleted records wait in the trash for 30 days. A site that must erase at once sets `'trash' => false` in `config/admin.php`, or `protected static bool $trash = false;` on a resource. `afterDelete()` still runs at the delete; upload files are now removed when the record is deleted for good, not at the delete.
- **The Activity page needs `activity.view`.** Add it to the roles that should see it in `config/config.php` (the skeleton's Admin role: `2 => [..., 'activity.view']`). Super Admin (`*`) has it. History on a record needs only the update permission.
- **Resources that override `query()`** to show part of a table (one site, one owner): their trash shows each person only what they deleted. Define `canSeeInTrash(object $record, ?int $deletedBy): bool` to share it by your own rule, or return `true` when `query()` only sorts.
- **Fields with secrets** that are not password fields (an API key, a personal note): add `->hiddenFromActivity()` so the log records the change without the value. Passwords are never logged.
- **`/admin/trash` and `/admin/activity`**: a resource or application controller with the slug `trash` or `activity` keeps it (the panel's page is then not shown; `admin:doctor` says so).
- **Cron (optional)**: `php flo admin:trash-purge` and `php flo admin:activity-purge` once a day. Panel pages do the same at most once an hour.
- **Templates and tests**: a delete now shows "Post deleted." with an Undo button (a session notification) when the trash is on, instead of the flash message; `Action::getDescription()` takes the resource as an optional argument; the panel's `#fa-confirm` dialog's title, text and button can change per form (`data-confirm-title`, `data-confirm-text`, `data-confirm-button`).

---

# Upgrading to Admin 0.18.1

Update with `composer require "hostkurd/flocms-admin:^0.18.1" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- The message after a failed save now names the fields ("Please check: Price (Details)." — key `flash.fix_errors_in`); an error that belongs to no field adds its own text to that list. `flash.fix_errors` remains for the rare case with nothing to list. Site tests that looked for "Please correct the errors below." on resource forms should look for the new text.
- Tab buttons holding an error have the class `has-error` and an `aria-describedby`.

---

# Upgrading to Admin 0.18.0

Update with `composer require "hostkurd/flocms-admin:^0.18.0" -W`, then:

```bash
php flo admin:install      # adds the media folders migration (nothing is overwritten)
php flo migrate            # creates media_folders; adds folder_id, hash, focal_x, focal_y to media
php flo admin:media-import # optional: fingerprints for items uploaded before 0.18 (for the duplicate check)
php flo admin:doctor       # checks them
php flo view:clear
```

Reload open Admin pages.

- **Until the migration has run the media library is off**: no Media link, and media fields say "The media library is not set up" (stored ids are kept on save). `admin:doctor` says what is missing.
- **Renamed media tables**: if you set `'table'` in `'media_library'`, the migration uses it. To rename the new folders table, set `'folders_table'` before running `admin:install`.
- **Permissions**: folders, moving items and the focal point need `content.media.update`, like the title and alt text. Nothing new to grant. An uploader without update can upload into a field's `->folder()` only once it exists; otherwise the files go to Unfiled.
- **Upload fields**: inside the `.fa-upload` wrapper (unchanged, with its `data-upload-*` attributes) the input is now a FileDrop (`.fa-filedrop`, `[data-admin-filedrop]`); the input keeps its `name`, `id`, `accept` and `data-upload-field`. Site CSS or tests that targeted the old current-file link, `.fa-upload-remove` or the input's `.fa-input` class should use the new markup ([Uploads guide](docs/uploads.md)). Saving, validation and storage are unchanged.
- **Media fields and the Media page**: new markup (`.fa-mf`, `.fa-mb-*`); the 0.17 classes (`.fa-media-grid`, `.fa-media-tile`, `.fa-media-chip`, `.fa-media-dialog`, …) are gone. The item's page (`/admin/media/edit/<id>`), `.fa-media-thumb` in lists and the rich text editor's `.fa-media-*` classes are unchanged. The picker needs a click on **Choose** (or a double-click) for one item.
- **Forms of your own in the panel** that a script posts itself can opt out of page navigation with `data-admin-local`.

---

# Upgrading to Admin 0.17.0

Update with `composer require "hostkurd/flocms-admin:^0.17.0" -W`, then:

```bash
php flo admin:install      # adds the media library migration (nothing is overwritten)
php flo migrate            # creates the media and media_usage tables
php flo admin:doctor       # checks them
php flo view:clear
```

Reload open Admin pages. Existing forms and pages are unchanged.

- **Roles**: give editors `content.media.view` (and `.create`, `.update`, `.delete` as needed; `php flo admin:list --permissions` prints them). Without view, the Media link and the editor's library are hidden. Without create, uploads in the rich text editor keep working as before and don't join the library.
- **Rich text uploads**: with the library set up, images and videos uploaded in the editor are stored in `library/<year>/<month>/` of the media folder and become library items. Files uploaded before stay where they are and keep working; `php flo admin:media-import` adds them to the library (`--dry-run` lists them first).
- **A `media` table of your own**: the migration stops with a message instead of touching it. Set `'media_library' => ['table' => 'site_media', 'usage_table' => 'site_media_usage']` in `config/admin.php`, delete the generated migration and run `php flo admin:install` again. Or set `'media_library' => false` to leave the library off.
- **Custom CSS or tests**: the panel's CSS now has `.fa-media-*` classes for the library (`.fa-media-box`, `.fa-media-tile`, `.fa-media-chip`, …). The rich text editor's `.fa-media`, `.fa-media-frame`, `.fa-media-handle` and `.fa-media-caption` are unchanged.
- This release includes the 0.16.1 fix (focus after a failed rich text save), which wasn't tagged on its own.

---

# Upgrading to Admin 0.16.0

Update with `composer require "hostkurd/flocms-admin:^0.16.0" -W`, then run `php flo view:clear` and reload open Admin pages. No migration is needed; nothing changes until a table calls `->reorderable()`.

- **Using Reorder**: add an integer column (`ALTER TABLE faqs ADD sort INT NOT NULL DEFAULT 0;` and `CREATE INDEX faqs_sort_index ON faqs (sort);`) and `->reorderable('sort')` to the resource's `table()`. Existing rows keep their current order: the first save numbers them. Remove a `defaultSort()` you no longer want, so the list follows the editors' order. On the website, read the records with `ORDER BY sort, id`. Run `php flo admin:doctor` to check the column.
- **Custom resources** that already define a method named `canReorder` or `afterReorder` must rename it (the panel now calls them).
- **Site CSS or tests** that look at the list toolbar: a reorderable list has one more button there (`[data-admin-reorder-link]`).

---

# Upgrading to Admin 0.15.5

Update with `composer require "hostkurd/flocms-admin:^0.15.5" -W`, run `php flo view:clear` and reload open Admin pages. Nothing else changes.

---

# Upgrading to Admin 0.15.4

Update with `composer require "hostkurd/flocms-admin:^0.15.4" -W`, run `php flo view:clear` and reload open Admin pages. No migration. The `.fa-input-row` around a translatable input no longer has a `dir` attribute (the input keeps its own); site CSS or tests that relied on it should use the input's `dir`.

---

# Upgrading to Admin 0.15.3

Update with `composer require "hostkurd/flocms-admin:^0.15.3" -W`, run `php flo view:clear` and reload open Admin pages. No migration. To give an existing list a Columns menu, add `->toggleable()` (or `->toggleable(hiddenByDefault: true)`) to the columns users may hide.

---

# Upgrading to Admin 0.15.2

Update with `composer require "hostkurd/flocms-admin:^0.15.2" -W`, run `php flo view:clear` and reload open Admin pages. No migration.

- **Notifications now close by themselves** (success and info, after 5 seconds). Add `->persistent()` to one that must stay, such as a message the user needs to copy. Browser tests that check a success or info notification after more than a few seconds should make it persistent or check sooner.
- Browser tests that looked for a relation list with `position: absolute` should expect `fixed`.

---

# Upgrading to Admin 0.15.1

Update with `composer require "hostkurd/flocms-admin:^0.15.1" -W`, run `php flo view:clear` and reload open Admin pages. Nothing else changes. Run `php flo admin:doctor` once: if it reports a table that "stores text as latin1", convert it with the `ALTER TABLE` it prints before entering Arabic or Kurdish text (text already saved as `?` must be typed again).

---

# Upgrading to Admin 0.15.0

Update with `composer require "hostkurd/flocms-admin:^0.15.0" -W`, then run `php flo view:clear` and reload open Admin pages. No migration is needed; nothing changes until a resource uses the new relation fields.

- **Using relations**: see `docs/relationships.md`. A `BelongsTo` column should allow `NULL` unless the field is `required()`; a `BelongsToMany` needs its pivot table. Run `php flo admin:doctor` afterwards: it checks the tables and suggests foreign keys.
- **Custom columns and filters**: classes that extend `Column` or `Filter` and already define a method named `prepareRecords`, `isDatabaseColumn`, `renderFilterInput`, `describeFilterValue` or `emptyFilterValue` must rename it (these names are now used by the panel). The same applies to `storesInColumn`, `saveRelated` and `submitted` on custom `Field` classes.
- **Deletes**: `RecordRepository::delete()` now runs in a transaction and turns a foreign-key refusal into a `ValidationException` with a readable message; code that caught `PDOException` around it should catch `FloCMS\Core\ValidationException`.

---

# Upgrading to Admin 0.14.0

Update with `composer require "hostkurd/flocms-admin:^0.14.0" -W`, then:

```bash
php flo admin:install     # adds the two new migrations; leaves config/admin.php alone
php flo migrate           # creates `settings` and `admin_user_preferences`
php flo view:clear
php flo admin:doctor
```

Reload open Admin pages. Until the tables exist the panel works as before, the Settings page asks you to migrate, and preferences last until logout.

- **One settings table.** Settings pages use FloCMS's own `settings` table (`param`, `value`, `lang`), the one core reads for `offline_mode`. Each admin setting is one row with an empty `lang` and a JSON value; existing rows are left alone. Sites that already have the table keep it (the migration only creates it when missing); `admin:doctor` checks its columns.
- **A resource with the slug `settings`?** That URL now belongs to the panel: give the resource another `$slug` (the doctor and debug log name it).
- **Who sees Settings:** website pages need `settings.manage`, the Admin panel page `settings.panel`. The skeleton's Admin (`settings.*`) and Super admin (`*`) roles have both; give them to other roles if needed.
- **Settings files are public.** If your web root is not `public/`, set `settings_uploads` (`['root' => …, 'url' => …]`) in `config/admin.php`.
- **Brand settings move to the browser (optional).** `brand`, `logo`, `primary_color`, `theme`, `sidebar`, `content_width` in `config/admin.php` keep working as defaults; a value saved in Settings → Admin panel overrides them. Add `'panel_settings' => false` to keep config-only branding.
- **Language links:** the top-bar language links now end with `?switch_language=1`. Browser tests that matched those URLs exactly need updating.
- **Browser tests** that located the Filters menu with a generic `details.fa-popover` should use `details.fa-popover[id$="-filters-menu"]`: tables with toggleable columns now have a second popover.
- **Profile page:** it now uses the settings layout with Account and My preferences tabs; the form fields are unchanged.

---

# Upgrading to Admin 0.13.1

Update with `composer require "hostkurd/flocms-admin:^0.13.1" -W` and reload open Admin pages. Nothing else changes; if your site sends a Content-Security-Policy, allow `frame-src https://www.youtube-nocookie.com https://player.vimeo.com` on admin pages so embeds show their player.

---

# Upgrading to Admin 0.13.0

Update with `composer require "hostkurd/flocms-admin:^0.13.0" -W`, then run `php flo view:clear` and reload open Admin pages. No migration or dependency change is needed.

- Add `{!! \FloCMS\Admin\Support\RichText::stylesheet() !!}` to the `<head>` of the website's layout, and print rich text inside an element with class `rt-content`. Without the stylesheet, sizes, positions, captions and alignment set in the editor are saved but not shown.
- Fields with their own `toolbar([...])` keep exactly their buttons; add `textAlign`, `direction`, `indent`, `outdent` or `clearFormatting` to offer the new ones.
- Browser tests that pressed End in the editor toolbar now land on Clear formatting (the last enabled button) instead of the embed button.

---

# Upgrading to Admin 0.12.0

Update with `composer require "hostkurd/flocms-admin:^0.12.0" -W`, then run `php flo view:clear` and reload open Admin pages. No migration or dependency change is needed.

- Create and Edit now open as full pages. To keep the dialog for a resource, add `protected static string $formMode = 'modal';` (or `'slide-over'`).
- Full-page forms: the default submit (Enter, or the first submit button) now saves and **stays** on the record; **Save and close** (`_close`) returns to the list. Scripts that posted the form and expected the list should send `_close=1`. Browser tests that clicked "Save changes" on a page click "Save and close" (or "Save") now; edit links carry `?back=…`, so match them with `a[href^=".../edit/8?"]`.
- Pages now use the full window width. Add `'content_width' => 'boxed'` to `config/admin.php` for the previous centred layout.

---

# Upgrading to Admin 0.11.0

Update with `composer require "hostkurd/flocms-admin:^0.11.0" -W`, then run `php flo view:clear` and reload open Admin pages. Composer ships `admin-v0.11.0.css`/`.js` and `admin-editor-v0.11.0.js`; no Node build, migration, configuration or dependency change is needed.

- Browser tests or scripts that drove the editor's insert panels (`[data-insert=…]`, `[data-insert-file]`) need updating: links, images from a link and video links are now `<dialog data-rich-dialog="link|image|embed">`, the Image button opens a menu (`role="menu"`) when uploads are available, and Video opens the file picker directly. Upload messages are in `[data-rich-status-text]`.
- The "Loading…" box during navigation is gone; the `.fa-navigation-status` region still exists for screen readers and gets the class `is-busy` while loading. If your styles targeted it, check them.

---

# Upgrading to Admin 0.10.0

Update with `composer require "hostkurd/flocms-admin:^0.10.0" -W` (this also brings `hostkurd/flocms-reactive` 0.5.1), then run `php flo view:clear` and reload open Admin pages. Composer ships `admin-v0.10.0.css`/`.js` and `admin-editor-v0.10.0.js`; no Node build or migration is needed, and nothing changes until a resource uses `RichEditor`.

- To use rich text, replace `Textarea::make('body')` with `RichEditor::make('body')` (keep `->translatable()`). Existing plain text shows as one paragraph and is saved as HTML from then on; print the column with `{!! RichText::clean($value) !!}` on the website instead of escaping it.
- Image and video uploads need a public, writable folder. The default is `public/uploads/editor`, served at `/uploads/editor`; set `media` in `config/admin.php` if your web root differs, and run `php flo admin:doctor`. Images need PHP's GD or Imagick extension.
- If your website sends a Content-Security-Policy, allow embeds with `frame-src https://www.youtube-nocookie.com https://player.vimeo.com`.

---

# Upgrading to Admin 0.9.0

Update with `composer require "hostkurd/flocms-admin:^0.9.0" -W`, then run `php flo view:clear` and reload open Admin pages. Composer ships `admin-v0.9.0.css`/`.js` and the fonts in `resources/fonts/`; no Node build, migration or dependency change is needed.

- The panel looks different (new fonts, colours, spacing and focus ring). If your template overrides admin styles by selector, check them; prefer the new `--fa-*` custom properties (README, "Look and theming").
- Optional: add `'theme' => 'light'` (or `'dark'`, `'auto'`) to `config/admin.php`. Without it the panel stays light.
- The sidebar is now light. Add `'sidebar' => 'dark'` to keep a dark one, `'primary_color' => '#rrggbb'` for your brand colour and `'logo' => '/path/logo.svg'` for a logo (see the README).
- The topbar links (View site, your name, Log out) moved into the account menu; profile is "My profile" there.
- On phones, list rows are shown as cards; the card title is the first `searchable()` column. Make your title column searchable (it usually is) so cards are titled by it rather than by the ID.
- On a site with a single language, translatable fields are now plain inputs; stored values keep the same JSON shape.
- If a strict `font-src` Content-Security-Policy is set, allow `'self'`; the fonts are served from the site's own `/flo-admin/asset/` route.

---

# Upgrading to Admin 0.8.1

Update with `composer require "hostkurd/flocms-admin:^0.8.1" -W` and reload open Admin pages. Composer ships `admin-v0.8.1.css`/`.js` in place of the 0.8.0 files; no configuration, resource, database or dependency change is needed. The only visible change is that the panel no longer sits inside the browser's default 8 px page margin.

---

# Upgrading to Admin 0.8.0

Update after release with `composer require "hostkurd/flocms-admin:^0.8.0" -W`, then run `php flo view:clear` and `php flo admin:doctor`, and reload open Admin pages. Composer ships `admin-v0.8.0.css`/`.js`; no application Node build or manual asset publishing is required. Existing Core `^2.3.2`, Reactive `^0.4.0`, Uploader `^1.2.0` and PHP `^8.1` requirements are unchanged.

Widgets are opt-in. Existing configuration with no `widgets` entry, or `'widgets' => []`, keeps the original resource-count dashboard. To use widgets, add the explicit class list and optional `widgets_path`, `widgets_namespace` and `widget_columns` settings shown in [docs/widgets.md](docs/widgets.md), then create your own widget PHP classes. No migration, example database, application layout replacement or skeleton update is installed.

Use only registered source resources and the scoped read helpers for tenant-sensitive dashboard data. Widgets check all declared source view permissions before their providers run; optional widget permissions can narrow access. Providers must be read-only, because rendering/refresh repeats them. Do not put user- or tenant-specific results in shared caches. See the guide for supported widget types, bounds, failure handling and the normal GET refresh fallback.

---

# Upgrading to Admin 0.7.0

## 0.6 → 0.7

Update `hostkurd/flocms-admin` to `^0.7.0`. Composer adds the already-released `hostkurd/flocms-uploader ^1.2.0` (requires `ext-fileinfo`); Core stays `^2.3.2`, Reactive stays `^0.4.0`, PHP stays `^8.1`. No separate prerequisite release or Core/skeleton update is required.

Upload fields are opt-in: add nullable string/TEXT columns in your application migration and declare `FileUpload` / `ImageUpload` in the resource form. Keep `<root>/storage/admin-uploads` outside public web access, writable by PHP, backed up with the database, and configure multipart request limits. See [uploads.md](docs/uploads.md) for exact supported types/size/dimensions, editor-only download permissions, token expiry/quotas, and scheduled cleanup. No file table, filesystem symlink, application migration, public storage route or global uploader config is installed.

Managed file columns belong to CRUD: do not rewrite them in beforeSave/afterSave/custom callbacks or copy their values between records. `beforeSave` must preserve prepared upload columns. Deleting through Admin row/bulk actions also deletes managed files after commit; raw SQL and external application code remain responsible for their own file handling. Legacy external paths are retained but not served/deleted. Custom/bulk input forms cannot declare upload fields in this version.

Publish/distribute `dist/admin-v0.7.0.css` and `.js` together with this package and clear cached asset references. Existing resources without upload fields retain their form contracts. All standard POST form navigation now uses FormData to preserve binary file inputs.


---

# Upgrading to Admin 0.6.0

Release Reactive 0.4.0 before Admin 0.6.0. Then run `composer require hostkurd/flocms-admin:0.6.0 -W`, `php flo view:clear` and `php flo admin:doctor`, and reload open Admin pages. Admin now requires Reactive `^0.4.0`; PHP 8.1+ and Core `^2.3.2` remain unchanged. Deploy the supplied `admin-v0.6.0.js` / `.css` and matching Reactive assets. Full release-version filenames remain the convention. No Node build, package database migration, skeleton change or resource rewrite is required.

Existing flat resource definitions remain supported. Opt into `Form\Layout\Section`, `Grid`, `Tabs`, and `Tab`, with 1–4 columns and `columnSpan('full')`. Fields retain flat column names across all layouts and must be globally unique. Tab names must be unique within a group. Without JavaScript, every tab remains visible as a section.

Use `Select::optionsFor(parent, callback)`, `visibleWhen(parent, values)` and `requiredWhen(parent, values)` for dependent fields. Parent values must be scalar and non-password. Declare no cycles or unknown fields. Callbacks must be read-only and use scoped, bound database queries; they can run multiple times. Current options and requirements are checked again on save. Hidden values are not written; existing stored values remain on edit, and hidden create columns need database defaults/nullability. Resource permissions and query scope still provide authorization.

A live dependency change refreshes controls without saving or consuming the operation. Invalid child select values clear, unrelated drafts remain, and passwords are scrubbed after the response. Enter passwords after changing dependencies. With JavaScript disabled, use the Update fields button before choosing a child value. Existing transaction, stale-record, actor/CSRF and operation replay protections remain in effect for CRUD and bulk/custom actions.

The complete modal draft limit increases from 7,000 to **64,000 UTF-8 JSON bytes**, including keys, values and translations. Full-page fallback remains above the limit and does not transfer unsaved text. Reactive opted-in requests may use up to a 1 MiB HTTP envelope; allow sufficient PHP/web-server request-body size. Signed snapshots remain readable client data; do not put secrets in state. This release adds no repeaters, uploads, nested/object state or relationship managers.

See [Forms API and example](docs/forms.md). Application fields still need matching database columns; installing/updating Admin never automatically adds example Property columns. Existing optional login-theme configuration remains unchanged.

---

# Upgrading to Admin 0.5.0

Update the package after release with `composer require hostkurd/flocms-admin:0.5.0`, run `php flo view:clear` and `php flo admin:doctor`, then reload open Admin pages. Deploy supplied `admin-v0.5.0.js` and `admin-v0.5.0.css`; no Node build is needed on the application host. Core `^2.3.2`, Reactive `^0.3.0` and PHP 8.1+ remain unchanged. No package database migration or existing resource rewrite is required.

## Filters and bulk actions

Both features are opt-in resource definitions. Add `Table::filters()` and `Table::bulkActions()` as shown in the [API guide](docs/tables-and-login.md). Application columns such as status, featured or publication date must exist before you configure filters. No generated Property fields or schema are changed automatically.

Selections contain at most 50 explicit IDs, carry across pagination, and clear on search/sort/filter changes or page navigation. There is no select-all-matching workflow. Every record must pass current scope, permission, policy and stale-row checks; one failure rolls back all same-connection writes. Bulk callbacks own one transaction, with commit-only notification delivery and operation consumption. Do not dispatch inside an outer transaction or manually commit from a callback. External side effects require an outbox. Custom helpers do not run full resource save hooks automatically.

Action form callbacks receive a null record. Bulk actions always require review, and the existing 7,000-byte modal draft limit remains. Use `?static=1` for larger bulk forms or without JavaScript. Oversized drafts do not transfer automatically; copy unsaved text first. Filter state is a bounded JSON scalar in Reactive URLs, with equivalent standard form query parameters. Invalid filter values show errors and are not applied. Existing list/bookmark URLs remain usable.

## Enable the optional login theme

Existing `config/admin.php` files are preserved by the installer. Add:

```php
'login_theme' => true,
```

Skeleton 1.8.3 adds a presentation hook to `views/users/admin_login.html`. For an existing 1.8.2 application, prepend this to that view (leave the existing markup below it):

```php
<?php
$loginRenderer = \FloCMS\Core\Config::get('auth.login_view');
if (is_callable($loginRenderer)) {
    $loginMarkup = $loginRenderer();
    if (is_string($loginMarkup) && $loginMarkup !== '') {
        echo $loginMarkup;
        return;
    }
}
?>
```

Then clear compiled views. `admin:doctor` warns when the enabled theme is missing this hook. Updating Composer alone cannot update skeleton application views. For language metadata without JavaScript, also copy the skeleton 1.8.3 admin layout's dynamic `lang` and `dir` attributes if your layout still hardcodes English. Your authentication controller, CSRF checks, login throttling and session logic remain the same.

New `admin:install` configurations enable the theme, but the view hook is still needed on older skeletons. Set `'login_theme' => false` to keep your own design. An application-provided `auth.login_view` callable takes precedence; return null/empty to use the skeleton view. Without Admin/config, the skeleton view remains usable, with no Admin or Reactive dependency added to the skeleton.

---

# Upgrading to Admin 0.4.1

Update Admin with Composer after release and deploy its supplied `admin-v0.4.1.js` and `admin-v0.4.1.css`. Reload already-open Admin pages to load the updated client. No database migration, application resource change, or Core/Reactive dependency upgrade is required.

Create/Edit and custom action forms now show the same default-language input and language badge as full-page CRUD forms. Clicking the badge opens that field's translation editor. Save accepts the translations; Cancel/Escape restores the values from when it opened and returns focus to the badge without closing the resource editor. Secondary-language validation opens the affected translation dialog. The legacy sixth `FormRenderer` constructor argument remains accepted for compatibility but no longer selects expanded language inputs.

The normal "Open standard view" link is removed. Reactive dialogs and navigation remain the default. Existing full-page CRUD/action URLs, `?static=1`, and `'reactive' => false` still work, including with JavaScript disabled. The 7,000-byte modal draft limit is unchanged: an oversized form offers **Continue on full page**. Copy unsaved text before using that link; it does not transfer the draft. With JavaScript disabled, the resource list exposes the same fallback link through `<noscript>`.

Standalone FloCMS and its login page remain unchanged. This patch does not start the 0.5.0 filters/bulk-actions milestone.

---

# Upgrading to Admin 0.4.0

Update Admin with Composer after release. Core `^2.3.2`, Reactive `^0.3.0`, PHP 8.1+ and the existing secret `APP_KEY` requirements remain unchanged. No database migration or application resource rewrite is required. Deploy the supplied `admin-v0.4.0.js` and `admin-v0.4.0.css`; filenames keep the full release version and Composer distributions include compiled assets. Core and the skeleton remain independent of optional Admin/Reactive packages.

## Action definitions and compatibility

Existing resources receive Create, Edit and Delete definitions by default. Add custom actions through `Resource::headerActions()`, `Table::headerActions()` and `Table::actions()`. These definitions render in Reactive mode and the standard view. An overridden collection replaces the defaults, so include the built-in definitions when retaining those workflows. Removing the default Create/Edit/Delete definition also removes its standard CRUD route. Customized built-in names use the generic action route.

Action `visible()` and `authorize()` callbacks are server guards, not just presentation hints. A hidden or disallowed action cannot be invoked through a crafted request. Custom actions default to `Resource::permission($name)`; apply current record policies explicitly with `authorize()`. Keep tenant/record scope in `Resource::query()`. Built-in actions retain the existing resource policies, save service and hooks. Application controllers still take precedence.

Custom row, table and page actions use independent forms. Only declared writable fields reach a handler. Custom updates do not automatically run full-form `beforeSave()/afterSave()` hooks; invoke application business services explicitly where appropriate. Standard action forms have their own CSRF-protected, session-bound operation token and can be used when Reactive is disabled. Existing standard CRUD URLs retain their previous save semantics, plus guards from their default definitions.

## Transactions, stale pages and notifications

Action dispatch owns its request transaction. Do not wrap the dispatcher/App action request inside an already-open database transaction; it is rejected before callback execution. Use same-connection nested transactions inside an action callback when needed. This ensures successful notification delivery and operation consumption follow the actual action commit. Existing `FormSubmission` savepoint semantics remain intact.

Complete-row fingerprints and locks reject stale/deleted/out-of-scope row actions before their callbacks run. `ActionContext::update()` and `delete()` provide guarded mutation helpers. Locks do not fire extra UPDATE triggers. Transactions cannot undo external deliveries, nontransactional tables or work on other connections. Existing hook/outbox guidance below still applies.

Notifications sent inside action execution are staged and discarded on failure. Success/info announce politely; warning/danger announce as alerts. Messages persist through panel navigation until dismissed, with the latest 10 retained; they are not a durable inbox. Core flash messages map to the same presentation. Custom CSS selectors targeting the old flash banner or per-component success banner should migrate to `.fa-notification` / `[data-admin-notification]`; stable delivery containers must not be removed by application scripts.

Reload pages left open across deployment: the component schema and operation store now include action context, so old 0.3 snapshots are rejected with 409. Renewing the CSRF session or changing the signed-in actor invalidates outstanding action operations. Operation expiry remains 30 minutes/32 outstanding operations per session. The 7,000-byte modal draft limit remains; standard action pages support larger forms and still navigate asynchronously. Active draft values are retained on validation/conflict errors, with password values scrubbed from responses. Requests do not automatically retry writes.

`'reactive' => false` still restores standard forms/navigation. No nested state, upload API, bulk selection, table filters or general Widgets are introduced here. See the [Actions and Notifications guide](docs/actions-and-notifications.md) for the public API, working example and test commands.

---

# Upgrading to Admin 0.3.0

After release, update `hostkurd/flocms-admin` with Composer and its dependencies. Admin requires Core `^2.3.2` and Reactive `^0.3.0`. No database migration is required. The dependency direction is Admin → Reactive → Core; standalone Core and the skeleton remain independent.

## Deployment and configuration

- Configure a stable, secret `APP_KEY` of at least 32 decoded bytes, or `Config::set('reactive.key', ...)`. Reactive also accepts `base64:` keys. All application workers must use the same key. Rotating it invalidates open component snapshots.
- Keep the skeleton's current `auth.user_loader` configured. Reactive calls Core's `Auth::refresh()` on each update; its refresh interval determines when account/role changes are reloaded. Resource permissions, scoped queries, `canCreate()`, `canEdit()` and `canDelete()` run again during actions.
- Allow the normal language-prefixed `/flo-reactive/update`, `/flo-reactive/asset/...` and `/flo-admin/asset/...` routes through your existing front controller. Preserve application controller precedence and do not create controllers that shadow these package routes.
- Deploy the supplied `dist/admin-v0.3.0.js` and `dist/admin-v0.3.0.css` through Composer. Filenames retain the complete release version; immutable caching applies only to these assets. The package supplies external asset tags; no Node process or build is needed on cPanel.
- The built-in UI works with `script-src 'self'` and `style-src 'self'`, without inline scripts or unsafe evaluation. Custom field renderers and the application's layout remain responsible for their own CSP compliance.

`config/admin.php` defaults to `'reactive' => true`. Existing resource definitions need no new base class. Set `'reactive' => false` to restore full-page forms/navigation; this does not uninstall the Composer dependency. Removing Admin still leaves Core and the site's own controllers usable.

## Interaction and form limits

Resource search/sort/page state is namespaced in URLs (`admin-resource_q`, `_page`, `_sort`, `_dir`). Bookmarks and back/forward restore lists by fetching current server state. Old `q/page/sort/dir` links remain supported by the standard view (`?static=1`). Panel navigation covers built-in dashboard, resources, accounts and profile, including standard form submissions. Logout/login, external links and application-owned controllers retain normal navigation.

Modal drafts encode declared form fields in one bounded JSON scalar, because Reactive 0.3 supports scalar state. The modal limit is 7,000 UTF-8 bytes, including field names and translations. Use **Open standard view** before editing larger records; standard form pages also submit without reloading while Reactive navigation is enabled. If a draft grows past the limit, it stays in the dialog for copying, but cannot be saved there. Standard pages remain usable with JavaScript disabled. This release does not add nested components, dependent-field RPCs, uploads or stacked dialogs.

Dialog operations expire after 30 minutes, with up to 32 outstanding operations per session. Save/delete consume the operation after a successful transaction; replaying it is rejected. Cancellation discards the server draft and stays locally available after permission, CSRF or network failures. It cannot undo a save already sent. Requests are not automatically retried. After a lost save response, cancel/reopen to check the persisted result before trying again.

## Concurrent edits and hooks

Modal edit/delete snapshots seal a fingerprint of the complete loaded row. The action reloads the record through `Resource::query()`, checks current record policy, then conditionally writes only if all original columns still match. A deleted, changed or newly out-of-scope record cannot be overwritten. Case-only changes are detected even under case-insensitive database collations. No new version column is needed. This guard applies to modal actions; standard pages retain their existing save semantics.

Keep row/tenant scope in `Resource::query()` and record rules in `canEdit()` / `canDelete()`. Snapshot state is signed, not encrypted: do not place secrets in custom component state. Password controls are blank after responses and password drafts never enter returned snapshots.

Normalization, field/resource validation, dehydration and hooks are shared with standard forms. Database writes from save hooks on the same connection roll back together. `afterSave()` is still before commit. A `beforeSave()` hook must return changes for the active record rather than writing that same row itself, which would invalidate the optimistic guard. Hooks must not commit manually. External side effects and other connections cannot be rolled back; use an outbox for external deliveries.

Read the [working integration guide](docs/reactive-admin.md) for development commands and a runnable resource example. The 0.2.1 upgrade details below still apply, including the limits of validation-only uniqueness.

---

# Upgrading to Admin 0.2.1

Update `hostkurd/flocms-admin` with Composer after the release. This patch is tested with released Core 2.3.2; Core's existing `^2.3` constraint remains unchanged. No database migration, new runtime dependency or application asset build is required. Admin remains optional; Core and the skeleton do not require Admin or Reactive. Reactive integration is deferred to 0.3.0. Existing assets are unchanged; full release-version filenames remain the convention for compiled assets.

## Normalize before validating

The save sequence is field input extraction → field `prepare()` → resource `normalizeState()` → skip rules → field validation → resource `validate()` → transactional dehydration/hooks/write.

Override `normalizeState(array $state, ?object $record): array` to derive or normalize user-editable values. Return the complete state, using strings or language-to-string arrays. Do not write to the database, deliver messages, or hash passwords here. Normalized values pass all field rules and uniqueness checks before saving. Keep trusted storage-only metadata in `beforeSave()`; changes to editable values in that late hook are not automatically revalidated.

Accounts and profiles now derive blank login from email before uniqueness validation. An unchanged login excludes the current account correctly. Blank passwords on edit retain the stored hash; new passwords are validated before hashing and never echoed back after an error. Profile email/password changes still require the current password.

## Existing duplicate logins

This fix prevents the blank-login validation bypass; it does not repair existing data or add a unique index. On a backup or through a read-only connection, audit the skeleton users table:

```sql
SELECT login, COUNT(*) AS occurrences
FROM users
GROUP BY login
HAVING COUNT(*) > 1;
```

Review each group and assign appropriate distinct logins with the account owners. Do not automatically delete or merge accounts. If database-enforced uniqueness is required, first resolve duplicates and review login collation, blank values, column size and existing application write paths before planning a unique-index migration. Validation alone cannot guarantee uniqueness under concurrent writes; this limitation also applies to translatable fields.

## Translatable uniqueness and preservation

`->translatable()->unique()` checks each nonempty submitted translation against the **same language** on other rows. Equality is exact and case-sensitive on SQLite and MySQL/MariaDB; equal text in different languages is allowed. Legacy plain-text columns count only as the default language. Empty optional translations do not trigger uniqueness checks. The current record is excluded on edit.

`FormValidator` uniqueness callbacks may accept a third `?string $language` argument (`null` for ordinary fields). Existing two-argument callbacks continue working in PHP, but custom callbacks must use the language argument to implement translated uniqueness. `RecordRepository::exists()` accepts the optional fourth language argument. Custom translation stores supply the per-language scalar SQL expression through the existing `sortExpression()` contract; values remain parameter-bound.

Saving a translation field retains stored valid language keys that are no longer configured. Posted unconfigured keys cannot replace these retained values. The form remains a full submission for configured languages: blank or omitted configured translations are cleared. Removing a language from configuration is no longer a way to delete its stored content; use an explicit application migration if deletion is intended.

## Save hooks and transactions

Resource dehydration, `beforeSave()`, the insert/update, and `afterSave()` now run in one transaction on the resource's Core database connection. `afterSave()` means **after the write, before commit**. Exceptions propagate and roll back the record and transactional database work performed by either hook on that same connection. An outer Core transaction is respected through savepoints; a resource save does not commit the caller's transaction.

Use transactional tables (for example InnoDB). DDL, nontransactional tables, separate database connections, filesystem changes, emails and HTTP deliveries are not undone by database rollback. Hooks must not manually commit the connection. Put external delivery requests in a transactional outbox and process them after commit; do not treat `afterSave()` as an after-commit notification hook.

Read-only fields, including Placeholder callbacks, now receive the original record again after failed edit validation. Editable controls retain the submitted draft.

## Test databases

`vendor/bin/phpunit` runs SQLite coverage without database configuration; MySQL-specific tests skip cleanly. Set `FLO_ADMIN_TEST_DB_HOST`, `FLO_ADMIN_TEST_DB_PORT`, `FLO_ADMIN_TEST_DB_NAME`, `FLO_ADMIN_TEST_DB_USER`, and `FLO_ADMIN_TEST_DB_PASS` to run MySQL/MariaDB repository and full request tests. Use a dedicated disposable database: fixtures drop/recreate `posts`, `tags` and `users`. CI exercises PHP 8.1–8.4 with MySQL 8.0 and PHP 8.3 with MariaDB 10.6/11.4.
