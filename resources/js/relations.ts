/*
 * Relation fields and filters. The server renders a native <select> (multiple
 * for many-to-many) that works without JavaScript; this turns it into a
 * searchable combobox (single) or chips with a search box (multi).
 *
 * - "complete" controls hold every related record: typing filters them here.
 * - Otherwise typing asks data-lookup ("…/lookup/<field>?q=…"), at most 20 results.
 * - data-depends names a field of the same form; its value is sent as ?parent=.
 *
 * The select keeps the value (single), or a hidden JSON input does (multi),
 * so forms, Reactive drafts and filters submit exactly as before.
 */
type Option = { id: string; label: string };
type Strings = Record<string, string>;

let counter = 0;

function text(strings: Strings, key: string, replace: Record<string, string> = {}): string {
  let value = strings[key] || '';
  // split/join: a label containing "$&" must not be read as a replacement pattern
  for (const [name, part] of Object.entries(replace)) value = value.split(':' + name).join(part);
  return value;
}

function normalize(value: string): string {
  return value.toLocaleLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '');
}

class RelationControl {
  private readonly select: HTMLSelectElement;
  private readonly multiple: boolean;
  private readonly strings: Strings;
  private readonly json: HTMLInputElement | null;
  private readonly input: HTMLInputElement;
  private readonly list: HTMLUListElement;
  private readonly status: HTMLElement;
  private readonly chips: HTMLElement | null;
  private readonly clear: HTMLButtonElement | null;
  private results: Option[] = [];
  private active = -1;
  private request: AbortController | null = null;
  private timer = 0;
  private chosen: Option[] = [];
  private readonly reposition = () => this.place();

  constructor(private readonly root: HTMLElement) {
    this.select = root.querySelector<HTMLSelectElement>('[data-relation-select]')!;
    // A Reactive update may have put back the server markup around an old control: start over.
    root.querySelectorAll(':scope > .fa-relation-ui').forEach(old => old.remove());
    this.multiple = root.dataset.mode === 'multi';
    this.strings = JSON.parse(root.dataset.strings || '{}');
    this.json = root.querySelector<HTMLInputElement>('[data-relation-json]');
    const id = this.select.id || `fa-relation-${++counter}`;

    // Current choice(s), in order: chosen ones first (the server lists them first)
    this.chosen = [...this.select.selectedOptions].filter(o => o.value !== '').map(o => ({ id: o.value, label: o.textContent || o.value }));
    if (this.multiple && this.json) {
      try {
        const order: string[] = JSON.parse(this.json.value || '[]');
        this.chosen.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
      } catch { /* keep the select order */ }
    }

    const ui = document.createElement('div');
    ui.className = 'fa-relation-ui' + (this.multiple ? ' is-multi' : '');
    ui.dataset.adminUntracked = '';
    this.chips = this.multiple ? document.createElement('div') : null;
    if (this.chips) { this.chips.className = 'fa-relation-chips'; this.chips.setAttribute('role', 'list'); }

    this.input = document.createElement('input');
    this.input.type = 'text';
    this.input.id = id + '-search';
    this.input.className = 'fa-input fa-relation-input';
    this.input.autocomplete = 'off';
    this.input.spellcheck = false;
    this.input.setAttribute('role', 'combobox');
    this.input.setAttribute('aria-autocomplete', 'list');
    this.input.setAttribute('aria-expanded', 'false');
    this.input.placeholder = text(this.strings, 'search');
    for (const name of ['aria-invalid', 'aria-describedby', 'aria-required']) {
      const value = this.select.getAttribute(name);
      if (value !== null) this.input.setAttribute(name, value);
    }
    this.input.disabled = this.select.disabled;
    this.input.dataset.relationSearch = '';

    this.list = document.createElement('ul');
    this.list.id = id + '-listbox';
    this.list.className = 'fa-relation-list';
    this.list.setAttribute('role', 'listbox');
    if (this.multiple) this.list.setAttribute('aria-multiselectable', 'true');
    this.list.hidden = true;
    this.input.setAttribute('aria-controls', this.list.id);

    this.status = document.createElement('p');
    this.status.className = 'fa-sr';
    this.status.setAttribute('role', 'status');
    this.status.setAttribute('aria-live', 'polite');

    if (this.chips) {
      this.chips.append(this.input);
      ui.append(this.chips);
      this.clear = null;
    } else {
      ui.append(this.input);
      this.clear = document.createElement('button');
      this.clear.type = 'button';
      this.clear.className = 'fa-relation-clear';
      this.clear.setAttribute('aria-label', text(this.strings, 'clear'));
      this.clear.title = text(this.strings, 'clear');
      this.clear.textContent = '×';
      this.clear.tabIndex = -1;
      this.clear.disabled = this.select.disabled;
      ui.append(this.clear);
    }
    ui.append(this.list, this.status);
    this.select.after(ui);
    this.select.hidden = true;
    this.select.tabIndex = -1;
    root.querySelector('.fa-relation-more')?.remove();

    // The label now names the search box
    const label = this.select.id ? document.querySelector<HTMLLabelElement>(`label[for="${CSS.escape(this.select.id)}"]`) : null;
    if (label) { label.htmlFor = this.input.id; if (!label.id) label.id = id + '-label'; this.input.setAttribute('aria-labelledby', label.id); }

    if (this.multiple && this.json) {
      // The JSON input carries the value (with its order); the select no longer submits.
      this.json.disabled = this.select.disabled;
      this.select.removeAttribute('name');
      this.select.disabled = true;
      if (root.dataset.filterKey) this.json.dataset.adminFilter = root.dataset.filterKey;
    }
    this.render();
    this.bind();
  }

