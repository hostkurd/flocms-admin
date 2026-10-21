/*
 * The Builder field (0.29; 0.31: row buttons, the block panel and dialogs):
 * the structure of a page's blocks, the selected block's shared settings
 * beside it, and its own fields in a dialog (Edit). Every block's fields are in the page, each with its
 * type, parent, column and place (hidden inputs), so all this script does is
 * show one block at a time, add blocks from templates, move, duplicate,
 * remove and copy them to another language, and keep the hidden inputs in
 * step with the structure. Without JavaScript the buttons submit the form
 * and the server makes the change (Builder::applyOperation).
 */
import { applyEditing, initializeTranslations } from './translations';
import { initializeMedia } from './media';
import { initializeForms } from './forms';
import { initializeEditors } from './editors';
import { initialize as initializeRepeaters } from './repeater';

type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
type Target = { list: HTMLElement; parent: string; column: number; after: HTMLElement | null };
type Removed = { list: HTMLElement; node: HTMLElement; panes: HTMLElement[]; slot: HTMLElement; next: Element | null; title: string };

const ready = new WeakSet<HTMLElement>();
const targets = new WeakMap<HTMLElement, Target>();
const removed = new WeakMap<HTMLElement, Removed>();
const pending = new WeakMap<HTMLElement, { list: HTMLElement; node: HTMLElement }>();
/** A block was just added from the Add dialog: its closing leaves the focus on the new block. */
const added = new WeakSet<HTMLElement>();
const TITLE_FIELDS = ['_label', 'title', 'heading', 'name', 'label', 'text', 'body', 'caption'];

const text = (template: string | undefined, values: Record<string, string>) =>
  Object.entries(values).reduce((out, [key, value]) => out.split(':' + key).join(value), template || '');
const builderOf = (element: Element) => element.closest<HTMLElement>('[data-builder]');
const listOf = (element: Element) => element.closest<HTMLElement>('[data-builder-list]');
const nodes = (scope: ParentNode) => [...scope.querySelectorAll<HTMLElement>('[data-builder-node]')];
const paneOf = (list: HTMLElement, id: string) => list.querySelector<HTMLElement>(`:scope > [data-builder-inspector] > [data-builder-pane="${CSS.escape(id)}"]`);
const slotOf = (node: HTMLElement) => node.parentElement as HTMLElement;
const childNodes = (slot: HTMLElement) => [...slot.children].filter((child): child is HTMLElement => child instanceof HTMLElement && child.hasAttribute('data-builder-node'));

const dialogOf = (builder: HTMLElement, kind: 'edit' | 'add' | 'confirm') =>
  builder.querySelector<HTMLDialogElement>(kind === 'confirm' ? ':scope > dialog[data-builder-confirm]' : `:scope > dialog[data-builder-${kind}-dialog]`);
const listByKey = (builder: HTMLElement, key: string) => builder.querySelector<HTMLElement>(`[data-builder-list="${CSS.escape(key)}"]`);
const nodeOf = (list: HTMLElement, id: string) => list.querySelector<HTMLElement>(`[data-builder-node="${CSS.escape(id)}"]`);

/** A block's own fields: in its pane, or in the edit dialog while it is open. */
function fieldsOf(list: HTMLElement, id: string): HTMLElement | null {
  const inPane = paneOf(list, id)?.querySelector<HTMLElement>(':scope > [data-builder-fields]');
  if (inPane) return inPane;
  const dialog = dialogOf(builderOf(list)!, 'edit');
  return dialog?.querySelector<HTMLElement>(`[data-builder-fields="${CSS.escape(id)}"][data-list="${CSS.escape(list.dataset.builderList || '')}"]`) ?? null;
}

/** Everything that holds a block's controls: its pane, and its fields when they are in the dialog. */
function scopesOf(list: HTMLElement, id: string): HTMLElement[] {
  const pane = paneOf(list, id);
  const fields = fieldsOf(list, id);
  return [pane, fields && !pane?.contains(fields) ? fields : null].filter((scope): scope is HTMLElement => scope !== null);
}

/** The list and block an element (a control) belongs to, in a pane or in the edit dialog. */
function blockFrom(element: Element): { list: HTMLElement; id: string } | null {
  const pane = element.closest<HTMLElement>('[data-builder-pane]');
  if (pane) { const list = listOf(pane); return list ? { list, id: pane.dataset.builderPane || '' } : null; }
  const fields = element.closest<HTMLElement>('[data-builder-fields]');
  const builder = fields ? builderOf(fields) : null;
  const list = fields && builder ? listByKey(builder, fields.dataset.list || '') : null;
  return list && fields ? { list, id: fields.dataset.builderFields || '' } : null;
}

function controlsOf(list: HTMLElement, id: string): Control[] {
  return scopesOf(list, id).flatMap(scope => [...scope.querySelectorAll<Control>('[name]')]);
}

/** A block's setting or field value by its name ("status", "_label"…). */
function valueOf(list: HTMLElement, id: string, name: string): string {
  const end = `[${list.dataset.builderList}][${id}][f][${name}]`;
  const controls = controlsOf(list, id).filter(control => control.name.endsWith(end));
  const radio = controls.find(control => control instanceof HTMLInputElement && control.type === 'radio');
  if (radio) return (controls.find(control => (control as HTMLInputElement).checked) as HTMLInputElement | undefined)?.value ?? '';
  return controls[0]?.value ?? '';
}

function newId(): string {
  const bytes = new Uint8Array(4);
  crypto.getRandomValues(bytes);
  return 'b' + [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
}

function announce(builder: HTMLElement, message: string): void {
  const region = builder.querySelector<HTMLElement>(':scope > [data-builder-live]');
  if (!region) return;
  region.textContent = '';
  requestAnimationFrame(() => { region.textContent = message; });
}

/** A list's picker: under its structure, or in the Add dialog while that is open for it. */
function pickerOf(list: HTMLElement): HTMLElement | null {
  const home = list.querySelector<HTMLElement>(':scope > [data-builder-outline] > .fa-builder-add-main [data-builder-picker]');
  if (home) return home;
  const builder = builderOf(list);
  const dialog = builder ? builder.querySelector<HTMLDialogElement>(':scope > dialog[data-builder-add-dialog]') : null;
  return dialog && dialog.dataset.list === list.dataset.builderList ? dialog.querySelector<HTMLElement>('[data-builder-picker]') : null;
}

/** Where a slot is: "top", or the type of the block it is in. */
function placeOf(slot: HTMLElement): string {
  const parent = slot.dataset.parent || '';
  if (!parent) return 'top';
  return slot.closest<HTMLElement>('[data-builder-node]')?.dataset.type || '';
}

/** Whether a kind of block may sit in a slot (from the picker's data-allowed). */
function allowed(list: HTMLElement, type: string, slot: HTMLElement): boolean {
  const choice = pickerOf(list)?.querySelector<HTMLElement>(`[data-builder-choice="${CSS.escape(type)}"]`);
  if (!choice) return type === '' ? false : !slot.dataset.parent;
  return (choice.dataset.allowed || '').split(' ').includes(placeOf(slot));
}

/** A block's title, as the server makes it: its first named text field, else its kind. */
function titleOf(list: HTMLElement, node: HTMLElement): string {
  const id = node.dataset.builderNode || '';
  const pane = paneOf(list, id);
  if (!pane) return '';
  const base = `[${list.dataset.builderList}][${id}][f]`;
  const all = controlsOf(list, id);
  for (const name of TITLE_FIELDS) {
    const control = all.find(element => element.name.endsWith(`${base}[${name}]`));
    if (!control) continue;
    const value = name === 'body' || control.closest('[data-admin-rich-editor]')
      ? (new DOMParser().parseFromString(control.value, 'text/html').body.textContent || '') : control.value;
    if (value.trim() !== '') return value.trim().replace(/\s+/g, ' ').slice(0, 80);
  }
  if (node.dataset.type === 'columns') {
    const builder = builderOf(list)!;
    return text(builder.dataset.columnsText || '', { count: String(node.querySelectorAll(':scope > [data-builder-cols] > [data-builder-col]').length) })
      || node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-title]')?.textContent || '';
  }
  return pane.querySelector<HTMLElement>('.fa-builder-pane-kind')?.textContent || '';
}

/** After any change: the hidden place inputs, titles, counts, the empty state and the Add buttons. */
function sync(list: HTMLElement): void {
  const builder = builderOf(list)!;
  list.querySelectorAll<HTMLElement>('[data-builder-slot]').forEach(slot => {
    childNodes(slot).forEach((node, order) => {
      const pane = paneOf(list, node.dataset.builderNode || '');
      if (!pane) return;
      const set = (part: string, value: string) => { const input = pane.querySelector<HTMLInputElement>(`:scope > [data-builder-${part}]`); if (input) input.value = value; };
      set('parent', slot.dataset.parent || '');
      set('column', slot.dataset.column || '0');
      set('order', String(order));
      node.dataset.level = String(1 + [...ancestors(node)].length);
    });
  });
  nodes(list).forEach(node => {
    const title = titleOf(list, node);
    const strong = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-title]');
    if (strong && title) strong.textContent = title;
    const pane = paneOf(list, node.dataset.builderNode || '');
    const heading = pane?.querySelector<HTMLElement>('[data-builder-pane-title]');
    if (heading && title) heading.textContent = title;
    const handle = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-handle]');
    if (handle) handle.setAttribute('aria-label', text(builder.dataset.dragText, { block: title }) || title);
    const inside = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-inside]');
    if (inside) { const count = nodes(node).length; inside.textContent = count > 0 ? text(builder.dataset.insideText, { count: String(count) }) : ''; }
    showState(list, node);
  });
  const count = nodes(list).length;
  const total = builder.querySelector<HTMLInputElement>(`[data-builder-total="${CSS.escape(list.dataset.builderList || '')}"]`);
  if (total) total.value = String(count);
  const max = Number(builder.dataset.max || 0);
  const counter = list.querySelector<HTMLElement>('[data-builder-count]');
  if (counter) counter.textContent = text(builder.dataset.countText, { count: String(count) });
  const empty = list.querySelector<HTMLElement>('[data-builder-empty]');
  if (empty) empty.hidden = count > 0;
  const full = max > 0 && count >= max;
  list.classList.toggle('is-full', full);
  list.querySelectorAll<HTMLElement>('[data-builder-add] > summary').forEach(summary => summary.setAttribute('aria-disabled', String(full)));
  const lang = list.dataset.builderList || '';
  builder.querySelectorAll<HTMLElement>(`[data-builder-lang="${CSS.escape(lang)}"] [data-builder-lang-count]`).forEach(element => { element.textContent = String(count); });
}

