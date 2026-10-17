# Media library

Admin 0.17 adds a media library: one place for the site's images and videos. Editors upload a file once, give it a title and an alt text per language, and pick it in any form or in the rich text editor. The library knows where each item is used, so an item in use can't be deleted by mistake.

Admin 0.18 adds **folders**, a file-manager-like Media page and picker (selection, batch actions, a details panel), **focal points**, a **duplicate check**, and media fields drawn as framed cards with drag and drop.

The library is generic: its tables are called `media` and `media_usage`, not `admin_*`, because the website and apps read them too.

## Setting it up

The library is on by default (`'media_library' => true` in `config/admin.php`). It needs its tables:

```bash
php flo admin:install   # writes *_create_media_table.php and (0.18) *_create_media_folders_table.php; never overwrites
php flo migrate
php flo admin:doctor    # checks the tables, the folder, GD and the permissions
```

The 0.18 migration creates `media_folders` and adds `folder_id`, `hash`, `focal_x` and `focal_y` to `media`. Until the tables (and, after an update from 0.17, the 0.18 migration) exist, the panel works as before: no Media link, and media fields say "The media library is not set up" and keep the ids already stored when the record is saved. The same holds with `'media_library' => false`.

Files are stored with flocms-uploader in the public media folder (`media` in `config/admin.php`, `public/uploads/editor` by default), under `library/<year>/<month>/`:

- **Images** (JPEG, PNG, WebP, GIF): a web version of at most 1920 px and a 400 px thumbnail in `thumb/`. The uploaded original is not kept.
- **Videos** (MP4, WebM): stored as uploaded, sent in 1 MB parts so large files and slow connections work.

### Options

```php
'media_library' => [
    'table' => 'site_media',             // default 'media'
    'usage_table' => 'site_media_usage', // default 'media_usage'
    'folders_table' => 'site_media_folders', // default 'media_folders' (0.18)
    'max_image_kb' => 10240,             // default 10 MB, at most 64 MB
    'max_video_mb' => 200,               // default 200 MB, at most 4 GB
],
```

`false` switches the library off. Rename the tables **before** the first migration, for example when the project already has a `media` table of its own: the migration stops with a message when it finds a table with that name that isn't the library's.

PHP's own limits (`upload_max_filesize`, `post_max_size`) still apply to images. New videos come in 1 MB parts, so only the limit above counts for them; **Replace file** for a video, and uploads on a page without JavaScript, send the whole file at once, so PHP's limits apply there too.

## The Media page

**Content → Media** (`/admin/media`) works like a file manager: folders on the start side, the items in the middle, the details of the selected item on the end side.

- **Header**: the number of items, images and videos, the space they use, and how many images have **no alt text** (click it to list them). **New folder** and **Upload**.
- **Upload**: the button, or drop files anywhere on the items. Files go into the open folder, one after another, in an upload list in the corner with progress, Cancel and Retry. Videos are sent in 1 MB parts.
- **Duplicates**: a file whose content is already in the library (same SHA-256 fingerprint) is held, and the upload list asks: **Use “…”** (the existing item; the new copy is dropped) or **Upload anyway**. Media fields and the picker use the existing item without asking.
- **Search** by file name, title or alt text, filter by type and status (**Not used**, **Without alt text**), sort (newest, oldest, by name, largest), grid or list view. A search looks in all folders; tick **This folder only** to stay in the open one. The address keeps the search, so a page can be bookmarked or shared.
- **Selecting**: click an item; Ctrl/⌘+click adds or removes one, Shift+click selects a range; the tick on each tile does the same on touch screens. With the keyboard: arrows move, Space selects, Shift+arrows extend, Ctrl/⌘+A selects all, Enter goes to the details, Delete deletes.
- **Batch actions** for the selection: **Move to folder**, **Edit details**, **Copy addresses**, **Delete** (items in use are kept, and the message says how many).
- **Edit details** steps through the selected items one by one ("3 of 8"): title, alt text in every language, folder and focal point, with **Save and next** (Ctrl/⌘+Enter). It shows how many have alt text, and can copy the last title into empty ones.
- **Details panel**: the preview, **Focal point**, **Replace file**, copy the address, the item's own page; the title, the alt text per language, the folder; dimensions, size, who uploaded it and when; and **Used in**: every record and settings page that uses the item, with links.
- **Replace file** keeps the item (its id, title and alt text) and swaps the file, so every page that uses it shows the new one. An image must be replaced by an image. Items shown inside rich text can't be replaced, because the text holds the file's address: upload a new item and change the text instead.
- **Delete** is only possible when the item is not used anywhere.

