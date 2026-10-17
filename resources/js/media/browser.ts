/**
 * The media browser (0.18): the Media page (mode "manage") and the picker dialog
 * (mode "choose") are this one component. Folders on the start side, the items in
 * the middle, details of the focused item on the end side.
 */
import {
  ApiError, button, element, fileSize, folderLabel, getJson, icon, postForm, preview, t, acceptFor, focalFromClick, placeFocal,
  type Config, type Details, type Folder, type Folders, type MediaItem,
} from './api';
import { UploadQueue } from './queue';
import { announce, confirmDialog, folderMenu, openMenu, promptDialog } from './ui';

export type Query = {
  q: string; kind: 'image' | 'video' | null; status: 'unused' | 'no_alt' | null;
  sort: 'newest' | 'oldest' | 'name' | 'largest'; folder: number | 'unfiled' | null; scope: 'all' | 'folder';
};

export type BrowserOptions = {
  mode: 'manage' | 'choose';
  /** Only this kind can be listed and chosen (a field's ->images() / ->videos()). */
  kind?: 'image' | 'video' | null;
  multiple?: boolean;
  /** At most this many may be chosen (minus `already`). */
  max?: number | null;
  /** Items the field already has: shown, not chosen again. */
  already?: number[];
  query?: Partial<Query>;
  /** Keep the address in step with the query (the Media page). */
  syncUrl?: boolean;
  /** Uploads with no open folder go to this path (a field's ->folder()), made when missing. */
  folderPath?: string;
  /** Where the upload queue panel goes (default: the browser itself). */
  queueHost?: HTMLElement;
  /** The batch editor (the Media page's "Edit details"). */
  editMany?: (items: MediaItem[], done: (changed: MediaItem[]) => void) => void;
  onSelection?: (items: MediaItem[]) => void;
  onChoose?: (items: MediaItem[]) => void;
};

const SORTS: Query['sort'][] = ['newest', 'oldest', 'name', 'largest'];
const MIME = 'application/x-flo-media';
const FOLDER_MIME = 'application/x-flo-media-folder';

export class MediaBrowser {
  private query: Query;
  private items: MediaItem[] = [];
  private page = 1;
  private pages = 1;
  private total = 0;
  private folders: Folders = { tree: [], counts: {} };
  private readonly selected = new Map<number, MediaItem>();
  private focused: number | null = null;
  private anchor: number | null = null;
  private details: Details | null = null;
  private view: 'grid' | 'list';
  private token = 0;
  private abort: AbortController | null = null;
  private detailsAbort: AbortController | null = null;
  private searchTimer = 0;
  private readonly collapsed = new Set<number>();
  private readonly queue: UploadQueue | null;
  private readonly already: Set<number>;
  private focal: { x: number; y: number } | null = null;
  private focalEditing = false;

  private readonly tree = element('nav', 'fa-mb-tree');
  private readonly pane = element('div', 'fa-mb-pane');
  private readonly toolbar = element('div', 'fa-mb-toolbar');
  private readonly search = element('input', 'fa-input');
  private readonly kindSelect = element('select', 'fa-input fa-select');
  private readonly statusSelect = element('select', 'fa-input fa-select');
  private readonly sortSelect = element('select', 'fa-input fa-select');
  private readonly viewSwitch = element('div', 'fa-mb-seg');
  private readonly batch = element('div', 'fa-mb-batch');
  private readonly crumbs = element('div', 'fa-mb-crumbs');
  private readonly scroller = element('div', 'fa-mb-scroll');
  private readonly grid = element('ul', 'fa-mb-grid');
  private readonly more = element('button', 'fa-btn fa-mb-more');
  private readonly status = element('p', 'fa-mb-status');
  private readonly detailsPane = element('aside', 'fa-mb-details');
  private readonly fileInput = element('input', 'fa-sr');

  constructor(readonly root: HTMLElement, private readonly cfg: Config, private readonly options: BrowserOptions) {
    const base: Query = { q: '', kind: options.kind ?? null, status: null, sort: 'newest', folder: null, scope: 'all' };
    this.query = { ...base, ...options.query, kind: options.kind ?? options.query?.kind ?? null };
    this.already = new Set(options.already ?? []);
    this.view = this.storedView();
    this.build();
    this.queue = cfg.can.create ? new UploadQueue(cfg, {
      folder: () => (typeof this.query.folder === 'number' ? this.query.folder : null),
      folderPath: () => (this.query.folder === null ? options.folderPath ?? '' : ''),
      duplicates: options.mode === 'manage' ? 'ask' : 'use',
      kind: options.kind ?? null,
      host: options.queueHost ?? this.root,
      onItem: item => this.uploaded(item),
    }) : null;
    void this.load(true, true);
  }

  /** The folder tree as last loaded. */
  folderData(): Folders {
    return this.folders;
  }

  /** The chosen items, in the order they were chosen. */
  selection(): MediaItem[] {
    return [...this.selected.values()];
  }

  focusSearch(): void {
    this.search.focus();
  }

  /** Open the file chooser (the Media page's Upload button). */
  chooseFiles(): void {
    this.fileInput.click();
  }

  /** Create a folder in the current one (the Media page's New folder button). */
  newFolder(): void {
    void this.createFolder(typeof this.query.folder === 'number' ? this.query.folder : null);
  }

  /* ------------------------------------------------------------------ building */

