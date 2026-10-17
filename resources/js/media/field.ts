/**
 * Media fields (0.18): a framed card of their own.
 *  - one item: a large preview with Change, Edit details and Remove; file name, size, and a
 *    "No alt text" badge;
 *  - several: tiles with a grip to drag them into order (mouse, touch, pen), Alt + arrow keys
 *    to move the focused one, and a ⋯ menu (Edit details, Focal point, Replace, Move, Remove);
 *  - empty: a drop area; files dropped on the field are uploaded into the library and used.
 * Without JavaScript the field is a select (rendered by the server).
 */
import { button, element, fileSize, icon, preview, t, uploadMedia, fits, acceptFor, config, type Config, type MediaItem } from './api';
import { editDetails } from './batch';
import { pick } from './picker';
import { openMenu, announce } from './ui';

type Slot = MediaItem & { uploading?: number; missing?: boolean };

const ready = new WeakSet<HTMLElement>();

export class MediaField {
  private readonly cfg: Config;
  private readonly kind: 'image' | 'video' | null;
  private readonly multiple: boolean;
  private readonly max: number | null;
  private readonly folder: number | null;
  private readonly folderPath: string;
  private readonly aspect: string;
  private readonly label: string;
  private readonly disabled: boolean;
  private items: Slot[];
  private readonly value: HTMLInputElement;
  private readonly frame = element('div', 'fa-mf');
  private readonly live = element('p', 'fa-sr');
  private readonly fileInput = element('input', 'fa-sr');

  constructor(private readonly root: HTMLElement, cfg: Config) {
    this.cfg = cfg;
    const kind = root.dataset.kind;
    this.kind = kind === 'image' || kind === 'video' ? kind : null;
    this.multiple = root.dataset.multiple === 'true';
    this.max = root.dataset.max ? Number(root.dataset.max) : null;
    this.folder = root.dataset.folder ? Number(root.dataset.folder) : null;
    this.folderPath = root.dataset.folderPath ?? '';
    this.aspect = root.dataset.aspect || (this.kind === 'video' ? '16 / 9' : '4 / 3');
    this.label = root.dataset.label ?? '';
    const select = root.querySelector<HTMLSelectElement>('[data-media-select]');
    this.value = root.querySelector<HTMLInputElement>('[data-media-value]')!;
    this.disabled = select?.disabled ?? root.dataset.disabled === 'true';
    // The script's own value (it keeps the order); the select is for pages without JavaScript.
    root.querySelector<HTMLElement>('[data-media-nojs]')?.setAttribute('hidden', '');
    if (select) select.disabled = true;
    if (!this.disabled) this.value.disabled = false;
    const known: MediaItem[] = JSON.parse(root.dataset.items ?? '[]');
    const ids = this.parse(this.value.value);
    // Ids the server knew but the library no longer has keep their place, shown as missing.
    this.items = ids.map(id => known.find(item => item.id === id) ?? this.missing(id));
    this.live.setAttribute('aria-live', 'polite');
    this.fileInput.type = 'file';
    this.fileInput.accept = acceptFor(this.kind);
    this.fileInput.multiple = this.multiple;
    this.fileInput.tabIndex = -1;
    this.fileInput.setAttribute('aria-hidden', 'true');
    this.fileInput.addEventListener('change', () => { void this.upload([...(this.fileInput.files ?? [])]); this.fileInput.value = ''; });
    this.frame.classList.add(this.multiple ? 'is-multiple' : 'is-single');
    if (this.disabled) this.frame.classList.add('is-disabled');
    for (const own of [this.frame, this.live, this.fileInput]) own.dataset.mfOwn = '';
    root.append(this.frame, this.live, this.fileInput);
    this.dropFiles();
    this.render();
  }

  private parse(value: string): number[] {
    const text = value.trim();
    if (text === '') return [];
    try {
      const list = text.startsWith('[') ? JSON.parse(text) as unknown[] : [text];
      return list.map(Number).filter(id => Number.isInteger(id) && id > 0);
    } catch { return []; }
  }

