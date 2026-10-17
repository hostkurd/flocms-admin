/**
 * The upload queue (0.18): every chosen or dropped file in one list, uploaded one after
 * another, with progress, Cancel, Retry, and the duplicate question ("Use the existing
 * one" or "Upload anyway").
 */
import { ApiError, button, element, fileSize, fits, icon, settleDuplicate, t, uploadMedia, type Config, type Duplicate, type MediaItem } from './api';

type Row = {
  file: File; node: HTMLElement; status: HTMLElement; bar: HTMLElement; actions: HTMLElement;
  state: 'waiting' | 'uploading' | 'done' | 'failed' | 'duplicate' | 'cancelled'; abort?: AbortController;
};

export type QueueOptions = {
  /** The folder new items go to, read when each upload starts. */
  folder: () => number | null;
  /** What to do with a file already in the library: ask (the Media page) or use the existing item (fields, picker). */
  duplicates: 'ask' | 'use';
  kind?: 'image' | 'video' | null;
  /** Called for each new (or reused) item. */
  onItem: (item: MediaItem) => void;
  /** Where the panel goes: the page (fixed in the corner) or a dialog. */
  host: HTMLElement;
};

export class UploadQueue {
  private readonly panel: HTMLElement;
  private readonly list: HTMLElement;
  private readonly title: HTMLElement;
  private readonly rows: Row[] = [];
  private running = false;

  constructor(private readonly cfg: Config, private readonly options: QueueOptions) {
    this.panel = element('section', 'fa-mb-queue');
    this.panel.hidden = true;
    this.panel.setAttribute('aria-label', t(cfg, 'uploads'));
    const head = element('div', 'fa-mb-queue-head');
    head.insertAdjacentHTML('beforeend', icon('upload'));
    this.title = element('span', 'fa-mb-queue-title');
    this.title.setAttribute('aria-live', 'polite');
    const toggle = button('fa-icon-btn fa-mb-queue-toggle', t(cfg, 'hide_uploads'), 'chevronDown', true);
    toggle.setAttribute('aria-expanded', 'true');
    toggle.addEventListener('click', () => {
      const open = this.panel.classList.toggle('is-collapsed') === false;
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', t(this.cfg, open ? 'hide_uploads' : 'show_uploads'));
    });
    const close = button('fa-icon-btn fa-mb-queue-close', t(cfg, 'close'), 'x', true);
    close.addEventListener('click', () => this.clear());
    head.append(this.title, toggle, close);
    this.list = element('ul', 'fa-mb-queue-list');
    this.panel.append(head, this.list);
    options.host.append(this.panel);
  }

