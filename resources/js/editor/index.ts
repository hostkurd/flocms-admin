/*
 * The rich text editor (Tiptap), loaded on demand by admin.js only on pages
 * with a RichEditor field. It turns each language's <textarea> into a visual
 * editor and keeps the textarea filled with the HTML, so forms submit as
 * before. The server cleans that HTML again: nothing here is a security check.
 */
import { Editor, Node, mergeAttributes } from '@tiptap/core';
import type { DOMOutputSpec } from '@tiptap/pm/model';
import StarterKit from '@tiptap/starter-kit';
import { NodeSelection } from '@tiptap/pm/state';
import Image from '@tiptap/extension-image';
import { Placeholder, TrailingNode } from '@tiptap/extensions';
import { parseEmbed, type Embed as EmbedInfo } from './embeds';
import { uploadImage, uploadVideo, UploadError } from './uploads';
import { mediaView, mediaAttributes, mediaClasses, BlockFormat, cleanPastedHtml, type Position } from './media';

type Config = {
  toolbar: string[]; images: boolean; videos: boolean; uploads: boolean; embeds: string[];
  media: string; maxImage: number; maxVideo: number; csrf: string;
};
type Strings = Record<string, string>;

/** The <img> inside a figure, or the element itself. */
const imageOf = (element: HTMLElement): HTMLElement => (element.tagName === 'FIGURE' ? element.querySelector<HTMLElement>('img') ?? element : element);

/**
 * An image: <img class="rt-w-60 rt-align-center">, or with a caption or link
 * <figure class="rt-figure rt-w-60 …"><a href><img></a><figcaption>…</figcaption></figure>.
 */
function imageNode(strings: Strings) {
  return Image.extend({
    addAttributes() {
      return {
        ...this.parent?.(),
        src: { default: null, parseHTML: element => imageOf(element).getAttribute('src') },
        alt: { default: null, parseHTML: element => imageOf(element).getAttribute('alt') },
        title: { default: null, parseHTML: element => imageOf(element).getAttribute('title') },
        width: { default: null, parseHTML: element => imageOf(element).getAttribute('width'), renderHTML: attributes => (attributes.width ? { width: attributes.width } : {}) },
        height: { default: null, parseHTML: element => imageOf(element).getAttribute('height'), renderHTML: attributes => (attributes.height ? { height: attributes.height } : {}) },
        caption: { default: '', rendered: false, parseHTML: element => (element.tagName === 'FIGURE' ? element.querySelector('figcaption')?.textContent?.trim() ?? '' : '') },
        href: { default: null, rendered: false, parseHTML: element => (element.tagName === 'FIGURE' ? element.querySelector('a[href]')?.getAttribute('href') ?? null : null) },
        ...mediaAttributes(),
      };
    },
    parseHTML() {
      return [{ tag: 'figure', priority: 60, getAttrs: element => ((element as HTMLElement).querySelector('img[src]') ? {} : false) }, { tag: 'img[src]' }];
    },
    renderHTML({ node, HTMLAttributes }) {
      const classes = mediaClasses(node.attrs);
      const caption = String(node.attrs.caption ?? '').trim();
      const href = node.attrs.href as string | null;
      if (caption === '' && !href) return ['img', mergeAttributes(HTMLAttributes, classes.length ? { class: classes.join(' ') } : {})];
      const img: DOMOutputSpec = ['img', HTMLAttributes];
      return ['figure', { class: ['rt-figure', ...classes].join(' ') }, href ? ['a', { href }, img] : img, ...(caption ? [['figcaption', {}, caption] as DOMOutputSpec] : [])];
    },
    addNodeView() {
      return ({ node, view, getPos }) => mediaView({
        node, view, getPos: getPos as () => number | undefined, strings, caption: current => String(current.attrs.caption ?? ''),
        render: first => {
          const img = document.createElement('img');
          const update = (current: typeof first) => {
            img.src = String(current.attrs.src ?? '');
            img.alt = String(current.attrs.alt ?? '');
            if (current.attrs.width) img.width = Number(current.attrs.width); else img.removeAttribute('width');
            if (current.attrs.height) img.height = Number(current.attrs.height); else img.removeAttribute('height');
            img.draggable = false;
          };
          update(first);
          return { element: img, update };
        },
      });
    },
  }).configure({ inline: false, allowBase64: false });
}

function videoNode(strings: Strings) {
  return Node.create({
    name: 'video', group: 'block', atom: true, draggable: true, selectable: true,
    addAttributes() { return { src: { default: null }, ...mediaAttributes() }; },
    parseHTML() { return [{ tag: 'video[src]' }]; },
    renderHTML({ node, HTMLAttributes }) {
      const classes = mediaClasses(node.attrs);
      return ['video', mergeAttributes(HTMLAttributes, classes.length ? { class: classes.join(' ') } : {}, { controls: '', preload: 'metadata' })];
    },
    addNodeView() {
      return ({ node, view, getPos }) => mediaView({
        node, view, getPos: getPos as () => number | undefined, strings,
        render: first => {
          const video = document.createElement('video');
          video.controls = true; video.preload = 'metadata';
          const update = (current: typeof first) => { if (video.getAttribute('src') !== current.attrs.src) video.src = String(current.attrs.src ?? ''); };
          update(first);
          return { element: video, update };
        },
      });
    },
  });
}

/** The player address the server builds for a stored embed ID (see RichText::embed()). */
function playerUrl(provider: string, id: string): string {
  if (provider === 'youtube') {
    const [video, query] = id.split('?');
    return /^[A-Za-z0-9_-]{11}$/.test(video) ? `https://www.youtube-nocookie.com/embed/${video}${query && /^start=\d+$/.test(query) ? `?${query}` : ''}` : 'about:blank';
  }
  if (provider === 'vimeo') {
    const [video, query] = id.split('?');
    return /^\d{1,12}$/.test(video) ? `https://player.vimeo.com/video/${video}?dnt=1${query && /^h=[a-f0-9]{6,20}$/.test(query) ? `&${query}` : ''}` : 'about:blank';
  }
  return 'about:blank';
}