  private build(): void {
    const { cfg } = this;
    const choose = this.options.mode === 'choose';
    this.root.replaceChildren();
    this.root.classList.add('fa-mb', 'is-js', choose ? 'is-choose' : 'is-manage');
    this.root.removeAttribute('data-media-browser');

    this.tree.setAttribute('aria-label', t(cfg, 'folders'));
    this.detailsPane.setAttribute('aria-label', t(cfg, 'details'));

    // Toolbar: search, filters, sort, view, upload.
    const searchBox = element('span', 'fa-search fa-mb-search');
    searchBox.insertAdjacentHTML('beforeend', icon('search'));
    const searchLabel = element('label', 'fa-sr', t(cfg, 'search'));
    this.search.type = 'search';
    this.search.id = `fa-mb-q-${Math.random().toString(36).slice(2)}`;
    searchLabel.htmlFor = this.search.id;
    this.search.placeholder = t(cfg, 'search_placeholder');
    this.search.maxLength = 100;
    this.search.value = this.query.q;
    this.search.addEventListener('input', () => {
      window.clearTimeout(this.searchTimer);
      this.searchTimer = window.setTimeout(() => { this.query.q = this.search.value.trim(); void this.load(true); }, 250);
    });
    this.search.addEventListener('keydown', event => {
      if (event.key === 'Enter') { event.preventDefault(); window.clearTimeout(this.searchTimer); this.query.q = this.search.value.trim(); void this.load(true); }
      if (event.key === 'ArrowDown') { event.preventDefault(); this.focusTile(this.focused ?? this.items[0]?.id ?? null); }
    });
    searchBox.append(searchLabel, this.search);

    this.toolbar.append(searchBox);
    if (!this.options.kind) {
      this.fillSelect(this.kindSelect, t(cfg, 'kind'), [['', t(cfg, 'all_types')], ['image', t(cfg, 'images')], ['video', t(cfg, 'videos')]], this.query.kind ?? '');
      this.kindSelect.addEventListener('change', () => { this.query.kind = (this.kindSelect.value || null) as Query['kind']; void this.load(true); });
      this.toolbar.append(this.kindSelect);
    }
    if (!choose) {
      this.fillSelect(this.statusSelect, t(cfg, 'status'), [['', t(cfg, 'any_status')], ['unused', t(cfg, 'unused')], ['no_alt', t(cfg, 'no_alt')]], this.query.status ?? '');
      this.statusSelect.addEventListener('change', () => { this.query.status = (this.statusSelect.value || null) as Query['status']; void this.load(true); });
      this.toolbar.append(this.statusSelect);
    }
    this.fillSelect(this.sortSelect, t(cfg, 'sort'), SORTS.map(sort => [sort, t(cfg, `sort_${sort}`)]), this.query.sort);
    this.sortSelect.addEventListener('change', () => { this.query.sort = this.sortSelect.value as Query['sort']; void this.load(true); });
    this.toolbar.append(this.sortSelect);

    this.viewSwitch.setAttribute('role', 'group');
    this.viewSwitch.setAttribute('aria-label', t(cfg, 'view'));
    for (const view of ['grid', 'list'] as const) {
      const option = button('', t(cfg, `view_${view}`), view, true);
      option.dataset.view = view;
      option.setAttribute('aria-pressed', String(this.view === view));
      option.addEventListener('click', () => this.setView(view));
      this.viewSwitch.append(option);
    }
    this.toolbar.append(this.viewSwitch);
    if (cfg.can.create) {
      const upload = button('fa-btn', t(cfg, 'upload'), 'upload');
      upload.addEventListener('click', () => this.fileInput.click());
      upload.hidden = !choose;
      this.fileInput.type = 'file';
      this.fileInput.multiple = this.options.mode === 'manage' || Boolean(this.options.multiple);
      this.fileInput.accept = acceptFor(this.options.kind ?? null);
      this.fileInput.tabIndex = -1;
      this.fileInput.setAttribute('aria-hidden', 'true');
      this.fileInput.addEventListener('change', () => { this.queue?.add([...(this.fileInput.files ?? [])]); this.fileInput.value = ''; });
      this.toolbar.append(upload, this.fileInput);
    }

    this.batch.hidden = true;
    this.batch.setAttribute('role', 'toolbar');
    this.batch.setAttribute('aria-label', t(cfg, 'selected_items'));

    this.grid.setAttribute('role', 'listbox');
    this.grid.setAttribute('aria-label', t(cfg, 'items'));
    if (this.options.mode === 'manage' || this.options.multiple) this.grid.setAttribute('aria-multiselectable', 'true');
    this.grid.classList.toggle('is-list', this.view === 'list');
    this.grid.addEventListener('keydown', event => this.keydown(event));

    this.more.type = 'button';
    this.more.textContent = t(cfg, 'more');
    this.more.hidden = true;
    this.more.addEventListener('click', () => void this.load(false));
    this.status.setAttribute('role', 'status');

    this.scroller.append(this.grid, this.status, this.more);
    this.pane.append(this.toolbar, this.batch, this.crumbs, this.scroller);
    this.root.append(this.tree, this.pane, this.detailsPane);
    this.dropFiles();
    this.renderDetails();
  }

  private fillSelect(select: HTMLSelectElement, label: string, options: string[][], value: string): void {
    select.setAttribute('aria-label', label);
    select.replaceChildren(...options.map(([key, text]) => { const option = element('option', '', text); option.value = key; return option; }));
    select.value = value;
  }

  private storedView(): 'grid' | 'list' {
    try { return localStorage.getItem('fa-media-view') === 'list' ? 'list' : 'grid'; } catch { return 'grid'; }
  }

  private setView(view: 'grid' | 'list'): void {
    this.view = view;
    try { localStorage.setItem('fa-media-view', view); } catch { /* private window */ }
    this.viewSwitch.querySelectorAll<HTMLButtonElement>('button').forEach(option => option.setAttribute('aria-pressed', String(option.dataset.view === view)));
    this.grid.classList.toggle('is-list', view === 'list');
    this.renderItems();
  }

  /* ------------------------------------------------------------------ loading */

  private url(page: number, extra: Record<string, string> = {}): string {
    const url = new URL(this.cfg.urls.lookup, location.href);
    const q = this.query;
    if (q.q) url.searchParams.set('q', q.q);
    if (q.kind) url.searchParams.set('kind', q.kind);
    if (q.status) url.searchParams.set('status', q.status);
    if (q.sort !== 'newest') url.searchParams.set('sort', q.sort);
    if (q.folder !== null) url.searchParams.set('folder', String(q.folder));
    if (q.scope === 'folder') url.searchParams.set('scope', 'folder');
    url.searchParams.set('page', String(page));
    url.searchParams.set('per', '40');
    for (const [key, value] of Object.entries(extra)) url.searchParams.set(key, value);
    return url.toString();
  }

  private async load(reset: boolean, withFolders = false): Promise<void> {
    const mine = ++this.token;
    this.abort?.abort();
    this.abort = new AbortController();
    const want = reset ? 1 : this.page + 1;
    this.root.setAttribute('aria-busy', 'true');
    if (reset) this.status.textContent = t(this.cfg, 'loading');
    try {
      const data = await getJson<{ items: MediaItem[]; page: number; pages: number; total: number; folders?: Folders; stats?: Record<string, number> }>(
        this.url(want, withFolders ? { tree: '1', stats: this.options.mode === 'manage' ? '1' : '0' } : {}), this.abort.signal);
      if (mine !== this.token) return;
      if (data.folders) { this.folders = data.folders; this.renderTree(); }
      if (data.stats) this.renderStats(data.stats);
      if (reset) { this.items = []; }
      const known = new Set(this.items.map(item => item.id));
      for (const item of data.items) if (!known.has(item.id)) this.items.push(item);
      this.page = data.page;
      this.pages = data.pages;
      this.total = data.total;
      if (reset && !withFolders) this.renderTree();
      this.renderItems();
      this.renderCrumbs();
      if (this.options.syncUrl) this.syncUrl();
    } catch (error) {
      if (mine !== this.token || (error instanceof DOMException && error.name === 'AbortError')) return;
      this.status.textContent = t(this.cfg, 'failed');
    } finally {
      if (mine === this.token) this.root.removeAttribute('aria-busy');
    }
  }

  /** Folders and the header's numbers again, after a change. */
  private async refreshMeta(): Promise<void> {
    try {
      const data = await getJson<{ folders: Folders; stats?: Record<string, number> }>(this.url(1, { tree: '1', stats: this.options.mode === 'manage' ? '1' : '0', per: '1' }));
      this.folders = data.folders;
      this.renderTree();
      this.renderCrumbs();
      if (data.stats) this.renderStats(data.stats);
    } catch { /* the next load shows them */ }
  }

