/*
 * The rich text editor (Tiptap), loaded on demand by admin.js only on pages
 * with a RichEditor field. It turns each language's <textarea> into a visual
 * editor and keeps the textarea filled with the HTML, so forms submit as
 * before. The server cleans that HTML again: nothing here is a security check.
 */
import { Editor, Node, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { NodeSelection } from '@tiptap/pm/state';
import Image from '@tiptap/extension-image';
import { parseEmbed, type Embed as EmbedInfo } from './embeds';
import { uploadImage, uploadVideo, UploadError } from './uploads';

type Config = {
  toolbar: string[]; images: boolean; videos: boolean; uploads: boolean; embeds: string[];
  media: string; maxImage: number; maxVideo: number; csrf: string;
};
type Strings = Record<string, string>;

const ImageNode = Image.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      width: { default: null, parseHTML: element => element.getAttribute('width'), renderHTML: attributes => (attributes.width ? { width: attributes.width } : {}) },
      height: { default: null, parseHTML: element => element.getAttribute('height'), renderHTML: attributes => (attributes.height ? { height: attributes.height } : {}) },
    };
  },
}).configure({ inline: false, allowBase64: false });

const VideoNode = Node.create({
  name: 'video', group: 'block', atom: true, draggable: true, selectable: true,
  addAttributes() { return { src: { default: null } }; },
  parseHTML() { return [{ tag: 'video[src]' }]; },
  renderHTML({ HTMLAttributes }) { return ['video', mergeAttributes(HTMLAttributes, { controls: '', preload: 'metadata' })]; },
});

