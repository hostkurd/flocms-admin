/**
 * Ctrl+K search (0.20).
 *
 * - Ctrl+K, ⌘K or / (outside a field) opens #fa-search-dialog; typing in the top-bar
 *   field, or clicking it, does too. Without JavaScript that field is a plain search
 *   form that opens the results page.
 * - Typing searches after a 200 ms pause, from 2 characters (1 for a number); a new
 *   keystroke cancels the request in flight.
 * - The input is an ARIA combobox: ↑ ↓ move through the options (results and "See all"
 *   links), Enter opens one, Ctrl/⌘+Enter opens it in a new tab, Esc closes.
 * - Results are built with textContent; only the panel's own icon markup is inserted.
 */

type Item = { title: string; detail: string; type: string; url: string; icon: string; thumb: string };
type Group = { label: string; more: string; items: Item[] };
type Answer = { query: string; groups: Group[]; total: number; partial: boolean };

const DELAY = 200;
const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

let timer: number | undefined;
let request: AbortController | undefined;
let active = -1;

const form = () => document.querySelector<HTMLFormElement>('form[data-admin-search]');
const dialog = () => document.getElementById('fa-search-dialog') as HTMLDialogElement | null;
const input = (d: HTMLDialogElement) => d.querySelector<HTMLInputElement>('.fa-cmdk-input')!;
const list = (d: HTMLDialogElement) => d.querySelector<HTMLUListElement>('.fa-cmdk-list')!;
const options = (d: HTMLDialogElement) => [...list(d).querySelectorAll<HTMLElement>('[role="option"]')];
const text = (d: HTMLDialogElement, key: string, values: Record<string, string | number> = {}) =>
  Object.entries(values).reduce((s, [k, v]) => s.split(':' + k).join(String(v)), d.dataset[key] || '');

function editable(target: EventTarget | null): boolean {
  return target instanceof HTMLElement && (target.isContentEditable || Boolean(target.closest('input, textarea, select, [contenteditable="true"]')));
}

export function openSearch(initial = ''): void {
  const d = dialog();
  if (!d || typeof d.showModal !== 'function') { form()?.requestSubmit(); return; }
  if (!d.open) {
    // Another dialog (an editor, a confirmation) keeps the keyboard.
    if (document.querySelector('dialog[open]:not(#fa-search-dialog)')) return;
    wire(d);
    d.showModal();
  }
  const field = input(d);
  field.value = initial;
  field.focus();
  field.setSelectionRange(initial.length, initial.length);
  search(d, initial, true);
}

function wire(d: HTMLDialogElement): void {
  if (d.dataset.wired === '1') return;
  d.dataset.wired = '1';
  const field = input(d);
  field.addEventListener('input', () => search(d, field.value, false));
  field.addEventListener('keydown', event => keys(d, event));
  list(d).addEventListener('mousemove', event => {
    const option = (event.target as Element).closest<HTMLElement>('[role="option"]');
    if (option) select(d, options(d).indexOf(option), false);
  });
  list(d).addEventListener('click', event => {
    const link = (event.target as Element).closest('a');
    const plain = event instanceof MouseEvent && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0;
    if (link && plain) d.close();
  });
  d.addEventListener('click', event => {
    if (event.target !== d) return;
    const box = d.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) d.close();
  });
  d.addEventListener('close', () => { request?.abort(); window.clearTimeout(timer); field.setAttribute('aria-expanded', 'false'); });
}

function search(d: HTMLDialogElement, raw: string, now: boolean): void {
  window.clearTimeout(timer);
  const query = raw.trim();
  // One letter finds too much; one digit is an id.
  const usable = query === '' || query.length >= 2 || /^#?\d+$/.test(query) ? query : '';
  if (!now && usable === d.dataset.shown) {
    // Back to what is shown: an answer still on its way is for other words.
    request?.abort();
    d.removeAttribute('aria-busy');
    return;
  }
  timer = window.setTimeout(() => void load(d, usable), now ? 0 : DELAY);
}