  private syncUrl(): void {
    const url = new URL(location.href);
    for (const key of ['q', 'kind', 'status', 'sort', 'folder', 'scope', 'page', 'unused']) url.searchParams.delete(key);
    const q = this.query;
    if (q.q) url.searchParams.set('q', q.q);
    if (q.kind && !this.options.kind) url.searchParams.set('kind', q.kind);
    if (q.status) url.searchParams.set('status', q.status);
    if (q.sort !== 'newest') url.searchParams.set('sort', q.sort);
    if (q.folder !== null) url.searchParams.set('folder', String(q.folder));
    if (q.scope === 'folder') url.searchParams.set('scope', 'folder');
    history.replaceState(history.state, '', url.toString());
  }

  /* ------------------------------------------------------------------ folders */

  private renderTree(): void {
    const { cfg } = this;
    const canUpdate = cfg.can.update;
    const head = element('div', 'fa-mb-tree-head');
    head.append(element('span', '', t(cfg, 'folders')));
    if (canUpdate) {
      const add = button('fa-icon-btn', t(cfg, 'new_folder'), 'folderPlus', true);
      add.addEventListener('click', () => void this.createFolder(typeof this.query.folder === 'number' ? this.query.folder : null));
      head.append(add);
    }
    const counts = this.folders.counts;
    const list = element('div', 'fa-mb-folders');
    list.append(this.folderRow(null, t(cfg, 'all_media'), counts.all ?? 0, 0, 0, 'library'));
    list.append(this.folderRow('unfiled', t(cfg, 'unfiled'), counts.unfiled ?? 0, 0, 0, 'folder'));
    if (this.folders.tree.length > 0) list.append(element('div', 'fa-mb-tree-sep'));
    const hidden = new Set<number>();
    for (const folder of this.folders.tree) {
      if (folder.parent !== null && (hidden.has(folder.parent) || this.collapsed.has(folder.parent))) { hidden.add(folder.id); continue; }
      list.append(this.folderRow(folder.id, folder.name, folder.count, folder.depth, folder.children, 'folder', folder));
    }
    this.tree.replaceChildren(head, list);
  }

  private folderRow(id: number | 'unfiled' | null, name: string, count: number, depth: number, children: number, iconName: string, folder?: Folder): HTMLElement {
    const { cfg } = this;
    const row = element('div', 'fa-mb-folder-row');
    row.style.setProperty('--depth', String(depth));
    const active = this.query.folder === id;
    if (typeof id !== 'number') {
      row.append(element('span', 'fa-mb-folder-toggle'));
    } else {
      if (children > 0) {
        const open = !this.collapsed.has(id);
        const toggle = button('fa-mb-folder-toggle', t(cfg, open ? 'collapse' : 'expand', { name }), 'chevronDown', true);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.addEventListener('click', () => { if (open) this.collapsed.add(id); else this.collapsed.delete(id); this.renderTree(); this.tree.querySelector<HTMLElement>(`[data-folder="${id}"]`)?.parentElement?.querySelector<HTMLElement>('.fa-mb-folder-toggle')?.focus(); });
        row.append(toggle);
      } else {
        row.append(element('span', 'fa-mb-folder-toggle'));
      }
    }
    const link = element('button', `fa-mb-folder${active ? ' is-active' : ''}`);
    link.type = 'button';
    link.dataset.folder = id === null ? 'all' : String(id);
    if (active) link.setAttribute('aria-current', 'true');
    link.insertAdjacentHTML('beforeend', icon(iconName));
    const label = element('span', 'fa-mb-folder-name', name);
    label.dir = 'auto';
    link.append(label, element('span', 'fa-mb-count', String(count)));
    link.addEventListener('click', () => this.openFolder(id));
    row.append(link);

    // Drop items (or, for folders, other folders) onto a folder to move them.
    if (cfg.can.update && id !== null) {
      link.addEventListener('dragover', event => {
        const types = event.dataTransfer?.types ?? [];
        if (!types.includes(MIME) && !(types.includes(FOLDER_MIME) && typeof id === 'number')) return;
        event.preventDefault();
        event.dataTransfer!.dropEffect = 'move';
        link.classList.add('is-drop');
      });
      link.addEventListener('dragleave', () => link.classList.remove('is-drop'));
      link.addEventListener('drop', event => {
        link.classList.remove('is-drop');
        const items = event.dataTransfer?.getData(MIME);
        const moved = event.dataTransfer?.getData(FOLDER_MIME);
        event.preventDefault();
        if (items) void this.moveItems(JSON.parse(items) as number[], id === 'unfiled' ? null : id);
        else if (moved && typeof id === 'number' && Number(moved) !== id) void this.folderAction('move', Number(moved), { parent: String(id) });
      });
    }
    if (folder && cfg.can.update) {
      link.draggable = true;
      link.addEventListener('dragstart', event => { event.dataTransfer?.setData(FOLDER_MIME, String(folder.id)); event.dataTransfer!.effectAllowed = 'move'; });
      if (active) {
        const more = button('fa-icon-btn fa-mb-folder-more', t(cfg, 'folder_options', { name }), 'more', true);
        more.setAttribute('aria-haspopup', 'menu');
        more.addEventListener('click', () => openMenu(more, [
          { label: t(cfg, 'new_folder_here'), icon: 'folderPlus', run: () => void this.createFolder(folder.id) },
          { label: t(cfg, 'rename_folder'), icon: 'pencil', run: () => void this.renameFolder(folder) },
          { label: t(cfg, 'move_folder'), icon: 'folderMove', run: () => this.moveFolderMenu(more, folder) },
          { label: '-', run: () => undefined },
          { label: t(cfg, 'delete_folder'), icon: 'trash', danger: true, run: () => void this.deleteFolder(folder) },
        ], t(cfg, 'folder_options', { name })));
        row.append(more);
      }
    }
    return row;
  }

  private openFolder(id: number | 'unfiled' | null): void {
    this.query.folder = id;
    this.query.q = '';
    this.query.scope = 'all';
    this.search.value = '';
    this.selected.clear();
    this.focused = null;
    this.renderBatch();
    void this.load(true);
  }

  private async createFolder(parent: number | null): Promise<void> {
    const name = await promptDialog(this.cfg, parent === null ? t(this.cfg, 'new_folder') : t(this.cfg, 'new_folder_in', { folder: folderLabel(this.folders, parent) }), '', t(this.cfg, 'create_folder'));
    if (name === null) return;
    const result = await this.folderAction('create', null, { name, parent: parent === null ? '' : String(parent) });
    if (result?.id) { if (parent !== null) this.collapsed.delete(parent); this.openFolder(result.id); }
  }

  private async renameFolder(folder: Folder): Promise<void> {
    const name = await promptDialog(this.cfg, t(this.cfg, 'rename_folder'), folder.name, t(this.cfg, 'save'));
    if (name !== null && name !== folder.name) await this.folderAction('rename', folder.id, { name });
  }

