/**
 * CheckboxList and TagsInput (0.37). Both work without this file: the
 * checkboxes are plain checkboxes, the tags one text box ("villa, sea view").
 * Here the checkbox list gets Select all / Clear, a count and a filter box, and
 * the text box becomes chips with suggestions. The text box stays the value
 * that is sent (hidden, kept up to date), so the server reads one format.
 */

const fold = (text: string): string => text.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
const format = (template: string, values: Record<string, string | number>): string =>
  template.replace(/:([a-z]+)/g, (match, key: string) => (key in values ? String(values[key]) : match));

/* ------------------------------------------------------------------
 * CheckboxList
 * ---------------------------------------------------------------- */

function boxes(group: HTMLElement): HTMLInputElement[] {
  return [...group.querySelectorAll<HTMLInputElement>('input[type=checkbox]')];
}

function updateChecks(group: HTMLElement): void {
  // Old keys kept until unticked are not options: they don't count and are never locked
  const all = boxes(group).filter(box => !box.hasAttribute('data-checks-kept'));
  const count = all.filter(box => box.checked).length;
  const total = Number(group.dataset.checksTotal || all.length);
  const counter = group.querySelector<HTMLElement>('[data-checks-count]');
  if (counter) counter.textContent = format(group.dataset.checksCountText || ':count / :total', { count, total });
  const max = Number(group.dataset.checksMax || 0);
  // At the maximum the other boxes wait until one is unticked (the server checks it too).
  all.forEach(box => {
    if (box.dataset.checksLocked === undefined && box.disabled) return; // disabled by the form itself
    const lock = max > 0 && count >= max && !box.checked;
    box.disabled = lock;
    if (lock) box.dataset.checksLocked = ''; else delete box.dataset.checksLocked;
  });
}

function filterChecks(group: HTMLElement, query: string): void {
  const wanted = fold(query);
  let shown = 0;
  group.querySelectorAll<HTMLElement>('.fa-checks-grid > .fa-check').forEach(label => {
    const match = wanted === '' || fold(label.textContent || '').includes(wanted);
    label.hidden = !match;
    if (match) shown++;
  });
  const empty = group.querySelector<HTMLElement>('[data-checks-empty]');
  if (empty) empty.hidden = shown > 0;
}

function initializeChecks(group: HTMLElement): void {
  group.querySelectorAll<HTMLElement>('[data-checks-tools], [data-checks-search-wrap]').forEach(element => { element.hidden = false; });
  const search = group.querySelector<HTMLInputElement>('[data-checks-search]');
  if (search && search.value) filterChecks(group, search.value);
  updateChecks(group);
}

document.addEventListener('change', event => {
  const box = event.target;
  if (!(box instanceof HTMLInputElement) || box.type !== 'checkbox') return;
  const group = box.closest<HTMLElement>('[data-admin-checks]');
  if (group) updateChecks(group);
});
document.addEventListener('input', event => {
  const search = event.target;
  if (!(search instanceof HTMLInputElement) || !search.matches('[data-checks-search]')) return;
  const group = search.closest<HTMLElement>('[data-admin-checks]');
  if (group) filterChecks(group, search.value);
});
document.addEventListener('keydown', event => {
  // Enter in the filter box must not submit the form
  if (event.key === 'Enter' && event.target instanceof HTMLInputElement && event.target.matches('[data-checks-search]')) event.preventDefault();
});
document.addEventListener('click', event => {
  const button = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-checks-all], [data-checks-none]') : null;
  const group = button?.closest<HTMLElement>('[data-admin-checks]');
  if (!button || !group) return;
  const on = button.hasAttribute('data-checks-all');
  // The options in view (a filter narrows "Select all"); Clear also unticks kept old keys.
  boxes(group).forEach(box => {
    if (box.disabled && box.dataset.checksLocked === undefined) return;
    if (on && (box.closest<HTMLElement>('.fa-check')?.hidden || box.hasAttribute('data-checks-kept'))) return;
    if (box.checked === on) return;
    box.checked = on;
    box.dispatchEvent(new Event('input', { bubbles: true }));
    box.dispatchEvent(new Event('change', { bubbles: true }));
  });
  updateChecks(group);
});

/* ------------------------------------------------------------------
 * TagsInput
 * ---------------------------------------------------------------- */

let tagsCount = 0;

class Tags {
  private readonly value: HTMLInputElement;
  private readonly box: HTMLDivElement;
  private readonly list: HTMLUListElement;
  private readonly input: HTMLInputElement;
  private readonly options: HTMLUListElement;
  private readonly status: HTMLSpanElement;
  private readonly separator: string;
  private readonly max: number;
  private readonly only: boolean;
  private readonly suggestions: string[];
  private tags: string[];
  private active = -1;
  private readonly listeners = new AbortController();
  private shown: Array<{ text: string; isNew: boolean }> = [];

