/**
 * The media library in the browser (0.17):
 *  - one picker dialog (search, show more, upload) shared by MediaPicker fields and the rich editor
 *    (window.FloAdminMedia.pick);
 *  - MediaPicker fields: thumbnails with Change/Remove, several in a row that can be reordered by
 *    dragging or with the move buttons;
 *  - the Media page: upload several files with progress (videos in parts), drop zone, copy address.
 * Without JavaScript the field is a select and the page uses plain forms.
 */

export type MediaItem = {
  id: number; kind: 'image' | 'video'; url: string; thumb: string; title: string; label: string; alt: string;
  alts?: Record<string, string>; name: string; width: number | null; height: number | null;
  thumbWidth: number | null; thumbHeight: number | null;
};
type Config = {
  lookup: string; upload: string | null; video: string | null; csrf: string;
  maxImage: number; maxVideo: number; chunk: number; strings: Record<string, string>;
};
type PickOptions = { kind: 'image' | 'video' | null; multiple: boolean; max?: number | null; selected?: number[] };

const VIDEO = /^video\/(mp4|webm)$/;
const IMAGE = /^image\/(jpeg|png|gif|webp)$/;

function config(): Config | null {
  const raw = document.querySelector<HTMLElement>('.fa-panel')?.dataset.adminMedia;
  if (!raw) return null;
  try { return JSON.parse(raw) as Config; } catch { return null; }
}

function svg(path: string): string {
  return `<svg class="fa-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="${path}"/></svg>`;
}
const ICONS = {
  x: 'M18 6 6 18M6 6l12 12',
  video: 'M3 6h12v12H3zM15 10l6-3v10l-6-3',
  before: 'M15 18l-6-6 6-6',
  after: 'M9 18l6-6-6-6',
  upload: 'M12 16V4M7 9l5-5 5 5M4 16v4h16v-4',
};

function element<K extends keyof HTMLElementTagNameMap>(tag: K, className = '', text = ''): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

function frame(item: MediaItem): HTMLElement {
  const box = element('span', 'fa-media-box');
  if (item.kind === 'image') {
    const img = element('img');
    img.src = item.thumb; img.alt = item.alt; img.loading = 'lazy';
    if (item.thumbWidth && item.thumbHeight) { img.width = item.thumbWidth; img.height = item.thumbHeight; }
    box.append(img);
  } else {
    const icon = element('span', 'fa-media-video');
    icon.innerHTML = svg(ICONS.video);
    box.append(icon);
  }
  return box;
}

function size(bytes: number): string {
  // One left-to-right piece (LRI … PDI), so right-to-left text doesn't show "MB 1.5".
  return `\u2066${bytes >= 1048576 ? `${Math.round(bytes / 104857.6) / 10}\u00a0MB` : `${Math.max(1, Math.round(bytes / 1024))}\u00a0KB`}\u2069`;
}

/* ------------------------------------------------------------------ Uploads */

class UploadFailed extends Error {
  /** Set when the server expects the next part at another offset (409). */
  constructor(message: string, readonly received: number | null = null) { super(message); }
}

async function post(url: string, body: FormData, csrf: string): Promise<Record<string, unknown>> {
  body.append('_token', csrf);
  const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
  const result = await response.json().catch(() => ({})) as Record<string, unknown>;
  if (response.status === 409 && typeof result.received === 'number') throw new UploadFailed('', result.received);
  if (!response.ok) throw new UploadFailed(typeof result.error === 'string' ? result.error : (Array.isArray(result.errors) && typeof result.errors[0] === 'string' ? result.errors[0] : ''));
  return result;
}