  private moveFolderMenu(anchor: HTMLElement, folder: Folder): void {
    const inside = new Set<number>([folder.id]);
    for (const other of this.folders.tree) if (other.parent !== null && inside.has(other.parent)) inside.add(other.id);
    openMenu(anchor, [
      { label: t(this.cfg, 'top_level'), icon: 'library', disabled: folder.parent === null, run: () => void this.folderAction('move', folder.id, { parent: '' }) },
      ...this.folders.tree.filter(other => !inside.has(other.id)).map(other => ({
        label: other.name, icon: 'folder', depth: other.depth, disabled: other.id === folder.parent,
        run: () => void this.folderAction('move', folder.id, { parent: String(other.id) }),
      })),
    ], t(this.cfg, 'move_folder'));
  }

  private async deleteFolder(folder: Folder): Promise<void> {
    if (!await confirmDialog(this.cfg, t(this.cfg, 'delete_folder_confirm', { name: folder.name }), t(this.cfg, 'delete_folder'), true)) return;
    const parent = folder.parent;
    if (await this.folderAction('delete', folder.id, {})) this.openFolder(parent);
  }

  private async folderAction(step: 'create' | 'rename' | 'move' | 'delete', id: number | null, data: Record<string, string>): Promise<{ id: number | null } | null> {
    try {
      const result = await postForm<{ id: number | null; message: string; folders: Folders }>(`${this.cfg.urls.folders}/${step}${id === null ? '' : `/${id}`}`, data, this.cfg.csrf);
      this.folders = result.folders;
      this.renderTree();
      this.renderCrumbs();
      announce(result.message);
      return result;
    } catch (error) {
      announce(error instanceof ApiError && error.message ? error.message : t(this.cfg, 'failed'), 'danger');
      return null;
    }
  }

  /* ------------------------------------------------------------------ items */

  private renderItems(): void {
    const { cfg } = this;
    const choose = this.options.mode === 'choose';
    const selectable = !choose || Boolean(this.options.multiple);
    this.grid.replaceChildren();
    this.grid.classList.toggle('is-selecting', this.selected.size > 0 && selectable);
    if (this.focused !== null && !this.items.some(item => item.id === this.focused)) this.focused = null;
    for (const item of this.items) {
      const tile = element('li', 'fa-mb-tile');
      tile.dataset.id = String(item.id);
      tile.setAttribute('role', 'option');
      const label = `${item.label}${item.kind === 'video' ? `, ${t(cfg, 'video')}` : ''}`;
      tile.setAttribute('aria-label', label);
      const isOn = this.selected.has(item.id);
      const added = this.already.has(item.id);
      tile.setAttribute('aria-selected', String(isOn));
      tile.tabIndex = (this.focused ?? this.items[0]?.id) === item.id ? 0 : -1;
      tile.classList.toggle('is-selected', isOn);
      tile.classList.toggle('is-focused', this.focused === item.id);
      if (added) { tile.classList.add('is-added'); tile.setAttribute('aria-disabled', 'true'); }

      const image = preview(item, 'fa-mb-img');
      if (selectable) {
        const check = element('span', 'fa-mb-check');
        check.innerHTML = icon('check', 14);
        check.setAttribute('aria-hidden', 'true');
        check.addEventListener('click', event => { event.stopPropagation(); this.toggle(item); });
        image.append(check);
      }
      const badges = element('span', 'fa-mb-badges');
      if (added) badges.append(element('span', 'fa-badge fa-badge-blue', t(cfg, 'added')));
      if (item.kind === 'image' && item.hasAlt === false) {
        const warn = element('span', 'fa-badge fa-badge-yellow fa-mb-noalt');
        warn.innerHTML = icon('warn', 12);
        warn.append(element('span', 'fa-sr', t(cfg, 'no_alt_short')));
        warn.title = t(cfg, 'no_alt_short');
        badges.append(warn);
      }
      if (!choose && item.used === 0) badges.append(element('span', 'fa-badge fa-badge-gray', t(cfg, 'not_used')));
      image.append(badges);
      const name = element('span', 'fa-mb-name', item.label);
      name.dir = 'auto';
      const sub = element('span', 'fa-mb-sub', [item.width && item.height ? `⁦${item.width} × ${item.height}⁩` : '', fileSize(item.bytes ?? 0)].filter(Boolean).join(' · '));
      const text = element('span', 'fa-mb-text');
      text.append(name, sub);
      tile.append(image, text);
      if (this.view === 'list') {
        const folderText = element('span', 'fa-mb-col fa-mb-col-folder', item.folder ? folderLabel(this.folders, item.folder) : t(cfg, 'unfiled'));
        folderText.dir = 'auto';
        tile.append(folderText, element('span', 'fa-mb-col', choose ? '' : t(cfg, 'used_count', { count: item.used ?? 0 })), element('span', 'fa-mb-col', (item.created ?? '').slice(0, 10)));
      }

      tile.addEventListener('click', event => this.click(item, event));
      tile.addEventListener('dblclick', () => { if (choose && !added) { this.selectOnly(item); this.choose(); } });
      if (!choose && cfg.can.update) {
        tile.draggable = true;
        tile.addEventListener('dragstart', event => {
          const ids = this.selected.has(item.id) ? [...this.selected.keys()] : [item.id];
          event.dataTransfer?.setData(MIME, JSON.stringify(ids));
          event.dataTransfer!.effectAllowed = 'move';
          tile.classList.add('is-dragging');
        });
        tile.addEventListener('dragend', () => tile.classList.remove('is-dragging'));
      }
      this.grid.append(tile);
    }
    const empty = this.items.length === 0;
    this.status.textContent = empty ? (this.query.q || this.query.status || (this.query.kind && !this.options.kind) ? t(cfg, 'no_results')
      : this.query.folder !== null ? t(cfg, 'folder_empty') : t(cfg, 'empty')) : '';
    this.status.classList.toggle('is-empty', empty);
    this.more.hidden = this.page >= this.pages;
    this.renderBatch();
  }

  private renderCrumbs(): void {
    const { cfg } = this;
    const q = this.query;
    const where = q.q && q.scope === 'all' ? t(cfg, 'search_everywhere')
      : q.folder === null ? t(cfg, 'all_media') : q.folder === 'unfiled' ? t(cfg, 'unfiled') : folderLabel(this.folders, q.folder);
    const place = element('b', '', where);
    place.dir = 'auto';
    this.crumbs.replaceChildren(place, element('span', 'fa-muted', ` · ${t(cfg, 'count', { count: this.total })}`));
    if (q.q && q.folder !== null) {
      const only = element('label', 'fa-check fa-mb-only');
      const box = element('input');
      box.type = 'checkbox';
      box.checked = q.scope === 'folder';
      box.addEventListener('change', () => { this.query.scope = box.checked ? 'folder' : 'all'; void this.load(true); });
      only.append(box, document.createTextNode(` ${t(cfg, 'this_folder_only')}`));
      this.crumbs.append(only);
    }
  }