/** Stored as <div data-embed data-id>; the server adds the iframe. The editor shows the player under a shield. */
function embedNode(strings: Strings) {
  return Node.create({
    name: 'embed', group: 'block', atom: true, draggable: true, selectable: true,
    addAttributes() { return { provider: { default: null, rendered: false }, id: { default: null, rendered: false }, ...mediaAttributes() }; },
    parseHTML() {
      return [{ tag: 'div[data-embed]', priority: 60, getAttrs: element => ({ provider: (element as HTMLElement).dataset.embed, id: (element as HTMLElement).dataset.id }) }];
    },
    renderHTML({ node }) {
      const classes = mediaClasses(node.attrs);
      return ['div', { 'data-embed': node.attrs.provider, 'data-id': node.attrs.id, ...(classes.length ? { class: classes.join(' ') } : {}) }];
    },
    addNodeView() {
      return ({ node, view, getPos }) => mediaView({
        node, view, getPos: getPos as () => number | undefined, strings,
        render: first => {
          // The real player, as on the website, under a shield: a click selects the
          // embed (to size or move it) instead of starting the video.
          const dom = document.createElement('div');
          dom.className = 'fa-rich-player';
          const frame = document.createElement('iframe');
          frame.loading = 'lazy';
          frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
          frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
          frame.setAttribute('allow', 'encrypted-media; picture-in-picture');
          frame.tabIndex = -1;
          const shield = document.createElement('span');
          shield.className = 'fa-rich-player-shield';
          const label = document.createElement('span');
          label.className = 'fa-rich-player-label';
          dom.append(frame, shield, label);
          const update = (current: typeof first) => {
            const provider = String(current.attrs.provider ?? '');
            const src = playerUrl(provider, String(current.attrs.id ?? ''));
            dom.dataset.provider = provider;
            if (frame.getAttribute('src') !== src) frame.src = src;
            frame.title = label.textContent = strings[provider === 'vimeo' ? 'vimeo' : 'youtube'] ?? 'Video';
          };
          update(first);
          return { element: dom, update };
        },
      });
    },
  });
}