/** Upload one file to the library; resolves with the new item. Videos go in parts. */
export async function uploadMedia(cfg: Config, file: File, progress: (percent: number) => void): Promise<MediaItem> {
  if (VIDEO.test(file.type)) {
    if (!cfg.video) throw new UploadFailed('');
    if (file.size > cfg.maxVideo) throw new UploadFailed(cfg.strings.too_big.replace(':name', file.name).replace(':max', size(cfg.maxVideo)));
    const start = new FormData();
    start.append('name', file.name); start.append('bytes', String(file.size));
    const begun = await post(`${cfg.video}/start`, start, cfg.csrf);
    const id = String(begun.upload_id ?? '');
    const chunk = Number(begun.chunk_size) || cfg.chunk;
    let offset = Number(begun.received) || 0;
    let resyncs = 0;
    try {
      while (offset < file.size) {
        const part = new FormData();
        part.append('upload_id', id); part.append('offset', String(offset));
        part.append('chunk', file.slice(offset, offset + chunk), 'chunk');
        let status: Record<string, unknown>;
        try {
          status = await post(`${cfg.video}/chunk`, part, cfg.csrf);
        } catch (error) {
          // The server has a different amount (a part sent twice or lost): go on from there.
          if (error instanceof UploadFailed && error.received !== null && resyncs < 3) { resyncs++; offset = error.received; continue; }
          throw error;
        }
        offset = Number(status.received) || offset + chunk;
        progress(Math.min(99, Math.round(offset / file.size * 100)));
      }
      const done = new FormData();
      done.append('upload_id', id);
      const result = await post(`${cfg.video}/complete`, done, cfg.csrf);
      progress(100);
      return result.item as MediaItem;
    } catch (error) {
      const abort = new FormData(); abort.append('upload_id', id);
      void post(`${cfg.video}/abort`, abort, cfg.csrf).catch(() => undefined);
      throw error;
    }
  }
  if (!cfg.upload) throw new UploadFailed('');
  if (file.size > cfg.maxImage) throw new UploadFailed(cfg.strings.too_big.replace(':name', file.name).replace(':max', size(cfg.maxImage)));
  return new Promise<MediaItem>((resolve, reject) => {
    const request = new XMLHttpRequest();
    const body = new FormData();
    body.append('files[]', file); body.append('_token', cfg.csrf);
    request.open('POST', cfg.upload!);
    request.setRequestHeader('Accept', 'application/json');
    request.setRequestHeader('X-CSRF-TOKEN', cfg.csrf);
    request.upload.addEventListener('progress', event => { if (event.lengthComputable) progress(Math.min(99, Math.round(event.loaded / event.total * 100))); });
    request.addEventListener('load', () => {
      let result: { items?: MediaItem[]; errors?: string[] } = {};
      try { result = JSON.parse(request.responseText); } catch { /* not JSON */ }
      if (request.status === 200 && result.items?.[0]) { progress(100); resolve(result.items[0]); }
      else reject(new UploadFailed(result.errors?.[0] ?? ''));
    });
    request.addEventListener('error', () => reject(new UploadFailed('')));
    request.send(body);
  });
}

function accepts(kind: string | null): string {
  if (kind === 'image') return 'image/jpeg,image/png,image/gif,image/webp';
  if (kind === 'video') return 'video/mp4,video/webm';
  return 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm';
}

function fits(file: File, kind: string | null): boolean {
  return kind === 'image' ? IMAGE.test(file.type) : kind === 'video' ? VIDEO.test(file.type) : IMAGE.test(file.type) || VIDEO.test(file.type);
}

/* ------------------------------------------------------------------ Picker */