  private bind(): void {
    this.input.addEventListener('input', () => {
      if (!this.multiple && this.input.value === '' && this.chosen.length) { this.choose(null); }
      this.search();
    });
    this.input.addEventListener('focus', () => { if (!this.multiple) this.input.select(); });
    this.input.addEventListener('click', () => { if (this.list.hidden) this.search(); });
    this.input.addEventListener('keydown', event => this.key(event));
    this.input.addEventListener('blur', () => {
      // Let a click on an option land first
      window.setTimeout(() => {
        if (this.root.contains(document.activeElement)) return;
        this.close();
        if (!this.multiple) this.input.value = this.chosen[0]?.label || '';
      }, 120);
    });
    this.list.addEventListener('mousedown', event => event.preventDefault());
    this.list.addEventListener('click', event => {
      // Choosing re-draws the list: keep the click from reaching page-wide handlers (popovers) with a detached target.
      event.stopPropagation();
      const item = (event.target as Element).closest<HTMLElement>('[role="option"]');
      if (item && item.getAttribute('aria-disabled') !== 'true') this.pick(Number(item.dataset.index));
    });
    this.clear?.addEventListener('click', () => { this.choose(null); this.input.focus(); });
    this.chips?.addEventListener('click', event => {
      event.stopPropagation();
      const remove = (event.target as Element).closest<HTMLButtonElement>('[data-relation-remove]');
      if (remove) { this.remove(remove.dataset.relationRemove!); this.input.focus(); }
      else if (event.target === this.chips) this.input.focus();
    });
  }

