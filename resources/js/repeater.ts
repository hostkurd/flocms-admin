/*
 * Repeater and key-value fields (0.24): add, remove with Undo, move (buttons,
 * drag and drop, arrow keys on the handle), duplicate and collapse items, at
 * once in the page. Without JavaScript the Add, Remove, Up and Down buttons
 * submit the form and the server shows it again, changed.
 *
 * Inputs are named rooms[<key>][field]; the browser sends the items in their
 * order on the page, so moving an item in the page is all a reorder needs.
 */
import { applyEditing, initializeTranslations } from './translations';
import { initializeMedia } from './media';
import { initializeChoiceFields } from './choices';

type Removed = { node: HTMLElement; index: number; title: string };
const removed = new WeakMap<HTMLElement, Removed>();
const ready = new WeakSet<HTMLElement>();

const items = (field: HTMLElement) => [...field.querySelectorAll<HTMLElement>(':scope > [data-repeater-items] > [data-repeater-item]')];
const fieldOf = (element: Element) => element.closest<HTMLElement>('[data-repeater]');
const text = (template: string | undefined, values: Record<string, string>) =>
  Object.entries(values).reduce((out, [key, value]) => out.split(':' + key).join(value), template || '');

function newKey(): string {
  const bytes = new Uint8Array(4);
  crypto.getRandomValues(bytes);
  return 'k' + [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
}

/** The live region that announces moves (screen readers). */
function announce(field: HTMLElement, message: string): void {
  let region = field.querySelector<HTMLElement>(':scope > [data-repeater-live]');
  if (!region) {
    region = document.createElement('p');
    region.className = 'fa-sr';
    region.setAttribute('aria-live', 'polite');
    region.dataset.repeaterLive = '';
    field.append(region);
  }
  region.textContent = '';
  requestAnimationFrame(() => { region!.textContent = message; });
}

/** An item's title: its itemLabel field (default language), else "Item 3". */
function title(field: HTMLElement, item: HTMLElement, index: number): string {
  const label = field.dataset.itemLabel || '';
  const key = item.dataset.key || '';
  if (label) {
    const base = `${field.dataset.name}[${key}][${label}]`;
    const scope = field.closest<HTMLElement>('[data-lang-scope]');
    const defaultLang = scope?.dataset.defaultLang || document.querySelector<HTMLElement>('.fa-panel')?.dataset.defaultLang || '';
    const control = item.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${CSS.escape(base)}"]`)
      ?? (defaultLang ? item.querySelector<HTMLInputElement>(`[name="${CSS.escape(base + '[' + defaultLang + ']')}"]`) : null)
      ?? item.querySelector<HTMLInputElement>(`[name^="${CSS.escape(base + '[')}"]:not([data-mirror])`);
    const value = control instanceof HTMLSelectElement ? (control.selectedOptions[0]?.value ? control.selectedOptions[0].textContent || '' : '') : control?.value || '';
    if (value.trim() !== '') return value.trim().slice(0, 80);
  }
  return text(field.dataset.itemText, { number: String(index + 1) });
}

/** The short summary of a collapsed item: its other values ("16 m² · Ground"). */
function summary(field: HTMLElement, item: HTMLElement): string {
  const body = item.querySelector<HTMLElement>('.fa-repeater-body');
  if (!body) return '';
  // The title field is already in the head.
  const label = field.dataset.itemLabel ? `${field.dataset.name}[${item.dataset.key || ''}][${field.dataset.itemLabel}]` : '';
  const parts: string[] = [];
  body.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[name]').forEach(control => {
    if (parts.length >= 3 || control.type === 'hidden' || control.type === 'checkbox' || control.hidden || control.closest('.fa-lang-dialog, .fa-lang-pane:not(.is-current)')) return;
    if (label && (control.name === label || control.name.startsWith(label + '['))) return;
    // The body itself is hidden while collapsed; panes of other languages inside it stay skipped.
    const hidden = control.parentElement?.closest('[hidden]');
    if (hidden && hidden !== body && body.contains(hidden)) return;
    const value = control instanceof HTMLSelectElement ? (control.selectedOptions[0]?.value ? control.selectedOptions[0].textContent || '' : '') : control.value;
    if (value.trim() !== '') parts.push(value.trim().slice(0, 40));
  });
  return parts.join(' · ');
}

