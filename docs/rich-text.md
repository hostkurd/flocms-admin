# Rich text (0.10; dialogs 0.11; layout tools 0.13)

`RichEditor` is a visual editor (bold, headings, lists, links, quotes, code) with images, videos and YouTube/Vimeo embeds. It stores **clean HTML** in a TEXT column, or one HTML value per language as JSON when translatable, exactly like `Textarea`.

```php
use FloCMS\Admin\Form\Fields\RichEditor;

RichEditor::make('body')->translatable()->required(),
RichEditor::make('summary')->toolbar(['bold', 'italic', 'link', 'bulletList'])->nullable(),
RichEditor::make('content')->maxImageSize(4096)->maxVideoSize(300)->embeds(['youtube']),
```

| Method | Default | Meaning |
| --- | --- | --- |
| `toolbar([...])` | all | Buttons, in order: `heading2`, `heading3`, `bold`, `italic`, `underline`, `strike`, `link`, `bulletList`, `orderedList`, `outdent`, `indent`, `textAlign`, `direction`, `blockquote`, `codeBlock`, `horizontalRule`, `image`, `video`, `embed`, `clearFormatting`, `undo`, `redo` |
| `images(false)` | on | No images. Without the `image` button images are off too |
| `videos(false)` | on | No uploaded videos |
| `embeds(['youtube'])` | YouTube, Vimeo | Video sites that can be embedded from a link; `false` for none |
| `maxImageSize(kib)` | 5120 | Largest image upload, at most 16384 KiB |
| `maxVideoSize(mib)` | 200 | Largest video upload, at most 2048 MiB |
| `maxLength(bytes)` | 256 KiB | Largest saved HTML per language, at most 1 MiB |

`required()` works as expected: a body with only empty paragraphs is empty. `min`/`max` rules would count HTML characters, so use `maxLength()` instead. The field spans the full form width.

## Languages

A translatable editor shows small language tabs above the toolbar (**EN · AR · KU**), each with the same filled/empty dot as other translatable fields; a red dot marks a language with an error. Each language has its own editor and undo history, and Arabic and Kurdish tabs write right to left. With one site language there are no tabs.

## What is saved

The browser sends HTML, and the server cleans it again with `FloCMS\Admin\Support\RichText::clean()` before validation and saving, whatever the browser sent. Only these survive:

- `p`, `h2`–`h4` (`h1` becomes `h2`), `strong`, `em`, `u`, `s`, `br`, `hr`, `ul`, `ol`, `li`, `blockquote`, `pre`, `code`;
- links to `http(s)://`, `mailto:`, `tel:`, `/paths` and `#anchors`; "open in a new tab" adds `rel="noopener noreferrer"`;
- images and videos from `http(s)://` or `/paths` (videos: `.mp4`, `.webm` only), with `loading="lazy"` and `controls`;
- embeds as `<div data-embed="youtube" data-id="…">` around an iframe that the server builds from the validated video ID (`youtube-nocookie.com`, or `player.vimeo.com` with `dnt=1`), lazily loaded and sandboxed.

- since 0.13, presentation classes from the package's `rich-text.css` and nothing else: on images, videos and embeds one width `rt-w-10` … `rt-w-100` (5 % steps) and one position `rt-align-left|center|right` or `rt-wrap-left|right`; on paragraphs and headings `rt-text-left|center|right|justify` and `rt-indent-1` … `rt-indent-4`; `dir="rtl|ltr"` on paragraphs, headings, quotes, lists and list items;
- images with a caption or a link as `<figure class="rt-figure …"><a href="…"><img …></a><figcaption>text</figcaption></figure>` (one image, a checked link, a plain-text caption).

Everything else is removed: scripts, styles, event handlers, `style`/`id` attributes and any other class, forms, other iframes, `javascript:` and `data:` URLs. Content is therefore safe to print on a public page. Print it unescaped, and clean it again if the column can be written by anything other than the admin:

```html
<article class="rt-content">{!! \FloCMS\Admin\Support\RichText::clean($post->body) !!}</article>
```

### Showing sizes, positions and captions on the website (0.13)

The classes above only take effect with the package's stylesheet. Add it once to the layout's `<head>`:

```html
{!! \FloCMS\Admin\Support\RichText::stylesheet() !!}
```

It links `/flo-admin/asset/rich-text-v<version>.css` (about 2 KB, cached, classes only, so it works under a strict Content-Security-Policy). You can also copy `vendor/hostkurd/flocms-admin/resources/assets/rich-text.css` into your own CSS. Put content in an element with class `rt-content`, so text wrapped around an image ends inside it. Embeds keep a 16:9 frame at any width. On phones (600 px and narrower) wrapped images stand on their own line at full width. Left and right are as on screen, also in right-to-left text.