Each item also has its own page (`/admin/media/edit/<id>`) with the same details.

Without JavaScript the page still works with plain links and forms: folders, search and filters, upload, a folder form (create, rename, delete), and boxes with **Move** and **Delete selected**.

### Folders

Folders are categories shown as folders: an item is in one folder or in **Unfiled**, and moving it changes nothing on disk (its address stays the same). Folders can be nested up to four levels.

- **New folder** creates one in the open folder. The ⋯ next to the open folder: **New folder inside**, **Rename**, **Move** (to the top level or into another folder) and **Delete**.
- Drag items onto a folder to move them (all selected items when the dragged one is selected), or drag a folder onto another.
- Deleting a folder deletes no file: what was in it (items and folders) moves up one level; a folder whose name is already taken there gets " (2)".
- Names are unique among the folders of one parent, up to 120 characters, without "/".

### Focal point

An image's focal point is the spot that must stay in view when the image is cropped. Set it with **Focal point** in the details panel or in **Edit details**: click the picture (or move the point with the arrow keys; Shift moves further). On the website `img()` adds `object-position`, so CSS that crops with `object-fit: cover` keeps that spot; `focal()` gives the value (`"62% 40%"`) for your own markup.

### Permissions

| Ability | Permission | Allows |
| --- | --- | --- |
| view | `content.media.view` | the Media page, details, choosing items in fields |
| create | `content.media.create` | uploading (also from a field's picker or the rich text editor) |
| update | `content.media.update` | saving the title, alt text and focal point, replacing a file, folders and moving items |
| delete | `content.media.delete` | deleting unused items |

`content` is the `permission_prefix` from `config/admin.php`. `php flo admin:list --permissions` prints them with the others.

## Media fields

`MediaPicker` stores the chosen item's id in the resource's own table:

```php
use FloCMS\Admin\Form\Fields\MediaPicker;

MediaPicker::make('cover_id')->label('Cover'),                    // one image (the default kind)
MediaPicker::make('gallery')->label('Gallery')->multiple()->max(12),
MediaPicker::make('video_id')->label('Video')->videos(),
MediaPicker::make('attachment_id')->any(),                        // an image or a video
```

| Field | Column | Stored value |
| --- | --- | --- |
| single | `INT NULL` | the id, or `NULL` |
| `->multiple()` | `TEXT NULL` | a JSON list of ids in the chosen order: `["12","7","31"]`, or `NULL` |

The field is a framed card of its own:

- **Empty**: a drop area with **Choose from the library** and **Upload**.
- **One item**: a large preview with **Change**, **Edit details** and **Remove** (on hover, always on touch screens), the name, size and dimensions, and a **No alt text** badge that opens the details.
- **Several** (`multiple()`): numbered tiles. Drag a tile by its grip (mouse, finger or pen) to change the order, or focus it and use Alt + arrow keys; the ⋯ on each tile has **Edit details**, **Set focal point**, **Replace from the library**, **Move earlier/later** and **Remove**. An **Add media** tile ends the list until `max()` is reached.
- **Dropping files** on the field uploads them into the library and uses them. A file already in the library is not added twice: the existing item is used.

Two options shape it:

```php
MediaPicker::make('cover_id')->label('Cover')
    ->folder('Listings/Covers')   // uploads go to this folder (made when missing); the picker opens there
    ->aspectRatio('16:9'),        // the shape of the preview: "16:9", "4:3" (default for images), "1:1"…
```

A cover image fits well in the record page's side column: put the field in `Form::aside([...])` (see [Forms](forms.md)).

**Choose from the library** opens the picker: the same browser as the Media page (folders, search, filters, sort, upload) in a dialog. Click an item and **Choose** (or double-click it); with `multiple()`, click several and **Use selected (n)**; the chosen ones show in the footer. Items already in the field are marked **Added**, and the picker says how many more fit. Escape closes it without changes. Without JavaScript the field is a plain list of the newest 200 items.

On save the server checks that every id exists and has the right kind, and `->max()` (1 to 100; 100 without it) limits the number of items. If another editor deletes a chosen item while the form is open, the save is refused with a message on the field and nothing is written. `->required()` works as for any field.

### In lists

```php
use FloCMS\Admin\Table\Columns\MediaColumn;

MediaColumn::make('cover_id')->label('Cover'),
MediaColumn::make('gallery')->limit(3),   // the first three, then "+n"
```

The column loads every item of the page in one query and shows its thumbnail (a video icon for videos). It can't be sorted or searched.

## In the rich text editor

When the library is set up, the editor's **Image** menu has **Choose from the library**, and images and videos uploaded from the editor become library items too (their files go to `library/` like any other). The inserted image takes the item's alt text in the field's language.

An editor without `content.media.create` can still upload in the rich text editor (with the field's own permissions, as before 0.17): those files are stored for the field and don't join the library.

The library counts an item as used in rich text when the text contains its address, so these items show "Used in" and can't be deleted either.

## On the website

```php
$cover = media($listing->cover_id);           // a MediaItem, or null
echo $cover?->img(['class' => 'cover']);       // <img src alt width height srcset sizes loading="lazy" decoding="async">

foreach (media_list($listing->gallery) as $photo) {
    echo $photo->img();
}

echo media($listing->video_id)?->video(['poster' => '/img/poster.jpg']);   // <video controls preload="metadata" playsinline>
```

- `media($id)` and `media_list($ids)` accept what the field stored (an id, a JSON list, an array). Missing or deleted items are left out, and both return nothing when the library is off.
- `media_preload([$a->cover_id, $b->cover_id, $a->gallery])` loads many items in one query before a loop, so a list of 30 records costs one query instead of 30.
- A `MediaItem` has `id`, `kind`, `title`, `width`, `height`, `mime`, `bytes`, `fileName`, and:
  - `url()` (the web version), `url('thumb')` (the thumbnail; for videos and items without one, the web version);
  - `alt()` in the current language (falling back to the default language), `alt('ar')`, `altValues()`;
  - `isImage()`, `isVideo()`, `label()` (the title, else the file name);
  - `img($attributes)`, `video($attributes)`, `html($attributes)` (whichever fits). Attributes are escaped, extra ones are added, and `on*` attributes are dropped.

The page's `img()` adds `srcset` with the thumbnail, so phones can load the small file. Set your own `sizes` when the image is shown smaller than the page: `->img(['sizes' => '(max-width: 600px) 100vw, 300px'])`. With a focal point it also adds `style="object-position: …"` (pass `'style' => null` to leave it out).

A folder can feed a part of the site, so editors change it from the Media page without touching a record:

```php
foreach (media_folder('Homepage slider') as $slide) {   // items directly in that folder, by name
    echo $slide->img(['class' => 'slide']);
}
media_folder('Listings/Villas', 'newest', 12);          // a path from the top; 'name', 'newest', 'oldest' or 'largest'
```

A folder is found by its path from the top, or by a name only one folder has; case does not matter. An unknown folder gives an empty list. A `MediaItem` also has `folderId`, `focalX` and `focalY`.

## Existing files and usage

```bash
php flo admin:media-import --dry-run   # list what would be added
php flo admin:media-import             # add the files already in the media folder
php flo admin:media-usage              # rebuild "Used in" from every record and settings page
```

`admin:media-import` adds the images and videos already in the media folder (for example those uploaded by the editor before 0.17), skipping `thumb/` folders and files the library already has, and then rebuilds the usage. Imported images keep their files as they are (no new web version or thumbnail is made). Since 0.18 it also adds the fingerprints of items uploaded before 0.18, so the duplicate check knows them.

Usage is kept up to date by every save and delete in the panel. Run `admin:media-usage` after changing records outside the panel (an import script, SQL by hand), after adding a media field to a resource that already has records, or after removing a field, a resource or a settings page (their old usage then goes away).

## Limits

- The library holds images and videos; documents (PDF and others) stay with `FileUpload` fields.
- An item is in one folder; there are no tags. Folders exist only in the database, not on disk.
- The duplicate check compares the uploaded file's content: the same picture saved again by an editor (another size or quality) is a different file.
- Usage covers the panel's resources and settings pages. If the website or an app stores media ids elsewhere, the library doesn't know, so check before deleting.