/** The library in a dialog. Single choice: a click chooses. Several: toggle, then "Use selected". */
export function pick(options: PickOptions): Promise<MediaItem[] | null> {
  const cfg = config();
  if (!cfg) return Promise.resolve(null);
  const s = cfg.strings;
  const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  const host = document.querySelector<HTMLElement>('.fa-panel') ?? document.body;
  const dialog = element('dialog', 'fa-media-dialog');
  const titleId = `fa-media-dialog-${Date.now()}`;
  dialog.setAttribute('aria-labelledby', titleId);
  dialog.innerHTML = `<div class="fa-dialog-head"><h2 class="fa-dialog-title" id="${titleId}"></h2><button type="button" class="fa-icon-action fa-dialog-close">${svg(ICONS.x)}</button></div>
    <div class="fa-media-dialog-bar"><label class="fa-sr" for="${titleId}-q"></label><input type="search" class="fa-input" id="${titleId}-q" maxlength="100"></div>
    <p class="fa-media-dialog-status" role="status" aria-live="polite"></p>
    <ul class="fa-media-grid is-picker"></ul>
    <div class="fa-media-dialog-more"><button type="button" class="fa-btn fa-btn-ghost" hidden></button></div>
    <div class="fa-dialog-foot"><span class="fa-toolbar-spacer"></span><button type="button" class="fa-btn" data-cancel></button></div>`;
  const [title, close, search, label, status, grid, more, foot, cancel] = [
    dialog.querySelector('h2')!, dialog.querySelector<HTMLButtonElement>('.fa-dialog-close')!, dialog.querySelector<HTMLInputElement>('input[type=search]')!,
    dialog.querySelector('label')!, dialog.querySelector<HTMLElement>('[role=status]')!, dialog.querySelector('ul')!,
    dialog.querySelector<HTMLButtonElement>('.fa-media-dialog-more button')!, dialog.querySelector<HTMLElement>('.fa-dialog-foot')!, dialog.querySelector<HTMLButtonElement>('[data-cancel]')!];
  title.textContent = s.picker_title; close.setAttribute('aria-label', s.cancel); close.title = s.cancel;
  search.placeholder = s.search; label.textContent = s.search; more.textContent = s.more; cancel.textContent = s.cancel;
  const chosen: MediaItem[] = [];
  const already = new Set(options.selected ?? []);
  let use: HTMLButtonElement | null = null;
  if (options.multiple) {
    use = element('button', 'fa-btn fa-btn-primary', s.select);
    use.type = 'button'; use.disabled = true;
    foot.append(use);
  }
  if (cfg.upload && options.kind !== 'video' || cfg.video && options.kind !== 'image') {
    const upload = element('label', 'fa-btn fa-media-dialog-upload');
    upload.innerHTML = `${svg(ICONS.upload)}<span></span><input class="fa-sr" type="file" ${options.multiple ? 'multiple' : ''}>`;
    upload.querySelector('span')!.textContent = s.upload;
    const input = upload.querySelector('input')!;
    input.accept = accepts(options.kind);
    input.addEventListener('change', () => void sendFiles([...(input.files ?? [])]));
    dialog.querySelector('.fa-media-dialog-bar')!.append(upload);
  }

  let page = 1; let pages = 1; let token = 0; let timer = 0;
  let settle: (value: MediaItem[] | null) => void = () => undefined;
  const result = new Promise<MediaItem[] | null>(resolve => { settle = resolve; });

  const refreshUse = () => { if (use) { use.disabled = chosen.length === 0; use.textContent = chosen.length ? `${s.select} (${chosen.length})` : s.select; } };
  const finish = (value: MediaItem[] | null) => { settle(value); dialog.close(); };

  function tile(item: MediaItem, prepend = false): HTMLButtonElement {
    const li = element('li');
    const button = element('button', 'fa-media-option');
    button.type = 'button';
    button.dataset.id = String(item.id);
    button.setAttribute('aria-pressed', 'false');
    button.append(frame(item), element('span', 'fa-media-name', item.label));
    button.querySelector('.fa-media-name')!.setAttribute('dir', 'auto');
    if (already.has(item.id)) button.disabled = true;
    button.addEventListener('click', () => {
      if (!options.multiple) { finish([item]); return; }
      const index = chosen.findIndex(entry => entry.id === item.id);
      if (index >= 0) chosen.splice(index, 1);
      else if (!options.max || chosen.length + already.size < options.max) chosen.push(item);
      button.setAttribute('aria-pressed', String(chosen.some(entry => entry.id === item.id)));
      refreshUse();
    });
    li.append(button);
    if (prepend) grid.prepend(li); else grid.append(li);
    return button;
  }

  async function load(reset: boolean): Promise<void> {
    const mine = ++token;
    // The page asked for; kept only when it loads, so "Show more" can be tried again after a failure.
    const want = reset ? 1 : page + 1;
    status.textContent = s.loading;
    const url = new URL(cfg!.lookup, location.href);
    url.searchParams.set('q', search.value.trim());
    if (options.kind) url.searchParams.set('kind', options.kind);
    url.searchParams.set('page', String(want));
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(String(response.status));
      const data = await response.json() as { items: MediaItem[]; page: number; pages: number };
      if (mine !== token) return;
      if (reset) grid.replaceChildren();
      page = want;
      // Items uploaded in this dialog are already at the top: don't list them twice.
      data.items.forEach(item => { if (!grid.querySelector(`[data-id="${item.id}"]`)) tile(item); });
      pages = data.pages;
      more.hidden = page >= pages;
      status.textContent = grid.children.length === 0 ? (search.value.trim() ? s.no_results : s.empty) : '';
    } catch {
      if (mine === token) status.textContent = s.failed;
    }
  }

  async function sendFiles(files: File[]): Promise<void> {
    const added: MediaItem[] = [];
    for (const file of files) {
      if (!fits(file, options.kind)) { status.textContent = `${file.name}: ${s.failed}`; continue; }
      status.textContent = s.uploading.replace(':name', file.name);
      try {
        const item = await uploadMedia(cfg!, file, percent => { status.textContent = `${s.uploading.replace(':name', file.name)} ${percent}%`; });
        added.push(item);
        const button = tile(item, true);
        if (options.multiple) button.click();
      } catch (error) {
        status.textContent = error instanceof UploadFailed && error.message ? error.message : s.failed;
        return;
      }
    }
    status.textContent = '';
    if (!options.multiple && added[0]) finish([added[0]]);
  }

  search.addEventListener('input', () => { clearTimeout(timer); timer = window.setTimeout(() => void load(true), 250); });
  search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); clearTimeout(timer); void load(true); } });
  more.addEventListener('click', () => { void load(false); });
  close.addEventListener('click', () => finish(null));
  cancel.addEventListener('click', () => finish(null));
  use?.addEventListener('click', () => finish([...chosen]));
  dialog.addEventListener('cancel', () => settle(null));
  dialog.addEventListener('close', () => { dialog.remove(); settle(null); opener?.focus({ preventScroll: true }); });

  host.append(dialog);
  dialog.showModal();
  search.focus();
  void load(true);
  return result;
}