  /** Queue files; ones the library doesn't take are listed as failed at once. */
  add(files: File[]): void {
    for (const file of files) {
      const node = element('li', 'fa-mb-queue-row');
      const thumb = element('span', 'fa-mb-queue-thumb');
      this.thumb(thumb, file);
      const info = element('div', 'fa-mb-queue-info');
      const name = element('span', 'fa-mb-queue-name', file.name);
      name.dir = 'auto';
      const status = element('span', 'fa-mb-queue-status', t(this.cfg, 'waiting_short'));
      const bar = element('div', 'fa-mb-queue-bar');
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-label', file.name);
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '100');
      bar.append(element('i'));
      const actions = element('div', 'fa-mb-queue-actions');
      info.append(name, status, bar);
      node.append(thumb, info, actions);
      const row: Row = { file, node, status, bar, actions, state: 'waiting' };
      this.list.append(node);
      this.rows.push(row);
      if (!fits(file, this.options.kind ?? null)) { this.fail(row, t(this.cfg, 'wrong_type', { name: file.name })); continue; }
      const limit = file.type.startsWith('video/') ? this.cfg.maxVideo : this.cfg.maxImage;
      if (file.size > limit) { this.fail(row, t(this.cfg, 'too_big', { name: file.name, max: fileSize(limit) })); continue; }
      this.cancelButton(row);
    }
    this.panel.hidden = false;
    this.panel.classList.remove('is-collapsed');
    this.update();
    void this.run();
  }

  /** Whether anything is still waiting or uploading. */
  busy(): boolean {
    return this.rows.some(row => row.state === 'waiting' || row.state === 'uploading');
  }

  private async run(): Promise<void> {
    if (this.running) return;
    this.running = true;
    try {
      for (let row = this.next(); row; row = this.next()) await this.upload(row);
    } finally {
      this.running = false;
      this.update();
    }
  }

  private next(): Row | undefined {
    return this.rows.find(row => row.state === 'waiting');
  }

  private async upload(row: Row): Promise<void> {
    row.state = 'uploading';
    row.abort = new AbortController();
    row.status.textContent = t(this.cfg, 'uploading_short');
    row.node.classList.add('is-uploading');
    this.update();
    try {
      const outcome = await uploadMedia(this.cfg, row.file, percent => this.progress(row, percent), {
        folder: this.options.folder(), duplicates: this.options.duplicates, signal: row.abort.signal,
      });
      if ('duplicate' in outcome) { this.duplicate(row, outcome.duplicate); return; }
      this.done(row, outcome.item);
    } catch (error) {
      // Cancel may have run while the request was out.
      if ((row.state as Row['state']) === 'cancelled') return;
      this.fail(row, error instanceof ApiError && error.message ? error.message : t(this.cfg, 'failed'), true);
    } finally {
      row.node.classList.remove('is-uploading');
      this.update();
    }
  }

  private progress(row: Row, percent: number): void {
    row.bar.setAttribute('aria-valuenow', String(percent));
    row.bar.querySelector('i')!.style.width = `${percent}%`;
    row.status.textContent = `${t(this.cfg, 'uploading_short')} ${percent}%`;
  }

  private done(row: Row, item: MediaItem): void {
    row.state = 'done';
    row.node.classList.add('is-done');
    row.actions.replaceChildren();
    row.status.textContent = item.reused ? t(this.cfg, 'reused', { name: item.label }) : t(this.cfg, 'upload_done_short');
    this.options.onItem(item);
  }

  private fail(row: Row, message: string, retry = false): void {
    row.state = 'failed';
    row.node.classList.add('is-failed');
    row.status.textContent = message;
    row.actions.replaceChildren();
    if (retry) {
      const again = button('fa-icon-btn', t(this.cfg, 'retry_named', { name: row.file.name }), 'retry', true);
      again.addEventListener('click', () => { row.state = 'waiting'; row.node.classList.remove('is-failed'); row.status.textContent = t(this.cfg, 'waiting_short'); this.cancelButton(row); this.update(); void this.run(); });
      row.actions.append(again);
    }
    this.update();
  }

  private duplicate(row: Row, duplicate: Duplicate): void {
    row.state = 'duplicate';
    row.node.classList.add('is-duplicate');
    row.status.textContent = duplicate.message;
    row.actions.replaceChildren();
    const choices = element('div', 'fa-mb-queue-choices');
    const use = element('button', 'fa-btn fa-btn-sm', t(this.cfg, 'use_existing', { name: duplicate.existing.label }));
    use.type = 'button';
    const keep = element('button', 'fa-btn fa-btn-sm fa-btn-ghost', t(this.cfg, 'upload_anyway'));
    keep.type = 'button';
    const settle = async (keepIt: boolean) => {
      use.disabled = keep.disabled = true;
      try {
        const item = await settleDuplicate(this.cfg, duplicate.token, keepIt);
        choices.remove();
        row.node.classList.remove('is-duplicate');
        this.done(row, keepIt && item ? item : { ...duplicate.existing, reused: true });
      } catch {
        use.disabled = keep.disabled = false;
        row.status.textContent = t(this.cfg, 'failed');
      }
      this.update();
    };
    use.addEventListener('click', () => void settle(false));
    keep.addEventListener('click', () => void settle(true));
    choices.append(use, keep);
    row.node.querySelector('.fa-mb-queue-info')!.append(choices);
    this.update();
    use.focus();
  }

  private cancelButton(row: Row): void {
    row.actions.replaceChildren();
    const cancel = button('fa-icon-btn', t(this.cfg, 'cancel_named', { name: row.file.name }), 'x', true);
    cancel.addEventListener('click', () => {
      row.state = 'cancelled';
      row.abort?.abort();
      row.node.remove();
      this.rows.splice(this.rows.indexOf(row), 1);
      this.update();
    });
    row.actions.append(cancel);
  }

  private thumb(box: HTMLElement, file: File): void {
    const badge = () => { box.replaceChildren(); box.classList.add('is-file'); box.textContent = (file.name.split('.').pop() || '').slice(0, 4).toUpperCase(); };
    if (!file.type.startsWith('image/')) { badge(); return; }
    // A strict Content-Security-Policy may refuse blob: images; then the type shows.
    const image = element('img');
    image.alt = '';
    const url = URL.createObjectURL(file);
    image.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
    image.addEventListener('error', () => { URL.revokeObjectURL(url); badge(); }, { once: true });
    image.src = url;
    box.append(image);
  }

  private update(): void {
    const total = this.rows.length;
    const finished = this.rows.filter(row => row.state === 'done').length;
    const current = this.rows.findIndex(row => row.state === 'uploading');
    const waiting = this.rows.filter(row => row.state === 'duplicate').length;
    this.title.textContent = current >= 0 ? t(this.cfg, 'uploading_of', { current: current + 1, total })
      : waiting > 0 ? t(this.cfg, 'uploads_waiting', { count: waiting })
      : t(this.cfg, 'uploads_done', { done: finished, total });
    this.panel.classList.toggle('is-busy', this.busy());
    if (total === 0) this.panel.hidden = true;
  }

  private clear(): void {
    for (const row of [...this.rows]) {
      if (row.state === 'waiting' || row.state === 'uploading') continue;
      row.node.remove();
      this.rows.splice(this.rows.indexOf(row), 1);
    }
    this.update();
    if (this.rows.length === 0) this.panel.hidden = true;
  }
}