  constructor(private readonly root: HTMLElement) {
    // A copy (a duplicated repeater item) brings the chips of its original: start again from the value.
    root.querySelectorAll(':scope > .fa-tags-box, :scope > .fa-tags-options, :scope > [data-tags-status]').forEach(element => element.remove());
    this.value = root.querySelector<HTMLInputElement>(':scope > input[data-tags-value], :scope > input')!;
    const data = root.dataset;
    this.separator = data.tagsSeparator || ',';
    this.max = Number(data.tagsMax || 100);
    this.only = data.tagsOnly === '1';
    try { const list = JSON.parse(data.tagsSuggestions || '[]'); this.suggestions = Array.isArray(list) ? list.filter((s): s is string => typeof s === 'string') : []; }
    catch { this.suggestions = []; }

    const id = this.value.dataset.tagsId || this.value.id || `fa-tags-${++tagsCount}`;
    this.value.dataset.tagsValue = '';
    this.value.dataset.tagsId = id;
    this.value.id = `${id}-value`;
    this.value.hidden = true;
    this.value.tabIndex = -1;

    this.box = document.createElement('div');
    this.box.className = 'fa-tags-box';
    this.list = document.createElement('ul');
    this.list.className = 'fa-tags-list';
    this.input = document.createElement('input');
    this.input.type = 'text';
    this.input.className = 'fa-tags-input';
    this.input.id = id;
    this.input.autocomplete = 'off';
    this.input.maxLength = Number(data.tagsMaxLength || 50);
    this.input.disabled = this.value.disabled;
    this.input.setAttribute('role', 'combobox');
    this.input.setAttribute('aria-autocomplete', 'list');
    this.input.setAttribute('aria-expanded', 'false');
    ['aria-describedby', 'aria-invalid', 'dir', 'lang'].forEach(name => { const v = this.value.getAttribute(name); if (v !== null) this.input.setAttribute(name, v); });
    this.input.setAttribute('aria-labelledby', `${id}-label`);
    this.options = document.createElement('ul');
    this.options.className = 'fa-tags-options';
    this.options.id = `${id}-options`;
    this.options.setAttribute('role', 'listbox');
    this.options.setAttribute('aria-label', data.tagsSuggestionsLabel || 'Suggestions');
    this.options.hidden = true;
    this.input.setAttribute('aria-controls', this.options.id);
    this.status = document.createElement('span');
    this.status.className = 'fa-sr';
    this.status.dataset.tagsStatus = '';
    this.status.setAttribute('aria-live', 'polite');

    this.box.append(this.list, this.input);
    root.append(this.box, this.options, this.status);
    root.dataset.tagsReady = 'true';

    this.tags = this.parse(this.value.value);
    this.render();
    this.listen();
  }

  private parse(text: string): string[] {
    const parts = text.split(this.separators()).map(part => part.trim().replace(/\s+/g, ' ')).filter(Boolean);
    const seen = new Set<string>();
    return parts.filter(part => { const key = fold(part); if (seen.has(key)) return false; seen.add(key); return true; });
  }

  private separators(): RegExp {
    const escaped = this.separator.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp(`[\\n${escaped}${this.separator === ',' ? '،' : ''}]`);
  }

  private isSeparatorKey(key: string): boolean {
    return key === this.separator || (this.separator === ',' && key === '،');
  }

  private say(message: string): void {
    this.status.textContent = '';
    window.setTimeout(() => { this.status.textContent = message; }, 30);
  }

  private render(): void {
    this.list.replaceChildren(...this.tags.map((tag, index) => {
      const item = document.createElement('li');
      item.className = 'fa-tag-chip';
      item.tabIndex = -1;
      item.dataset.index = String(index);
      const text = document.createElement('span');
      text.textContent = tag;
      item.append(text);
      if (!this.input.disabled) {
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'fa-tag-remove';
        remove.tabIndex = -1;
        remove.setAttribute('aria-label', format(this.root.dataset.tagsRemove || 'Remove :tag', { tag }));
        remove.innerHTML = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
        item.append(remove);
      }
      return item;
    }));
    const full = this.tags.length >= this.max;
    this.input.placeholder = full ? format(this.root.dataset.tagsFull || '', { max: this.max }) : (this.tags.length ? '' : this.root.dataset.tagsPlaceholder || '');
    this.root.classList.toggle('is-full', full);
  }