/* ------------------------------------------------------------------ Field */

class MediaField {
  private readonly kind: 'image' | 'video' | null;
  private readonly multiple: boolean;
  private readonly max: number | null;
  private readonly strings: Record<string, string>;
  private items: MediaItem[];
  private readonly value: HTMLInputElement;
  private readonly list: HTMLElement;
  private readonly actions: HTMLElement;
  private readonly announce: HTMLElement;

  constructor(private readonly root: HTMLElement) {
    const kind = root.dataset.kind;
    this.kind = kind === 'image' || kind === 'video' ? kind : null;
    this.multiple = root.dataset.multiple === 'true';
    this.max = root.dataset.max ? Number(root.dataset.max) : null;
    this.strings = JSON.parse(root.dataset.strings ?? '{}');
    this.items = JSON.parse(root.dataset.items ?? '[]');
    const select = root.querySelector<HTMLSelectElement>('[data-media-select]');
    this.value = root.querySelector<HTMLInputElement>('[data-media-value]')!;
    const disabled = select?.disabled ?? false;
    // The script's own value (keeps the order); the select is for pages without JavaScript.
    if (select) { select.hidden = true; select.disabled = true; }
    if (!disabled) this.value.disabled = false;
    // Ids the server knew but the library no longer has keep their place, shown as missing.
    const ids = this.value.value.trim().startsWith('[') ? (JSON.parse(this.value.value) as string[]).map(Number) : (this.value.value ? [Number(this.value.value)] : []);
    for (const id of ids) {
      if (!this.items.some(item => item.id === id)) this.items.push({ id, kind: 'image', url: '', thumb: '', title: '', label: this.strings.missing.replace(':id', String(id)), alt: '', name: '', width: null, height: null, thumbWidth: null, thumbHeight: null });
    }
    this.items.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
    this.list = element('div', `fa-media-chosen${this.multiple ? ' is-multiple' : ''}`);
    this.list.setAttribute('role', 'list');
    this.actions = element('div', 'fa-media-actions');
    this.announce = element('p', 'fa-sr');
    this.announce.setAttribute('aria-live', 'polite');
    root.append(this.list, this.actions, this.announce);
    if (disabled) root.classList.add('is-disabled');
    this.render(disabled);
    root.dataset.mediaReady = 'true';
  }

  private ids(): number[] { return this.items.map(item => item.id); }

  private save(): void {
    const ids = this.ids();
    this.value.value = this.multiple ? (ids.length ? JSON.stringify(ids.map(String)) : '') : (ids[0] ? String(ids[0]) : '');
    this.value.dispatchEvent(new Event('input', { bubbles: true }));
    this.value.dispatchEvent(new Event('change', { bubbles: true }));
  }