/** Stored as <div data-embed data-id>; the server adds the iframe. The editor shows a card instead. */
function embedNode(strings: Strings) {
  return Node.create({
    name: 'embed', group: 'block', atom: true, draggable: true, selectable: true,
    addAttributes() { return { provider: { default: null }, id: { default: null } }; },
    parseHTML() {
      return [{ tag: 'div[data-embed]', priority: 60, getAttrs: element => ({ provider: (element as HTMLElement).dataset.embed, id: (element as HTMLElement).dataset.id }) }];
    },
    renderHTML({ node }) { return ['div', { 'data-embed': node.attrs.provider, 'data-id': node.attrs.id }]; },
    addNodeView() {
      return ({ node }) => {
        const dom = document.createElement('div');
        dom.className = 'fa-rich-embed';
        dom.dataset.provider = String(node.attrs.provider ?? '');
        dom.contentEditable = 'false';
        const ns = 'http://www.w3.org/2000/svg';
        const icon = document.createElementNS(ns, 'svg');
        icon.setAttribute('viewBox', '0 0 40 28');
        icon.setAttribute('aria-hidden', 'true');
        icon.classList.add('fa-rich-embed-icon');
        const play = document.createElementNS(ns, 'path');
        play.setAttribute('d', 'M16 8l10 6-10 6z');
        play.setAttribute('fill', 'currentColor');
        icon.append(play);
        const label = document.createElement('strong');
        label.textContent = strings[node.attrs.provider === 'vimeo' ? 'vimeo' : 'youtube'] ?? 'Video';
        const id = document.createElement('span');
        id.textContent = String(node.attrs.id ?? '');
        dom.append(icon, label, id);
        return { dom };
      };
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

class RichField {
  private readonly config: Config;
  private readonly strings: Strings;
  private readonly editors = new Map<string, Editor>();
  private readonly toolbar: HTMLElement | null;
  private active = '';
  private openPanel: HTMLElement | null = null;
  private upload: AbortController | null = null;

  constructor(private readonly root: HTMLElement) {
    this.config = JSON.parse(root.dataset.config ?? '{}');
    this.strings = JSON.parse(root.dataset.strings ?? '{}');
    this.toolbar = root.querySelector<HTMLElement>('[data-rich-toolbar]');
    // After a failed save Reactive focuses the invalid source textarea; remember it before hiding it.
    const focused = document.activeElement;
    const panels = [...root.querySelectorAll<HTMLElement>(':scope > .fa-rich-panel')];
    panels.forEach((panel, index) => this.createEditor(panel, index === 0));
    this.active = panels[0]?.dataset.lang ?? '';
    this.setUpTabs(panels);
    this.setUpToolbar();
    this.setUpInsertPanels();
    root.classList.add('is-ready');
    // The field label names the editor and moves focus into it.
    const label = root.closest('.fa-field')?.querySelector<HTMLLabelElement>(':scope > .fa-label');
    label?.addEventListener('click', event => { event.preventDefault(); this.selectTab(panels[0]?.dataset.lang ?? ''); this.current()?.commands.focus(); });
    // A failed save marked the source invalid and focused it: carry both over.
    panels.forEach(panel => {
      const source = panel.querySelector<HTMLTextAreaElement>('[data-rich-source]')!;
      if (focused === source) { this.selectTab(panel.dataset.lang ?? ''); this.editorFor(panel)?.commands.focus('end'); }
    });
    this.refreshToolbar();
  }

  private key(panel: HTMLElement): string { return panel.dataset.lang ?? ''; }
  private current(): Editor | undefined { return this.editors.get(this.active); }
  private editorFor(panel: HTMLElement): Editor | undefined { return this.editors.get(this.key(panel)); }

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
    const editor = new Editor({
      element: host, injectCSS: false, editable: !source.disabled, content: source.value,
      extensions: [
        StarterKit.configure({
          heading: { levels: [2, 3, 4] },
          link: { openOnClick: false, autolink: true, defaultProtocol: 'https', HTMLAttributes: { target: null, rel: null } },
        }),
        ...(this.config.images ? [ImageNode] : []), ...(this.config.videos ? [VideoNode] : []),
        ...(this.config.embeds.length > 0 ? [embedNode(this.strings)] : []),
      ],
      editorProps: { attributes },
      onUpdate: () => this.sync(panel, editor, source),
      onSelectionUpdate: () => this.refreshToolbar(),
      onTransaction: () => this.refreshToolbar(),
      onFocus: () => { this.active = this.key(panel); this.refreshToolbar(); },
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
    this.closePanel(false);
    this.refreshToolbar();
  }

  private setUpToolbar(): void {
    const toolbar = this.toolbar;
    if (!toolbar) return;
    toolbar.hidden = false;
    const buttons = () => [...toolbar.querySelectorAll<HTMLButtonElement>('[data-command]')];
    buttons()[0]?.setAttribute('tabindex', '0');
    // Keep the editor's selection: toolbar clicks must not move focus first.
    toolbar.addEventListener('mousedown', event => { if ((event.target as Element).closest('[data-command]')) event.preventDefault(); });
    toolbar.addEventListener('click', event => {
      const button = (event.target as Element).closest<HTMLButtonElement>('[data-command]');
      if (button && !button.disabled) this.run(button.dataset.command!, button);
    });
    // One tab stop; arrow keys move between buttons.
    toolbar.addEventListener('keydown', event => {
      const list = buttons().filter(button => !button.disabled);
      const index = list.indexOf(document.activeElement as HTMLButtonElement);
      if (index < 0) return;
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
    if (['link', 'image', 'video', 'embed'].includes(command)) { this.showPanel(command, button); return; }
    // A chain focuses the editor even before run(), so build it only for formatting.
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
    });
  }

  /* Insert panels: link, image, video and embed ----------------------- */

  private setUpInsertPanels(): void {
    this.root.querySelectorAll<HTMLElement>('[data-insert]').forEach(panel => {
      panel.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.closePanel(true); }
        // Enter applies the panel; it must never submit the surrounding form.
        if (event.key === 'Enter' && (event.target as HTMLElement).matches('input:not([type="file"])')) { event.preventDefault(); this.apply(panel); }
      });
      panel.querySelector('[data-insert-cancel]')?.addEventListener('click', () => this.closePanel(true));
      panel.querySelector('[data-insert-apply]')?.addEventListener('click', () => this.apply(panel));
      panel.querySelector('[data-insert-remove]')?.addEventListener('click', () => { this.current()?.chain().focus().extendMarkRange('link').unsetLink().run(); this.closePanel(false); });
      panel.querySelector<HTMLInputElement>('[data-insert-file]')?.addEventListener('change', event => {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0];
        input.value = '';
        if (file) void (panel.dataset.insert === 'video' ? this.insertVideo(panel, file) : this.insertImage(panel, file));
      });
    });
  }

  private showPanel(name: string, opener: HTMLButtonElement): void {
    const panel = this.root.querySelector<HTMLElement>(`[data-insert="${name}"]`);
    if (!panel) return;
    if (this.openPanel === panel) { this.closePanel(true); return; }
    this.closePanel(false);
    this.openPanel = panel;
    panel.hidden = false;
    opener.setAttribute('aria-expanded', 'true');
    panel.dataset.opener = opener.dataset.command;
    this.status(panel, '');
    panel.querySelectorAll<HTMLInputElement>('input[type="url"], input[type="text"]').forEach(input => { input.value = ''; input.removeAttribute('aria-invalid'); });
    if (name === 'link') {
      const attributes = this.current()?.getAttributes('link') ?? {};
      panel.querySelector<HTMLInputElement>('[data-insert-url]')!.value = attributes.href ?? '';
      panel.querySelector<HTMLInputElement>('[data-insert-blank]')!.checked = attributes.target === '_blank';
      const remove = panel.querySelector<HTMLElement>('[data-insert-remove]');
      if (remove) remove.hidden = !attributes.href;
    }
    (panel.querySelector<HTMLElement>('input[type="url"], input[type="file"]') ?? panel).focus();
  }

  private closePanel(focusEditor: boolean): void {
    if (!this.openPanel) return;
    this.upload?.abort();
    this.upload = null;
    this.openPanel.hidden = true;
    this.toolbar?.querySelector(`[data-command="${this.openPanel.dataset.opener}"]`)?.setAttribute('aria-expanded', 'false');
    this.openPanel = null;
    if (focusEditor) this.current()?.commands.focus();
  }

  private status(panel: HTMLElement, text: string, error = false): void {
    const status = panel.querySelector<HTMLElement>('[data-insert-status]');
    if (status) { status.textContent = text; status.classList.toggle('fa-error', error); }
  }

  private invalid(panel: HTMLElement, input: HTMLInputElement | null, message: string): void {
    input?.setAttribute('aria-invalid', 'true');
    input?.focus();
    this.status(panel, message, true);
  }

  private apply(panel: HTMLElement): void {
    const editor = this.current();
    if (!editor) return;
    const input = panel.querySelector<HTMLInputElement>('[data-insert-url]');
    const url = (input?.value ?? '').trim();
    switch (panel.dataset.insert) {
      case 'link': {
        if (url === '') { editor.chain().focus().extendMarkRange('link').unsetLink().run(); break; }
        if (!validLink(url)) { this.invalid(panel, input, this.strings.link_invalid); return; }
        const target = panel.querySelector<HTMLInputElement>('[data-insert-blank]')!.checked ? '_blank' : null;
        if (editor.state.selection.empty && !editor.isActive('link')) {
          editor.chain().focus().insertContent({ type: 'text', text: url, marks: [{ type: 'link', attrs: { href: url, target } }] }).run();
        } else {
          editor.chain().focus().extendMarkRange('link').setLink({ href: url, target }).run();
        }
        break;
      }
      case 'image': {
        if (!validMediaUrl(url)) { this.invalid(panel, input, this.strings.image_invalid); return; }
        const alt = panel.querySelector<HTMLInputElement>('[data-insert-alt]')?.value.trim() ?? '';
        insertBlock(editor, { type: 'image', attrs: { src: url, alt } });
        break;
      }
      case 'embed': {
        const embed: EmbedInfo | null = parseEmbed(url, this.config.embeds);
        if (!embed) { this.invalid(panel, input, this.strings.embed_invalid); return; }
        insertBlock(editor, { type: 'embed', attrs: embed });
        break;
      }
    }
    this.closePanel(false);
  }

  private async insertImage(panel: HTMLElement, file: File): Promise<void> {
    if (file.size > this.config.maxImage) { this.status(panel, this.strings.too_large.replace(':size', size(this.config.maxImage)), true); return; }
    const editor = this.current();
    this.upload = new AbortController();
    this.status(panel, this.strings.uploading);
    this.busy(panel, true);
    try {
      const image = await uploadImage(this.config, file, this.upload.signal);
      const alt = panel.querySelector<HTMLInputElement>('[data-insert-alt]')?.value.trim() ?? '';
      if (editor) insertBlock(editor, { type: 'image', attrs: { src: image.url, alt, width: image.width ?? null, height: image.height ?? null } });
      this.upload = null;
      this.closePanel(false);
    } catch (error) {
      if ((error as Error).name !== 'AbortError') this.status(panel, error instanceof UploadError ? error.message || this.strings.image_invalid : this.strings.upload_failed, true);
    } finally { this.busy(panel, false); }
  }

  private async insertVideo(panel: HTMLElement, file: File): Promise<void> {
    if (file.size > this.config.maxVideo) { this.status(panel, this.strings.too_large.replace(':size', size(this.config.maxVideo)), true); return; }
    const editor = this.current();
    const progress = panel.querySelector<HTMLProgressElement>('[data-insert-progress]');
    this.upload = new AbortController();
    if (progress) { progress.hidden = false; progress.value = 0; }
    this.busy(panel, true);
    try {
      const video = await uploadVideo(this.config, file, this.upload.signal, percent => {
        if (progress) progress.value = percent;
        this.status(panel, this.strings.progress.replace(':percent', String(percent)));
      });
      if (editor) insertBlock(editor, { type: 'video', attrs: { src: video.url } });
      this.upload = null;
      this.closePanel(false);
    } catch (error) {
      if ((error as Error).name !== 'AbortError') this.status(panel, error instanceof UploadError ? error.message || this.strings.video_invalid : this.strings.upload_failed, true);
    } finally {
      if (progress) progress.hidden = true;
      this.busy(panel, false);
    }
  }

  private busy(panel: HTMLElement, busy: boolean): void {
    panel.setAttribute('aria-busy', String(busy));
    panel.querySelectorAll<HTMLInputElement | HTMLButtonElement>('input, [data-insert-apply]').forEach(control => { control.disabled = busy; });
  }

  destroy(): void {
    this.upload?.abort();
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
    element.querySelectorAll('.fa-rich-host').forEach(host => host.remove());
    mounted.set(element, new RichField(element));
  });
}

(window as unknown as { FloAdminEditor: { mount: typeof mount } }).FloAdminEditor = { mount };
document.dispatchEvent(new CustomEvent('flo-admin:editor-ready'));