/** A row's badge, faded look, Show / hide buttons and summary, from the block's settings and fields. */
function showState(list: HTMLElement, node: HTMLElement): void {
  const builder = builderOf(list)!;
  const id = node.dataset.builderNode || '';
  if (!paneOf(list, id)?.querySelector('[data-builder-settings]')) return;
  const draft = valueOf(list, id, 'status') === 'draft';
  const now = Date.now();
  const starts = Date.parse(valueOf(list, id, 'starts'));
  const ends = Date.parse(valueOf(list, id, 'ends'));
  const devices = valueOf(list, id, 'devices');
  let state = '';
  if (draft) state = builder.dataset.stateDraft || '';
  else if (!Number.isNaN(starts) && starts > now) state = text(builder.dataset.stateFrom, { date: new Date(starts).toLocaleDateString(document.documentElement.lang || undefined, { day: 'numeric', month: 'short' }) });
  else if (!Number.isNaN(ends) && ends <= now) state = builder.dataset.stateEnded || '';
  else if (devices === 'desktop') state = builder.dataset.stateDesktop || '';
  else if (devices === 'phone') state = builder.dataset.statePhone || '';
  const badge = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-state]');
  if (badge && badge.textContent !== state) {
    badge.replaceChildren();
    if (state) { const dot = document.createElement('span'); dot.className = 'fa-builder-state-dot'; dot.setAttribute('aria-hidden', 'true'); badge.append(dot, state); }
    badge.classList.toggle('is-shown', state !== '');
  }
  node.classList.toggle('is-draft', draft);
  const toggles = [...node.querySelectorAll<HTMLElement>(':scope > .fa-builder-row [data-builder-action="toggle"]'), ...(paneOf(list, id)?.querySelectorAll<HTMLElement>('[data-builder-action="toggle"]') ?? [])];
  toggles.forEach(button => {
    button.setAttribute('aria-pressed', String(draft));
    const label = (draft ? button.dataset.showText : button.dataset.hideText) || '';
    button.setAttribute('aria-label', label);
    button.title = label;
  });
  // "3 items": the first list inside the block (a slider's slides, cards).
  const summary = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-summary]');
  const fields = fieldsOf(list, id);
  const repeater = fields?.querySelector<HTMLElement>('[data-repeater]');
  if (summary && repeater) {
    const count = repeater.querySelectorAll(':scope > [data-repeater-items] > [data-repeater-item]').length;
    summary.textContent = count > 0 ? text(builder.dataset.summaryItems, { count: String(count) }) : '';
  }
}

function* ancestors(node: HTMLElement): Generator<HTMLElement> {
  let parent = node.parentElement?.closest<HTMLElement>('[data-builder-node]');
  while (parent) { yield parent; parent = parent.parentElement?.closest<HTMLElement>('[data-builder-node]'); }
}

function changed(list: HTMLElement): void {
  sync(list);
  list.dispatchEvent(new Event('input', { bubbles: true }));
}

/** Show one block's fields. */
function select(list: HTMLElement, id: string | null, focus = false): void {
  list.querySelectorAll<HTMLElement>(':scope > [data-builder-inspector] > [data-builder-pane]').forEach(pane => { pane.hidden = pane.dataset.builderPane !== id; });
  nodes(list).forEach(node => {
    const current = node.dataset.builderNode === id;
    node.classList.toggle('is-selected', current);
    const link = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]');
    if (link) { if (current) link.setAttribute('aria-current', 'true'); else link.removeAttribute('aria-current'); }
  });
  const none = list.querySelector<HTMLElement>(':scope > [data-builder-inspector] > [data-builder-none]');
  if (none) none.hidden = id !== null;
  list.dataset.selected = id || '';
  if (focus && id) paneOf(list, id)?.focus({ preventScroll: false });
}

/** Make a new pane's fields work. */
function prepare(builder: HTMLElement, pane: HTMLElement): void {
  initializeForms(pane);
  initializeTranslations(pane);
  initializeRepeaters(pane);
  initializeMedia(pane);
  initializeEditors(pane);
  enhance(pane);
  const scope = builder.closest<HTMLElement>('[data-lang-scope].is-switching');
  if (scope) applyEditing(scope, scope.dataset.editing || scope.dataset.defaultLang || '');
}

/** A new block's row and pane from its template (not in the page yet). */
function fromTemplate(builder: HTMLElement, type: string, lang: string, id: string): { node: HTMLElement; pane: HTMLElement } | null {
  const template = builder.querySelector<HTMLTemplateElement>(`:scope > template[data-builder-template="${CSS.escape(type)}"]`);
  if (!template) return null;
  const holder = document.createElement('div');
  holder.innerHTML = template.innerHTML.split('__ID__').join(id).split('__LANG__').join(lang);
  const node = holder.querySelector<HTMLElement>(':scope > ul > [data-builder-node]');
  const pane = holder.querySelector<HTMLElement>(':scope > [data-builder-pane]');
  return node && pane ? { node, pane } : null;
}

function insert(list: HTMLElement, node: HTMLElement, pane: HTMLElement, slot: HTMLElement, after: HTMLElement | null): void {
  if (after && after.parentElement === slot) after.after(node); else slot.append(node);
  list.querySelector<HTMLElement>(':scope > [data-builder-inspector]')!.append(pane);
}

function full(list: HTMLElement, adding = 1): boolean {
  const max = Number(builderOf(list)?.dataset.max || 0);
  return max > 0 && nodes(list).length + adding > max;
}

function slotFor(list: HTMLElement, parent: string, column: number): HTMLElement | null {
  if (!parent) return list.querySelector<HTMLElement>(':scope > [data-builder-outline] > [data-builder-slot]');
  return list.querySelector<HTMLElement>(`[data-builder-slot][data-parent="${CSS.escape(parent)}"][data-column="${column}"]`);
}

function add(list: HTMLElement, type: string, target: Target): void {
  const builder = builderOf(list)!;
  if (full(list)) { announce(builder, builder.dataset.fullText || ''); return; }
  const slot = slotFor(list, target.parent, target.column);
  if (!slot || !allowed(list, type, slot)) return;
  const made = fromTemplate(builder, type, list.dataset.builderList || '', newId());
  if (!made) return;
  insert(list, made.node, made.pane, slot, target.after);
  reveal(list, made.node);
  prepare(builder, made.pane);
  enhance(made.node);
  changed(list);
  markChanged(made.node);
  const id = made.node.dataset.builderNode || '';
  // A new block opens to be filled in (one without fields of its own shows its settings).
  if (fieldsOf(list, id)) openEditor(list, id);
  else { select(list, id); made.node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]')?.focus(); }
}

/**
 * A block (and the blocks in it) as plain data: its kind, its controls' values
 * by their name after "[lang][id]", the items of lists inside it, rich text,
 * media and its children. Copies (Duplicate, Copy to language, Paste on any
 * page) are made from it.
 */
type Value = { s: string; t: string; v: string; c?: boolean; o?: boolean[] };
type Snapshot = { type: string; title: string; values: Value[]; repeaters: { s: string; keys: string[] }[]; rich: string[]; media: (string | null)[]; columns: number; children: { column: number; block: Snapshot }[] };

function snapshot(list: HTMLElement, node: HTMLElement): Snapshot | null {
  const id = node.dataset.builderNode || '';
  const pane = paneOf(list, id);
  if (!pane) return null;
  const from = `[${list.dataset.builderList}][${id}]`;
  const suffix = (name: string) => { const at = name.indexOf(from); return at < 0 ? null : name.slice(at + from.length); };
  const scopes = scopesOf(list, id);
  const values: Value[] = [];
  scopes.forEach(scope => scope.querySelectorAll<Control>('[name]').forEach(control => {
    // The place inputs are the new block's own (sync() fills them).
    if (control.parentElement === pane) return;
    const s = suffix(control.name);
    if (s === null) return;
    const value: Value = { s, t: control.type, v: control.value };
    if (control instanceof HTMLInputElement && (control.type === 'radio' || control.type === 'checkbox')) value.c = control.checked;
    if (control instanceof HTMLSelectElement) value.o = [...control.options].map(option => option.selected);
    values.push(value);
  }));
  const repeaters = scopes.flatMap(scope => [...scope.querySelectorAll<HTMLElement>('[data-repeater]')]).map(repeater => ({
    s: suffix(repeater.dataset.name || '') ?? '',
    keys: [...repeater.querySelectorAll<HTMLElement>(':scope > [data-repeater-items] > [data-repeater-item]')].map(item => item.dataset.key || ''),
  }));
  // A rich editor keeps its text in the editor until the form is sent.
  const rich = scopes.flatMap(scope => [...scope.querySelectorAll<HTMLElement>('[data-admin-rich-editor]')]).map(editor => editor.querySelector<HTMLElement>('.ProseMirror, [contenteditable="true"]')?.innerHTML ?? '');
  const media = scopes.flatMap(scope => [...scope.querySelectorAll<HTMLElement>('[data-admin-media-field]')]).map(field => field.dataset.items ?? null);
  const children: { column: number; block: Snapshot }[] = [];
  node.querySelectorAll<HTMLElement>(':scope > [data-builder-cols] > [data-builder-col] > [data-builder-slot], :scope > [data-builder-slot]').forEach(slot => {
    childNodes(slot).forEach(child => { const block = snapshot(list, child); if (block) children.push({ column: Number(slot.dataset.column || 0), block }); });
  });
  return { type: node.dataset.type || '', title: titleOf(list, node), values, repeaters, rich, media, columns: node.querySelectorAll(':scope > [data-builder-cols] > [data-builder-col]').length, children };
}