  private render(disabled = false): void {
    this.list.replaceChildren();
    this.items.forEach((item, index) => this.list.append(this.chip(item, index, disabled)));
    this.actions.replaceChildren();
    if (disabled) return;
    const full = this.max !== null && this.items.length >= this.max;
    const choose = element('button', 'fa-btn fa-btn-sm', !this.multiple && this.items.length ? this.strings.change : this.strings.choose);
    choose.type = 'button';
    choose.disabled = full;
    choose.addEventListener('click', () => void this.open());
    this.actions.append(choose);
    if (!this.multiple && this.items.length) {
      const remove = element('button', 'fa-btn fa-btn-ghost fa-btn-sm', this.strings.remove);
      remove.type = 'button';
      remove.addEventListener('click', () => { this.items = []; this.render(); this.save(); this.actions.querySelector('button')?.focus(); });
      this.actions.append(remove);
    }
    if (full && this.strings.max) this.actions.append(element('span', 'fa-help', this.strings.max));
  }

  private chip(item: MediaItem, index: number, disabled: boolean): HTMLElement {
    const chip = element('div', 'fa-media-chip');
    chip.setAttribute('role', 'listitem');
    chip.dataset.id = String(item.id);
    if (!item.url) chip.classList.add('is-missing');
    chip.append(item.url ? frame(item) : element('span', 'fa-media-box'));
    const name = element('span', 'fa-media-chip-name', item.label);
    name.dir = 'auto';
    chip.append(name);
    if (!this.multiple || disabled) return chip;
    const tools = element('span', 'fa-media-chip-tools');
    const button = (label: string, icon: string, action: () => void, off = false) => {
      const b = element('button', 'fa-icon-action');
      b.type = 'button'; b.innerHTML = svg(icon); b.setAttribute('aria-label', label); b.title = label; b.disabled = off;
      b.addEventListener('click', action);
      tools.append(b);
    };
    button(this.strings.moveBefore.replace(':name', item.label), ICONS.before, () => this.move(index, index - 1, 'before'), index === 0);
    button(this.strings.moveAfter.replace(':name', item.label), ICONS.after, () => this.move(index, index + 1, 'after'), index === this.items.length - 1);
    button(`${this.strings.remove}: ${item.label}`, ICONS.x, () => {
      this.items.splice(index, 1); this.render(); this.save();
      (this.list.querySelectorAll<HTMLElement>('.fa-media-chip')[Math.min(index, this.items.length - 1)]?.querySelector('button') ?? this.actions.querySelector('button'))?.focus();
    });
    chip.append(tools);
    this.draggable(chip);
    return chip;
  }

  private move(from: number, to: number, focus: 'before' | 'after'): void {
    if (to < 0 || to >= this.items.length) return;
    const [item] = this.items.splice(from, 1);
    this.items.splice(to, 0, item);
    this.render(); this.save();
    const chip = this.list.querySelectorAll<HTMLElement>('.fa-media-chip')[to];
    const buttons = chip?.querySelectorAll<HTMLButtonElement>('.fa-media-chip-tools button');
    const target = buttons?.[focus === 'before' ? 0 : 1];
    (target && !target.disabled ? target : buttons?.[focus === 'before' ? 1 : 0])?.focus();
    this.announce.textContent = this.strings.moved.replace(':name', item.label).replace(':position', String(to + 1)).replace(':total', String(this.items.length));
  }

  /** Drag a thumbnail among the others (they may wrap to several rows, in either direction). */
  private draggable(chip: HTMLElement): void {
    chip.addEventListener('pointerdown', event => {
      if ((event.target as HTMLElement).closest('button') || (event.pointerType === 'mouse' && event.button !== 0)) return;
      event.preventDefault();
      chip.setPointerCapture(event.pointerId);
      chip.classList.add('is-dragging');
      const rtl = getComputedStyle(this.list).direction === 'rtl';
      const move = (e: PointerEvent) => {
        const others = [...this.list.querySelectorAll<HTMLElement>('.fa-media-chip')].filter(other => other !== chip);
        const target = others.find(other => { const r = other.getBoundingClientRect(); return e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom; });
        if (!target) return;
        const r = target.getBoundingClientRect();
        const before = rtl ? e.clientX > r.left + r.width / 2 : e.clientX < r.left + r.width / 2;
        this.list.insertBefore(chip, before ? target : target.nextElementSibling);
        try { chip.setPointerCapture(e.pointerId); } catch { /* the pointer is gone */ }
      };
      const end = () => {
        chip.removeEventListener('pointermove', move);
        chip.removeEventListener('pointerup', end);
        chip.removeEventListener('pointercancel', end);
        chip.classList.remove('is-dragging');
        const order = [...this.list.querySelectorAll<HTMLElement>('.fa-media-chip')].map(c => Number(c.dataset.id));
        if (order.join() !== this.ids().join()) {
          this.items.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
          this.render(); this.save();
        }
      };
      chip.addEventListener('pointermove', move);
      chip.addEventListener('pointerup', end);
      chip.addEventListener('pointercancel', end);
    });
  }