  private renderStats(stats: Record<string, number>): void {
    const host = document.querySelector<HTMLElement>('[data-media-stats]');
    if (!host || this.options.mode !== 'manage') return;
    const { cfg } = this;
    const stat = (html: string, warn = false) => { const node = element(warn ? 'button' : 'span', `fa-mb-stat${warn ? ' is-warning' : ''}`); node.innerHTML = html; return node; };
    const bold = (value: string | number) => `<b>${String(value).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]!))}</b>`;
    const nodes = [
      stat(t(cfg, 'stat_items', { count: bold(stats.items) })),
      stat(t(cfg, 'stat_kinds', { images: bold(stats.images), videos: bold(stats.videos) })),
      stat(t(cfg, 'stat_size', { size: bold(fileSize(stats.bytes)) })),
    ];
    if (stats.no_alt > 0) {
      const warn = stat(t(cfg, 'stat_no_alt', { count: bold(stats.no_alt) }), true) as HTMLButtonElement;
      warn.type = 'button';
      warn.addEventListener('click', () => { this.query.status = 'no_alt'; this.statusSelect.value = 'no_alt'; void this.load(true); });
      nodes.push(warn);
    }
    host.replaceChildren(...nodes);
  }

  /* ------------------------------------------------------------------ selection */

  private click(item: MediaItem, event: MouseEvent): void {
    if (this.already.has(item.id)) { this.setFocus(item.id); return; }
    const choose = this.options.mode === 'choose';
    if (choose && !this.options.multiple) { this.selectOnly(item); return; }
    if (choose) { this.toggle(item); return; }
    // The Media page: like a file manager.
    if (event.shiftKey && this.anchor !== null) { this.selectRange(this.anchor, item.id, event.ctrlKey || event.metaKey); return; }
    if (event.ctrlKey || event.metaKey) { this.toggle(item); return; }
    this.selectOnly(item);
  }

  private selectOnly(item: MediaItem): void {
    this.selected.clear();
    this.selected.set(item.id, item);
    this.anchor = item.id;
    this.setFocus(item.id);
    this.changed();
  }

  private toggle(item: MediaItem): void {
    if (this.already.has(item.id)) return;
    if (this.selected.has(item.id)) {
      this.selected.delete(item.id);
    } else {
      const room = this.options.max ? this.options.max - this.already.size : Infinity;
      if (this.options.mode === 'choose' && this.selected.size >= room) {
        announce(t(this.cfg, 'max_reached', { max: this.options.max ?? 0 }), 'warning');
        return;
      }
      this.selected.set(item.id, item);
    }
    this.anchor = item.id;
    this.setFocus(item.id);
    this.changed();
  }

  private selectRange(from: number, to: number, add: boolean): void {
    const ids = this.items.map(item => item.id);
    const [a, b] = [ids.indexOf(from), ids.indexOf(to)].sort((x, y) => x - y);
    if (a < 0 || b < 0) return;
    if (!add) this.selected.clear();
    for (const item of this.items.slice(a, b + 1)) this.selected.set(item.id, item);
    this.setFocus(to);
    this.changed();
  }

  private selectAll(): void {
    // In the picker, no more than the field has room for.
    const room = this.options.mode === 'choose' && this.options.max ? this.options.max - this.already.size : Infinity;
    for (const item of this.items) {
      if (this.selected.size >= room) { announce(t(this.cfg, 'max_reached', { max: this.options.max ?? 0 }), 'warning'); break; }
      if (!this.already.has(item.id)) this.selected.set(item.id, item);
    }
    this.changed();
  }

  private clearSelection(): void {
    this.selected.clear();
    this.changed();
  }

  private changed(): void {
    // Drawing the tiles again drops the focus; keep it on the focused tile.
    const hadFocus = this.grid.contains(document.activeElement);
    this.renderItems();
    if (hadFocus && this.focused !== null) this.grid.querySelector<HTMLElement>(`[data-id="${this.focused}"]`)?.focus({ preventScroll: true });
    this.options.onSelection?.(this.selection());
  }

  private setFocus(id: number | null): void {
    if (this.focused === id) return;
    this.focused = id;
    this.focalEditing = false;
    void this.loadDetails();
  }

  private focusTile(id: number | null, move = true): void {
    if (id === null) return;
    if (move && this.focused !== id) { this.setFocus(id); this.renderItems(); }
    const tile = this.grid.querySelector<HTMLElement>(`[data-id="${id}"]`);
    if (!tile) return;
    this.grid.querySelectorAll<HTMLElement>('.fa-mb-tile').forEach(other => { other.tabIndex = other === tile ? 0 : -1; });
    if (move || this.grid.contains(document.activeElement)) tile.focus({ preventScroll: false });
  }

  private keydown(event: KeyboardEvent): void {
    const tiles = [...this.grid.querySelectorAll<HTMLElement>('.fa-mb-tile')];
    const index = tiles.findIndex(tile => tile === document.activeElement);
    if (index < 0) return;
    const item = this.items[index];
    const rtl = getComputedStyle(this.grid).direction === 'rtl';
    const columns = this.view === 'list' ? 1 : Math.max(1, tiles.filter(tile => tile.offsetTop === tiles[0].offsetTop).length);
    let next = -1;
    switch (event.key) {
      case 'ArrowRight': next = index + (rtl ? -1 : 1); break;
      case 'ArrowLeft': next = index + (rtl ? 1 : -1); break;
      case 'ArrowDown': next = index + columns; break;
      case 'ArrowUp': next = index - columns; break;
      case 'Home': next = 0; break;
      case 'End': next = tiles.length - 1; break;
      case ' ':
        event.preventDefault();
        if (this.options.mode === 'choose' && !this.options.multiple) this.selectOnly(item); else this.toggle(item);
        return;
      case 'Enter':
        event.preventDefault();
        if (this.options.mode === 'choose') { if (!this.options.multiple && !this.already.has(item.id)) this.selectOnly(item); this.choose(); }
        else this.detailsPane.querySelector<HTMLElement>('input, textarea, button')?.focus();
        return;
      case 'a':
        if ((event.ctrlKey || event.metaKey) && (this.options.mode === 'manage' || this.options.multiple)) { event.preventDefault(); this.selectAll(); }
        return;
      case 'Delete':
        if (this.options.mode === 'manage' && this.cfg.can.delete && this.selected.size > 0) { event.preventDefault(); void this.deleteSelected(); }
        return;
      default: return;
    }
    event.preventDefault();
    next = Math.max(0, Math.min(tiles.length - 1, next));
    if (next === index) return;
    const target = this.items[next];
    if (event.shiftKey && this.options.mode === 'manage') { this.selectRange(this.anchor ?? item.id, target.id, false); this.focusTile(target.id); return; }
    this.focusTile(target.id);
  }

  private choose(): void {
    const picked = this.selection();
    if (picked.length > 0) this.options.onChoose?.(picked);
  }

  /* ------------------------------------------------------------------ batch */