/** Numbers, titles, the first/last buttons, the count and the Add button after any change. */
function refresh(field: HTMLElement): void {
  const list = items(field);
  const max = Number(field.dataset.max || 0);
  list.forEach((item, index) => {
    const number = item.querySelector<HTMLElement>('[data-repeater-number]');
    if (number) number.textContent = String(index + 1);
    const name = title(field, item, index);
    const heading = item.querySelector<HTMLElement>('[data-repeater-title]');
    if (heading) heading.textContent = name;
    const small = item.querySelector<HTMLElement>('[data-repeater-summary]');
    if (small) small.textContent = item.classList.contains('is-collapsed') ? summary(field, item) : '';
    const handle = item.querySelector<HTMLElement>('[data-repeater-handle]');
    if (handle) handle.setAttribute('aria-label', text(field.dataset.dragText, { item: name }));
    const up = item.querySelector<HTMLButtonElement>('[data-repeater-action="up"]');
    const down = item.querySelector<HTMLButtonElement>('[data-repeater-action="down"]');
    if (up) up.disabled = index === 0;
    if (down) down.disabled = index === list.length - 1;
  });
  const count = field.querySelector<HTMLElement>(':scope > .fa-repeater-head [data-repeater-count]');
  if (count && !field.hasAttribute('data-fixed')) count.textContent = text(field.dataset.countText, { count: String(list.length) });
  const add = field.querySelector<HTMLButtonElement>(':scope > [data-repeater-add]');
  if (add) add.disabled = max > 0 && list.length >= max;
  // Undo can't bring an item back past the maximum.
  const undoButton = field.querySelector<HTMLButtonElement>(':scope > [data-repeater-undo] [data-repeater-undo-button]');
  if (undoButton) undoButton.disabled = max > 0 && list.length >= max;
}

/** A change the form must hear about (unsaved changes, the language counts). */
function changed(field: HTMLElement): void {
  refresh(field);
  field.dispatchEvent(new Event('input', { bubbles: true }));
}

/** Make a new item (or a dialog's morphed item) work: badges, media fields, the language switch. */
function prepare(field: HTMLElement, item: HTMLElement): void {
  enhance(item);
  initialize(item);
  initializeTranslations(item);
  initializeMedia(item);
  initializeChoiceFields(item);
  const scope = field.closest<HTMLElement>('[data-lang-scope].is-switching');
  if (scope) applyEditing(scope, scope.dataset.editing || scope.dataset.defaultLang || '');
}

function fromTemplate(field: HTMLElement, key: string, number: number): HTMLElement | null {
  const template = field.querySelector<HTMLTemplateElement>(':scope > template[data-repeater-template]');
  if (!template) return null;
  const holder = document.createElement('div');
  holder.innerHTML = template.innerHTML.split('__KEY__').join(key).split('__NUMBER__').join(String(number));
  return holder.firstElementChild as HTMLElement | null;
}

function add(field: HTMLElement): void {
  const list = items(field);
  const max = Number(field.dataset.max || 0);
  if (max > 0 && list.length >= max) return;
  const item = fromTemplate(field, newKey(), list.length + 1);
  if (!item) return;
  field.querySelector<HTMLElement>(':scope > [data-repeater-items]')!.append(item);
  prepare(field, item);
  hideUndo(field);
  changed(field);
  item.querySelector<HTMLElement>('.fa-repeater-body input:not([type="hidden"]), .fa-repeater-body select, .fa-repeater-body textarea')?.focus();
}