/** A new block made from a snapshot, in a list (new ids, the same values). */
function build(list: HTMLElement, data: Snapshot, slot: HTMLElement, after: HTMLElement | null): HTMLElement | null {
  const builder = builderOf(list)!;
  const id = newId();
  const made = fromTemplate(builder, data.type, list.dataset.builderList || '', id);
  if (!made) return null;
  const to = `[${list.dataset.builderList}][${id}]`;
  const named = (element: Element, name: string) => { const at = name.indexOf(to); return at < 0 ? null : name.slice(at + to.length); };
  // The items of lists inside the block (a slider's slides) first, so every value has a place.
  data.repeaters.forEach(source => {
    const target = [...made.pane.querySelectorAll<HTMLElement>('[data-repeater]')].find(element => named(element, element.dataset.name || '') === source.s);
    const template = target?.querySelector<HTMLTemplateElement>(':scope > template[data-repeater-template]');
    const items = target?.querySelector<HTMLElement>(':scope > [data-repeater-items]');
    if (!target || !template || !items) return;
    source.keys.forEach((key, index) => {
      if (!/^[A-Za-z0-9_-]{1,40}$/.test(key)) return;
      const holder = document.createElement('div');
      holder.innerHTML = template.innerHTML.split('__KEY__').join(key).split('__NUMBER__').join(String(index + 1));
      if (holder.firstElementChild) items.append(holder.firstElementChild);
    });
  });
  // Never the place, type or raw inputs: those are the new block's own.
  const controls = [...made.pane.querySelectorAll<Control>('[name]')].filter(control => control.parentElement !== made.pane);
  data.values.forEach(value => {
    const radio = value.t === 'radio' || value.t === 'checkbox';
    const target = controls.find(element => named(element, element.name) === value.s && element.type === value.t && (!radio || element.value === value.v));
    if (!target) return;
    if (radio) (target as HTMLInputElement).checked = value.c === true;
    else if (target instanceof HTMLSelectElement && value.o) [...target.options].forEach((option, i) => { option.selected = value.o?.[i] ?? false; });
    else target.value = value.v;
  });
  made.pane.querySelectorAll<HTMLElement>('[data-admin-rich-editor]').forEach((editor, index) => {
    const textarea = editor.querySelector<HTMLTextAreaElement>('textarea');
    if (textarea && data.rich[index]) textarea.value = data.rich[index];
  });
  made.pane.querySelectorAll<HTMLElement>('[data-admin-media-field]').forEach((field, index) => {
    const items = data.media[index];
    if (items) field.dataset.items = items;
  });
  insert(list, made.node, made.pane, slot, after);
  // Columns: the same number of columns before the blocks in them.
  if (data.type === 'columns' && data.columns > 0) setColumns(list, made.node, valueOf(list, id, 'layout') || Array(data.columns).fill(Math.round(100 / data.columns)).join('-'));
  prepare(builder, made.pane);
  enhance(made.node);
  data.children.forEach(child => {
    const target = slotFor(list, id, child.column);
    if (target && allowed(list, child.block.type, target)) build(list, child.block, target, null);
  });
  return made.node;
}

function copyNode(fromList: HTMLElement, source: HTMLElement, toList: HTMLElement, slot: HTMLElement, after: HTMLElement | null): HTMLElement | null {
  const data = snapshot(fromList, source);
  return data ? build(toList, data, slot, after) : null;
}

function count(data: Snapshot): number {
  return 1 + data.children.reduce((sum, child) => sum + count(child.block), 0);
}

/** Copy (Ctrl+C on a row): the block is kept in this browser, for any page. */
const CLIPBOARD_KEY = 'flo-admin-block-clipboard';
function copyToClipboard(list: HTMLElement, node: HTMLElement): void {
  const data = snapshot(list, node);
  const builder = builderOf(list)!;
  if (!data) return;
  try { localStorage.setItem(CLIPBOARD_KEY, JSON.stringify({ v: 1, block: data })); } catch { return; }
  announce(builder, builder.dataset.copiedBlockText || '');
  showUndo(builder, builder.dataset.copiedBlockText || '');
}
function clipboard(): Snapshot | null {
  try {
    const value = JSON.parse(localStorage.getItem(CLIPBOARD_KEY) || 'null');
    return value && value.v === 1 && value.block && typeof value.block.type === 'string' ? value.block as Snapshot : null;
  } catch { return null; }
}

/** Paste (Ctrl+V on a row, or Paste in Add): the copied block, after the row or in the chosen place. */
function paste(list: HTMLElement, target: Target): boolean {
  const builder = builderOf(list)!;
  const data = clipboard();
  const slot = slotFor(list, target.parent, target.column);
  if (!data || !slot || !builder.querySelector(`:scope > template[data-builder-template="${CSS.escape(data.type)}"]`) || !allowed(list, data.type, slot)) return false;
  if (full(list, count(data))) { announce(builder, builder.dataset.fullText || ''); return true; }
  const made = build(list, data, slot, target.after);
  if (!made) return false;
  changed(list);
  markChanged(made);
  select(list, made.dataset.builderNode || null);
  made.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]')?.focus();
  announce(builder, text(builder.dataset.pastedText, { block: titleOf(list, made) }));
  return true;
}

function duplicate(list: HTMLElement, node: HTMLElement): void {
  const builder = builderOf(list)!;
  if (full(list, 1 + nodes(node).length)) { announce(builder, builder.dataset.fullText || ''); return; }
  const copy = copyNode(list, node, list, slotOf(node), node);
  if (!copy) return;
  changed(list);
  markChanged(copy);
  select(list, copy.dataset.builderNode || null);
}

/** Copy a block into another language's list, at its end (or in place when nested). */
function copyTo(list: HTMLElement, node: HTMLElement, lang: string): void {
  const builder = builderOf(list)!;
  const target = builder.querySelector<HTMLElement>(`[data-builder-list="${CSS.escape(lang)}"]`);
  if (!target || target === list) return;
  if (full(target, 1 + nodes(node).length)) { announce(builder, builder.dataset.fullText || ''); return; }
  const slot = slotFor(target, '', 0)!;
  if (!allowed(target, node.dataset.type || '', slot)) return;
  markChanged(copyNode(list, node, target, slot, null));
  changed(target);
  announce(builder, text(builder.dataset.copiedText, { language: languageName(builder, lang) }));
}

function copyAll(list: HTMLElement, from: string): void {
  const builder = builderOf(list)!;
  const source = builder.querySelector<HTMLElement>(`[data-builder-list="${CSS.escape(from)}"]`);
  if (!source) return;
  if (full(list, nodes(source).length)) { announce(builder, builder.dataset.fullText || ''); return; }
  const slot = slotFor(list, '', 0)!;
  childNodes(slotFor(source, '', 0)!).forEach(node => { copyNode(source, node, list, slot, null); });
  changed(list);
  const first = childNodes(slot)[0];
  if (first) select(list, first.dataset.builderNode || null);
}

function languageName(builder: HTMLElement, lang: string): string {
  return builder.querySelector<HTMLElement>(`[data-builder-lang="${CSS.escape(lang)}"] .fa-seg-name`)?.textContent || lang;
}

function remove(list: HTMLElement, node: HTMLElement): void {
  const builder = builderOf(list)!;
  const ids = [node, ...nodes(node)].map(item => item.dataset.builderNode || '');
  const panes = ids.map(id => paneOf(list, id)).filter((pane): pane is HTMLElement => pane !== null);
  const title = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-title]')?.textContent || '';
  const slot = slotOf(node);
  const siblings = childNodes(slot);
  const index = siblings.indexOf(node);
  removed.set(builder, { list, node, panes, slot, next: node.nextElementSibling, title });
  node.remove();
  panes.forEach(pane => pane.remove());
  if (ids.includes(list.dataset.selected || '')) select(list, null);
  changed(list);
  showUndo(builder, text(builder.dataset.removedText, { block: title }));
  const next = childNodes(slot)[Math.min(index, childNodes(slot).length - 1)];
  (next?.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]') ?? builder.querySelector<HTMLElement>('[data-builder-undo-button]'))?.focus();
}

function showUndo(builder: HTMLElement, message: string): void {
  let bar = builder.querySelector<HTMLElement>(':scope > [data-builder-undo]');
  if (!bar) {
    bar = document.createElement('div');
    bar.className = 'fa-repeater-undo fa-builder-undo';
    bar.setAttribute('role', 'status');
    bar.dataset.builderUndo = '';
    const span = document.createElement('span');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'fa-btn fa-btn-sm';
    button.dataset.builderUndoButton = '';
    button.textContent = builder.dataset.undoText || 'Undo';
    bar.append(span, button);
    builder.querySelector(':scope > [data-builder-live]')?.before(bar);
  }
  bar.hidden = false;
  bar.querySelector('span')!.textContent = message;
  // A plain message (Copied) has no Undo, and does not drop the last removal.
  bar.querySelector<HTMLElement>('[data-builder-undo-button]')!.hidden = !removed.has(builder);
}

function undo(builder: HTMLElement): void {
  const last = removed.get(builder);
  if (!last) return;
  if (full(last.list, 1 + nodes(last.node).length)) { announce(builder, builder.dataset.fullText || ''); return; }
  if (last.next && last.next.parentElement === last.slot) last.slot.insertBefore(last.node, last.next); else last.slot.append(last.node);
  const inspector = last.list.querySelector<HTMLElement>(':scope > [data-builder-inspector]')!;
  last.panes.forEach(pane => inspector.append(pane));
  removed.delete(builder);
  const bar = builder.querySelector<HTMLElement>(':scope > [data-builder-undo]');
  if (bar) bar.hidden = true;
  changed(last.list);
  select(last.list, last.node.dataset.builderNode || null);
  last.node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]')?.focus();
}

/** Move a block up or down among the blocks next to it (Alt + arrow keys). */
function move(list: HTMLElement, node: HTMLElement, by: number): void {
  const slot = slotOf(node);
  const siblings = childNodes(slot);
  const index = siblings.indexOf(node);
  const target = index + by;
  if (target < 0 || target >= siblings.length) return;
  if (by < 0) siblings[target].before(node); else siblings[target].after(node);
  changed(list);
  markChanged(node);
  node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-handle], :scope > .fa-builder-row [data-builder-select]')?.focus();
  const builder = builderOf(list)!;
  announce(builder, text(builder.dataset.movedText, { block: titleOf(list, node), position: String(target + 1) }));
}

/* Drag and drop (0.32) ----------------------------------------------------
 * The block stays where it is while it is dragged: a line shows where it will
 * land, and it moves when it is let go. Every container is a target as a whole
 * (its padding, labels, Add button and the gaps between its blocks); the innermost
 * one under the pointer wins. A place the block may not go shows a red line and
 * nothing moves. Near the window's edges the page scrolls; Escape cancels; a
 * folded container opens when a block is held over it.
 */
type Drop = { slot: HTMLElement; before: HTMLElement | null; ok: boolean };
let dragging: { list: HTMLElement; node: HTMLElement; cancel: () => void } | null = null;

/** The area that belongs to a slot: the whole structure, a column, or a container's body (not its header). */
function zoneOf(list: HTMLElement, slot: HTMLElement): DOMRect | null {
  if (!slot.dataset.parent) return list.querySelector<HTMLElement>(':scope > [data-builder-outline]')?.getBoundingClientRect() ?? null;
  const column = slot.closest<HTMLElement>('[data-builder-col]');
  if (column && slot.parentElement === column) return column.getBoundingClientRect();
  const container = slot.closest<HTMLElement>('[data-builder-node]');
  if (!container || container.classList.contains('is-folded')) return null;
  const box = container.getBoundingClientRect();
  const head = container.querySelector<HTMLElement>(':scope > .fa-builder-row')?.getBoundingClientRect();
  return head ? new DOMRect(box.x, head.bottom, box.width, Math.max(0, box.bottom - head.bottom)) : box;
}