  private async open(): Promise<void> {
    const picked = await pick({ kind: this.kind, multiple: this.multiple, max: this.max, selected: this.multiple ? this.ids() : [] });
    if (!picked || picked.length === 0) return;
    if (this.multiple) {
      for (const item of picked) { if (!this.items.some(entry => entry.id === item.id) && (this.max === null || this.items.length < this.max)) this.items.push(item); }
    } else this.items = [picked[0]];
    this.render(); this.save();
    this.actions.querySelector('button')?.focus();
  }
}

/* ------------------------------------------------------------------ Media page */

function uploadPage(form: HTMLFormElement): void {
  const cfg = config();
  const input = form.querySelector<HTMLInputElement>('[data-media-files]');
  const list = document.querySelector<HTMLElement>('[data-media-progress]');
  const library = document.querySelector<HTMLElement>('[data-admin-media-library]');
  if (!cfg || !input || !list) return;
  form.querySelector<HTMLElement>('[data-media-send]')?.setAttribute('hidden', '');
  const text = (name: string) => form.dataset[`text${name.charAt(0).toUpperCase()}${name.slice(1)}`] ?? '';

  async function send(files: File[]): Promise<void> {
    let ok = 0;
    // Every file is listed at once, so the whole queue is visible; they upload one after another.
    const rows = files.map(file => {
      const row = element('li', 'fa-media-progress-item');
      const label = element('span', 'fa-media-progress-name', text('waiting').replace(':name', file.name));
      const bar = element('progress');
      bar.max = 100; bar.value = 0;
      row.append(label, bar);
      list!.append(row);
      return { file, row, label, bar };
    });
    for (const { file, row, label, bar } of rows) {
      label.textContent = text('uploading').replace(':name', file.name);
      try {
        await uploadMedia(cfg!, file, percent => { bar.value = percent; });
        row.classList.add('is-done');
        label.textContent = text('done').replace(':name', file.name);
        ok++;
      } catch (error) {
        row.classList.add('is-error');
        label.textContent = `${file.name}: ${error instanceof UploadFailed && error.message ? error.message : text('failed')}`;
      }
    }
    if (ok > 0) location.reload();
  }

  input.addEventListener('change', () => { void send([...(input.files ?? [])]); input.value = ''; });
  if (library) {
    library.dataset.dropLabel = text('drop');
    library.addEventListener('dragover', event => { if (event.dataTransfer?.types.includes('Files')) { event.preventDefault(); library.classList.add('is-dropping'); } });
    library.addEventListener('dragleave', event => { if (!library.contains(event.relatedTarget as Node)) library.classList.remove('is-dropping'); });
    library.addEventListener('drop', event => {
      if (!event.dataTransfer?.files.length) return;
      event.preventDefault();
      library.classList.remove('is-dropping');
      void send([...event.dataTransfer.files].filter(file => fits(file, null)));
    });
  }
}

function copyButtons(root: ParentNode): void {
  root.querySelectorAll<HTMLButtonElement>('[data-media-copy]').forEach(button => {
    if (button.dataset.mediaReady === 'true') return;
    button.dataset.mediaReady = 'true';
    const field = document.getElementById(button.dataset.mediaCopy ?? '') as HTMLInputElement | null;
    if (!field || !navigator.clipboard) return;
    button.hidden = false;
    const label = button.textContent ?? '';
    button.addEventListener('click', () => {
      void navigator.clipboard.writeText(field.value).then(() => {
        button.textContent = button.dataset.copied ?? label;
        window.setTimeout(() => { button.textContent = label; }, 1500);
      });
    });
  });
}

export function initializeMedia(root: ParentNode): void {
  (window as unknown as { FloAdminMedia?: { pick: typeof pick } }).FloAdminMedia = { pick };
  root.querySelectorAll<HTMLElement>('[data-admin-media-field]').forEach(field => { if (field.dataset.mediaReady !== 'true') new MediaField(field); });
  root.querySelectorAll<HTMLFormElement>('[data-admin-media-upload]').forEach(form => { if (form.dataset.mediaReady !== 'true') { form.dataset.mediaReady = 'true'; uploadPage(form); } });
  copyButtons(root);
}