  private missing(id: number): Slot {
    return { id, kind: this.kind ?? 'image', url: '', thumb: '', title: '', label: t(this.cfg, 'missing_item', { id }), alt: '', name: '', width: null, height: null, thumbWidth: null, thumbHeight: null, missing: true };
  }

  private ids(): number[] { return this.items.filter(item => item.uploading === undefined).map(item => item.id); }

  private save(): void {
    const ids = this.ids();
    this.value.value = this.multiple ? (ids.length ? JSON.stringify(ids.map(String)) : '') : (ids[0] ? String(ids[0]) : '');
    this.value.dispatchEvent(new Event('input', { bubbles: true }));
    this.value.dispatchEvent(new Event('change', { bubbles: true }));
  }

  private full(): boolean { return this.max !== null && this.items.length >= this.max; }

  /* ------------------------------------------------------------------ drawing */

  private render(): void {
    if (this.items.length === 0) { this.frame.replaceChildren(this.empty()); return; }
    if (!this.multiple) { this.frame.replaceChildren(this.single(this.items[0])); return; }
    this.frame.replaceChildren(this.head(), this.gallery(), this.foot());
  }

  private empty(): HTMLElement {
    const { cfg } = this;
    const box = element('div', 'fa-mf-empty');
    const circle = element('span', 'fa-mf-circle');
    circle.innerHTML = icon(this.kind === 'video' ? 'video' : 'image', 22);
    box.append(circle, element('span', 'fa-mf-empty-title', t(cfg, 'drop_here_field')));
    const limit = this.kind === 'video' ? fileSize(cfg.maxVideo) : fileSize(cfg.maxImage);
    box.append(element('span', 'fa-mf-empty-sub', this.kind === 'video' ? `MP4, WebM · ${limit}` : this.kind === 'image' ? `JPEG, PNG, WebP, GIF · ${limit}` : `JPEG, PNG, WebP, GIF, MP4, WebM`));
    if (this.disabled) return box;
    const actions = element('div', 'fa-mf-empty-actions');
    actions.append(this.chooseButton(), ...this.uploadButton());
    box.append(actions);
    return box;
  }

  private single(item: Slot): HTMLElement {
    const { cfg } = this;
    const wrap = element('div', 'fa-mf-single');
    const frame = element('div', 'fa-mf-preview');
    frame.style.aspectRatio = this.aspect;
    if (item.uploading !== undefined) {
      frame.classList.add('is-uploading');
      frame.append(this.progressBar(item.uploading, item.label));
    } else if (item.missing) {
      frame.classList.add('is-missing');
      frame.append(element('span', 'fa-mf-missing', item.label));
    } else if (item.kind === 'image') {
      const image = element('img');
      image.src = item.url; image.alt = ''; image.decoding = 'async';
      if (item.focalX != null && item.focalY != null) image.style.objectPosition = `${item.focalX}% ${item.focalY}%`;
      frame.append(image);
    } else {
      const video = element('video');
      video.src = item.url; video.preload = 'metadata'; video.muted = true; video.playsInline = true;
      frame.append(video, element('span', 'fa-mf-play'));
      frame.querySelector('.fa-mf-play')!.innerHTML = icon('play', 20);
    }
    if (!this.disabled && item.uploading === undefined) {
      const actions = element('div', 'fa-mf-actions');
      const change = this.chooseButton(t(cfg, 'change'));
      actions.append(change);
      if (!item.missing && cfg.can.update) {
        const edit = button('fa-btn fa-btn-sm', t(cfg, 'edit_details'));
        edit.addEventListener('click', () => this.edit(0));
        actions.append(edit);
      }
      const remove = button('fa-btn fa-btn-sm', t(cfg, 'remove'));
      remove.addEventListener('click', () => { this.items = []; this.render(); this.save(); this.frame.querySelector<HTMLElement>('button')?.focus(); this.say(t(cfg, 'remove_named', { name: item.label })); });
      actions.append(remove);
      frame.append(actions);
    }
    const meta = element('div', 'fa-mf-meta');
    const text = element('div', 'fa-mf-meta-text');
    const name = element('span', 'fa-mf-name', item.label);
    name.dir = 'auto';
    const sub = element('span', 'fa-mf-sub', [item.name !== item.label ? item.name : '', item.width && item.height ? `⁦${item.width} × ${item.height}⁩` : '', item.bytes ? fileSize(item.bytes) : ''].filter(Boolean).join(' · '));
    sub.dir = 'auto';
    text.append(name, sub);
    meta.append(text);
    if (item.kind === 'image' && item.hasAlt === false && !item.missing) meta.append(this.noAlt(0));
    if (item.focalX != null) meta.append(element('span', 'fa-badge fa-badge-blue', t(cfg, 'focal_set')));
    wrap.append(frame, meta);
    return wrap;
  }