/** Where a dragged block would land for a pointer position, or null outside the structure. */
function dropAt(list: HTMLElement, node: HTMLElement, x: number, y: number): Drop | null {
  const type = node.dataset.type || '';
  let best: { slot: HTMLElement; depth: number } | null = null;
  list.querySelectorAll<HTMLElement>('[data-builder-slot]').forEach(slot => {
    if (node.contains(slot)) return;
    const zone = zoneOf(list, slot);
    if (!zone || x < zone.left || x > zone.right || y < zone.top || y > zone.bottom) return;
    // The innermost place wins: the more blocks around it, the deeper it is.
    const depth = [...ancestors(slot)].length;
    if (!best || depth > best.depth) best = { slot, depth };
  });
  if (!best) return null;
  const slot = (best as { slot: HTMLElement }).slot;
  const before = childNodes(slot).filter(child => child !== node).find(child => {
    const box = child.getBoundingClientRect();
    return y < box.top + box.height / 2;
  }) ?? null;
  return { slot, before, ok: allowed(list, type, slot) };
}

/** The drop line: between two blocks, after the last one, or at the top of an empty place. */
function showDrop(list: HTMLElement, node: HTMLElement, drop: Drop | null): void {
  const line = list.querySelector<HTMLElement>(':scope > [data-builder-outline] > [data-builder-drop]')
    ?? (() => { const made = document.createElement('div'); made.className = 'fa-builder-drop'; made.dataset.builderDrop = ''; made.setAttribute('aria-hidden', 'true'); list.querySelector(':scope > [data-builder-outline]')!.append(made); return made; })();
  list.querySelectorAll('.is-drop-zone, .is-drop-refused').forEach(element => element.classList.remove('is-drop-zone', 'is-drop-refused'));
  if (!drop) { line.hidden = true; return; }
  const outline = list.querySelector<HTMLElement>(':scope > [data-builder-outline]')!.getBoundingClientRect();
  const others = childNodes(drop.slot).filter(child => child !== node);
  let top: number; let left: number; let width: number;
  const zoneElement = drop.slot.closest<HTMLElement>('[data-builder-col]') ?? (drop.slot.dataset.parent ? drop.slot.closest<HTMLElement>('[data-builder-node]') : null);
  if (others.length) {
    const reference = drop.before ?? others[others.length - 1];
    const box = reference.getBoundingClientRect();
    top = drop.before ? box.top - 4 : box.bottom + 3;
    left = box.left; width = box.width;
  } else {
    const zone = zoneOf(list, drop.slot)!;
    const add = zoneElement?.querySelector<HTMLElement>(':scope > [data-builder-add], :scope > .fa-builder-add');
    const box = add?.getBoundingClientRect();
    top = box ? box.top - 4 : zone.top + 8;
    left = box ? box.left : zone.left + 8; width = box ? box.width : zone.width - 16;
  }
  line.hidden = false;
  line.classList.toggle('is-refused', !drop.ok);
  line.style.top = `${top - outline.top}px`;
  line.style.left = `${left - outline.left}px`;
  line.style.width = `${width}px`;
  zoneElement?.classList.add(drop.ok ? 'is-drop-zone' : 'is-drop-refused');
}

function drag(list: HTMLElement, node: HTMLElement, event: PointerEvent, touch = false): void {
  if (dragging) return;
  const builder = builderOf(list)!;
  const handle = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-handle]');
  const pointer = event.pointerId;
  const startX = event.clientX; const startY = event.clientY;
  let started = touch;
  let x = startX; let y = startY;
  let drop: Drop | null = null;
  let ghost: HTMLElement | null = null;
  let scroller = 0;
  let opener = 0; let opening: HTMLElement | null = null;
  const begin = () => {
    started = true;
    node.classList.add('is-drag-source');
    list.classList.add('is-dragging');
    const row = node.querySelector<HTMLElement>(':scope > .fa-builder-row')!;
    ghost = row.cloneNode(true) as HTMLElement;
    ghost.classList.add('fa-builder-ghost');
    ghost.querySelectorAll('[id]').forEach(element => element.removeAttribute('id'));
    ghost.setAttribute('aria-hidden', 'true');
    ghost.style.width = `${Math.min(row.offsetWidth, 360)}px`;
    builder.append(ghost);
  };
  const place = () => {
    if (!ghost) return;
    ghost.style.left = `${x + 12}px`;
    ghost.style.top = `${y - 18}px`;
    drop = dropAt(list, node, x, y);
    showDrop(list, node, drop);
    // Held over a folded container: it opens after half a second.
    const over = document.elementFromPoint(x, y)?.closest<HTMLElement>('[data-builder-node].is-folded');
    if (over !== opening) {
      window.clearTimeout(opener); opening = over ?? null;
      if (over && !node.contains(over)) opener = window.setTimeout(() => { setFolded(list, over, false); place(); }, 500);
    }
  };
  const scroll = () => {
    const edge = 60;
    const step = y < edge ? -Math.ceil((edge - y) / 4) : y > window.innerHeight - edge ? Math.ceil((y - window.innerHeight + edge) / 4) : 0;
    if (step) { window.scrollBy(0, step); place(); }
    scroller = requestAnimationFrame(scroll);
  };
  const onMove = (moved: PointerEvent) => {
    if (moved.pointerId !== pointer) return;
    x = moved.clientX; y = moved.clientY;
    if (!started) {
      if (Math.hypot(x - startX, y - startY) < 4) return;
      begin();
      scroller = requestAnimationFrame(scroll);
    }
    moved.preventDefault();
    place();
  };
  const finish = (apply: boolean) => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onCancel);
    document.removeEventListener('keydown', onKey, true);
    cancelAnimationFrame(scroller);
    window.clearTimeout(opener);
    dragging = null;
    if (!started) return;
    ghost?.remove();
    node.classList.remove('is-drag-source');
    list.classList.remove('is-dragging');
    showDrop(list, node, null);
    const target = drop;
    if (apply && target && target.ok) {
      const from = slotOf(node); const next = node.nextElementSibling;
      if (target.before) target.slot.insertBefore(node, target.before); else target.slot.append(node);
      if (slotOf(node) !== from || node.nextElementSibling !== next) {
        reveal(list, node);
        changed(list);
        markChanged(node);
        announce(builder, text(builder.dataset.movedText, { block: titleOf(list, node), position: String(childNodes(slotOf(node)).indexOf(node) + 1) }));
      }
    } else if (apply && target && !target.ok) {
      announce(builder, text(builder.dataset.notHereText, { block: titleOf(list, node) }));
    }
    (handle ?? node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]'))?.focus({ preventScroll: true });
  };
  const onUp = (up: PointerEvent) => { if (up.pointerId === pointer) finish(true); };
  const onCancel = (up: PointerEvent) => { if (up.pointerId === pointer) finish(false); };
  const onKey = (key: KeyboardEvent) => { if (key.key === 'Escape') { key.preventDefault(); key.stopPropagation(); finish(false); } };
  dragging = { list, node, cancel: () => finish(false) };
  document.addEventListener('pointermove', onMove, { passive: false });
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onCancel);
  document.addEventListener('keydown', onKey, true);
  if (touch) { begin(); place(); scroller = requestAnimationFrame(scroll); }
}

// While a block is dragged by touch, the page does not scroll under the finger.
document.addEventListener('touchmove', event => { if (dragging) event.preventDefault(); }, { passive: false });

/**
 * A Columns block's columns in the structure follow its layout ("67-33"): as many
 * columns, as wide (admin.css draws a grid per layout); a column that goes away gives
 * its blocks to the last remaining one.
 */
function setColumns(list: HTMLElement, node: HTMLElement, layout: string): void {
  const holder = node.querySelector<HTMLElement>(':scope > [data-builder-cols]');
  const widths = layout.split('-').map(Number).filter(width => width > 0);
  const count = widths.length;
  if (!holder || count < 1) return;
  const builder = builderOf(list)!;
  holder.dataset.layout = layout;
  holder.dataset.count = String(count);
  let columns = [...holder.querySelectorAll<HTMLElement>(':scope > [data-builder-col]')];
  let moved = 0;
  while (columns.length > count) {
    const last = columns.pop()!;
    const keep = columns[columns.length - 1].querySelector<HTMLElement>(':scope > [data-builder-slot]')!;
    childNodes(last.querySelector<HTMLElement>(':scope > [data-builder-slot]')!).forEach(child => { keep.append(child); moved++; });
    last.remove();
  }
  while (columns.length < count) {
    const copy = columns[0].cloneNode(true) as HTMLElement;
    const index = columns.length;
    copy.dataset.builderCol = String(index);
    const slot = copy.querySelector<HTMLElement>(':scope > [data-builder-slot]')!;
    slot.replaceChildren();
    slot.dataset.column = String(index);
    const label = copy.querySelector<HTMLElement>('.fa-builder-col-label');
    if (label) label.textContent = text(builder.dataset.columnText, { number: String(index + 1) });
    copy.querySelectorAll<HTMLElement>('[data-builder-add]').forEach(add => {
      add.dataset.column = String(index);
      add.removeAttribute('open');
      add.querySelectorAll<HTMLButtonElement>('button[value]').forEach(button => { button.value = button.value.replace(/:\d+$/, ':' + index); });
      add.querySelector(':scope > summary')?.setAttribute('aria-label', text(builder.dataset.addColumnText, { number: String(index + 1) }));
    });
    holder.append(copy);
    columns.push(copy);
  }
  columns.forEach((column, index) => {
    const width = column.querySelector<HTMLElement>('[data-builder-col-width]');
    if (width) width.textContent = `${widths[index] ?? ''}%`;
  });
  if (moved > 0) announce(builder, text(builder.dataset.movedColumnText, { count: String(moved), number: String(count) }));
}

/* The ⋯ menu of a block (0.32): its actions again, Move to… and Delete ---------
 * One menu per Builder, filled when it opens. Each action clicks the row's own
 * button, so the menu and the row always do the same; Move to… lists every place
 * the block may go (a container's body or a column, or the top level after the
 * container it is in). Arrow keys move through it; Escape closes it. */
type Place = { label: string; slot: HTMLElement; after: HTMLElement | null };
const menus = new WeakMap<HTMLElement, { list: HTMLElement; node: HTMLElement; button: HTMLElement; places: Place[] }>();