/** Same rules as the server: web, email and phone links, page paths and #anchors. */
function validLink(url: string): boolean {
  if (url.startsWith('/') && !url.startsWith('//')) return true;
  if (/^#[A-Za-z0-9_\-:.]{1,100}$/.test(url)) return true;
  if (/^mailto:[^\s<>"]{3,320}$/i.test(url) || /^tel:\+?[0-9 ()\-.]{3,32}$/i.test(url)) return true;
  try { const parsed = new URL(url); return ['http:', 'https:'].includes(parsed.protocol) && parsed.username === '' && parsed.password === ''; }
  catch { return false; }
}

function validMediaUrl(url: string): boolean {
  if (url.startsWith('/') && !url.startsWith('//')) return true;
  try { const parsed = new URL(url); return ['http:', 'https:'].includes(parsed.protocol) && parsed.username === ''; }
  catch { return false; }
}

/** Insert a block; when an image, video or embed is selected, put it after that one instead of replacing it. */
function insertBlock(editor: Editor, content: Record<string, unknown>): void {
  const { selection } = editor.state;
  const chain = editor.chain().focus();
  (selection instanceof NodeSelection ? chain.insertContentAt(selection.to, content) : chain.insertContent(content)).run();
}

function isEmpty(html: string): boolean {
  if (/<(img|video|iframe|hr)\b/i.test(html) || /data-embed=/.test(html)) return false;
  const template = document.createElement('template');
  template.innerHTML = html;
  return (template.content.textContent ?? '').replace(/ /g, ' ').trim() === '';
}

type DialogName = 'link' | 'image' | 'embed';

class RichField {
  private readonly config: Config;
  private readonly strings: Strings;
  private readonly editors = new Map<string, Editor>();
  private readonly toolbar: HTMLElement | null;
  private readonly statusBox: HTMLElement | null;
  private words: HTMLElement | null = null;
  private active = '';
  private openDialog: HTMLDialogElement | null = null;
  private editingImage = false;
  private upload: AbortController | null = null;
  private statusTimer = 0;
  private readonly mediaBar: HTMLElement | null;
  /** The image a Replace upload goes into (checked again when the upload ends). */
  private replacing: { pos: number; src: string } | null = null;
  private readonly listeners = new AbortController();

  constructor(private readonly root: HTMLElement) {
    this.config = JSON.parse(root.dataset.config ?? '{}');
    this.strings = JSON.parse(root.dataset.strings ?? '{}');
    this.toolbar = root.querySelector<HTMLElement>('[data-rich-toolbar]');
    this.statusBox = root.querySelector<HTMLElement>('[data-rich-status]');
    this.mediaBar = root.querySelector<HTMLElement>('[data-rich-mediabar]');
    // After a failed save Reactive focuses the invalid source textarea; remember it before hiding it.
    // When focus was lost on the way (another update ran first, the page blurred), the editor still
    // takes it if its source is the first invalid field and focus is not in another control.
    // Both are read before the editor adds its own (invalid) content area.
    const focused = document.activeElement;
    const scope = root.closest<HTMLElement>('dialog') ?? root.closest<HTMLElement>('form') ?? document.body;
    const firstInvalid = scope.querySelector('[aria-invalid="true"]:not([disabled])');
    const lost = !focused || focused === document.body || focused === scope || !focused.isConnected;
    const panels = [...root.querySelectorAll<HTMLElement>(':scope > .fa-rich-panel')];
    panels.forEach((panel, index) => this.createEditor(panel, index === 0));
    this.active = panels[0]?.dataset.lang ?? '';
    this.setUpTabs(panels);
    // A page-level "Editing: language" switch (settings, records, dialogs) chooses the language here too.
    const page = root.closest<HTMLElement>('[data-lang-scope].is-switching');
    if (page?.dataset.editing) this.selectTab(page.dataset.editing);
    root.addEventListener('flo-admin:editing-language', event => this.selectTab(String((event as CustomEvent).detail ?? '')), { signal: this.listeners.signal });
    this.setUpToolbar();
    this.setUpMenu();
    this.setUpMediaBar();
    this.setUpDialogs();
    this.setUpFiles();
    this.setUpFooter();
    root.classList.add('is-ready');
    // The field label names the editor and moves focus into it.
    const label = root.closest('.fa-field')?.querySelector<HTMLLabelElement>(':scope > .fa-label');
    label?.addEventListener('click', event => { event.preventDefault(); if (!root.closest('[data-lang-scope].is-switching')) this.selectTab(panels[0]?.dataset.lang ?? ''); this.current()?.commands.focus(); });
    // A failed save marked the source invalid and focused it: carry both over (see above).
    panels.forEach(panel => {
      const source = panel.querySelector<HTMLTextAreaElement>('[data-rich-source]')!;
      if (focused === source || (lost && firstInvalid === source)) { this.selectTab(panel.dataset.lang ?? ''); this.editorFor(panel)?.commands.focus('end'); }
    });
    this.refreshToolbar();
  }

  private key(panel: HTMLElement): string { return panel.dataset.lang ?? ''; }
  private current(): Editor | undefined { return this.editors.get(this.active); }
  private editorFor(panel: HTMLElement): Editor | undefined { return this.editors.get(this.key(panel)); }
  private has(command: string): boolean { return this.config.toolbar.includes(command); }

  private createEditor(panel: HTMLElement, first: boolean): void {
    const source = panel.querySelector<HTMLTextAreaElement>('[data-rich-source]');
    if (!source) return;
    const host = document.createElement('div');
    host.className = 'fa-rich-host';
    source.before(host);
    const label = this.root.closest('.fa-field')?.querySelector<HTMLLabelElement>(':scope > .fa-label');
    if (label && !label.id) label.id = `${source.id}-label`;
    const tab = this.root.querySelector<HTMLElement>(`[data-rich-tabs] [data-lang="${CSS.escape(this.key(panel))}"]`);
    const labelledBy = [label?.id, tab?.id].filter(Boolean).join(' ');
    const attributes: Record<string, string> = {
      class: 'fa-rich-content', role: 'textbox', 'aria-multiline': 'true', 'aria-labelledby': labelledBy, spellcheck: 'true',
    };
    ['dir', 'lang', 'aria-describedby', 'aria-invalid'].forEach(name => { const value = source.getAttribute(name); if (value) attributes[name] = value; });
    const editor: Editor = new Editor({
      element: host, injectCSS: false, editable: !source.disabled, content: source.value,
      extensions: [
        StarterKit.configure({
          heading: { levels: [2, 3, 4] },
          link: { openOnClick: false, autolink: true, defaultProtocol: 'https', HTMLAttributes: { target: null, rel: null } },
        }),
        Placeholder.configure({ placeholder: this.strings.placeholder ?? '' }),
        BlockFormat,
        // Always a paragraph after the last image or embed, so typing can continue below it.
        TrailingNode,
        ...(this.config.images ? [imageNode(this.strings)] : []), ...(this.config.videos ? [videoNode(this.strings)] : []),
        ...(this.config.embeds.length > 0 ? [embedNode(this.strings)] : []),
      ],
      editorProps: {
        attributes,
        handleKeyDown: (_view, event) => {
          // Ctrl/⌘+K opens the link dialog, as in most editors.
          if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key === '\\' && this.has('clearFormatting')) {
            event.preventDefault();
            this.clearFormatting();
            return true;
          }
          if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k' && this.has('link')) {
            event.preventDefault();
            this.openLinkDialog();
            return true;
          }
          return false;
        },
        transformPastedHTML: html => cleanPastedHtml(html),
        handleDoubleClickOn: (_view, _pos, node) => {
          if (node.type.name !== 'image' || !this.has('image')) return false;
          this.openImageDialog(true);
          return true;
        },
        handlePaste: (_view, event) => this.takeFiles(event.clipboardData?.files),
        handleDrop: (view, event, _slice, moved) => {
          if (moved) return false;
          const at = view.posAtCoords({ left: event.clientX, top: event.clientY });
          return this.takeFiles(event.dataTransfer?.files, at?.pos);
        },
      },
      onUpdate: () => { this.sync(panel, editor, source); this.countWords(); },
      onSelectionUpdate: () => this.refreshToolbar(),
      onTransaction: () => this.refreshToolbar(),
      onFocus: () => { this.active = this.key(panel); this.refreshToolbar(); this.countWords(); },
      onBlur: () => { requestAnimationFrame(() => this.refreshMedia()); },
    });
    source.hidden = true;
    this.editors.set(this.key(panel), editor);
    if (!first) panel.hidden = true;
  }

  /** Keep the submitted textarea and the language dot in step with the editor. */
  private sync(panel: HTMLElement, editor: Editor, source: HTMLTextAreaElement): void {
    // The editor keeps an empty paragraph at the end; it is not content (the server drops it too).
    const html = editor.getHTML().replace(/(?:<p>(?:<br>)?<\/p>)+$/, '');
    source.value = isEmpty(html) ? '' : html;
    source.dispatchEvent(new Event('input', { bubbles: true }));
    const lang = this.key(panel);
    const tab = this.root.querySelector<HTMLElement>(`[data-rich-tabs] [data-lang="${CSS.escape(lang)}"]`);
    const dot = tab?.querySelector<HTMLElement>('.fa-dot');
    if (dot) {
      dot.classList.remove('is-invalid');
      dot.classList.toggle('is-filled', source.value !== '');
      dot.classList.toggle('is-empty', source.value === '');
    }
    const state = tab?.querySelector<HTMLElement>('[data-rich-tab-state]');
    if (state) state.textContent = this.strings[source.value === '' ? 'empty' : 'filled'] ?? '';
  }

  private setUpTabs(panels: HTMLElement[]): void {
    const list = this.root.querySelector<HTMLElement>('[data-rich-tabs]');
    if (!list) return;
    list.hidden = false;
    this.root.querySelectorAll<HTMLElement>('[data-rich-lang-title]').forEach(title => { title.hidden = true; });
    panels.forEach(panel => panel.setAttribute('role', 'tabpanel'));
    const tabs = [...list.querySelectorAll<HTMLButtonElement>('[role="tab"]')];
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => { this.selectTab(tab.dataset.lang ?? ''); this.current()?.commands.focus(); });
      tab.addEventListener('keydown', event => {
        const rtl = getComputedStyle(list).direction === 'rtl';
        const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, Home: -index, End: tabs.length - 1 - index }[event.key];
        if (step === undefined) return;
        event.preventDefault();
        const next = tabs[(index + step + tabs.length) % tabs.length];
        this.selectTab(next.dataset.lang ?? '');
        next.focus();
      });
    });
  }

  private selectTab(lang: string): void {
    if (!this.editors.has(lang)) return;
    this.active = lang;
    this.root.querySelectorAll<HTMLElement>(':scope > .fa-rich-panel').forEach(panel => { panel.hidden = this.key(panel) !== lang; });
    this.root.querySelectorAll<HTMLButtonElement>('[data-rich-tabs] [role="tab"]').forEach(tab => {
      const selected = tab.dataset.lang === lang;
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
    });
    this.closeMenu(false);
    this.refreshToolbar();
    this.countWords();
  }

  /* Toolbar ----------------------------------------------------------- */

  private setUpToolbar(): void {
    const toolbar = this.toolbar;
    if (!toolbar) return;
    toolbar.hidden = false;
    const buttons = () => [...toolbar.querySelectorAll<HTMLButtonElement>('[data-command]')];
    buttons()[0]?.setAttribute('tabindex', '0');
    // Keep the editor's selection: toolbar clicks must not move focus first.
    toolbar.addEventListener('mousedown', event => { if ((event.target as Element).closest('[data-command], [data-rich-action]')) event.preventDefault(); });
    toolbar.addEventListener('click', event => {
      const button = (event.target as Element).closest<HTMLButtonElement>('[data-command]');
      if (button && !button.disabled) this.run(button.dataset.command!, button);
    });
    // One tab stop; arrow keys move between buttons.
    toolbar.addEventListener('keydown', event => {
      const list = buttons().filter(button => !button.disabled);
      const index = list.indexOf(document.activeElement as HTMLButtonElement);
      if (index < 0) return;
      if (event.key === 'ArrowDown' && list[index].getAttribute('aria-haspopup') === 'menu') { event.preventDefault(); this.openMenu(list[index]); return; }
      const rtl = getComputedStyle(toolbar).direction === 'rtl';
      const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, Home: -index, End: list.length - 1 - index }[event.key];
      if (step === undefined) return;
      event.preventDefault();
      const next = list[(index + step + list.length) % list.length];
      buttons().forEach(button => { button.tabIndex = button === next ? 0 : -1; });
      next.focus();
    });
  }

  private run(command: string, button: HTMLButtonElement): void {
    const editor = this.current();
    if (!editor) return;
    // Buttons that open a menu, a dialog or the file picker must not build a chain:
    // a chain moves focus to the editor at once, even before run().
    switch (command) {
      case 'link': this.openLinkDialog(); return;
      case 'embed': this.open('embed'); return;
      case 'video': this.pick('video'); return;
      case 'image':
        // Always adds an image (after a selected one); the media toolbar edits a selected image.
        if (button.getAttribute('aria-haspopup') === 'menu') { this.toggleMenu(button); return; }
        this.openImageDialog(false);
        return;
      case 'textAlign': this.toggleMenu(button); return;
      case 'direction': this.toggleDirection(); return;
      case 'indent': this.indent(1); return;
      case 'outdent': this.indent(-1); return;
      case 'clearFormatting': this.clearFormatting(); return;
    }
    const chain = editor.chain().focus();
    switch (command) {
      case 'heading2': chain.toggleHeading({ level: 2 }).run(); break;
      case 'heading3': chain.toggleHeading({ level: 3 }).run(); break;
      case 'bold': chain.toggleBold().run(); break;
      case 'italic': chain.toggleItalic().run(); break;
      case 'underline': chain.toggleUnderline().run(); break;
      case 'strike': chain.toggleStrike().run(); break;
      case 'bulletList': chain.toggleBulletList().run(); break;
      case 'orderedList': chain.toggleOrderedList().run(); break;
      case 'blockquote': chain.toggleBlockquote().run(); break;
      case 'codeBlock': chain.toggleCodeBlock().run(); break;
      case 'horizontalRule': chain.setHorizontalRule().run(); break;
      case 'undo': chain.undo().run(); break;
      case 'redo': chain.redo().run(); break;
    }
  }

  private refreshToolbar(): void {
    const editor = this.current();
    if (!editor || !this.toolbar) return;
    const active: Record<string, () => boolean> = {
      heading2: () => editor.isActive('heading', { level: 2 }), heading3: () => editor.isActive('heading', { level: 3 }),
      bold: () => editor.isActive('bold'), italic: () => editor.isActive('italic'), underline: () => editor.isActive('underline'),
      strike: () => editor.isActive('strike'), link: () => editor.isActive('link'), bulletList: () => editor.isActive('bulletList'),
      orderedList: () => editor.isActive('orderedList'), blockquote: () => editor.isActive('blockquote'), codeBlock: () => editor.isActive('codeBlock'),
    };
    this.toolbar.querySelectorAll<HTMLButtonElement>('[data-command]').forEach(button => {
      const command = button.dataset.command!;
      if (active[command]) button.setAttribute('aria-pressed', String(active[command]()));
      if (command === 'undo') button.disabled = !editor.can().undo();
      if (command === 'redo') button.disabled = !editor.can().redo();
      if (command === 'direction') {
        const dir = (editor.isActive('heading') ? editor.getAttributes('heading') : editor.getAttributes('paragraph')).dir;
        button.setAttribute('aria-pressed', String(Boolean(dir) && dir !== (this.isRtl() ? 'rtl' : 'ltr')));
      }
      if (command === 'outdent') {
        const indent = Number((editor.isActive('heading') ? editor.getAttributes('heading') : editor.getAttributes('paragraph')).indent) || 0;
        button.disabled = indent === 0 && !editor.isActive('listItem');
      }
      if (command === 'textAlign') {
        // The button shows the current alignment's icon; the menu marks it.
        const align = this.currentAlign(editor);
        const menu = document.getElementById(button.getAttribute('aria-controls') ?? '');
        menu?.querySelectorAll<HTMLElement>('[data-rich-action^="align-"]').forEach(item => item.setAttribute('aria-checked', String(item.dataset.richAction === `align-${align}`)));
        const icon = menu?.querySelector(`[data-rich-action="align-${align}"] svg`);
        const holder = button.querySelector<HTMLElement>('[data-rich-icon]');
        if (icon && holder && holder.dataset.align !== align) { holder.replaceChildren(icon.cloneNode(true)); holder.dataset.align = align; }
        button.classList.toggle('is-active', align !== (this.isRtl() ? 'right' : 'left'));
      }
    });
    this.refreshMedia();
  }

  /* Media options bar over a selected image, video or embed ---------------- */

  private selectedMedia(): { node: import('@tiptap/pm/model').Node; pos: number } | null {
    const selection = this.current()?.state.selection;
    return selection instanceof NodeSelection && ['image', 'video', 'embed'].includes(selection.node.type.name)
      ? { node: selection.node, pos: selection.from } : null;
  }

  private setUpMediaBar(): void {
    const bar = this.mediaBar;
    if (!bar) return;
    bar.addEventListener('mousedown', event => event.preventDefault());
    bar.addEventListener('click', event => {
      const button = (event.target as Element).closest<HTMLButtonElement>('[data-media]');
      const editor = this.current();
      const media = this.selectedMedia();
      if (!button || !editor || !media) return;
      const action = button.dataset.media!;
      const set = (attrs: Record<string, unknown>) => {
        const { tr } = editor.state;
        tr.setNodeMarkup(media.pos, undefined, { ...media.node.attrs, ...attrs });
        tr.setSelection(NodeSelection.create(tr.doc, media.pos));
        editor.view.dispatch(tr);
        editor.view.focus();
      };
      if (action.startsWith('align-') || action.startsWith('wrap-')) {
        const position = (action.startsWith('align-') ? action.slice(6) : action) as Position;
        set({ align: media.node.attrs.align === position ? null : position });
      } else if (action.startsWith('width-')) {
        const size = Number(action.slice(6));
        set({ size: media.node.attrs.size === size ? null : size });
      } else if (action === 'edit') this.openImageDialog(true);
      else if (action === 'replace') { this.replacing = { pos: media.pos, src: String(media.node.attrs.src ?? '') }; this.pick('image'); }
      else if (action === 'remove') editor.chain().focus().deleteSelection().run();
    });
    // One tab stop; arrow keys move between its buttons.
    bar.addEventListener('keydown', event => {
      const buttons = [...bar.querySelectorAll<HTMLButtonElement>('[data-media]')].filter(button => button.offsetParent !== null);
      const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.current()?.view.focus(); return; }
      if (index < 0) return;
      const rtl = getComputedStyle(bar).direction === 'rtl';
      const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, Home: -index, End: buttons.length - 1 - index }[event.key];
      if (step === undefined) return;
      event.preventDefault();
      buttons[(index + step + buttons.length) % buttons.length].focus();
    });
    const reposition = () => this.refreshMedia();
    // An image that finishes loading changes the layout under the bar.
    this.root.addEventListener('load', reposition, { capture: true, signal: this.listeners.signal });
    window.addEventListener('resize', reposition, { signal: this.listeners.signal });
    window.addEventListener('scroll', reposition, { signal: this.listeners.signal, passive: true, capture: true });
  }

  private refreshMedia(): void {
    const bar = this.mediaBar;
    if (!bar) return;
    const editor = this.current();
    const media = editor?.isFocused || bar.contains(document.activeElement) || this.openDialog ? this.selectedMedia() : null;
    const element = media && editor ? editor.view.nodeDOM(media.pos) as HTMLElement | null : null;
    if (!media || !element) { bar.hidden = true; return; }
    const kind = media.node.type.name;
    bar.hidden = false;
    bar.dataset.kind = kind;
    bar.querySelectorAll<HTMLElement>('[data-media-only]').forEach(item => { item.hidden = item.dataset.mediaOnly !== kind; });
    bar.querySelectorAll<HTMLButtonElement>('[data-media]').forEach(button => {
      const action = button.dataset.media!;
      const position = action.startsWith('align-') ? action.slice(6) : action.startsWith('wrap-') ? action : null;
      if (position) button.setAttribute('aria-pressed', String(media.node.attrs.align === position));
      if (action.startsWith('width-')) button.setAttribute('aria-pressed', String(media.node.attrs.size === Number(action.slice(6))));
      button.tabIndex = -1;
    });
    const first = [...bar.querySelectorAll<HTMLButtonElement>('[data-media]')].find(button => button.offsetParent !== null);
    if (first) first.tabIndex = 0;
    // Above the item, or over its top edge when the toolbar or the window edge is in the way.
    const box = this.root.getBoundingClientRect();
    const at = element.getBoundingClientRect();
    const toolbarBottom = this.toolbar?.getBoundingClientRect().bottom ?? box.top;
    let top = at.top - box.top - bar.offsetHeight - 8;
    if (at.top - bar.offsetHeight - 8 < Math.max(toolbarBottom, 0)) top = Math.max(at.top, toolbarBottom) - box.top + 8;
    const left = Math.max(4, Math.min(at.left - box.left + at.width / 2 - bar.offsetWidth / 2, box.width - bar.offsetWidth - 4));
    bar.style.top = `${top}px`;
    bar.style.left = `${left}px`;
  }

  private selectedImage(): Record<string, unknown> | null {
    const selection = this.current()?.state.selection;
    return selection instanceof NodeSelection && selection.node.type.name === 'image' ? selection.node.attrs : null;
  }

  /* Menus: Image (upload / from a link) and Text alignment -------------- */

  private openMenuEl: HTMLElement | null = null;

  private menuButton(menu: HTMLElement | null = this.openMenuEl): HTMLButtonElement | null {
    return menu ? this.toolbar?.querySelector<HTMLButtonElement>(`[aria-controls="${CSS.escape(menu.id)}"]`) ?? null : null;
  }

  private menuItems(menu: HTMLElement | null = this.openMenuEl): HTMLButtonElement[] {
    return [...(menu?.querySelectorAll<HTMLButtonElement>('[role^="menuitem"]') ?? [])];
  }

  private setUpMenu(): void {
    this.root.querySelectorAll<HTMLElement>('[data-rich-menu]').forEach(menu => {
      // Out of the toolbar, which may scroll sideways on phones and would clip the menu.
      this.root.append(menu);
      menu.addEventListener('mousedown', event => event.preventDefault());
      menu.addEventListener('click', event => {
        const item = (event.target as Element).closest<HTMLButtonElement>('[data-rich-action]');
        if (!item) return;
        const action = item.dataset.richAction!;
        this.closeMenu(false);
        if (action === 'image-upload') this.pick('image');
        else if (action === 'image-library') void this.insertFromLibrary();
        else if (action === 'image-link') this.openImageDialog(false);
        else if (action.startsWith('align-')) this.align(action.slice(6));
      });
      menu.addEventListener('keydown', event => {
        const items = this.menuItems(menu);
        const index = items.indexOf(document.activeElement as HTMLButtonElement);
        const move = { ArrowDown: 1, ArrowUp: -1, Home: -index, End: items.length - 1 - index }[event.key];
        if (move !== undefined) { event.preventDefault(); items[(index + move + items.length) % items.length]?.focus(); return; }
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.closeMenu(true); }
        if (event.key === 'Tab') this.closeMenu(false);
      });
    });
    document.addEventListener('pointerdown', event => {
      const menu = this.openMenuEl;
      if (menu && !menu.contains(event.target as globalThis.Node) && !this.menuButton()?.contains(event.target as globalThis.Node)) this.closeMenu(false);
    }, { signal: this.listeners.signal });
  }

  private toggleMenu(button: HTMLButtonElement): void {
    const menu = document.getElementById(button.getAttribute('aria-controls') ?? '');
    if (menu && this.openMenuEl === menu) { this.closeMenu(false); return; }
    this.openMenu(button);
  }

  private openMenu(button: HTMLButtonElement): void {
    const menu = document.getElementById(button.getAttribute('aria-controls') ?? '');
    if (!menu || !this.root.contains(menu)) return;
    this.closeMenu(false);
    this.openMenuEl = menu;
    menu.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    // Under the button, kept inside the editor's width.
    const box = this.root.getBoundingClientRect();
    const at = button.getBoundingClientRect();
    const rtl = getComputedStyle(this.root).direction === 'rtl';
    menu.style.top = `${at.bottom - box.top + 4}px`;
    if (rtl) { menu.style.right = `${Math.max(0, box.right - at.right)}px`; menu.style.left = 'auto'; }
    else { menu.style.left = `${Math.max(0, Math.min(at.left - box.left, box.width - menu.offsetWidth))}px`; menu.style.right = 'auto'; }
    const items = this.menuItems(menu);
    (items.find(item => item.getAttribute('aria-checked') === 'true') ?? items[0])?.focus();
  }

  private closeMenu(focusButton: boolean): void {
    const menu = this.openMenuEl;
    if (!menu) return;
    menu.hidden = true;
    const button = this.menuButton(menu);
    this.openMenuEl = null;
    button?.setAttribute('aria-expanded', 'false');
    if (focusButton) button?.focus();
  }

  /* Paragraph formatting: alignment, direction, indent, clear ------------ */

  private blockTypes(): string[] { return ['paragraph', 'heading']; }

  private align(value: string): void {
    const editor = this.current();
    if (!editor) return;
    // The natural side of the language needs no class.
    const textAlign = value === (this.isRtl() ? 'right' : 'left') ? null : value;
    let chain = editor.chain().focus();
    for (const type of this.blockTypes()) chain = chain.updateAttributes(type, { textAlign });
    chain.run();
  }

  private currentAlign(editor: Editor): string {
    const attrs = editor.isActive('heading') ? editor.getAttributes('heading') : editor.getAttributes('paragraph');
    return (attrs.textAlign as string | null) ?? (this.isRtl() ? 'right' : 'left');
  }

  /** The written direction of the field in the open language. */
  private isRtl(): boolean {
    return this.current()?.view.dom.getAttribute('dir') === 'rtl';
  }

  /** Flip the selected paragraphs to the other direction (Kurdish in an English text), or back. */
  private toggleDirection(): void {
    const editor = this.current();
    if (!editor) return;
    const other = this.isRtl() ? 'ltr' : 'rtl';
    const current = (editor.isActive('heading') ? editor.getAttributes('heading') : editor.getAttributes('paragraph')).dir as string | null;
    const dir = current === other ? null : other;
    let chain = editor.chain().focus();
    for (const type of [...this.blockTypes(), 'listItem']) chain = chain.updateAttributes(type, { dir });
    if (editor.isActive('bulletList')) chain = chain.updateAttributes('bulletList', { dir });
    if (editor.isActive('orderedList')) chain = chain.updateAttributes('orderedList', { dir });
    chain.run();
  }

  private indent(step: 1 | -1): void {
    const editor = this.current();
    if (!editor) return;
    if (editor.isActive('listItem')) {
      if (step > 0) editor.chain().focus().sinkListItem('listItem').run();
      else editor.chain().focus().liftListItem('listItem').run();
      return;
    }
    const attrs = editor.isActive('heading') ? editor.getAttributes('heading') : editor.getAttributes('paragraph');
    const indent = Math.max(0, Math.min(4, (Number(attrs.indent) || 0) + step));
    let chain = editor.chain().focus();
    for (const type of this.blockTypes()) chain = chain.updateAttributes(type, { indent });
    chain.run();
  }

  private clearFormatting(): void {
    const editor = this.current();
    if (!editor) return;
    let chain = editor.chain().focus().unsetAllMarks().clearNodes();
    for (const type of this.blockTypes()) chain = chain.updateAttributes(type, { textAlign: null, indent: 0, dir: null });
    chain.run();
  }

  /* Dialogs: link, image from a link, YouTube/Vimeo ------------------- */

  private dialog(name: DialogName): HTMLDialogElement | null {
    return this.root.querySelector<HTMLDialogElement>(`dialog[data-rich-dialog="${name}"]`);
  }

  private setUpDialogs(): void {
    this.root.querySelectorAll<HTMLDialogElement>('dialog[data-rich-dialog]').forEach(dialog => {
      // A resource dialog around the form must not see this dialog's Escape, Tab or Enter.
      dialog.addEventListener('cancel', event => { event.preventDefault(); event.stopPropagation(); this.close(); });
      dialog.addEventListener('keydown', event => {
        event.stopPropagation();
        if (event.key === 'Escape') { event.preventDefault(); this.close(); return; }
        // Enter applies; it must never submit the surrounding form.
        if (event.key === 'Enter' && event.target instanceof HTMLInputElement && event.target.type !== 'checkbox') { event.preventDefault(); this.apply(dialog); }
        if (event.key === 'Tab') {
          const focusable = [...dialog.querySelectorAll<HTMLElement>('input:not(:disabled), button:not(:disabled)')].filter(control => control.getClientRects().length > 0);
          const first = focusable[0];
          const last = focusable.at(-1);
          if (first && last && event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
          else if (first && last && !event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
      });
      // A click on the backdrop (outside the dialog box) closes it.
      dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        const inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
        if (!inside) this.close();
      });
      dialog.querySelectorAll('[data-rich-cancel]').forEach(button => button.addEventListener('click', () => this.close()));
      dialog.querySelector('[data-rich-apply]')?.addEventListener('click', () => this.apply(dialog));
      dialog.querySelector('[data-rich-remove]')?.addEventListener('click', () => {
        const editor = this.current();
        this.close(false);
        editor?.chain().focus().extendMarkRange('link').unsetLink().run();
      });
      dialog.querySelectorAll<HTMLInputElement>('input').forEach(input => input.addEventListener('input', () => { input.removeAttribute('aria-invalid'); this.error(dialog, ''); }));
    });
  }

  private open(name: DialogName, values: Record<string, string | boolean> = {}, title?: string): void {
    const dialog = this.dialog(name);
    if (!dialog || dialog.open) return;
    this.closeMenu(false);
    dialog.querySelectorAll<HTMLInputElement>('input').forEach(input => {
      input.removeAttribute('aria-invalid');
      if (input.type === 'checkbox') input.checked = values.blank === true;
      else if (input.matches('[data-rich-url]')) input.value = String(values.url ?? '');
      else if (input.matches('[data-rich-alt]')) input.value = String(values.alt ?? '');
      else if (input.matches('[data-rich-caption]')) input.value = String(values.caption ?? '');
      else if (input.matches('[data-rich-href]')) input.value = String(values.href ?? '');
    });
    const heading = dialog.querySelector<HTMLElement>('.fa-rich-dialog-title');
    if (heading) {
      heading.dataset.title ??= heading.textContent ?? '';
      heading.textContent = title ?? heading.dataset.title;
    }
    this.error(dialog, '');
    this.openDialog = dialog;
    dialog.showModal();
    const url = dialog.querySelector<HTMLInputElement>('[data-rich-url]');
    url?.focus();
    url?.select();
  }

  private close(focusEditor = true): void {
    const dialog = this.openDialog;
    if (!dialog) return;
    this.openDialog = null;
    this.editingImage = false;
    dialog.close();
    if (focusEditor) this.current()?.commands.focus();
  }

  private openLinkDialog(): void {
    const editor = this.current();
    if (!editor) return;
    const attributes = editor.getAttributes('link');
    const editing = Boolean(attributes.href);
    const dialog = this.dialog('link');
    const remove = dialog?.querySelector<HTMLElement>('[data-rich-remove]');
    if (remove) remove.hidden = !editing;
    const apply = dialog?.querySelector<HTMLElement>('[data-rich-apply]');
    if (apply) apply.textContent = editing ? this.strings.save : this.strings.insert;
    this.open('link', { url: attributes.href ?? '', blank: attributes.target === '_blank' }, editing ? this.strings.link_edit_title : this.strings.link_title);
  }

  private openImageDialog(edit: boolean): void {
    const image = edit ? this.selectedImage() : null;
    const apply = this.dialog('image')?.querySelector<HTMLElement>('[data-rich-apply]');
    if (apply) apply.textContent = image ? this.strings.save : this.strings.insert;
    this.open('image', { url: String(image?.src ?? ''), alt: String(image?.alt ?? ''), caption: String(image?.caption ?? ''), href: String(image?.href ?? '') },
      image ? this.strings.image_edit_title : this.strings.image_title);
    this.editingImage = image !== null;
  }

  private error(dialog: HTMLDialogElement, message: string): void {
    const box = dialog.querySelector<HTMLElement>('[data-rich-error]');
    if (!box) return;
    box.textContent = message;
    box.hidden = message === '';
  }

  private invalid(dialog: HTMLDialogElement, input: HTMLInputElement | null, message: string): void {
    input?.setAttribute('aria-invalid', 'true');
    input?.focus();
    this.error(dialog, message);
  }

  private apply(dialog: HTMLDialogElement): void {
    const editor = this.current();
    if (!editor) return;
    const input = dialog.querySelector<HTMLInputElement>('[data-rich-url]');
    const url = (input?.value ?? '').trim();
    const name = dialog.dataset.richDialog as DialogName;
    const editingImage = this.editingImage;
    if (name === 'link') {
      if (url !== '' && !validLink(url)) { this.invalid(dialog, input, this.strings.link_invalid); return; }
      const target = dialog.querySelector<HTMLInputElement>('[data-rich-blank]')?.checked ? '_blank' : null;
      this.close(false);
      if (url === '') editor.chain().focus().extendMarkRange('link').unsetLink().run();
      else if (editor.state.selection.empty && !editor.isActive('link')) {
        editor.chain().focus().insertContent({ type: 'text', text: url, marks: [{ type: 'link', attrs: { href: url, target } }] }).run();
      } else editor.chain().focus().extendMarkRange('link').setLink({ href: url, target }).run();
      return;
    }
    if (name === 'image') {
      if (!validMediaUrl(url)) { this.invalid(dialog, input, this.strings.image_invalid); return; }
      const alt = dialog.querySelector<HTMLInputElement>('[data-rich-alt]')?.value.trim() ?? '';
      const caption = dialog.querySelector<HTMLInputElement>('[data-rich-caption]')?.value.trim() ?? '';
      const hrefInput = dialog.querySelector<HTMLInputElement>('[data-rich-href]');
      const href = (hrefInput?.value ?? '').trim();
      if (href !== '' && !validLink(href)) { this.invalid(dialog, hrefInput, this.strings.link_invalid); return; }
      this.close(false);
      const extra = { caption, href: href === '' ? null : href };
      if (editingImage) {
        const old = this.selectedImage();
        // A new address drops the old pixel size; the browser measures the new image.
        const size = old && old.src === url ? {} : { width: null, height: null };
        editor.chain().focus().updateAttributes('image', { src: url, alt, ...extra, ...size }).run();
      } else insertBlock(editor, { type: 'image', attrs: { src: url, alt, ...extra } });
      return;
    }
    const embed: EmbedInfo | null = parseEmbed(url, this.config.embeds);
    if (!embed) { this.invalid(dialog, input, this.strings.embed_invalid); return; }
    this.close(false);
    insertBlock(editor, { type: 'embed', attrs: embed });
  }

  /* Uploads: file picker, paste and drop, through flocms-uploader ------ */

  private setUpFiles(): void {
    this.root.querySelectorAll<HTMLInputElement>('input[data-rich-file]').forEach(input => {
      input.addEventListener('change', () => {
        const file = input.files?.[0];
        input.value = '';
        if (file) void (input.dataset.richFile === 'video' ? this.insertVideo(file) : this.insertImage(file));
        else this.replacing = null;
      });
      // Closing the picker without a file cancels a Replace.
      input.addEventListener('cancel', () => { this.replacing = null; });
    });
    this.statusBox?.querySelector('[data-rich-status-cancel]')?.addEventListener('click', () => {
      if (this.upload) { this.upload.abort(); this.upload = null; this.showStatus(this.strings.cancelled, 'done'); }
      else this.hideStatus();
      this.current()?.commands.focus();
    });
  }

  /** Open the browser's file picker directly. */
  private pick(kind: 'image' | 'video'): void {
    this.closeMenu(false);
    if (this.upload) return;
    this.root.querySelector<HTMLInputElement>(`input[data-rich-file="${kind}"]`)?.click();
  }

  /** Pasted or dropped files: upload the first image or video this field accepts. */
  private takeFiles(files: FileList | undefined, at?: number): boolean {
    const file = [...(files ?? [])][0];
    if (!file || !this.config.uploads || this.upload) return false;
    const image = this.config.images && this.has('image') && /^image\/(jpeg|png|gif|webp)$/.test(file.type);
    const video = this.config.videos && /^video\/(mp4|webm)$/.test(file.type);
    if (!image && !video) return false;
    if (at !== undefined) this.current()?.commands.setTextSelection(at);
    void (image ? this.insertImage(file) : this.insertVideo(file));
    return true;
  }

  /** An image chosen from the media library (0.17), with its alt text in this panel's language. */
  private async insertFromLibrary(): Promise<void> {
    const editor = this.current();
    const lang = this.active;
    const api = (window as unknown as { FloAdminMedia?: { pick: (o: { kind: 'image'; multiple: false }) => Promise<Array<{ url: string; alt: string; alts?: Record<string, string>; width: number | null; height: number | null }> | null> } }).FloAdminMedia;
    const picked = await api?.pick({ kind: 'image', multiple: false });
    const item = picked?.[0];
    if (!item || !editor || editor.isDestroyed) { editor?.commands.focus(); return; }
    insertBlock(editor, { type: 'image', attrs: { src: item.url, alt: (lang && item.alts?.[lang]) || item.alt || '', width: item.width, height: item.height } });
  }

  private async insertImage(file: File): Promise<void> {
    if (file.size > this.config.maxImage) { this.showStatus(this.strings.too_large.replace(':size', size(this.config.maxImage)), 'error'); return; }
    const editor = this.current();
    const replacing = this.replacing;
    this.replacing = null;
    const upload = this.upload = new AbortController();
    this.showStatus(`${this.strings.uploading} ${file.name}`, 'busy');
    try {
      const image = await uploadImage(this.config, file, upload.signal);
      if (editor && !editor.isDestroyed) {
        const attrs = { src: image.url, width: image.width ?? null, height: image.height ?? null };
        const target = replacing ? editor.state.doc.nodeAt(replacing.pos) : null;
        if (replacing && target?.type.name === 'image' && target.attrs.src === replacing.src) {
          // Replace keeps the description, caption, link, width and position.
          const { tr } = editor.state;
          tr.setNodeMarkup(replacing.pos, undefined, { ...target.attrs, ...attrs });
          tr.setSelection(NodeSelection.create(tr.doc, replacing.pos));
          editor.view.dispatch(tr);
        } else insertBlock(editor, { type: 'image', attrs: { ...attrs, alt: '' } });
      }
      this.showStatus(this.strings.uploaded, 'done');
    } catch (error) {
      if ((error as Error).name !== 'AbortError') this.showStatus(error instanceof UploadError ? error.message || this.strings.image_invalid : this.strings.upload_failed, 'error');
    } finally { if (this.upload === upload) this.upload = null; }
  }

  private async insertVideo(file: File): Promise<void> {
    if (file.size > this.config.maxVideo) { this.showStatus(this.strings.too_large.replace(':size', size(this.config.maxVideo)), 'error'); return; }
    const editor = this.current();
    const upload = this.upload = new AbortController();
    this.showStatus(this.strings.progress.replace(':percent', '0'), 'busy', 0);
    try {
      const video = await uploadVideo(this.config, file, upload.signal, percent => {
        if (this.upload === upload) this.showStatus(this.strings.progress.replace(':percent', String(percent)), 'busy', percent);
      });
      if (editor && !editor.isDestroyed) insertBlock(editor, { type: 'video', attrs: { src: video.url } });
      this.showStatus(this.strings.uploaded, 'done');
    } catch (error) {
      if ((error as Error).name !== 'AbortError') this.showStatus(error instanceof UploadError ? error.message || this.strings.video_invalid : this.strings.upload_failed, 'error');
    } finally { if (this.upload === upload) this.upload = null; }
  }

  /** The strip under the editor: progress while uploading, then the result. */
  private showStatus(text: string, state: 'busy' | 'done' | 'error', percent?: number): void {
    const box = this.statusBox;
    if (!box) return;
    window.clearTimeout(this.statusTimer);
    box.hidden = false;
    box.dataset.state = state;
    box.setAttribute('aria-busy', String(state === 'busy'));
    box.querySelector<HTMLElement>('[data-rich-status-text]')!.textContent = text;
    const bar = box.querySelector<HTMLElement>('[data-rich-status-bar]');
    box.classList.toggle('is-indeterminate', state === 'busy' && percent === undefined);
    if (bar) bar.style.width = state === 'busy' ? (percent === undefined ? '' : `${percent}%`) : '100%';
    if (state === 'done') this.statusTimer = window.setTimeout(() => this.hideStatus(), 2500);
  }

  private hideStatus(): void {
    window.clearTimeout(this.statusTimer);
    if (this.statusBox) this.statusBox.hidden = true;
  }

  /* Footer: word count ------------------------------------------------ */

  private setUpFooter(): void {
    const footer = document.createElement('div');
    footer.className = 'fa-rich-footer';
    if (this.statusBox) footer.append(this.statusBox);
    this.words = document.createElement('span');
    this.words.className = 'fa-rich-words';
    footer.append(this.words);
    this.root.append(footer);
    this.countWords();
  }

  private countWords(): void {
    const editor = this.current();
    if (!this.words || !editor) return;
    const text = editor.state.doc.textBetween(0, editor.state.doc.content.size, ' ', ' ').trim();
    const count = text === '' ? 0 : text.split(/\s+/u).length;
    this.words.textContent = (this.strings.words ?? ':count').replace(':count', count.toLocaleString(document.documentElement.lang || undefined));
  }

  destroy(): void {
    this.upload?.abort();
    this.listeners.abort();
    window.clearTimeout(this.statusTimer);
    this.editors.forEach(editor => editor.destroy());
  }
}

function size(bytes: number): string {
  return bytes >= 1048576 ? `${Math.round(bytes / 104857.6) / 10} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

const mounted = new WeakMap<HTMLElement, RichField>();

/** Start every editor below root that is not running yet. */
export function mount(root: ParentNode = document): void {
  root.querySelectorAll<HTMLElement>('[data-admin-rich-editor]').forEach(element => {
    // Reactive rebuilds an editor in place when its key changes: start it again.
    if (element.classList.contains('is-ready')) return;
    mounted.get(element)?.destroy();
    element.querySelectorAll('.fa-rich-host, .fa-rich-footer').forEach(node => node.remove());
    mounted.set(element, new RichField(element));
  });
}

(window as unknown as { FloAdminEditor: { mount: typeof mount } }).FloAdminEditor = { mount };
document.dispatchEvent(new CustomEvent('flo-admin:editor-ready'));