  private key(event: KeyboardEvent): void {
    const open = !this.list.hidden;
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        if (!open) { this.search(); return; }
        this.move(1);
        break;
      case 'ArrowUp':
        event.preventDefault();
        if (open) this.move(-1);
        break;
      case 'Home': case 'End':
        if (open && this.results.length) { event.preventDefault(); this.highlight(event.key === 'Home' ? 0 : this.results.length - 1); }
        break;
      case 'Enter':
        if (open && this.active >= 0) { event.preventDefault(); this.pick(this.active); }
        else if (open) event.preventDefault();
        break;
      case 'Escape':
        if (open) { event.preventDefault(); event.stopPropagation(); this.close(); }
        else if (!this.multiple && this.chosen.length) this.input.value = this.chosen[0].label;
        break;
      case 'Backspace':
        if (this.multiple && this.input.value === '' && this.chosen.length) { this.remove(this.chosen[this.chosen.length - 1].id); }
        break;
      case 'Tab':
        this.close();
        break;
    }
  }

  private parentValue(): string | null {
    const name = this.root.dataset.depends;
    if (!name) return null;
    const form = this.select.form || this.root.closest('form');
    const field = form?.elements.namedItem(name);
    if (field instanceof HTMLSelectElement || field instanceof HTMLInputElement) return field.value;
    if (field instanceof RadioNodeList) return field.value;
    return '';
  }

  private search(): void {
    window.clearTimeout(this.timer);
    const parent = this.parentValue();
    if (parent === '') { this.show([], text(this.strings, 'parent')); return; }
    if (this.max()) { this.show([], text(this.strings, 'max')); return; }
    const term = this.multiple || this.input.value !== this.chosen[0]?.label ? this.input.value.trim() : '';
    if (this.root.dataset.complete === 'true' || !this.root.dataset.lookup) {
      const needle = normalize(term);
      const all = [...this.select.options].filter(o => o.value !== '').map(o => ({ id: o.value, label: o.textContent || o.value }));
      const seen = new Set<string>();
      this.show(all.filter(o => !seen.has(o.id) && seen.add(o.id) && (needle === '' || normalize(o.label).includes(needle) || o.id === term)).slice(0, 50));
      return;
    }
    this.timer = window.setTimeout(() => void this.fetch(term, parent), term === '' ? 0 : 200);
  }

  private async fetch(term: string, parent: string | null): Promise<void> {
    this.request?.abort();
    const request = new AbortController();
    this.request = request;
    this.show([], text(this.strings, 'loading'), true);
    try {
      const url = new URL(this.root.dataset.lookup!, location.href);
      if (url.origin !== location.origin) throw new Error('cross-origin');
      url.searchParams.set('q', term);
      if (parent !== null) url.searchParams.set('parent', parent);
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal });
      if (!response.ok) throw new Error(String(response.status));
      const data = await response.json() as { results?: Option[] };
      if (request.signal.aborted || !this.focused()) return;
      this.show(Array.isArray(data.results) ? data.results.filter(o => typeof o?.id === 'string' && typeof o?.label === 'string') : []);
    } catch (error) {
      if ((error as Error).name === 'AbortError' || !this.focused()) return;
      this.show([], text(this.strings, 'failed'));
    }
  }

  private show(options: Option[], message = '', busy = false): void {
    this.results = options;
    this.active = -1;
    this.list.replaceChildren();
    this.list.setAttribute('aria-busy', String(busy));
    const chosen = new Set(this.chosen.map(o => o.id));
    options.forEach((option, index) => {
      const item = document.createElement('li');
      item.id = `${this.list.id}-${index}`;
      item.setAttribute('role', 'option');
      item.className = 'fa-relation-option';
      item.dataset.index = String(index);
      item.textContent = option.label;
      item.setAttribute('aria-selected', String(chosen.has(option.id)));
      this.list.append(item);
    });
    if (!options.length) {
      const item = document.createElement('li');
      item.className = 'fa-relation-empty';
      item.setAttribute('role', 'option');
      item.setAttribute('aria-disabled', 'true');
      item.textContent = message || text(this.strings, 'none');
      this.list.append(item);
    }
    if (this.list.hidden) {
      window.addEventListener('scroll', this.reposition, { capture: true, passive: true });
      window.addEventListener('resize', this.reposition, { passive: true });
    }
    this.list.hidden = false;
    this.place();
    this.input.setAttribute('aria-expanded', 'true');
    this.status.textContent = options.length ? text(this.strings, 'results', { count: String(options.length) }) : (message || text(this.strings, 'none'));
    if (options.length) this.highlight(0);
  }

  private move(step: number): void {
    if (!this.results.length) return;
    this.highlight((this.active + step + this.results.length) % this.results.length);
  }

  private highlight(index: number): void {
    this.active = index;
    this.list.querySelectorAll<HTMLElement>('[role="option"]').forEach((item, i) => item.classList.toggle('is-active', i === index));
    const item = this.list.querySelector<HTMLElement>(`#${CSS.escape(this.list.id)}-${index}`);
    if (item) { this.input.setAttribute('aria-activedescendant', item.id); item.scrollIntoView({ block: 'nearest' }); }
  }

  private focused(): boolean {
    return this.root.contains(document.activeElement);
  }

  private close(): void {
    window.clearTimeout(this.timer);
    this.request?.abort();
    this.list.hidden = true;
    window.removeEventListener('scroll', this.reposition, { capture: true });
    window.removeEventListener('resize', this.reposition);
    this.input.setAttribute('aria-expanded', 'false');
    this.input.removeAttribute('aria-activedescendant');
  }

  /**
   * The list floats over the page (position: fixed) so a dialog or any scrolling
   * box never clips it or grows a scroll bar; it opens upwards when there is more
   * room above. Lists drawn in place (the Filters menu) are left alone.
   */
  private place(): void {
    if (this.list.hidden) return;
    const style = this.list.style;
    if (this.root.closest('.fa-filters')) { style.cssText = ''; return; }
    const box = (this.list.parentElement as HTMLElement).getBoundingClientRect();
    // The field scrolled out of sight (out of the window or of its dialog's body): close rather than float alone
    const clip = this.root.closest('.fa-dialog-body')?.getBoundingClientRect();
    if (box.bottom < Math.max(0, clip?.top ?? 0) || box.top > Math.min(window.innerHeight, clip?.bottom ?? Infinity)) { this.close(); return; }
    const margin = 8;
    const below = window.innerHeight - box.bottom - margin;
    const above = box.top - margin;
    style.position = 'fixed';
    style.inset = 'auto';
    style.left = `${box.left}px`;
    style.width = `${box.width}px`;
    style.maxBlockSize = '';
    const wanted = Math.min(this.list.scrollHeight + 2, 256);
    const up = below < wanted && above > below;
    const room = Math.max(96, Math.min(256, (up ? above : below) - 4));
    style.maxBlockSize = `${room}px`;
    if (up) style.bottom = `${window.innerHeight - box.top + 4}px`;
    else style.top = `${box.bottom + 4}px`;
    this.list.classList.toggle('is-above', up);
  }

  private pick(index: number): void {
    const option = this.results[index];
    if (!option) return;
    if (this.multiple) {
      if (this.chosen.some(o => o.id === option.id)) this.remove(option.id);
      else if (!this.max()) { this.chosen.push(option); this.commit(); }
      this.input.value = '';
      this.search();
    } else {
      this.choose(option);
      this.close();
    }
  }

  private choose(option: Option | null): void {
    this.chosen = option ? [option] : [];
    this.input.value = option?.label || '';
    this.commit();
  }

  private remove(id: string): void {
    this.chosen = this.chosen.filter(o => o.id !== id);
    this.commit();
  }

  private max(): boolean {
    const max = Number(this.root.dataset.max || 0);
    return max > 0 && this.chosen.length >= max;
  }

  /** Write the choice back to the form and tell the page (dirty state, dependent fields). */
  private commit(): void {
    for (const option of this.chosen) {
      if (![...this.select.options].some(o => o.value === option.id)) {
        const element = document.createElement('option');
        element.value = option.id;
        element.textContent = option.label;
        element.dir = 'auto';
        this.select.append(element);
      }
    }
    const ids = this.chosen.map(o => o.id);
    [...this.select.options].forEach(o => { o.selected = ids.includes(o.value); });
    if (!this.multiple && !ids.length) this.select.value = '';
    if (this.json) this.json.value = JSON.stringify(ids);
    this.render();
    const target: HTMLElement = this.json && this.multiple ? this.json : this.select;
    target.dispatchEvent(new Event('input', { bubbles: true }));
    // Hidden inputs are ignored by the dirty tracker: report the change on the root as well.
    target.dispatchEvent(new Event('change', { bubbles: true }));
    if (this.json && this.multiple) this.root.dispatchEvent(new Event('change', { bubbles: true }));
  }

  private render(): void {
    if (this.chips) {
      this.chips.querySelectorAll('.fa-relation-chip').forEach(chip => chip.remove());
      for (const option of this.chosen) {
        const chip = document.createElement('span');
        chip.className = 'fa-relation-chip';
        chip.setAttribute('role', 'listitem');
        const label = document.createElement('span');
        label.textContent = option.label;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'fa-relation-chip-remove';
        remove.dataset.relationRemove = option.id;
        remove.setAttribute('aria-label', text(this.strings, 'remove', { label: option.label }));
        remove.textContent = '×';
        remove.disabled = this.input.disabled;
        chip.append(label, remove);
        this.chips.insertBefore(chip, this.input);
      }
      this.input.placeholder = this.max() ? text(this.strings, 'max') : text(this.strings, 'search');
    } else {
      if (document.activeElement !== this.input) this.input.value = this.chosen[0]?.label || '';
      if (this.clear) this.clear.hidden = this.chosen.length === 0 || this.input.disabled;
    }
  }
}

/**
 * Enhance every relation control that shows its server markup: new ones, and
 * those a Reactive update morphed back (the select is visible again and the
 * JSON input disabled), so the value is never sent the no-JavaScript way by mistake.
 */
export function initializeRelations(root: ParentNode = document): void {
  root.querySelectorAll<HTMLElement>('[data-admin-relation]').forEach(element => {
    const select = element.querySelector<HTMLSelectElement>('[data-relation-select]');
    if (!select) return;
    const built = element.querySelector(':scope > .fa-relation-ui') !== null && select.hidden;
    if (built) return;
    new RelationControl(element);
  });
}