function movePlaces(list: HTMLElement, node: HTMLElement): Place[] {
  const builder = builderOf(list)!;
  const type = node.dataset.type || '';
  const current = slotOf(node);
  const places: Place[] = [];
  const outer = [...ancestors(node)].pop();
  const topSlot = slotFor(list, '', 0);
  if (outer && topSlot && allowed(list, type, topSlot)) places.push({ label: text(builder.dataset.moveTopText, { block: titleOf(list, outer) }), slot: topSlot, after: outer });
  const parent = node.parentElement?.closest<HTMLElement>('[data-builder-node]') ?? null;
  list.querySelectorAll<HTMLElement>('[data-builder-slot]').forEach(slot => {
    if (!slot.dataset.parent || slot === current || node.contains(slot) || !allowed(list, type, slot)) return;
    const container = nodeOf(list, slot.dataset.parent);
    if (!container) return;
    const number = String(Number(slot.dataset.column || 0) + 1);
    const inColumn = slot.parentElement?.hasAttribute('data-builder-col') ?? false;
    const label = !inColumn ? text(builder.dataset.whereIn, { block: titleOf(list, container) })
      : container === parent ? text(builder.dataset.columnText, { number }) : text(builder.dataset.whereColumn, { block: titleOf(list, container), number });
    places.push({ label, slot, after: null });
  });
  return places;
}

function menuOf(builder: HTMLElement): HTMLElement {
  let menu = builder.querySelector<HTMLElement>(':scope > [data-builder-menu]');
  if (!menu) {
    menu = document.createElement('div');
    menu.className = 'fa-menu-panel fa-builder-menu';
    menu.dataset.builderMenu = '';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    builder.append(menu);
  }
  return menu;
}

function closeMenu(builder: HTMLElement, focus = false): void {
  const menu = builder.querySelector<HTMLElement>(':scope > [data-builder-menu]');
  if (!menu || menu.hidden) return;
  menu.hidden = true;
  const job = menus.get(menu);
  job?.button.setAttribute('aria-expanded', 'false');
  if (focus) job?.button.focus();
}

function openMenu(list: HTMLElement, node: HTMLElement, button: HTMLElement): void {
  const builder = builderOf(list)!;
  const menu = menuOf(builder);
  if (!menu.hidden && menus.get(menu)?.button === button) { closeMenu(builder, true); return; }
  closeMenu(builder);
  const tools = node.querySelector<HTMLElement>(':scope > .fa-builder-row .fa-builder-row-tools');
  const item = (label: string, icon: string, act: string, danger = false) => {
    const made = document.createElement('button');
    made.type = 'button';
    made.className = 'fa-menu-item' + (danger ? ' is-danger' : '');
    made.setAttribute('role', 'menuitem');
    made.tabIndex = -1;
    made.dataset.menuAct = act;
    made.innerHTML = icon;
    const span = document.createElement('span');
    span.textContent = label;
    made.append(span);
    return made;
  };
  const heading = (label: string) => { const made = document.createElement('p'); made.className = 'fa-builder-menu-heading'; made.setAttribute('role', 'presentation'); made.textContent = label; return made; };
  const rule = () => { const made = document.createElement('hr'); made.className = 'fa-builder-menu-rule'; return made; };
  const parts: HTMLElement[] = [];
  ['edit', 'duplicate', 'toggle'].forEach(action => {
    const source = tools?.querySelector<HTMLElement>(`:scope > [data-builder-action="${action}"]`);
    if (!source) return;
    const pressed = source.getAttribute('aria-pressed') === 'true';
    const svg = action === 'toggle' ? source.querySelector(pressed ? '.fa-builder-icon-hidden' : '.fa-builder-icon-shown')?.outerHTML ?? '' : source.querySelector('svg')?.outerHTML ?? '';
    parts.push(item(source.getAttribute('aria-label') || '', svg, 'click:' + action));
  });
  tools?.querySelectorAll<HTMLElement>('[data-builder-copy-to]').forEach(copy => {
    if (copy.dataset.builderCopyTo === list.dataset.builderList) return;
    parts.push(item(copy.textContent || '', tools.querySelector('[data-builder-action="copy-menu"] svg')?.outerHTML ?? '', 'copy:' + (copy.dataset.builderCopyTo || '')));
  });
  const places = movePlaces(list, node);
  if (places.length) {
    parts.push(rule(), heading(builder.dataset.moveToText || ''));
    places.forEach((place, index) => parts.push(item(place.label, '', 'move:' + index)));
  }
  const remove = tools?.querySelector<HTMLElement>(':scope > [data-builder-action="remove"]');
  if (remove) parts.push(rule(), item(remove.getAttribute('aria-label') || '', remove.querySelector('svg')?.outerHTML ?? '', 'click:remove', true));
  menu.replaceChildren(...parts);
  menu.setAttribute('aria-label', button.getAttribute('aria-label') || '');
  menus.set(menu, { list, node, button, places });
  menu.hidden = false;
  button.setAttribute('aria-expanded', 'true');
  // Under the button, its end edges lined up; above it when there is no room below.
  const at = button.getBoundingClientRect();
  const rtl = getComputedStyle(list).direction === 'rtl';
  const width = menu.offsetWidth; const height = menu.offsetHeight;
  const left = rtl ? Math.max(8, at.left) : Math.max(8, at.right - width);
  const below = at.bottom + 4 + height <= window.innerHeight - 8;
  menu.style.left = `${Math.min(left, window.innerWidth - width - 8)}px`;
  menu.style.top = `${below ? at.bottom + 4 : Math.max(8, at.top - 4 - height)}px`;
  menu.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
}

function menuChoose(builder: HTMLElement, choice: HTMLElement): void {
  const menu = choice.closest<HTMLElement>('[data-builder-menu]');
  const job = menu ? menus.get(menu) : undefined;
  if (!job) return;
  const [kind, value] = (choice.dataset.menuAct || '').split(':');
  closeMenu(builder);
  const { list, node } = job;
  const tools = node.querySelector<HTMLElement>(':scope > .fa-builder-row .fa-builder-row-tools');
  if (kind === 'click') { tools?.querySelector<HTMLElement>(`:scope > [data-builder-action="${value}"]`)?.click(); return; }
  if (kind === 'copy') { copyTo(list, node, value); return; }
  if (kind === 'move') {
    const place = job.places[Number(value)];
    if (!place || !node.isConnected) return;
    if (place.after) place.after.after(node); else place.slot.append(node);
    reveal(list, node);
    changed(list);
    markChanged(node);
    announce(builder, text(builder.dataset.movedText, { block: titleOf(list, node), position: String(childNodes(slotOf(node)).indexOf(node) + 1) }));
    node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]')?.focus();
  }
}

document.addEventListener('keydown', event => {
  const menu = event.target instanceof Element ? event.target.closest<HTMLElement>('[data-builder-menu]') : null;
  if (!menu) return;
  const builder = builderOf(menu)!;
  const items = [...menu.querySelectorAll<HTMLElement>('[role="menuitem"]')];
  const at = items.indexOf(event.target as HTMLElement);
  if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeMenu(builder, true); return; }
  if (event.key === 'Tab') { closeMenu(builder); return; }
  const step = event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0;
  if (step) { event.preventDefault(); items[(at + step + items.length) % items.length]?.focus(); return; }
  if (event.key === 'Home' || event.key === 'End') { event.preventDefault(); (event.key === 'Home' ? items[0] : items[items.length - 1])?.focus(); }
}, true);

/* Fold and unfold containers (0.32), remembered per page in this browser. */
const FOLD_KEY = 'flo-admin-builder-folds';
const foldPlace = (list: HTMLElement) => `${location.pathname}|${builderOf(list)?.dataset.name || ''}|${list.dataset.builderList || ''}`;
function readFolds(): Record<string, string[]> {
  try { const value = JSON.parse(localStorage.getItem(FOLD_KEY) || '{}'); return value && typeof value === 'object' && !Array.isArray(value) ? value : {}; } catch { return {}; }
}
function saveFolds(list: HTMLElement): void {
  const all = readFolds();
  const place = foldPlace(list);
  const ids = nodes(list).filter(node => node.classList.contains('is-folded')).map(node => node.dataset.builderNode || '');
  delete all[place];
  if (ids.length) all[place] = ids;
  const places = Object.keys(all);
  places.slice(0, Math.max(0, places.length - 40)).forEach(old => { delete all[old]; });
  try { localStorage.setItem(FOLD_KEY, JSON.stringify(all)); } catch { /* private window */ }
}
const containersOf = (list: HTMLElement) => nodes(list).filter(node => node.hasAttribute('data-holds'));
function setFolded(list: HTMLElement, node: HTMLElement, folded: boolean, save = true): void {
  if (!node.hasAttribute('data-holds')) return;
  node.classList.toggle('is-folded', folded);
  const button = node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-action="fold"]');
  if (button) {
    const builder = builderOf(list)!;
    const label = text(folded ? builder.dataset.unfoldText : builder.dataset.foldText, { block: titleOf(list, node) });
    button.setAttribute('aria-expanded', String(!folded));
    button.setAttribute('aria-label', label);
    button.title = label;
  }
  if (save) { saveFolds(list); showCollapseAll(list); }
}
/** The containers around a block open (a block added, pasted or moved into a folded one is seen). */
function reveal(list: HTMLElement, node: HTMLElement): void {
  let changedAny = false;
  for (const parent of ancestors(node)) { if (parent.classList.contains('is-folded')) { setFolded(list, parent, false, false); changedAny = true; } }
  if (changedAny) { saveFolds(list); showCollapseAll(list); }
}
/** "Collapse all" folds every container; when all are folded it reads "Expand all". */
function showCollapseAll(list: HTMLElement): void {
  const button = list.querySelector<HTMLElement>('[data-builder-action="collapse-all"]');
  if (!button) return;
  const containers = containersOf(list);
  const all = containers.length > 0 && containers.every(node => node.classList.contains('is-folded'));
  button.hidden = containers.length === 0;
  button.setAttribute('aria-pressed', String(all));
  if (button.dataset.expandText && button.dataset.collapseText) button.textContent = all ? button.dataset.expandText : button.dataset.collapseText;
}

/** Where the picker's block will go, in words ("After “Hero”", "In “Columns”, column 2"). */
function placeText(list: HTMLElement, target: Target): string {
  const builder = builderOf(list)!;
  if (target.after) return text(builder.dataset.whereAfter, { block: titleOf(list, target.after) });
  if (!target.parent) return builder.dataset.whereTop || '';
  const parent = nodeOf(list, target.parent);
  const name = parent ? titleOf(list, parent) : '';
  return parent?.querySelector(':scope > [data-builder-cols]')
    ? text(builder.dataset.whereColumn, { block: name, number: String(target.column + 1) })
    : text(builder.dataset.whereIn, { block: name });
}