  private head(): HTMLElement {
    const { cfg } = this;
    const head = element('div', 'fa-mf-head');
    const title = element('span', 'fa-mf-head-title');
    title.innerHTML = icon(this.kind === 'video' ? 'video' : 'image');
    title.append(element('span', '', t(cfg, 'count', { count: this.items.length })));
    if (this.max !== null) title.append(element('span', 'fa-mf-count', t(cfg, 'limit_many', { max: this.max })));
    head.append(title, element('span', 'fa-mb-spacer'));
    if (!this.disabled) head.append(this.chooseButton(t(cfg, 'choose_from_library')), ...this.uploadButton());
    return head;
  }

  private foot(): HTMLElement {
    const foot = element('div', 'fa-mf-foot');
    foot.innerHTML = icon('grip', 14);
    foot.append(element('span', '', t(this.cfg, 'drag_hint')));
    return foot;
  }

  private gallery(): HTMLElement {
    const { cfg } = this;
    const grid = element('ul', 'fa-mf-grid');
    grid.setAttribute('aria-label', this.label);
    this.items.forEach((item, index) => {
      const tile = element('li', 'fa-mf-tile');
      tile.dataset.id = String(item.id);
      if (item.uploading !== undefined) {
        tile.classList.add('is-uploading');
        tile.append(this.progressBar(item.uploading, item.label));
        grid.append(tile);
        return;
      }
      tile.tabIndex = 0;
      tile.setAttribute('aria-label', t(cfg, 'tile_label', { name: item.label, position: index + 1, total: this.items.length }));
      if (item.missing) { tile.classList.add('is-missing'); tile.append(element('span', 'fa-mf-missing', item.label)); }
      else tile.append(preview(item, 'fa-mf-img'));
      tile.append(element('span', 'fa-mf-order', String(index + 1)));
      if (item.kind === 'image' && item.hasAlt === false && !item.missing) tile.append(this.noAlt(index));
      if (!this.disabled) {
        const grip = element('span', 'fa-mf-grip');
        grip.innerHTML = icon('grip', 14);
        grip.setAttribute('aria-hidden', 'true');
        const more = button('fa-mf-more', t(cfg, 'options_named', { name: item.label }), 'more', true);
        more.setAttribute('aria-haspopup', 'menu');
        more.addEventListener('click', event => { event.stopPropagation(); this.menu(more, index); });
        tile.append(grip, more);
        this.draggable(tile, grid);
        tile.addEventListener('keydown', event => this.keydown(event, index));
      }
      grid.append(tile);
    });
    if (!this.disabled && !this.full()) {
      const add = element('li', 'fa-mf-add-slot');
      const addButton = element('button', 'fa-mf-add');
      addButton.type = 'button';
      addButton.innerHTML = icon('plus', 22);
      addButton.append(element('span', '', t(cfg, 'add')), element('small', '', t(cfg, 'or_drop')));
      addButton.addEventListener('click', () => void this.open());
      add.append(addButton);
      grid.append(add);
    }
    return grid;
  }