  private sync(): void {
    const text = this.tags.join(`${this.separator} `);
    if (this.value.value === text) return;
    this.value.value = text;
    this.value.dispatchEvent(new Event('input', { bubbles: true }));
    this.value.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /** Add tags; false when none could be added (full, or not a suggestion). */
  private add(texts: string[]): boolean {
    let added = 0;
    for (let text of texts) {
      text = text.trim().replace(/\s+/g, ' ').slice(0, this.input.maxLength);
      if (!text) continue;
      if (this.tags.length >= this.max) { this.say(format(this.root.dataset.tagsFull || '', { max: this.max })); break; }
      const known = this.suggestions.find(suggestion => fold(suggestion) === fold(text));
      if (this.only && !known) continue;
      if (known) text = known;
      if (this.tags.some(tag => fold(tag) === fold(text))) continue;
      this.tags.push(text);
      added++;
      this.say(format(this.root.dataset.tagsAdded || ':tag', { tag: text }));
    }
    if (added) { this.render(); this.sync(); }
    return added > 0;
  }

  private remove(index: number, focusAfter: boolean, forward = false): void {
    const [tag] = this.tags.splice(index, 1);
    if (tag === undefined) return;
    this.render();
    this.sync();
    this.say(format(this.root.dataset.tagsRemoved || ':tag', { tag }));
    if (!focusAfter) return;
    const chips = this.chips();
    const next = forward ? index : index - 1;
    if (forward && next >= chips.length) this.input.focus();
    else if (chips.length) chips[Math.max(0, Math.min(next, chips.length - 1))].focus();
    else this.input.focus();
  }

  private chips(): HTMLElement[] {
    return [...this.list.querySelectorAll<HTMLElement>('.fa-tag-chip')];
  }

  /** Still the widget on the page (a reactive update may have replaced its parts). */
  alive(): boolean {
    return this.box.parentElement === this.root && this.value.parentElement === this.root && this.value.isConnected && this.value.hidden === true;
  }

  destroy(): void {
    this.listeners.abort();
  }

  commit(): void {
    const text = this.input.value;
    if (!text.trim()) return;
    if (this.add(this.parse(text)) || this.only) this.input.value = '';
    this.close();
  }

  private open(all = false): void {
    const query = fold(this.input.value);
    const taken = new Set(this.tags.map(fold));
    const free = this.suggestions.filter(suggestion => !taken.has(fold(suggestion)));
    const matches = query === '' ? (all ? free.slice(0, 8) : []) : [
      ...free.filter(suggestion => fold(suggestion).startsWith(query)),
      ...free.filter(suggestion => !fold(suggestion).startsWith(query) && fold(suggestion).includes(query)),
    ].slice(0, 8);
    this.shown = matches.map(text => ({ text, isNew: false }));
    const typed = this.input.value.trim().replace(/\s+/g, ' ');
    if (typed && !this.only && !taken.has(fold(typed)) && !matches.some(match => fold(match) === fold(typed))) this.shown.push({ text: typed, isNew: true });
    if (!this.shown.length || this.tags.length >= this.max) { this.close(); return; }
    this.active = this.shown.findIndex(item => !item.isNew);
    if (this.active < 0) this.active = 0;
    this.options.replaceChildren(...this.shown.map((item, index) => {
      const option = document.createElement('li');
      option.id = `${this.options.id}-${index}`;
      option.className = 'fa-tags-option';
      option.setAttribute('role', 'option');
      option.dataset.index = String(index);
      const label = document.createElement('span');
      label.textContent = item.isNew ? format(this.root.dataset.tagsAdd || ':tag', { tag: item.text }) : item.text;
      option.append(label);
      if (item.isNew) { const note = document.createElement('small'); note.textContent = this.root.dataset.tagsNew || ''; option.append(note); }
      return option;
    }));
    this.options.hidden = false;
    this.input.setAttribute('aria-expanded', 'true');
    this.highlight();
  }

  private close(): void {
    this.options.hidden = true;
    this.input.setAttribute('aria-expanded', 'false');
    this.input.removeAttribute('aria-activedescendant');
    this.active = -1;
  }

  private highlight(): void {
    [...this.options.children].forEach((option, index) => {
      option.classList.toggle('is-active', index === this.active);
      option.setAttribute('aria-selected', String(index === this.active));
    });
    if (this.active >= 0) this.input.setAttribute('aria-activedescendant', `${this.options.id}-${this.active}`);
    else this.input.removeAttribute('aria-activedescendant');
  }

  private choose(index: number): void {
    const item = this.shown[index];
    if (!item) return;
    this.add([item.text]);
    this.input.value = '';
    this.close();
    this.input.focus();
  }

  private rtl(): boolean {
    return getComputedStyle(this.root).direction === 'rtl';
  }

  private listen(): void {
    this.box.addEventListener('mousedown', event => {
      if (event.target === this.box || event.target === this.list) { event.preventDefault(); this.input.focus(); }
    }, { signal: this.listeners.signal });
    this.list.addEventListener('click', event => {
      const remove = event.target instanceof Element ? event.target.closest('.fa-tag-remove') : null;
      const chip = remove?.closest<HTMLElement>('.fa-tag-chip');
      if (chip) { this.remove(Number(chip.dataset.index), false); this.input.focus(); }
    }, { signal: this.listeners.signal });
    this.list.addEventListener('keydown', event => {
      const chip = event.target instanceof HTMLElement ? event.target.closest<HTMLElement>('.fa-tag-chip') : null;
      if (!chip) return;
      const index = Number(chip.dataset.index);
      const chips = this.chips();
      const back = this.rtl() ? 'ArrowRight' : 'ArrowLeft';
      const forward = this.rtl() ? 'ArrowLeft' : 'ArrowRight';
      if (event.key === 'Backspace' || event.key === 'Delete') { event.preventDefault(); this.remove(index, true, event.key === 'Delete'); }
      else if (event.key === back) { event.preventDefault(); chips[Math.max(0, index - 1)].focus(); }
      else if (event.key === forward) { event.preventDefault(); if (index + 1 < chips.length) chips[index + 1].focus(); else this.input.focus(); }
      else if (event.key === 'Escape' || event.key === 'End') { event.preventDefault(); this.input.focus(); }
      else if (event.key === 'Home') { event.preventDefault(); chips[0].focus(); }
    }, { signal: this.listeners.signal });
    this.input.addEventListener('keydown', event => {
      if (event.isComposing) return;
      const open = !this.options.hidden;
      if (event.key === 'Enter') {
        // Enter adds the tag (or the suggestion); in an empty box it acts as in any other field
        if (!open && this.input.value.trim() === '') return;
        event.preventDefault();
        if (open && this.active >= 0) this.choose(this.active); else this.commit();
      } else if (this.isSeparatorKey(event.key)) {
        event.preventDefault();
        this.commit();
      } else if (event.key === 'ArrowDown' && !open) {
        event.preventDefault(); this.open(true);
      } else if (event.key === 'ArrowDown' && open) {
        event.preventDefault(); this.active = (this.active + 1) % this.shown.length; this.highlight();
      } else if (event.key === 'ArrowUp' && open) {
        event.preventDefault(); this.active = (this.active - 1 + this.shown.length) % this.shown.length; this.highlight();
      } else if (event.key === 'Escape' && open) {
        event.preventDefault(); event.stopPropagation(); this.close();
      } else if (this.input.value === '' && this.tags.length && (event.key === 'Backspace' || event.key === (this.rtl() ? 'ArrowRight' : 'ArrowLeft'))) {
        // Backspace in the empty box goes to the last tag; Backspace again removes it
        event.preventDefault();
        this.chips()[this.tags.length - 1]?.focus();
      }
    }, { signal: this.listeners.signal });
    this.input.addEventListener('input', event => {
      if ((event as InputEvent).isComposing) return;
      // A pasted "a, b, c" (or a separator typed on a phone keyboard) becomes tags; the last part stays to type on
      if (this.separators().test(this.input.value)) {
        const parts = this.input.value.split(this.separators());
        const rest = parts.pop() ?? '';
        this.add(parts);
        this.input.value = rest.trimStart();
      }
      this.open();
    }, { signal: this.listeners.signal });
    this.input.addEventListener('blur', () => { window.setTimeout(() => { if (document.activeElement !== this.input) this.commit(); }, 120); }, { signal: this.listeners.signal });
    this.options.addEventListener('mousedown', event => event.preventDefault(), { signal: this.listeners.signal });
    this.options.addEventListener('click', event => {
      const option = event.target instanceof Element ? event.target.closest<HTMLElement>('.fa-tags-option') : null;
      if (option) this.choose(Number(option.dataset.index));
    }, { signal: this.listeners.signal });
  }
}

const instances = new WeakMap<HTMLElement, Tags>();

// Words typed but not yet added become tags when the form is sent (one listener for every form)
document.addEventListener('submit', event => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  form.querySelectorAll<HTMLElement>('[data-admin-tags]').forEach(element => instances.get(element)?.commit());
}, { capture: true });

export function initializeChoiceFields(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-admin-checks]').forEach(initializeChecks);
  root.querySelectorAll<HTMLElement>('[data-admin-tags]').forEach(element => {
    if (element.closest('template')) return;
    // Initialised again (a refresh, a dialog, a builder pane): keep the working widget and what is being typed
    const existing = instances.get(element);
    if (existing?.alive()) return;
    existing?.destroy();
    instances.set(element, new Tags(element));
  });
}