const RECENT_KEY = 'flo-admin-recent-blocks';
function recentTypes(): string[] {
  try { const value = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string').slice(0, 6) : []; } catch { return []; }
}
function rememberType(type: string): void {
  try { localStorage.setItem(RECENT_KEY, JSON.stringify([type, ...recentTypes().filter(item => item !== type)].slice(0, 6))); } catch { /* private window */ }
}

/** Add block (0.31): the list's picker in a dialog, offering the kinds that may sit in the chosen place. */
function openPicker(list: HTMLElement, target: Target): void {
  const builder = builderOf(list)!;
  const dialog = dialogOf(builder, 'add');
  const picker = pickerOf(list);
  if (!dialog || !picker) return;
  if (list.classList.contains('is-full')) { announce(builder, builder.dataset.fullText || ''); return; }
  const slot = slotFor(list, target.parent, target.column);
  if (!slot) return;
  targets.set(list, target);
  returnPicker(builder);
  dialog.dataset.list = list.dataset.builderList || '';
  dialog.querySelector('[data-builder-add-body]')!.append(picker);
  picker.querySelectorAll<HTMLElement>('[data-builder-choice]').forEach(choice => {
    choice.dataset.placeOk = String(allowed(list, choice.dataset.builderChoice || '', slot));
  });
  // Recently used kinds, as the first category.
  const cats = picker.querySelector<HTMLElement>('[data-builder-cats], .fa-builder-cats');
  const recent = recentTypes().filter(type => picker.querySelector(`[data-builder-choice="${CSS.escape(type)}"]`));
  let recentTab = cats?.querySelector<HTMLElement>('[data-builder-cat="recent"]') ?? null;
  if (cats && recent.length && !recentTab) {
    const button = document.createElement('button');
    button.type = 'button';
    recentTab = button;
    recentTab.setAttribute('role', 'tab');
    recentTab.dataset.builderCat = 'recent';
    recentTab.textContent = builder.dataset.recentText || 'Recent';
    cats.querySelector('[data-builder-cat=""]')?.after(recentTab);
  }
  picker.dataset.recent = recent.join(' ');
  cats?.querySelectorAll<HTMLElement>('[data-builder-cat]').forEach(button => button.setAttribute('aria-selected', String(button.dataset.builderCat === '')));
  const search = picker.querySelector<HTMLInputElement>('[data-builder-search]');
  if (search) search.value = '';
  filter(picker);
  const place = dialog.querySelector<HTMLElement>('[data-builder-add-place]');
  if (place) place.textContent = placeText(list, target);
  // Paste: the copied block, when it may sit here.
  const copied = clipboard();
  const pasteButton = dialog.querySelector<HTMLElement>('[data-builder-paste]');
  if (pasteButton) {
    const fits = copied !== null && builder.querySelector(`:scope > template[data-builder-template="${CSS.escape(copied.type)}"]`) !== null && allowed(list, copied.type, slot);
    pasteButton.hidden = !fits;
    const name = pasteButton.querySelector<HTMLElement>('[data-builder-paste-name]');
    if (name && copied) name.textContent = copied.title;
  }
  if (!dialog.open) dialog.showModal();
  search?.focus();
}

/** The picker goes back to its list when the dialog closes. */
function returnPicker(builder: HTMLElement): void {
  const dialog = dialogOf(builder, 'add');
  const picker = dialog?.querySelector<HTMLElement>('[data-builder-picker]');
  if (!dialog || !picker) return;
  const list = listByKey(builder, dialog.dataset.list || '');
  list?.querySelector(':scope > [data-builder-outline] > .fa-builder-add-main')?.append(picker);
}

/** Edit (0.31): a block's own fields in a dialog, with its name (0.32: one block per dialog, one height). */
function openEditor(list: HTMLElement, id: string): void {
  const builder = builderOf(list)!;
  const dialog = dialogOf(builder, 'edit');
  const pane = paneOf(list, id);
  if (!dialog || !pane) return;
  returnFields(builder);
  const fields = pane.querySelector<HTMLElement>(':scope > [data-builder-fields]');
  if (!fields) return;
  select(list, id);
  dialog.dataset.list = list.dataset.builderList || '';
  dialog.dataset.block = id;
  dialog.querySelector('[data-builder-dialog-body]')!.append(fields);
  const icon = dialog.querySelector<HTMLElement>('[data-builder-dialog-icon]');
  if (icon) icon.innerHTML = pane.querySelector('.fa-builder-pane-head .fa-builder-kind-icon')?.innerHTML || '';
  const kind = dialog.querySelector<HTMLElement>('[data-builder-dialog-kind]');
  if (kind) kind.textContent = pane.querySelector('.fa-builder-pane-kind')?.textContent || '';
  const node = nodeOf(list, id);
  const title = dialog.querySelector<HTMLElement>('[data-builder-dialog-title]');
  if (title && node) title.textContent = titleOf(list, node);
  if (!dialog.open) dialog.showModal();
  fitDialog(dialog);
  dialog.querySelector('[data-builder-dialog-body]')!.scrollTop = 0;
  const invalid = fields.querySelector<HTMLElement>('[aria-invalid="true"]');
  (invalid ?? fields.querySelector<HTMLElement>('input:not([type="hidden"]):not([type="radio"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [contenteditable="true"]'))?.focus();
}

/**
 * The edit dialog keeps one height while it is open (0.32): as tall as the block's
 * tallest tab, at least 360px and at most 760px (or the window less 64px); what
 * doesn't fit scrolls inside its body, so switching tabs or adding slides never
 * moves it. Each tab is shown for a moment, before the browser paints, to measure it.
 */
function fitDialog(dialog: HTMLDialogElement): void {
  const body = dialog.querySelector<HTMLElement>('[data-builder-dialog-body]');
  if (!body) return;
  dialog.style.removeProperty('height');
  let tallest = body.scrollHeight;
  const group = body.querySelector<HTMLElement>('[data-admin-tabs]');
  const panels = group ? [...group.querySelectorAll<HTMLElement>(':scope > [data-admin-tab-panel]')] : [];
  if (panels.length > 1) {
    const hidden = panels.map(panel => panel.hidden);
    panels.forEach(shown => {
      panels.forEach(panel => { panel.hidden = panel !== shown; });
      tallest = Math.max(tallest, body.scrollHeight);
    });
    panels.forEach((panel, index) => { panel.hidden = hidden[index]; });
  }
  const chrome = dialog.offsetHeight - body.clientHeight;
  const most = Math.min(760, window.innerHeight - 64);
  dialog.style.height = `${Math.max(Math.min(360, most), Math.min(most, tallest + chrome))}px`;
}

/** The fields in the dialog go back to their block's pane. */
function returnFields(builder: HTMLElement): HTMLElement | null {
  const dialog = dialogOf(builder, 'edit');
  const fields = dialog?.querySelector<HTMLElement>('[data-builder-fields]');
  if (!dialog || !fields) return null;
  const list = listByKey(builder, fields.dataset.list || '');
  const pane = list ? paneOf(list, fields.dataset.builderFields || '') : null;
  if (pane) pane.append(fields); else fields.remove();
  if (list) sync(list);
  return list ? nodeOf(list, fields.dataset.builderFields || '') : null;
}

/** Delete (0.31): asks first, naming the blocks inside; Undo still brings it back. */
function confirmRemove(list: HTMLElement, node: HTMLElement): void {
  const builder = builderOf(list)!;
  const dialog = dialogOf(builder, 'confirm');
  if (!dialog) { remove(list, node); return; }
  pending.set(builder, { list, node });
  const title = dialog.querySelector<HTMLElement>('[data-builder-confirm-title]');
  if (title) title.textContent = text(title.dataset.template, { block: titleOf(list, node) });
  const inside = nodes(node).length;
  const body = dialog.querySelector<HTMLElement>('[data-builder-confirm-text]');
  if (body) body.textContent = inside > 0 ? text(body.dataset.many, { count: String(inside) }) : body.dataset.one || '';
  dialog.showModal();
  dialog.querySelector<HTMLElement>('[data-builder-confirm-yes]')?.focus();
}

/** Show or hide on the website: the block's Status, Published ↔ Draft. */
function toggle(list: HTMLElement, node: HTMLElement): void {
  const id = node.dataset.builderNode || '';
  const end = `[${list.dataset.builderList}][${id}][f][status]`;
  const controls = controlsOf(list, id).filter(control => control.name.endsWith(end));
  const next = valueOf(list, id, 'status') === 'draft' ? 'published' : 'draft';
  controls.forEach(control => {
    if (control instanceof HTMLInputElement && control.type === 'radio') control.checked = control.value === next;
    else control.value = next;
  });
  controls[0]?.dispatchEvent(new Event('change', { bubbles: true }));
  changed(list);
  markChanged(node);
}

/** A dot on rows changed since the page was opened (saved). */
function markChanged(node: HTMLElement | null): void {
  if (!node || node.classList.contains('is-changed')) return;
  node.classList.add('is-changed');
  const row = node.querySelector<HTMLElement>(':scope > .fa-builder-row');
  const builder = builderOf(node);
  if (row && !row.querySelector('[data-builder-changed]')) {
    const dot = document.createElement('span');
    dot.className = 'fa-builder-changed';
    dot.dataset.builderChanged = '';
    dot.title = builder?.dataset.changedText || '';
    dot.setAttribute('aria-hidden', 'true');
    row.querySelector('.fa-builder-row-main')?.after(dot);
  }
}

/** Search and category: the choices that match and may sit in the chosen place. */
function filter(picker: HTMLElement): void {
  const query = (picker.querySelector<HTMLInputElement>('[data-builder-search]')?.value || '').trim().toLowerCase();
  const category = picker.querySelector<HTMLElement>('[data-builder-cat][aria-selected="true"]')?.dataset.builderCat || '';
  const recent = (picker.dataset.recent || '').split(' ').filter(Boolean);
  let shown = 0;
  picker.querySelectorAll<HTMLElement>('[data-builder-choice]').forEach(choice => {
    const type = choice.dataset.builderChoice || '';
    const inCategory = !category || (category === 'recent' ? recent.includes(type) : choice.dataset.category === category);
    const visible = choice.dataset.placeOk !== 'false' && inCategory && (!query || (choice.dataset.searchText || '').includes(query));
    choice.hidden = !visible;
    if (visible) shown++;
  });
  // Recently used: in the order they were used.
  picker.querySelectorAll<HTMLElement>('[data-builder-choice]').forEach(choice => { choice.style.order = category === 'recent' ? String(recent.indexOf(choice.dataset.builderChoice || '')) : ''; });
  const none = picker.querySelector<HTMLElement>('[data-builder-no-match]');
  if (none) none.hidden = shown > 0;
}

/** The parts that need JavaScript appear; the no-JavaScript buttons go. */
function enhance(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-builder-handle], [data-builder-js]').forEach(element => {
    if (!element.closest('template')) element.hidden = false;
  });
  root.querySelectorAll<HTMLElement>('[data-builder] [data-nojs-only], [data-builder-node] [data-nojs-only]').forEach(element => {
    if (!element.closest('[data-repeater]')) element.hidden = true;
  });
}