  private noAlt(index: number): HTMLElement {
    const badge = element(this.disabled || !this.cfg.can.update ? 'span' : 'button', 'fa-badge fa-badge-yellow fa-mf-noalt');
    badge.innerHTML = icon('warn', 12);
    badge.append(element('span', '', t(this.cfg, 'no_alt_short')));
    if (badge instanceof HTMLButtonElement) { badge.type = 'button'; badge.addEventListener('click', event => { event.stopPropagation(); this.edit(index); }); }
    return badge;
  }

  private progressBar(percent: number, name: string): HTMLElement {
    const box = element('div', 'fa-mf-progress');
    box.append(element('span', 'fa-mf-progress-name', name));
    const bar = element('div', 'fa-mf-bar');
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-label', name);
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', '100');
    bar.setAttribute('aria-valuenow', String(percent));
    const fill = element('i');
    fill.style.width = `${percent}%`;
    bar.append(fill);
    box.append(bar);
    return box;
  }

  private chooseButton(text?: string): HTMLButtonElement {
    const { cfg } = this;
    const choose = button('fa-btn fa-btn-sm', text ?? t(cfg, 'choose_from_library'), text ? undefined : 'library');
    choose.disabled = this.multiple && this.full();
    choose.addEventListener('click', () => void this.open());
    return choose;
  }

  private uploadButton(): HTMLButtonElement[] {
    if (!this.cfg.can.create) return [];
    const upload = button('fa-btn fa-btn-sm', t(this.cfg, 'upload'), 'upload');
    upload.disabled = this.multiple && this.full();
    upload.addEventListener('click', () => this.fileInput.click());
    return [upload];
  }

  /* ------------------------------------------------------------------ actions */

  private say(text: string): void {
    this.live.textContent = text;
  }

  private async open(replaceAt: number | null = null): Promise<void> {
    const picked = await pick({
      kind: this.kind, multiple: this.multiple && replaceAt === null, max: this.max,
      already: this.multiple ? this.ids() : [], folder: this.folder, folderPath: this.folderPath,
    });
    if (!picked || picked.length === 0) return;
    if (replaceAt !== null) {
      this.items[replaceAt] = picked[0];
    } else if (this.multiple) {
      for (const item of picked) if (!this.items.some(entry => entry.id === item.id) && !this.full()) this.items.push(item);
    } else {
      this.items = [picked[0]];
    }
    this.render();
    this.save();
    this.frame.querySelector<HTMLElement>(replaceAt !== null ? `.fa-mf-tile[data-id="${picked[0].id}"]` : '.fa-mf-add, .fa-mf-actions button, .fa-btn')?.focus();
  }

  private edit(index: number, focal = false): void {
    const item = this.items[index];
    if (!item || item.missing) return;
    editDetails(this.cfg, [item], changed => {
      for (const update of changed) {
        const at = this.items.findIndex(entry => entry.id === update.id);
        if (at >= 0) this.items[at] = { ...this.items[at], ...update };
      }
      this.render();
    }, { focal });
  }

  private menu(anchor: HTMLElement, index: number): void {
    const { cfg } = this;
    const item = this.items[index];
    const last = this.items.filter(entry => entry.uploading === undefined).length - 1;
    openMenu(anchor, [
      ...(cfg.can.update && !item.missing ? [
        { label: t(cfg, 'edit_details'), icon: 'pencil', run: () => this.edit(index) },
        ...(item.kind === 'image' ? [{ label: t(cfg, 'set_focal'), icon: 'target', run: () => this.edit(index, true) }] : []),
      ] : []),
      { label: t(cfg, 'replace_from_library'), icon: 'library', run: () => void this.open(index) },
      { label: '-', run: () => undefined },
      { label: t(cfg, 'move_earlier'), icon: 'chevronStart', disabled: index === 0, run: () => this.move(index, index - 1) },
      { label: t(cfg, 'move_later'), icon: 'chevronEnd', disabled: index === last, run: () => this.move(index, index + 1) },
      { label: '-', run: () => undefined },
      { label: t(cfg, 'remove'), icon: 'x', danger: true, run: () => this.remove(index) },
    ], t(cfg, 'options_named', { name: item.label }));
  }