function duplicate(field: HTMLElement, source: HTMLElement): void {
  const list = items(field);
  const max = Number(field.dataset.max || 0);
  if (max > 0 && list.length >= max) return;
  const oldKey = source.dataset.key || '';
  const key = newKey();
  const copy = fromTemplate(field, key, list.indexOf(source) + 2);
  if (!copy) return;
  // Copy every value, then the media fields' chosen items (before their script runs).
  source.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[name]').forEach(control => {
    if (control.name.endsWith('[_key]')) return;
    const name = control.name.replace(`[${oldKey}]`, `[${key}]`);
    const target = [...copy.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[name]')].find(element => element.name === name && element.type === control.type);
    if (!target) return;
    if (target instanceof HTMLInputElement && (target.type === 'checkbox' || target.type === 'radio')) target.checked = (control as HTMLInputElement).checked;
    else if (target instanceof HTMLSelectElement && control instanceof HTMLSelectElement) [...target.options].forEach((option, i) => { option.selected = control.options[i]?.selected ?? false; });
    else target.value = control.value;
  });
  source.querySelectorAll<HTMLElement>('[data-admin-media-field]').forEach((media, index) => {
    const target = copy.querySelectorAll<HTMLElement>('[data-admin-media-field]')[index];
    if (target && media.dataset.items) target.dataset.items = media.dataset.items;
  });
  source.after(copy);
  prepare(field, copy);
  changed(field);
  copy.querySelector<HTMLElement>('[data-repeater-action="duplicate"]')?.focus();
}

function hideUndo(field: HTMLElement): void {
  const undo = field.querySelector<HTMLElement>(':scope > [data-repeater-undo]');
  if (undo) undo.hidden = true;
  removed.delete(field);
}

function remove(field: HTMLElement, item: HTMLElement): void {
  const list = items(field);
  const index = list.indexOf(item);
  const name = title(field, item, index);
  removed.set(field, { node: item, index, title: name });
  item.remove();
  const undo = field.querySelector<HTMLElement>(':scope > [data-repeater-undo]');
  if (undo) {
    undo.hidden = false;
    const message = undo.querySelector<HTMLElement>('[data-repeater-undo-text]');
    if (message) message.textContent = text(field.dataset.removedText, { item: name });
  }
  changed(field);
  const next = items(field)[Math.min(index, items(field).length - 1)];
  (next?.querySelector<HTMLElement>('[data-repeater-action="remove"]') ?? undo?.querySelector<HTMLElement>('[data-repeater-undo-button]'))?.focus();
}

function undo(field: HTMLElement): void {
  const last = removed.get(field);
  const max = Number(field.dataset.max || 0);
  if (!last || (max > 0 && items(field).length >= max)) return;
  const container = field.querySelector<HTMLElement>(':scope > [data-repeater-items]')!;
  const list = items(field);
  container.insertBefore(last.node, list[last.index] ?? null);
  hideUndo(field);
  changed(field);
  last.node.querySelector<HTMLElement>('[data-repeater-action="remove"]')?.focus();
}

function move(field: HTMLElement, item: HTMLElement, by: number, focus?: HTMLElement | null): void {
  const list = items(field);
  const index = list.indexOf(item);
  const target = index + by;
  if (index < 0 || target < 0 || target >= list.length) return;
  if (by < 0) list[target].before(item); else list[target].after(item);
  changed(field);
  focus?.focus();
  announce(field, text(field.dataset.movedText, { item: title(field, item, target), position: String(target + 1) }));
}

function toggle(field: HTMLElement, item: HTMLElement): void {
  const body = item.querySelector<HTMLElement>('.fa-repeater-body');
  const button = item.querySelector<HTMLButtonElement>('[data-repeater-action="toggle"]');
  if (!body || !button) return;
  const collapse = !body.hidden;
  body.hidden = collapse;
  item.classList.toggle('is-collapsed', collapse);
  button.setAttribute('aria-expanded', String(!collapse));
  const label = collapse ? field.dataset.expandText : field.dataset.collapseText;
  if (label) { button.setAttribute('aria-label', label); button.title = label; }
  refresh(field);
}