/**
 * Saving as one value (0.32): when the form is sent (Save, preview, drafts, the
 * panel's page navigation all build their FormData from it), each Builder's fields
 * leave as one value, content = {"_form":1,"pairs":[["[en][b1][f][title]","Hello"], …]},
 * so max_input_vars never cuts a long page short. The server unpacks the same names
 * and values (Builder::unpack) and checks them as before. Without the formdata event
 * the fields are sent one by one, as in 0.31.
 */
const packing = new WeakSet<HTMLFormElement>();
function packOnSubmit(builder: HTMLElement): void {
  const form = builder.closest('form');
  if (!form || packing.has(form) || typeof FormDataEvent === 'undefined') return;
  packing.add(form);
  form.addEventListener('formdata', event => {
    form.querySelectorAll<HTMLElement>('[data-builder][data-name]').forEach(item => {
      const name = item.dataset.name || '';
      if (!name) return;
      const prefix = name + '[';
      const pairs: [string, string][] = [];
      const names = new Set<string>();
      for (const [key, value] of event.formData) {
        if (!key.startsWith(prefix) || typeof value !== 'string') continue;
        pairs.push([key.slice(name.length), value]);
        names.add(key);
      }
      if (pairs.length === 0) return;
      names.forEach(key => event.formData.delete(key));
      event.formData.append(name, JSON.stringify({ _form: 1, pairs }));
    });
  });
}

/** The editor's own language buttons (when the page has no language switch). */
function showLanguage(builder: HTMLElement, lang: string): void {
  builder.querySelectorAll<HTMLElement>(':scope > [data-lang-panes] > .fa-lang-pane').forEach(pane => pane.classList.toggle('is-current', pane.dataset.lang === lang));
  builder.querySelectorAll<HTMLButtonElement>('[data-builder-lang]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.builderLang === lang)));
}

export function initializeBuilders(root: ParentNode): void {
  const builders = [...root.querySelectorAll<HTMLElement>('[data-builder]')];
  if (root instanceof HTMLElement && root.matches('[data-builder]')) builders.unshift(root);
  builders.forEach(builder => {
    if (ready.has(builder)) return;
    ready.add(builder);
    builder.classList.add('is-ready');
    enhance(builder);
    packOnSubmit(builder);
    const switching = builder.closest('[data-lang-scope]');
    const langs = builder.querySelector<HTMLElement>(':scope > [data-builder-langs]');
    if (langs && !switching) {
      langs.hidden = false;
      builder.classList.add('has-own-languages');
      showLanguage(builder, builder.querySelector<HTMLElement>('[data-builder-lang][aria-pressed="true"]')?.dataset.builderLang || builder.dataset.defaultLang || '');
    }
    builder.querySelectorAll<HTMLElement>('[data-builder-list]').forEach(list => {
      const folded = readFolds()[foldPlace(list)] || [];
      folded.forEach(id => { const node = nodeOf(list, id); if (node && !node.querySelector('.has-error')) setFolded(list, node, true, false); });
      showCollapseAll(list);
      sync(list);
      const invalid = list.querySelector<HTMLElement>('[data-builder-node].has-error');
      select(list, invalid?.dataset.builderNode || null);
    });
    wireDialogs(builder);
    // A block whose own fields have an error opens with them.
    const wrong = [...builder.querySelectorAll<HTMLElement>('[data-builder-pane] .fa-builder-pane-error')]
      .map(error => error.closest<HTMLElement>('[data-builder-pane]')!)
      .find(pane => !pane.closest('.fa-lang-pane:not(.is-current)'));
    const wrongList = wrong ? listOf(wrong) : null;
    const edit = dialogOf(builder, 'edit');
    if (wrong && wrongList && edit && !edit.inert && !edit.closest('[inert]')) openEditor(wrongList, wrong.dataset.builderPane || '');
  });
}

/** What happens when each dialog closes: fields and picker go home, focus goes back to the row. */
function wireDialogs(builder: HTMLElement): void {
  const focusRow = (list: HTMLElement | null, id: string, selector = '[data-builder-select]') => {
    const node = list ? nodeOf(list, id) : null;
    (node?.querySelector<HTMLElement>(`:scope > .fa-builder-row ${selector}`) ?? node?.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]'))?.focus();
  };
  dialogOf(builder, 'edit')?.addEventListener('close', () => {
    const dialog = dialogOf(builder, 'edit')!;
    if (dialog.open) return;
    dialog.style.removeProperty('height');
    const list = listByKey(builder, dialog.dataset.list || '');
    returnFields(builder);
    focusRow(list, dialog.dataset.block || '', '[data-builder-action="edit"]');
  });
  dialogOf(builder, 'add')?.addEventListener('close', () => {
    const dialog = dialogOf(builder, 'add')!;
    if (dialog.open) return;
    const list = listByKey(builder, dialog.dataset.list || '');
    returnPicker(builder);
    const target = list ? targets.get(list) : null;
    // Back to where Add was pressed, unless a block was added (the focus is on it, or in its dialog).
    if (added.has(builder)) { added.delete(builder); return; }
    if (dialogOf(builder, 'edit')?.open || !list || !target) return;
    if (target.after) focusRow(list, target.after.dataset.builderNode || '', '[data-builder-action="insert"]');
    else list.querySelector<HTMLElement>(`[data-builder-add][data-parent="${CSS.escape(target.parent)}"][data-column="${target.column}"] > summary`)?.focus();
  });
  dialogOf(builder, 'confirm')?.addEventListener('close', () => {
    // Opened again before this event came (a quick second Delete): that one is waiting.
    if (dialogOf(builder, 'confirm')?.open) return;
    const job = pending.get(builder);
    pending.delete(builder);
    if (job) focusRow(job.list, job.node.dataset.builderNode || '', '[data-builder-action="remove"]');
  });
}

/** Dialogs: their close buttons, a click on the backdrop, Delete's answer. */
function dialogClick(builder: HTMLElement, event: MouseEvent, target: Element): boolean {
  const dialog = target.closest<HTMLDialogElement>('dialog.fa-builder-dialog');
  if (!dialog || builderOf(dialog) !== builder) return false;
  // A click on the backdrop lands on the dialog itself (pressed there too, not a text selection ending outside).
  if (target === dialog && pressedOn.get(dialog) === dialog) {
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) { dialog.close(); return true; }
  }
  if (target.closest('[data-builder-dialog-close]')) { event.preventDefault(); dialog.close(); return true; }
  if (target.closest('[data-builder-confirm-yes]')) {
    event.preventDefault();
    const job = pending.get(builder);
    pending.delete(builder);
    dialog.close();
    if (job && job.node.isConnected) remove(job.list, job.node);
    return true;
  }
  if (dialog.matches('[data-builder-add-dialog]')) {
    const list = listByKey(builder, dialog.dataset.list || '');
    const choice = target.closest<HTMLButtonElement>('[data-builder-choice]');
    if (choice && list) {
      event.preventDefault();
      const where = targets.get(list) || { list, parent: '', column: 0, after: null };
      added.add(builder);
      dialog.close();
      rememberType(choice.dataset.builderChoice || '');
      add(list, choice.dataset.builderChoice || '', where);
      return true;
    }
    if (target.closest('[data-builder-paste]') && list) {
      event.preventDefault();
      const where = targets.get(list) || { list, parent: '', column: 0, after: null };
      added.add(builder);
      dialog.close();
      paste(list, where);
      return true;
    }
    const category = target.closest<HTMLElement>('[data-builder-cat]');
    if (category) {
      category.parentElement!.querySelectorAll<HTMLElement>('[data-builder-cat]').forEach(button => button.setAttribute('aria-selected', String(button === category)));
      filter(category.closest<HTMLElement>('[data-builder-picker]')!);
      return true;
    }
  }
  return dialog.contains(target);
}

const pressedOn = new WeakMap<HTMLDialogElement, EventTarget | null>();
document.addEventListener('mousedown', event => {
  const dialog = event.target instanceof Element ? event.target.closest<HTMLDialogElement>('dialog.fa-builder-dialog') : null;
  if (dialog) pressedOn.set(dialog, event.target);
}, true);