  private renderBatch(): void {
    if (this.options.mode !== 'manage') { this.batch.hidden = true; return; }
    const { cfg } = this;
    const count = this.selected.size;
    this.batch.hidden = count === 0;
    if (count === 0) { this.batch.replaceChildren(); return; }
    const label = element('span', 'fa-mb-batch-count', t(cfg, count === 1 ? 'selected_one' : 'selected_many', { count }));
    const actions: HTMLElement[] = [label];
    if (cfg.can.update) {
      const move = button('fa-btn fa-btn-sm', t(cfg, 'move_to'), 'folderMove');
      move.setAttribute('aria-haspopup', 'menu');
      move.addEventListener('click', () => openMenu(move, folderMenu(cfg, this.folders, undefined, folder => void this.moveItems([...this.selected.keys()], folder)), t(cfg, 'move_to')));
      actions.push(move);
      if (this.options.editMany) {
        const edit = button('fa-btn fa-btn-sm', t(cfg, 'edit_details'), 'pencil');
        edit.addEventListener('click', () => this.options.editMany!(this.selection(), changed => this.updated(changed)));
        actions.push(edit);
      }
    }
    const copy = button('fa-btn fa-btn-sm fa-mb-icon-only', t(cfg, 'copy_addresses'), 'copy', true);
    copy.title = t(cfg, 'copy_addresses');
    copy.addEventListener('click', () => {
      const text = this.selection().map(item => new URL(item.url, location.href).toString()).join('\n');
      void navigator.clipboard?.writeText(text).then(() => announce(t(cfg, 'copied_count', { count: this.selected.size })), () => announce(t(cfg, 'failed'), 'danger'));
    });
    actions.push(copy);
    if (cfg.can.delete) {
      const remove = button('fa-btn fa-btn-sm fa-btn-danger-outline', t(cfg, 'delete'), 'trash');
      remove.addEventListener('click', () => void this.deleteSelected());
      actions.push(remove);
    }
    // Select all and Clear stay together at the end, also when the bar wraps.
    const end = element('span', 'fa-mb-batch-end');
    if (this.items.length > count) {
      const all = button('fa-btn fa-btn-sm fa-btn-ghost', t(cfg, 'select_all_loaded', { count: this.items.length }));
      all.addEventListener('click', () => this.selectAll());
      end.append(all);
    }
    const clear = button('fa-btn fa-btn-sm fa-btn-ghost fa-mb-icon-only', t(cfg, 'clear_selection'), 'x', true);
    clear.title = t(cfg, 'clear_selection');
    clear.addEventListener('click', () => this.clearSelection());
    end.append(clear);
    actions.push(end);
    this.batch.replaceChildren(...actions);
  }

  private async moveItems(ids: number[], folder: number | null): Promise<void> {
    try {
      const result = await postForm<{ moved: number; message: string; folders: Folders }>(this.cfg.urls.move, { ids, folder: folder === null ? '' : folder }, this.cfg.csrf);
      this.folders = result.folders;
      announce(result.message);
      // Items that left the folder on screen go; the others show their new folder.
      const stays = this.query.folder === null || (this.query.q && this.query.scope === 'all') || this.query.folder === (folder ?? 'unfiled');
      for (const item of this.items) if (ids.includes(item.id)) item.folder = folder;
      if (!stays) {
        this.items = this.items.filter(item => !ids.includes(item.id));
        ids.forEach(id => this.selected.delete(id));
        this.total = Math.max(0, this.total - ids.length);
      }
      this.renderTree();
      this.renderItems();
      this.renderCrumbs();
      void this.loadDetails();
    } catch (error) {
      announce(error instanceof ApiError && error.message ? error.message : t(this.cfg, 'failed'), 'danger');
    }
  }

  private async deleteSelected(): Promise<void> {
    const ids = [...this.selected.keys()];
    if (ids.length === 0) return;
    const used = this.selection().filter(item => (item.used ?? 0) > 0).length;
    const message = t(this.cfg, ids.length === 1 ? 'delete_one_confirm' : 'delete_many_confirm', { count: ids.length, name: this.selection()[0]?.label ?? '' })
      + (used > 0 ? ` ${t(this.cfg, 'delete_kept_note', { count: used })}` : '');
    if (!await confirmDialog(this.cfg, message, t(this.cfg, 'delete'), true)) return;
    try {
      // The server takes 200 ids a request.
      const messages: string[] = [];
      let kept = 0;
      for (let at = 0; at < ids.length; at += 200) {
        const result = await postForm<{ deleted: number; kept: number; message: string }>(this.cfg.urls.delete, { ids: ids.slice(at, at + 200) }, this.cfg.csrf);
        messages.push(result.message);
        kept += result.kept;
      }
      announce(messages.join(' '), kept > 0 ? 'warning' : 'success');
      this.selected.clear();
      this.focused = null;
      await this.load(true);
      void this.refreshMeta();
      this.renderDetails();
    } catch (error) {
      announce(error instanceof ApiError && error.message ? error.message : t(this.cfg, 'failed'), 'danger');
    }
  }

  /** New or changed items from the batch editor or the details panel. */
  private updated(changed: MediaItem[]): void {
    const away = changed.filter(item => item.folder !== undefined && !this.shows(item.folder ?? null)).map(item => item.id);
    if (away.length > 0) {
      this.items = this.items.filter(item => !away.includes(item.id));
      away.forEach(id => this.selected.delete(id));
      this.total = Math.max(0, this.total - away.length);
      if (this.focused !== null && away.includes(this.focused)) this.focused = null;
    }
    for (const item of changed) {
      const index = this.items.findIndex(existing => existing.id === item.id);
      if (index >= 0) this.items[index] = { ...this.items[index], ...item };
      if (this.selected.has(item.id)) this.selected.set(item.id, this.items[index] ?? item);
    }
    this.renderItems();
    void this.loadDetails();
    void this.refreshMeta();
  }

  /* ------------------------------------------------------------------ uploads */