async function load(d: HTMLDialogElement, query: string): Promise<void> {
  request?.abort();
  request = new AbortController();
  const url = new URL(form()?.dataset.adminSearch || '', location.href);
  url.searchParams.set('q', query);
  d.setAttribute('aria-busy', 'true');
  try {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: request.signal });
    if (!response.ok || !(response.headers.get('Content-Type') || '').includes('json')) throw new Error(String(response.status));
    render(d, (await response.json()) as Answer);
  } catch (error) {
    if ((error as Error).name === 'AbortError') return;
    empty(d, text(d, 'failed'), '');
  } finally {
    d.removeAttribute('aria-busy');
  }
}

function render(d: HTMLDialogElement, answer: Answer): void {
  const field = input(d);
  d.dataset.shown = answer.query;
  const box = list(d);
  box.replaceChildren();
  let index = 0;
  for (const group of answer.groups) {
    const li = document.createElement('li');
    li.className = 'fa-cmdk-group';
    li.setAttribute('role', 'presentation');
    const label = document.createElement('div');
    label.className = 'fa-cmdk-label';
    label.setAttribute('role', 'presentation');
    label.textContent = group.label;
    const ul = document.createElement('ul');
    ul.setAttribute('role', 'group');
    ul.setAttribute('aria-label', group.label);
    for (const item of group.items) ul.append(option(item, index++, answer.query));
    if (group.more) ul.append(more(d, group, index++));
    li.append(label, ul);
    box.append(li);
  }
  const status = d.querySelector<HTMLElement>('[data-admin-search-status]')!;
  const partial = answer.partial ? ' ' + text(d, 'partial') : '';
  if (answer.total === 0 && answer.query !== '') {
    empty(d, text(d, 'none', { query: answer.query }), text(d, 'hint') + partial);
    return;
  }
  d.querySelector<HTMLElement>('[data-admin-search-empty]')!.hidden = true;
  box.hidden = false;
  field.setAttribute('aria-expanded', index > 0 ? 'true' : 'false');
  status.textContent = (answer.total === 1 ? text(d, 'resultsOne') : text(d, 'results', { count: answer.total })) + partial;
  select(d, index > 0 ? 0 : -1, true);
}

function option(item: Item, index: number, query: string): HTMLLIElement {
  const li = document.createElement('li');
  li.className = 'fa-cmdk-opt';
  li.id = 'fa-cmdk-opt-' + index;
  li.setAttribute('role', 'option');
  li.setAttribute('aria-selected', 'false');
  const link = document.createElement('a');
  link.className = 'fa-cmdk-link';
  link.href = item.url;
  link.tabIndex = -1;
  if (item.thumb) {
    const img = document.createElement('img');
    img.className = 'fa-cmdk-thumb';
    img.src = item.thumb;
    img.alt = '';
    img.width = 32;
    img.height = 32;
    img.loading = 'lazy';
    link.append(img);
  } else {
    const icon = document.createElement('span');
    icon.className = 'fa-cmdk-ico';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = item.icon; // the panel's own icon markup (Icons::svg)
    link.append(icon);
  }
  const body = document.createElement('span');
  body.className = 'fa-cmdk-text';
  const title = document.createElement('span');
  title.className = 'fa-cmdk-title';
  highlight(title, item.title, query);
  body.append(title);
  if (item.detail) {
    const detail = document.createElement('span');
    detail.className = 'fa-cmdk-detail';
    detail.textContent = item.detail;
    body.append(detail);
  }
  const type = document.createElement('span');
  type.className = 'fa-cmdk-type';
  type.textContent = item.type;
  link.append(body, type);
  li.append(link);
  return li;
}

function more(d: HTMLDialogElement, group: Group, index: number): HTMLLIElement {
  const li = document.createElement('li');
  li.className = 'fa-cmdk-opt fa-cmdk-more';
  li.id = 'fa-cmdk-opt-' + index;
  li.setAttribute('role', 'option');
  li.setAttribute('aria-selected', 'false');
  const link = document.createElement('a');
  link.className = 'fa-cmdk-link';
  link.href = group.more;
  link.tabIndex = -1;
  link.textContent = text(d, 'seeAll', { label: group.label });
  li.append(link);
  return li;
}