function closeCopyMenus(except: Element | null = null): void {
  document.querySelectorAll<HTMLElement>('.fa-builder-copy-menu:not([hidden])').forEach(menu => {
    if (except && menu.contains(except)) return;
    menu.hidden = true;
    menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
  });
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  // A click anywhere else closes an open ⋯ menu.
  if (!event.target.closest('[data-builder-menu], [data-builder-action="more"]')) {
    document.querySelectorAll<HTMLElement>('[data-builder-menu]:not([hidden])').forEach(menu => { const owner = builderOf(menu); if (owner) closeMenu(owner); });
  }
  const builder = builderOf(event.target);
  if (!builder || !builder.classList.contains('is-ready') || event.target.closest('template')) return;
  closeCopyMenus(event.target.closest('[data-builder-action="copy-menu"]') ? event.target.closest('.fa-builder-copy') : event.target);
  if (dialogClick(builder, event, event.target)) return;
  const choice = event.target.closest<HTMLElement>('[data-builder-menu] [data-menu-act]');
  if (choice) { event.preventDefault(); menuChoose(builder, choice); return; }
  const list = listOf(event.target);

  const undoButton = event.target.closest('[data-builder-undo-button]');
  if (undoButton) { undo(builder); return; }
  const langButton = event.target.closest<HTMLElement>('[data-builder-lang]');
  if (langButton) { showLanguage(builder, langButton.dataset.builderLang || ''); return; }
  if (!list) return;

  const summary = event.target.closest<HTMLElement>('[data-builder-add] > summary');
  if (summary) {
    event.preventDefault();
    const add = summary.parentElement as HTMLDetailsElement;
    openPicker(list, { list, parent: add.dataset.parent || '', column: Number(add.dataset.column || 0), after: null });
    return;
  }
  const copyAllButton = event.target.closest<HTMLElement>('[data-builder-copy-all]');
  if (copyAllButton) { event.preventDefault(); copyAll(list, copyAllButton.dataset.builderCopyAll || ''); return; }
  const copyTarget = event.target.closest<HTMLElement>('[data-builder-copy-to]');
  if (copyTarget) {
    const pane = copyTarget.closest<HTMLElement>('[data-builder-pane]');
    const node = copyTarget.closest<HTMLElement>('[data-builder-node]') ?? (pane ? nodeOf(list, pane.dataset.builderPane || '') : null);
    closeCopyMenus();
    if (node) copyTo(list, node, copyTarget.dataset.builderCopyTo || '');
    return;
  }
  const link = event.target.closest<HTMLElement>('[data-builder-select]');
  if (link) {
    event.preventDefault();
    const node = link.closest<HTMLElement>('[data-builder-node]')!;
    select(list, node.dataset.builderNode || null, event.detail === 0);
    return;
  }
  const action = event.target.closest<HTMLButtonElement>('[data-builder-action]');
  if (!action) return;
  const pane = action.closest<HTMLElement>('[data-builder-pane]');
  const node = action.closest<HTMLElement>('[data-builder-node]') ?? (pane ? nodeOf(list, pane.dataset.builderPane || '') : null);
  event.preventDefault();
  if (action.disabled) return;
  switch (action.dataset.builderAction) {
    case 'collapse-all': {
      const containers = containersOf(list);
      const fold = !containers.every(container => container.classList.contains('is-folded'));
      containers.forEach(container => setFolded(list, container, fold, false));
      saveFolds(list);
      showCollapseAll(list);
      break;
    }
    case 'fold': if (node) setFolded(list, node, !node.classList.contains('is-folded')); break;
    case 'more': if (node) openMenu(list, node, action); break;
    case 'close': select(list, null); node?.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]')?.focus(); break;
    case 'edit': if (node) openEditor(list, node.dataset.builderNode || ''); break;
    case 'remove': if (node) confirmRemove(list, node); break;
    case 'duplicate': if (node) duplicate(list, node); break;
    case 'toggle': if (node) toggle(list, node); break;
    case 'up': if (node) move(list, node, -1); break;
    case 'down': if (node) move(list, node, 1); break;
    case 'insert': {
      if (!node) break;
      const slot = slotOf(node);
      openPicker(list, { list, parent: slot.dataset.parent || '', column: Number(slot.dataset.column || 0), after: node });
      break;
    }
    case 'copy-menu': {
      const menu = action.nextElementSibling as HTMLElement | null;
      if (!menu) break;
      menu.hidden = !menu.hidden;
      action.setAttribute('aria-expanded', String(!menu.hidden));
      // Its own language is not offered.
      menu.querySelectorAll<HTMLElement>('[data-builder-copy-to]').forEach(item => { item.hidden = item.dataset.builderCopyTo === list.dataset.builderList; });
      if (!menu.hidden) menu.querySelector<HTMLElement>('[data-builder-copy-to]:not([hidden])')?.focus();
      break;
    }
  }
});

// A double click on a row opens its block.
document.addEventListener('dblclick', event => {
  if (!(event.target instanceof Element)) return;
  const row = event.target.closest<HTMLElement>('.fa-builder.is-ready .fa-builder-row');
  if (!row || event.target.closest('button, [data-builder-handle]')) return;
  const node = row.closest<HTMLElement>('[data-builder-node]');
  const list = node ? listOf(node) : null;
  if (node && list && fieldsOf(list, node.dataset.builderNode || '')) { event.preventDefault(); openEditor(list, node.dataset.builderNode || ''); }
});

document.addEventListener('pointerdown', event => {
  if (!(event.target instanceof Element) || event.button !== 0) return;
  const handle = event.target.closest<HTMLElement>('.fa-builder.is-ready [data-builder-handle]');
  if (handle) {
    const node = handle.closest<HTMLElement>('[data-builder-node]');
    const list = node ? listOf(node) : null;
    if (node && list) { event.preventDefault(); drag(list, node, event, event.pointerType === 'touch'); }
    return;
  }
  // Touch (0.32): press and hold a block's row to drag it; a tap still selects it.
  if (event.pointerType !== 'touch') return;
  const row = event.target.closest<HTMLElement>('.fa-builder.is-ready:not([data-fixed]) .fa-builder-row');
  if (!row || event.target.closest('button, input, select, textarea')) return;
  const node = row.closest<HTMLElement>('[data-builder-node]');
  const list = node ? listOf(node) : null;
  if (!node || !list) return;
  const startX = event.clientX; const startY = event.clientY;
  const stop = () => { window.clearTimeout(timer); document.removeEventListener('pointermove', moved); document.removeEventListener('pointerup', stop); document.removeEventListener('pointercancel', stop); };
  const moved = (move: PointerEvent) => { if (Math.hypot(move.clientX - startX, move.clientY - startY) > 8) stop(); };
  const timer = window.setTimeout(() => { stop(); navigator.vibrate?.(10); drag(list, node, event, true); }, 450);
  document.addEventListener('pointermove', moved);
  document.addEventListener('pointerup', stop);
  document.addEventListener('pointercancel', stop);
});
// A long press opens no menu on a block being dragged.
document.addEventListener('contextmenu', event => { if (dragging || (event.target instanceof Element && event.target.closest('.fa-builder.is-ready .fa-builder-row') && (event as PointerEvent).pointerType === 'touch')) event.preventDefault(); });

document.addEventListener('keydown', event => {
  if (!(event.target instanceof HTMLElement)) return;
  const builder = builderOf(event.target);
  if (!builder || !builder.classList.contains('is-ready')) return;
  const list = listOf(event.target);
  // Escape closes the copy menu (the dialogs close by themselves).
  if (event.key === 'Escape') {
    const menu = event.target.closest<HTMLElement>('.fa-builder-copy-menu');
    if (menu) { event.preventDefault(); event.stopPropagation(); menu.hidden = true; (menu.previousElementSibling as HTMLElement | null)?.focus(); return; }
  }
  // Enter in the search adds the first choice shown.
  if (event.key === 'Enter' && event.target.matches('[data-builder-search]')) {
    event.preventDefault();
    (event.target.closest('[data-builder-picker]')?.querySelector<HTMLButtonElement>('[data-builder-choice]:not([hidden])'))?.click();
    return;
  }
  // On a row: Enter opens the block, Delete asks to delete it, Ctrl+D duplicates it.
  const onRow = list && event.target.matches('.fa-builder-row [data-builder-select], .fa-builder-row [data-builder-handle]');
  const rowNode = onRow ? event.target.closest<HTMLElement>('[data-builder-node]') : null;
  if (list && rowNode && !builder.hasAttribute('data-fixed') && !event.altKey) {
    const id = rowNode.dataset.builderNode || '';
    if (event.key === 'Enter' && !event.ctrlKey && !event.metaKey && fieldsOf(list, id)) { event.preventDefault(); openEditor(list, id); return; }
    if (event.key === 'Delete') { event.preventDefault(); confirmRemove(list, rowNode); return; }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'd') { event.preventDefault(); duplicate(list, rowNode); return; }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'c') { event.preventDefault(); copyToClipboard(list, rowNode); return; }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'v') {
      const slot = slotOf(rowNode);
      if (paste(list, { list, parent: slot.dataset.parent || '', column: Number(slot.dataset.column || 0), after: rowNode })) event.preventDefault();
      return;
    }
  } else if (list && rowNode && event.key === 'Enter' && fieldsOf(list, rowNode.dataset.builderNode || '')) {
    event.preventDefault(); openEditor(list, rowNode.dataset.builderNode || ''); return;
  }
  // Alt + ↑ ↓ on a row (or the handle) moves its block.
  if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown') && list && event.target.closest('.fa-builder-row')) {
    const node = event.target.closest<HTMLElement>('[data-builder-node]');
    if (node) { event.preventDefault(); move(list, node, event.key === 'ArrowUp' ? -1 : 1); }
    return;
  }
  // Alt + → moves a block into the container before it; Alt + ← out of its container (mirrored right to left).
  if (event.altKey && (event.key === 'ArrowLeft' || event.key === 'ArrowRight') && list && event.target.closest('.fa-builder-row')) {
    const node = event.target.closest<HTMLElement>('[data-builder-node]');
    if (!node) return;
    const rtl = getComputedStyle(list).direction === 'rtl';
    const into = (event.key === 'ArrowRight') !== rtl;
    event.preventDefault();
    if (into) {
      const before = node.previousElementSibling as HTMLElement | null;
      const slot = before?.hasAttribute('data-builder-node') ? [...before.querySelectorAll<HTMLElement>(':scope > [data-builder-slot], :scope > [data-builder-cols] > [data-builder-col] > [data-builder-slot]')].reverse().find(target => allowed(list, node.dataset.type || '', target)) : null;
      if (!slot) return;
      slot.append(node);
    } else {
      const parent = node.parentElement?.closest<HTMLElement>('[data-builder-node]');
      if (!parent || !allowed(list, node.dataset.type || '', slotOf(parent))) return;
      parent.after(node);
    }
    changed(list);
    markChanged(node);
    node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]')?.focus();
    announce(builderOf(list)!, text(builderOf(list)!.dataset.movedText, { block: titleOf(list, node), position: String(childNodes(slotOf(node)).indexOf(node) + 1) }));
    return;
  }
  if (event.target.matches('[data-builder-handle]') && (event.key === 'ArrowUp' || event.key === 'ArrowDown') && list) {
    const node = event.target.closest<HTMLElement>('[data-builder-node]');
    if (node) { event.preventDefault(); move(list, node, event.key === 'ArrowUp' ? -1 : 1); }
  }
});

document.addEventListener('input', event => {
  if (!(event.target instanceof HTMLElement)) return;
  if (event.target.matches('[data-builder-search]')) { filter(event.target.closest<HTMLElement>('[data-builder-picker]')!); return; }
  const block = blockFrom(event.target);
  if (!block) return;
  sync(block.list);
  markChanged(nodeOf(block.list, block.id));
  refreshDialogTitle(block.list, block.id);
});

/** The edit dialog's title follows the block's name as it is typed. */
function refreshDialogTitle(list: HTMLElement, id: string): void {
  const dialog = dialogOf(builderOf(list)!, 'edit');
  const node = nodeOf(list, id);
  if (!dialog?.open || dialog.dataset.block !== id || !node) return;
  const title = dialog.querySelector<HTMLElement>('[data-builder-dialog-title]');
  if (title) title.textContent = titleOf(list, node);
}

document.addEventListener('change', event => {
  if (!(event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement)) return;
  const block = blockFrom(event.target);
  if (!block) return;
  const { list, id } = block;
  const node = nodeOf(list, id);
  // A Columns block's layout: its columns in the structure follow.
  if (node?.dataset.type === 'columns' && event.target.name.endsWith('[f][layout]') && event.target instanceof HTMLInputElement && event.target.checked) {
    setColumns(list, node, event.target.value);
  }
  sync(list);
  markChanged(node);
  refreshDialogTitle(list, id);
});