  private dropFiles(): void {
    if (!this.cfg.can.create) return;
    let depth = 0;
    const files = (event: DragEvent) => Boolean(event.dataTransfer?.types.includes('Files'));
    this.pane.addEventListener('dragenter', event => { if (!files(event)) return; event.preventDefault(); depth++; this.pane.classList.add('is-dropping'); });
    this.pane.addEventListener('dragover', event => { if (!files(event)) return; event.preventDefault(); event.dataTransfer!.dropEffect = 'copy'; });
    this.pane.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (depth === 0) this.pane.classList.remove('is-dropping'); });
    this.pane.addEventListener('drop', event => {
      if (!files(event)) return;
      event.preventDefault();
      depth = 0;
      this.pane.classList.remove('is-dropping');
      const dropped = [...event.dataTransfer!.files];
      this.queue?.add(this.options.mode === 'choose' && !this.options.multiple ? dropped.slice(0, 1) : dropped);
    });
    this.pane.dataset.dropLabel = t(this.cfg, 'drop_to_upload');
  }

  /** Whether an item in this folder belongs in the list as it is filtered now. */
  private shows(folder: number | null): boolean {
    const q = this.query;
    if (q.folder === null || (q.q && q.scope === 'all')) return true;
    return q.folder === 'unfiled' ? folder === null : q.folder === folder;
  }

  private uploaded(item: MediaItem): void {
    // "Use the existing one" may answer with an item kept in another folder.
    if (item.reused && this.options.mode === 'manage' && !this.shows(item.folder ?? null)) { this.renderCrumbs(); return; }
    if (!this.items.some(existing => existing.id === item.id)) {
      this.items.unshift({ ...item, used: item.used ?? 0, folder: item.folder ?? (typeof this.query.folder === 'number' ? this.query.folder : null) });
      this.total++;
    }
    if (this.options.mode === 'choose') {
      if (this.options.multiple) { if (!this.selected.has(item.id)) this.toggle(this.items.find(existing => existing.id === item.id)!); }
      else { this.selectOnly(this.items.find(existing => existing.id === item.id)!); }
    } else {
      this.renderItems();
    }
    this.renderCrumbs();
    if (!this.queue?.busy()) void this.refreshMeta();
  }

  /* ------------------------------------------------------------------ details */

  private async loadDetails(): Promise<void> {
    const id = this.focused;
    this.detailsAbort?.abort();
    if (id === null) { this.details = null; this.renderDetails(); return; }
    this.detailsAbort = new AbortController();
    try {
      const data = await getJson<{ item: Details }>(`${this.cfg.urls.details}/${id}`, this.detailsAbort.signal);
      if (this.focused !== id) return;
      this.details = data.item;
      this.focal = data.item.focalX != null && data.item.focalY != null ? { x: data.item.focalX, y: data.item.focalY } : null;
      this.renderDetails();
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      this.details = null;
      this.renderDetails();
    }
  }

  private renderDetails(): void {
    const { cfg } = this;
    const item = this.details;
    const pane = this.detailsPane;
    pane.replaceChildren();
    if (!item) {
      const empty = element('div', 'fa-mb-details-empty');
      empty.insertAdjacentHTML('beforeend', icon('image', 28));
      empty.append(element('p', '', this.selected.size > 1 ? t(cfg, 'selected_many', { count: this.selected.size }) : t(cfg, 'pick_for_details')));
      pane.append(empty);
      return;
    }
    const manage = this.options.mode === 'manage';
    const canEdit = cfg.can.update;

    // Preview; for images with "Focal point" on, a click (or the arrow keys) places the point.
    // Only the dot moves: the form below keeps what was typed.
    const frame = element('div', 'fa-mb-preview');
    const tools = element('div', 'fa-mb-tools');
    if (item.kind === 'image') {
      const image = element('img');
      image.src = item.url; image.alt = item.alt; image.decoding = 'async';
      frame.append(image);
      const showDot = () => {
        let dot = frame.querySelector<HTMLElement>('.fa-mb-focal');
        if (!this.focal) { dot?.remove(); return; }
        if (!dot) { dot = element('span', 'fa-mb-focal'); dot.setAttribute('aria-hidden', 'true'); frame.append(dot); }
        placeFocal(frame, image, dot, this.focal);
      };
      showDot();
      frame.addEventListener('click', event => {
        if (!this.focalEditing) return;
        this.focal = focalFromClick(image, event);
        showDot();
      });
      frame.addEventListener('keydown', event => {
        if (!this.focalEditing) return;
        const step = event.shiftKey ? 10 : 2;
        const point = this.focal ?? { x: 50, y: 50 };
        // The point is measured on the picture, so the arrows move it the way they point, in any language.
        const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.detailsPane.querySelector<HTMLButtonElement>('.fa-mb-tools [aria-pressed]')?.click(); return; }
        const move = moves[event.key];
        if (!move) return;
        event.preventDefault();
        this.focal = { x: Math.max(0, Math.min(100, point.x + move[0])), y: Math.max(0, Math.min(100, point.y + move[1])) };
        showDot();
      });
      if (canEdit) {
        const focal = button('fa-btn fa-btn-sm', t(cfg, 'focal_set'), 'target');
        const label = focal.querySelector('span')!;
        const setEditing = (on: boolean) => {
          this.focalEditing = on;
          if (on && !this.focal) { this.focal = { x: 50, y: 50 }; showDot(); }
          focal.classList.toggle('is-on', on);
          focal.setAttribute('aria-pressed', String(on));
          label.textContent = t(cfg, on ? 'focal_done' : 'focal_set');
          frame.classList.toggle('is-focal', on);
          if (on) { frame.tabIndex = 0; frame.setAttribute('role', 'application'); frame.setAttribute('aria-label', t(cfg, 'focal_help')); }
          else { frame.removeAttribute('tabindex'); frame.removeAttribute('role'); frame.removeAttribute('aria-label'); }
        };
        setEditing(this.focalEditing);
        focal.addEventListener('click', () => { setEditing(!this.focalEditing); if (this.focalEditing) frame.focus(); });
        tools.append(focal);
      }
    } else {
      const video = element('video');
      video.src = item.url; video.controls = true; video.preload = 'metadata'; video.playsInline = true;
      frame.append(video);
    }
    pane.append(frame);

    if (manage && item.canReplace) {
      const replace = button('fa-btn fa-btn-sm', t(cfg, 'replace'), 'upload');
      replace.addEventListener('click', () => this.replace(item));
      tools.append(replace);
    }
    const copy = button('fa-icon-btn', t(cfg, 'copy'), 'copy', true);
    copy.title = t(cfg, 'copy');
    copy.addEventListener('click', () => void navigator.clipboard?.writeText(item.absoluteUrl).then(() => announce(t(cfg, 'copied')), () => announce(t(cfg, 'failed'), 'danger')));
    const open = element('a', 'fa-icon-btn');
    open.href = item.editUrl;
    open.innerHTML = icon('external');
    open.setAttribute('aria-label', t(cfg, 'open_page'));
    open.title = t(cfg, 'open_page');
    if (tools.childElementCount > 0) pane.append(tools);

    const heading = element('div', 'fa-mb-details-head');
    const names = element('div', 'fa-mb-details-names');
    const name = element('h3', 'fa-mb-details-title', item.label);
    name.dir = 'auto';
    const file = element('p', 'fa-mb-sub', item.name);
    file.dir = 'auto';
    names.append(name, file);
    heading.append(names, copy, open);
    pane.append(heading);

    // The form: title, alt text per language, folder (Media page).
    const form = element('form', 'fa-mb-form');
    form.noValidate = true;
    form.dataset.adminLocal = '';
    const field = (labelText: string, control: HTMLElement, id: string, extra?: HTMLElement) => {
      const wrap = element('div', 'fa-mb-field');
      const label = element('label', 'fa-mb-label', labelText);
      label.htmlFor = id;
      if (extra) label.append(extra);
      wrap.append(label, control);
      return wrap;
    };
    const uid = `fa-mb-${item.id}`;
    const title = element('input', 'fa-input');
    title.id = `${uid}-title`; title.name = 'title'; title.value = item.title; title.maxLength = 190; title.dir = 'auto'; title.disabled = !canEdit;
    form.append(field(t(cfg, 'item_title'), title, title.id));
    const alts = element('div', 'fa-mb-alts');
    const altLabel = element('p', 'fa-mb-label', t(cfg, 'alt'));
    alts.append(altLabel);
    for (const language of cfg.languages) {
      const row = element('label', 'fa-mb-alt');
      const badge = element('span', 'fa-mb-lang', language.code.toUpperCase());
      badge.title = language.name;
      const input = element('input', 'fa-input');
      input.name = `alt[${language.code}]`;
      input.value = item.alts?.[language.code] ?? '';
      input.maxLength = 250; input.dir = language.dir; input.lang = language.code; input.disabled = !canEdit;
      input.setAttribute('aria-label', `${t(cfg, 'alt')} (${language.name})`);
      if (item.kind === 'image' && language.code === cfg.defaultLanguage && !item.hasAlt) input.placeholder = t(cfg, 'alt_placeholder');
      row.append(badge, input);
      alts.append(row);
    }
    if (item.kind === 'image' && !item.hasAlt) alts.append(element('p', 'fa-mb-hint is-warning', t(cfg, 'alt_missing')));
    form.append(alts);
    let folder: HTMLSelectElement | null = null;
    if (manage) {
      folder = element('select', 'fa-input fa-select');
      folder.id = `${uid}-folder`; folder.name = 'folder'; folder.disabled = !canEdit;
      const none = element('option', '', t(cfg, 'unfiled')); none.value = '';
      folder.append(none);
      for (const entry of this.folders.tree) {
        const option = element('option', '', `${'— '.repeat(entry.depth)}${entry.name}`);
        option.value = String(entry.id);
        folder.append(option);
      }
      folder.value = item.folder ? String(item.folder) : '';
      form.append(field(t(cfg, 'folder'), folder, folder.id));
    }
    const formStatus = element('p', 'fa-mb-form-status');
    formStatus.setAttribute('role', 'status');
    if (canEdit) {
      const save = element('button', 'fa-btn fa-btn-primary', t(cfg, 'save'));
      save.type = 'submit';
      const actions = element('div', 'fa-mb-form-actions');
      actions.append(save);
      if (manage && cfg.can.delete) {
        const remove = button('fa-btn fa-btn-danger-outline', t(cfg, 'delete'), 'trash');
        remove.disabled = !item.canDelete;
        if (!item.canDelete) remove.title = t(cfg, 'delete_in_use');
        remove.addEventListener('click', () => { this.selected.clear(); this.selected.set(item.id, item); void this.deleteSelected(); });
        actions.append(remove);
      }
      form.append(actions, formStatus);
      form.addEventListener('submit', event => {
        event.preventDefault();
        void this.saveDetails(item, form, folder, save, formStatus);
      });
    }
    pane.append(form);

    const facts = element('dl', 'fa-mb-facts');
    const fact = (term: string, value: string) => { if (!value) return; const dt = element('dt', '', term); const dd = element('dd', '', value); dd.dir = 'auto'; facts.append(dt, dd); };
    fact(t(cfg, 'dimensions'), item.dims);
    fact(t(cfg, 'size'), item.size);
    fact(t(cfg, 'folder'), item.folderLabel || t(cfg, 'unfiled'));
    fact(t(cfg, 'uploaded'), [item.date, item.uploader].filter(Boolean).join(' · '));
    pane.append(facts);

    const usage = element('div', 'fa-mb-usage');
    usage.append(element('p', 'fa-mb-label', t(cfg, 'used_in')));
    if (item.usage.length === 0) usage.append(element('p', 'fa-muted', t(cfg, 'used_nowhere')));
    for (const place of item.usage) {
      const entry = place.url ? element('a', '', place.label) : element('span', '', place.label);
      if (entry instanceof HTMLAnchorElement && place.url) entry.href = place.url;
      entry.dir = 'auto';
      usage.append(entry);
    }
    if (item.inText && manage) usage.append(element('p', 'fa-mb-hint', t(cfg, 'replace_in_text')));
    pane.append(usage);
  }

  private async saveDetails(item: Details, form: HTMLFormElement, folder: HTMLSelectElement | null, save: HTMLButtonElement, status: HTMLElement): Promise<void> {
    const data = new FormData(form);
    if (!folder) data.delete('folder');
    if (item.kind === 'image') {
      data.set('focal_x', this.focal ? String(this.focal.x) : '');
      data.set('focal_y', this.focal ? String(this.focal.y) : '');
    }
    save.disabled = true;
    status.textContent = '';
    form.querySelectorAll('[aria-invalid]').forEach(input => input.removeAttribute('aria-invalid'));
    try {
      const result = await postForm<{ item: Details; message: string }>(item.saveUrl, data, this.cfg.csrf);
      announce(result.message);
      this.focalEditing = false;
      const movedAway = folder !== null && typeof this.query.folder === 'number' && result.item.folder !== this.query.folder && !(this.query.q && this.query.scope === 'all');
      if (movedAway || (this.query.folder === 'unfiled' && result.item.folder)) {
        this.items = this.items.filter(existing => existing.id !== item.id);
        this.selected.delete(item.id);
        this.focused = null;
        this.total = Math.max(0, this.total - 1);
        this.details = null;
        this.renderItems();
        this.renderDetails();
        this.renderCrumbs();
        void this.refreshMeta();
        return;
      }
      this.details = result.item;
      this.updated([result.item]);
    } catch (error) {
      if (error instanceof ApiError) {
        for (const [key, message] of Object.entries(error.errors)) {
          const name = key.includes('.') ? key.replace(/\.(\w+)$/, '[$1]') : key;
          form.querySelector(`[name="${CSS.escape(name)}"]`)?.setAttribute('aria-invalid', 'true');
          status.textContent = message;
        }
        if (!status.textContent) status.textContent = error.message || t(this.cfg, 'failed');
      } else {
        status.textContent = t(this.cfg, 'failed');
      }
      status.classList.add('is-error');
    } finally {
      save.disabled = false;
    }
  }

  private replace(item: Details): void {
    const input = element('input');
    input.type = 'file';
    input.accept = acceptFor(item.kind);
    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      if (!file) return;
      const limit = item.kind === 'video' ? this.cfg.maxVideo : this.cfg.maxImage;
      if (file.size > limit) { announce(t(this.cfg, 'too_big', { name: file.name, max: fileSize(limit) }), 'danger'); return; }
      const body = new FormData();
      body.append('file', file);
      try {
        await postForm(item.replaceUrl, body, this.cfg.csrf);
        announce(t(this.cfg, 'replaced'));
        await this.load(true);
        void this.loadDetails();
      } catch (error) {
        announce(error instanceof ApiError && error.message ? error.message : t(this.cfg, 'failed'), 'danger');
      }
    }, { once: true });
    input.click();
  }
}
