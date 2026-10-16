# Rich text (0.10, editor reworked in 0.11)

`RichEditor` is a visual editor (bold, headings, lists, links, quotes, code) with images, videos and YouTube/Vimeo embeds. It stores **clean HTML** in a TEXT column, or one HTML value per language as JSON when translatable, exactly like `Textarea`.

```php
use FloCMS\Admin\Form\Fields\RichEditor;

RichEditor::make('body')->translatable()->required(),
RichEditor::make('summary')->toolbar(['bold', 'italic', 'link', 'bulletList'])->nullable(),
RichEditor::make('content')->maxImageSize(4096)->maxVideoSize(300)->embeds(['youtube']),
```

| Method | Default | Meaning |
| --- | --- | --- |
| `toolbar([...])` | all | Buttons, in order: `heading2`, `heading3`, `bold`, `italic`, `underline`, `strike`, `link`, `bulletList`, `orderedList`, `blockquote`, `codeBlock`, `horizontalRule`, `image`, `video`, `embed`, `undo`, `redo` |
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

Everything else is removed: scripts, styles, event handlers, `style`/`class`/`id` attributes, forms, other iframes, `javascript:` and `data:` URLs. Content is therefore safe to print on a public page. Print it unescaped, and clean it again if the column can be written by anything other than the admin:

```html
<article>{!! \FloCMS\Admin\Support\RichText::clean($post->body) !!}</article>
```

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

How people add media (0.11):

- **Image** opens a menu with **Upload an image** (the file picker opens at once) and **Image from a link** (a dialog for the address and description). Where uploads are not available, Image opens the link dialog directly.
- **Video** opens the file picker at once. **YouTube or Vimeo video** opens a dialog for the video page address.
- An image or video can also be **pasted or dropped** into the text.
- Double-click an image to change its address or description (alt text).
- Progress and errors show in the editor's footer, with **Cancel** while uploading.

Uploads need a create or edit form of the resource; elsewhere (custom action forms) only images by address are offered.

## In the browser

The editor is Tiptap 3, bundled as `admin-editor-v<version>.js` (about 126 KB gzipped) and loaded only on pages with an editor. It works under a strict Content-Security-Policy (`script-src 'self'; style-src 'self'`): no inline scripts or style elements, and embeds show as cards in the admin, so no third-party frames load there. On the website, allow the embed hosts if you set a CSP: `frame-src https://www.youtube-nocookie.com https://player.vimeo.com`.

Links, images from a link and YouTube/Vimeo links are entered in native modal dialogs above the form or record dialog (plain buttons, no nested `<form>`). The footer shows the word count of the current language; the field's `placeholder()` (default "Start writing…") shows in an empty editor.

Keyboard: the toolbar is one tab stop (arrow keys, Home, End move inside it; Down opens the Image menu); Ctrl/⌘+B, I, U and Z work in the text and Ctrl/⌘+K opens the link dialog; button tooltips show the shortcuts. In a dialog, Enter applies and Escape closes only that dialog, then focus returns to the text. Without JavaScript each language is a plain HTML textarea.

Toolbar icons are from Lucide (ISC licence), shown at 20 px with a 2 px stroke.

In Reactive dialogs the editor keeps its own state while the dialog updates (validation errors on other fields do not reset it or its undo history), using Reactive 0.5.1's `data-rx-ignore`. Very long content (over 64 KB for the whole form) continues on the full-page form, as for other large forms.