/** The typed words in bold inside the title (the first match, any case). */
function highlight(target: HTMLElement, title: string, query: string): void {
  const needle = query.replace(/^#/, '').toLocaleLowerCase();
  const at = needle === '' ? -1 : title.toLocaleLowerCase().indexOf(needle);
  if (at < 0) { target.textContent = title; return; }
  const mark = document.createElement('mark');
  mark.textContent = title.slice(at, at + needle.length);
  target.append(title.slice(0, at), mark, title.slice(at + needle.length));
}

function empty(d: HTMLDialogElement, title: string, hint: string): void {
  const box = list(d);
  box.replaceChildren();
  box.hidden = true;
  const panel = d.querySelector<HTMLElement>('[data-admin-search-empty]')!;
  const strong = document.createElement('b');
  strong.textContent = title;
  panel.replaceChildren(strong, hint);
  panel.hidden = false;
  input(d).setAttribute('aria-expanded', 'false');
  input(d).removeAttribute('aria-activedescendant');
  d.querySelector<HTMLElement>('[data-admin-search-status]')!.textContent = title;
  active = -1;
}

function select(d: HTMLDialogElement, index: number, reset: boolean): void {
  const all = options(d);
  if (!reset && index === active) return;
  all.forEach((el, i) => {
    el.classList.toggle('is-active', i === index);
    el.setAttribute('aria-selected', i === index ? 'true' : 'false');
  });
  active = index;
  const field = input(d);
  const current = all[index];
  if (current) {
    field.setAttribute('aria-activedescendant', current.id);
    if (!reset) current.scrollIntoView({ block: 'nearest' });
  } else {
    field.removeAttribute('aria-activedescendant');
  }
  if (reset) list(d).scrollTop = 0;
}

function keys(d: HTMLDialogElement, event: KeyboardEvent): void {
  // An input method is choosing a word: its Enter and arrows are its own.
  if (event.isComposing || event.keyCode === 229) return;
  const all = options(d);
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    if (all.length === 0) return;
    event.preventDefault();
    const step = event.key === 'ArrowDown' ? 1 : -1;
    select(d, (active + step + all.length) % all.length, false);
  } else if (event.key === 'Enter') {
    event.preventDefault();
    const link = all[active]?.querySelector<HTMLAnchorElement>('a');
    if (!link) return;
    if (event.ctrlKey || event.metaKey) { window.open(link.href, '_blank', 'noopener'); return; }
    d.close();
    link.click();
  }
}

function shortcuts(event: KeyboardEvent): void {
  if (event.defaultPrevented || event.isComposing || form()?.dataset.adminSearchShortcut !== '1') return;
  const k = event.key.toLowerCase() === 'k' && (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey;
  const slash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !editable(event.target);
  if (!k && !slash) return;
  if (dialog()?.open) { if (k) { event.preventDefault(); input(dialog()!).select(); } return; }
  if (!dialog()) return;
  event.preventDefault();
  openSearch();
}

function initializeField(): void {
  document.querySelectorAll<HTMLElement>('[data-admin-search-mod]').forEach(el => { el.textContent = isMac ? '⌘' : 'Ctrl'; });
}

document.addEventListener('keydown', shortcuts);
// The top-bar field: typing in it, Enter or a click opens the dialog with what was typed.
function fromField(field: EventTarget | null): void {
  if (!(field instanceof HTMLInputElement) || !field.closest('form[data-admin-search]') || !dialog()) return;
  const value = field.value;
  field.value = '';
  openSearch(value);
}
document.addEventListener('input', event => {
  // While an input method composes, wait for the finished word.
  if ((event as InputEvent).isComposing) return;
  fromField(event.target);
});
document.addEventListener('compositionend', event => fromField(event.target));
document.addEventListener('click', event => {
  const field = (event.target as Element | null)?.closest?.('form[data-admin-search]');
  if (!field || !dialog()) return;
  event.preventDefault();
  openSearch((field.querySelector('input') as HTMLInputElement).value);
});
document.addEventListener('submit', event => {
  const target = event.target;
  if (!(target instanceof HTMLFormElement) || !target.matches('form[data-admin-search]') || !dialog()) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const field = target.querySelector('input') as HTMLInputElement;
  openSearch(field.value);
  field.value = '';
}, true);
document.addEventListener('DOMContentLoaded', initializeField);
document.addEventListener('flo-admin:navigated', initializeField);
initializeField();