  private remove(index: number): void {
    const [item] = this.items.splice(index, 1);
    this.render();
    this.save();
    const tiles = this.frame.querySelectorAll<HTMLElement>('.fa-mf-tile');
    (tiles[Math.min(index, tiles.length - 1)] ?? this.frame.querySelector<HTMLElement>('.fa-mf-add, .fa-btn'))?.focus();
    this.say(t(this.cfg, 'remove_named', { name: item.label }));
  }

  private move(from: number, to: number): void {
    if (to < 0 || to >= this.items.length || this.items[to].uploading !== undefined) return;
    const [item] = this.items.splice(from, 1);
    this.items.splice(to, 0, item);
    this.render();
    this.save();
    this.frame.querySelector<HTMLElement>(`.fa-mf-tile[data-id="${item.id}"]`)?.focus();
    this.say(t(this.cfg, 'moved', { name: item.label, position: to + 1, total: this.items.length }));
  }

  private keydown(event: KeyboardEvent, index: number): void {
    if (event.target !== event.currentTarget) return;
    const rtl = getComputedStyle(this.frame).direction === 'rtl';
    if (event.altKey && (event.key === 'ArrowLeft' || event.key === 'ArrowRight' || event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
      event.preventDefault();
      const forward = event.key === 'ArrowDown' || (event.key === 'ArrowRight' ? !rtl : event.key === 'ArrowLeft' ? rtl : false);
      this.move(index, index + (forward ? 1 : -1));
      return;
    }
    if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); this.remove(index); return; }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      const more = (event.currentTarget as HTMLElement).querySelector<HTMLElement>('.fa-mf-more');
      if (more) this.menu(more, index);
      return;
    }
    const tiles = [...this.frame.querySelectorAll<HTMLElement>('.fa-mf-tile[tabindex]')];
    const step = event.key === 'ArrowRight' ? (rtl ? -1 : 1) : event.key === 'ArrowLeft' ? (rtl ? 1 : -1) : event.key === 'Home' ? -tiles.length : event.key === 'End' ? tiles.length : 0;
    if (step !== 0) { event.preventDefault(); tiles[Math.max(0, Math.min(tiles.length - 1, index + step))]?.focus(); }
  }

  /** Drag a tile among the others (they may wrap to several rows, in either direction). */
  private draggable(tile: HTMLElement, grid: HTMLElement): void {
    tile.addEventListener('pointerdown', event => {
      if ((event.target as HTMLElement).closest('button') || (event.pointerType === 'mouse' && event.button !== 0)) return;
      // Touch: only the grip drags, so the page still scrolls over a tile.
      if (event.pointerType === 'touch' && !(event.target as HTMLElement).closest('.fa-mf-grip')) return;
      event.preventDefault();
      tile.setPointerCapture(event.pointerId);
      tile.classList.add('is-lifted');
      grid.classList.add('is-sorting');
      const rtl = getComputedStyle(grid).direction === 'rtl';
      const move = (e: PointerEvent) => {
        const others = [...grid.querySelectorAll<HTMLElement>('.fa-mf-tile:not(.is-uploading)')].filter(other => other !== tile);
        const target = others.find(other => { const r = other.getBoundingClientRect(); return e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom; });
        if (!target) return;
        const r = target.getBoundingClientRect();
        const before = rtl ? e.clientX > r.left + r.width / 2 : e.clientX < r.left + r.width / 2;
        grid.insertBefore(tile, before ? target : target.nextElementSibling);
        try { tile.setPointerCapture(e.pointerId); } catch { /* the pointer is gone */ }
      };
      const end = () => {
        tile.removeEventListener('pointermove', move);
        tile.removeEventListener('pointerup', end);
        tile.removeEventListener('pointercancel', end);
        tile.classList.remove('is-lifted');
        grid.classList.remove('is-sorting');
        const order = [...grid.querySelectorAll<HTMLElement>('.fa-mf-tile')].map(c => Number(c.dataset.id));
        if (order.join() !== this.items.map(item => item.id).join()) {
          this.items.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
          const id = Number(tile.dataset.id);
          this.render();
          this.save();
          const position = this.items.findIndex(item => item.id === id);
          this.frame.querySelector<HTMLElement>(`.fa-mf-tile[data-id="${id}"]`)?.focus();
          this.say(t(this.cfg, 'moved', { name: this.items[position].label, position: position + 1, total: this.items.length }));
        }
      };
      tile.addEventListener('pointermove', move);
      tile.addEventListener('pointerup', end);
      tile.addEventListener('pointercancel', end);
    });
  }

  /* ------------------------------------------------------------------ uploads */

  private dropFiles(): void {
    if (this.disabled || !this.cfg.can.create) return;
    let depth = 0;
    const carries = (event: DragEvent) => Boolean(event.dataTransfer?.types.includes('Files'));
    this.frame.addEventListener('dragenter', event => { if (!carries(event)) return; event.preventDefault(); depth++; this.frame.classList.add('is-over'); });
    this.frame.addEventListener('dragover', event => { if (!carries(event)) return; event.preventDefault(); event.dataTransfer!.dropEffect = 'copy'; });
    this.frame.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (depth === 0) this.frame.classList.remove('is-over'); });
    this.frame.addEventListener('drop', event => {
      if (!carries(event)) return;
      event.preventDefault();
      depth = 0;
      this.frame.classList.remove('is-over');
      void this.upload([...event.dataTransfer!.files]);
    });
  }

  /** Upload into the library (the field's folder) and use the new items; a file already there is reused. */
  private async upload(files: File[]): Promise<void> {
    const { cfg } = this;
    const room = this.multiple ? (this.max === null ? Infinity : this.max - this.items.length) : 1;
    const accepted = files.filter(file => fits(file, this.kind)).slice(0, Math.max(0, room));
    const refused = files.find(file => !fits(file, this.kind));
    if (refused) announce(t(cfg, 'wrong_type', { name: refused.name }), 'danger');
    if (accepted.length === 0) return;
    for (const file of accepted) {
      // A single field keeps its item until the new one has arrived.
      const before = [...this.items];
      const slot: Slot = { id: -Date.now() - Math.random(), kind: file.type.startsWith('video/') ? 'video' : 'image', url: '', thumb: '', title: '', label: file.name, alt: '', name: file.name, width: null, height: null, thumbWidth: null, thumbHeight: null, uploading: 0 };
      if (this.multiple) this.items.push(slot); else this.items = [slot];
      this.render();
      try {
        const outcome = await uploadMedia(cfg, file, percent => {
          slot.uploading = percent;
          const bar = this.frame.querySelector<HTMLElement>(`[aria-label="${CSS.escape(file.name)}"][role="progressbar"]`);
          if (bar) { bar.setAttribute('aria-valuenow', String(percent)); bar.querySelector('i')!.style.width = `${percent}%`; }
        }, { folder: this.folder, duplicates: 'use', folderPath: this.folderPath });
        const item = 'item' in outcome ? outcome.item : outcome.duplicate.existing;
        const at = this.items.indexOf(slot);
        if (this.items.some(entry => entry.id === item.id)) this.items.splice(at, 1);
        else this.items[at] = item;
        if (item.reused) announce(t(cfg, 'reused', { name: item.label }), 'info');
      } catch (error) {
        if (this.multiple) this.items.splice(this.items.indexOf(slot), 1); else this.items = before;
        announce(error instanceof Error && error.message ? error.message : t(cfg, 'failed'), 'danger');
      }
      this.render();
      this.save();
    }
  }
}

/** Set up every media field under root (also after Reactive updates). */
export function initializeMediaFields(root: ParentNode): void {
  const cfg = config();
  if (!cfg) return;
  root.querySelectorAll<HTMLElement>('[data-admin-media-field]').forEach(field => {
    // Reactive updates morph the markup in place; a field already set up keeps its own state.
    if (ready.has(field) && field.querySelector('.fa-mf')) return;
    ready.add(field);
    field.querySelectorAll(':scope > [data-mf-own]').forEach(node => node.remove());
    new MediaField(field, cfg);
  });
}
