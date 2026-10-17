# Media library

Admin 0.17 adds a media library: one place for the site's images and videos. Editors upload a file once, give it a title and an alt text per language, and pick it in any form or in the rich text editor. The library knows where each item is used, so an item in use can't be deleted by mistake.

The library is generic: its tables are called `media` and `media_usage`, not `admin_*`, because the website and apps read them too.

## Setting it up

The library is on by default (`'media_library' => true` in `config/admin.php`). It needs its two tables:

```bash
php flo admin:install   # writes the migration *_create_media_table.php (never overwrites)
php flo migrate
php flo admin:doctor    # checks the tables, the folder, GD and the permissions
```

Until the tables exist the panel works as before: no Media link, and media fields say "The media library is not set up" and keep the ids already stored when the record is saved. The same holds with `'media_library' => false`.

Files are stored with flocms-uploader in the public media folder (`media` in `config/admin.php`, `public/uploads/editor` by default), under `library/<year>/<month>/`:

- **Images** (JPEG, PNG, WebP, GIF): a web version of at most 1920 px and a 400 px thumbnail in `thumb/`. The uploaded original is not kept.
- **Videos** (MP4, WebM): stored as uploaded, sent in 1 MB parts so large files and slow connections work.

### Options

```php
'media_library' => [
    'table' => 'site_media',             // default 'media'
    'usage_table' => 'site_media_usage', // default 'media_usage'
    'max_image_kb' => 10240,             // default 10 MB, at most 64 MB
    'max_video_mb' => 200,               // default 200 MB, at most 4 GB
],
```

`false` switches the library off. Rename the tables **before** the first migration, for example when the project already has a `media` table of its own: the migration stops with a message when it finds a table with that name that isn't the library's.

PHP's own limits (`upload_max_filesize`, `post_max_size`) still apply to images. New videos come in 1 MB parts, so only the limit above counts for them; **Replace file** for a video, and uploads on a page without JavaScript, send the whole file at once, so PHP's limits apply there too.

## The Media page

**Content → Media** (`/admin/media`) shows every item as a grid, newest first:

- **Upload**: choose files or drop them on the grid. Each file shows its progress; the page reloads when they are done.
- **Search** by file name, title or alt text, filter by **Images** / **Videos**, or show only **Not used** items. The Not used view has boxes to delete several unused items at once.
- **Details** (click an item): the preview, size and dimensions, the address to copy, the **title** and the **alt text** (one per language, like any translatable field), and **Used in**: every record and settings page that uses the item, with links.
- **Replace file** keeps the item (its id, title and alt text) and swaps the file, so every page that uses it shows the new one. An image must be replaced by an image. Items shown inside rich text can't be replaced, because the text holds the file's address: upload a new item and change the text instead.
- **Delete** is only possible when the item is not used anywhere.

### Permissions

| Ability | Permission | Allows |
| --- | --- | --- |
| view | `content.media.view` | the Media page, details, choosing items in fields |
| create | `content.media.create` | uploading (also from a field's picker or the rich text editor) |
| update | `content.media.update` | saving the title and alt text, replacing a file |
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

The field shows the chosen items as thumbnails with **Change** and **Remove** (multiple: **Add media**, and the thumbnails can be dragged, or moved with their arrow buttons, into another order). **Choose** opens the picker: search, filter, upload a new file right there, and click an item to use it (with `multiple()`, click several, then **Use selected**). Without JavaScript the field is a plain list of the newest 200 items.

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

The page's `img()` adds `srcset` with the thumbnail, so phones can load the small file. Set your own `sizes` when the image is shown smaller than the page: `->img(['sizes' => '(max-width: 600px) 100vw, 300px'])`.

## Existing files and usage

```bash
php flo admin:media-import --dry-run   # list what would be added
php flo admin:media-import             # add the files already in the media folder
php flo admin:media-usage              # rebuild "Used in" from every record and settings page
```

`admin:media-import` adds the images and videos already in the media folder (for example those uploaded by the editor before 0.17), skipping `thumb/` folders and files the library already has, and then rebuilds the usage. Imported images keep their files as they are (no new web version or thumbnail is made).

Usage is kept up to date by every save and delete in the panel. Run `admin:media-usage` after changing records outside the panel (an import script, SQL by hand), after adding a media field to a resource that already has records, or after removing a field, a resource or a settings page (their old usage then goes away).

## Limits

- The library holds images and videos; documents (PDF and others) stay with `FileUpload` fields.
- Items are not organised in folders; search, the kind filter and the Not used view are meant to keep a large library usable.
- Usage covers the panel's resources and settings pages. If the website or an app stores media ids elsewhere, the library doesn't know, so check before deleting.