/** Drag an item by its handle: it follows the pointer and the others make room. */
function drag(field: HTMLElement, item: HTMLElement, event: PointerEvent): void {
  const handle = event.target instanceof Element ? event.target.closest<HTMLElement>('[data-repeater-handle]') : null;
  if (!handle) return;
  event.preventDefault();
  // Moving the item in the page drops pointer capture, so the document follows the pointer.
  const pointer = event.pointerId;
  item.classList.add('is-dragging');
  const start = items(field).indexOf(item);
  const onMove = (move: PointerEvent) => {
    if (move.pointerId !== pointer) return;
    const list = items(field);
    for (const other of list) {
      if (other === item) continue;
      const box = other.getBoundingClientRect();
      const middle = box.top + box.height / 2;
      const index = list.indexOf(other);
      const mine = list.indexOf(item);
      if (index < mine && move.clientY < middle) { other.before(item); break; }
      if (index > mine && move.clientY > middle) { other.after(item); break; }
    }
  };
  const onUp = (up: PointerEvent) => {
    if (up.pointerId !== pointer) return;
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onUp);
    item.classList.remove('is-dragging');
    const end = items(field).indexOf(item);
    if (end !== start) {
      changed(field);
      announce(field, text(field.dataset.movedText, { item: title(field, item, end), position: String(end + 1) }));
    }
    handle.focus();
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
}

/** The tools that need JavaScript appear; the no-JavaScript Up and Down of table rows go. */
function enhance(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-repeater-handle], [data-repeater-action="duplicate"], [data-repeater-action="toggle"]').forEach(tool => {
    if (!tool.closest('template')) tool.hidden = false;
  });
  root.querySelectorAll<HTMLElement>('[data-nojs-only]').forEach(tool => { tool.hidden = true; });
}

export function initialize(root: ParentNode): void {
  const fields = [...root.querySelectorAll<HTMLElement>('[data-repeater]')];
  if (root instanceof HTMLElement && root.matches('[data-repeater]')) fields.unshift(root);
  fields.forEach(field => {
    enhance(field);
    if (!ready.has(field)) { ready.add(field); refresh(field); }
  });
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const control = event.target.closest<HTMLButtonElement>('[data-repeater-action], [data-repeater-add], [data-repeater-undo-button]');
  const field = control ? fieldOf(control) : null;
  if (!control || !field || control.closest('template')) return;
  // With JavaScript the buttons act here; the form is not sent.
  event.preventDefault();
  if (control.disabled) return;
  if (control.matches('[data-repeater-add]')) { add(field); return; }
  if (control.matches('[data-repeater-undo-button]')) { undo(field); return; }
  const item = control.closest<HTMLElement>('[data-repeater-item]');
  if (!item) return;
  switch (control.dataset.repeaterAction) {
    case 'remove': remove(field, item); break;
    case 'up': move(field, item, -1, control.disabled ? item.querySelector<HTMLElement>('[data-repeater-action="down"]') : control); break;
    case 'down': move(field, item, 1, control); break;
    case 'duplicate': duplicate(field, item); break;
    case 'toggle': toggle(field, item); break;
  }
  // A moved button can become disabled (first or last): keep focus on the item.
  if (document.activeElement instanceof HTMLButtonElement && document.activeElement.disabled) item.querySelector<HTMLElement>('[data-repeater-handle], [data-repeater-action]:not(:disabled)')?.focus();
});

document.addEventListener('pointerdown', event => {
  if (!(event.target instanceof Element) || event.button !== 0) return;
  const handle = event.target.closest<HTMLElement>('[data-repeater-handle]');
  const item = handle?.closest<HTMLElement>('[data-repeater-item]');
  const field = item ? fieldOf(item) : null;
  if (handle && item && field) drag(field, item, event);
});

// The handle moves its item with the arrow keys.
document.addEventListener('keydown', event => {
  if (!(event.target instanceof HTMLElement) || !event.target.matches('[data-repeater-handle]')) return;
  if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
  const item = event.target.closest<HTMLElement>('[data-repeater-item]');
  const field = item ? fieldOf(item) : null;
  if (!item || !field) return;
  event.preventDefault();
  move(field, item, event.key === 'ArrowUp' ? -1 : 1, event.target);
});

// Titles and summaries follow what is typed.
document.addEventListener('input', event => {
  if (!(event.target instanceof HTMLElement) || event.target.matches('[data-repeater]')) return;
  const field = event.target.closest<HTMLElement>('[data-repeater]');
  if (field && event.target.closest('[data-repeater-item]')) refresh(field);
});
document.addEventListener('change', event => {
  if (!(event.target instanceof HTMLSelectElement)) return;
  const field = event.target.closest<HTMLElement>('[data-repeater]');
  if (field) refresh(field);
});