`RichText::plain($html, 160)` gives a plain-text excerpt (for list columns, meta descriptions and search).

## Images and videos

Uploads go through **flocms-uploader** to a **public** folder, because the website shows them:

```php
// config/admin.php
'media' => [
    'root' => 'public/uploads/editor',   // relative to the project, inside the web root
    'url'  => '/uploads/editor',         // where the web server serves that folder
],
```

Files are stored as `<resource>/<field>/<yyyy>/<mm>/…` with random names, and content is checked by type, not by file name.

- **Images** (JPEG, PNG, GIF, WebP, up to 8192 px) are stored as one web version of at most 1920 px; the original is not kept. This needs PHP's GD or Imagick extension (`admin:doctor` checks it). An animated GIF becomes still.
- **Videos** (MP4, WebM) are sent in 1 MiB parts, so PHP's `upload_max_filesize` does not limit them. A part upload belongs to the user and session that started it; unfinished uploads expire after a day (`storage/admin-uploads/chunks`).
- Only people who may create or edit the resource can upload, and only into a `RichEditor` whose toolbar has that button. The endpoint is `POST /<lang>/admin/<resource>/media/<field>/<image|start|chunk|complete|abort>`, CSRF-protected.
- Uploaded files stay when content that used them is deleted; they are public files, and Admin cannot know whether another page links to them.

### Size and position (0.13)

Click an image, video or embed to select it. It shows **grips** on its corners and sides, and a small **toolbar** above it:

- **Left · Centre · Right**, and **Text wraps on the right / on the left** (the image floats and text flows beside it). Pressing the active one again takes it off.
- **25 % · 50 % · 75 % · 100 %** widths, or drag a grip: the width follows the pointer in 5 % steps of the text column and shows a "Width 60%" label; the height follows, so pictures never stretch.
- For images: **Edit image** (address, description, caption, link), **Replace image** (opens the file picker; keeps description, caption, link, size and position) and **Remove**.

The toolbar is one tab stop (arrow keys move inside it; Escape returns to the text).

### Paragraph tools (0.13)

- **Text alignment** menu: left, centre, right, justify. The language's own side is the default and stores no class.
- **Switch text direction**: the selected paragraphs become right-to-left in a left-to-right text (or the other way), for a Kurdish line in an English article. Stored as `dir`.
- **Increase / Decrease indent**: nests list items; indents paragraphs and headings up to four steps.
- **Clear formatting** (Ctrl/⌘+\\): removes bold, links and other marks, headings, alignment, indent and direction from the selection.
- Text pasted from **Word** or other office programs arrives clean: their hidden markup, styles and list bullets are dropped, while bold, italic, links, lists and centred or right-aligned paragraphs stay.

How people add media (0.11):

- **Image** opens a menu with **Upload an image** (the file picker opens at once) and **Image from a link** (a dialog for the address and description). Where uploads are not available, Image opens the link dialog directly.
- **Video** opens the file picker at once. **YouTube or Vimeo video** opens a dialog for the video page address.
- An image or video can also be **pasted or dropped** into the text.
- Double-click an image to change its address or description (alt text).
- Progress and errors show in the editor's footer, with **Cancel** while uploading.

Uploads need a create or edit form of the resource; elsewhere (custom action forms) only images by address are offered.

## In the browser

The editor is Tiptap 3, bundled as `admin-editor-v<version>.js` (about 126 KB gzipped) and loaded only on pages with an editor. It works under a strict Content-Security-Policy (`script-src 'self'; style-src 'self'`): no inline scripts or style elements. Since 0.13.1 an embed shows its real player in the editor (sandboxed, under a transparent shield so a click selects it); if your site sends a CSP, allow the embed hosts for both the admin and the website: `frame-src https://www.youtube-nocookie.com https://player.vimeo.com`.

Links, images from a link and YouTube/Vimeo links are entered in native modal dialogs above the form or record dialog (plain buttons, no nested `<form>`). The footer shows the word count of the current language; the field's `placeholder()` (default "Start writing…") shows in an empty editor.

Keyboard: the toolbar is one tab stop (arrow keys, Home, End move inside it; Down opens the Image menu); Ctrl/⌘+B, I, U and Z work in the text and Ctrl/⌘+K opens the link dialog; button tooltips show the shortcuts. In a dialog, Enter applies and Escape closes only that dialog, then focus returns to the text. Without JavaScript each language is a plain HTML textarea.

Toolbar icons are from Lucide (ISC licence), shown at 20 px with a 2 px stroke.

In Reactive dialogs the editor keeps its own state while the dialog updates (validation errors on other fields do not reset it or its undo history), using Reactive 0.5.1's `data-rx-ignore`. Very long content (over 64 KB for the whole form) continues on the full-page form, as for other large forms.
