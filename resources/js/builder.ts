/*
 * The Builder field (0.29): the structure of a page's blocks and the fields
 * of the selected one. Every block's fields are in the page, each with its
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
const TITLE_FIELDS = ['title', 'heading', 'name', 'label', 'text', 'body', 'caption'];

const text = (template: string | undefined, values: Record<string, string>) =>
  Object.entries(values).reduce((out, [key, value]) => out.split(':' + key).join(value), template || '');
const builderOf = (element: Element) => element.closest<HTMLElement>('[data-builder]');
const listOf = (element: Element) => element.closest<HTMLElement>('[data-builder-list]');
const nodes = (scope: ParentNode) => [...scope.querySelectorAll<HTMLElement>('[data-builder-node]')];
const paneOf = (list: HTMLElement, id: string) => list.querySelector<HTMLElement>(`:scope > [data-builder-inspector] > [data-builder-pane="${CSS.escape(id)}"]`);
const slotOf = (node: HTMLElement) => node.parentElement as HTMLElement;
const childNodes = (slot: HTMLElement) => [...slot.children].filter((child): child is HTMLElement => child instanceof HTMLElement && child.hasAttribute('data-builder-node'));

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

/** Where a slot is: "top", or the type of the block it is in. */
function placeOf(slot: HTMLElement): string {
  const parent = slot.dataset.parent || '';
  if (!parent) return 'top';
  return slot.closest<HTMLElement>('[data-builder-node]')?.dataset.type || '';
}

/** Whether a kind of block may sit in a slot (from the picker's data-allowed). */
function allowed(list: HTMLElement, type: string, slot: HTMLElement): boolean {
  const choice = list.querySelector<HTMLElement>(`[data-builder-picker] [data-builder-choice="${CSS.escape(type)}"]`);
  if (!choice) return type === '' ? false : !slot.dataset.parent;
  return (choice.dataset.allowed || '').split(' ').includes(placeOf(slot));
}

/** A block's title, as the server makes it: its first named text field, else its kind. */
function titleOf(list: HTMLElement, node: HTMLElement): string {
  const id = node.dataset.builderNode || '';
  const pane = paneOf(list, id);
  if (!pane) return '';
  const base = `[${list.dataset.builderList}][${id}][f]`;
  for (const name of TITLE_FIELDS) {
    const control = [...pane.querySelectorAll<Control>('[name]')].find(element => element.name.endsWith(`${base}[${name}]`));
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
  prepare(builder, made.pane);
  enhance(made.node);
  changed(list);
  select(list, made.node.dataset.builderNode || null);
  made.pane.querySelector<HTMLElement>('input:not([type="hidden"]):not([type="radio"]), select, textarea')?.focus();
}

/**
 * A copy of a block (and the blocks in it) in a list: new ids, the same values.
 * Lists inside the block get the same items first, so every value has a place.
 */
function copyNode(fromList: HTMLElement, source: HTMLElement, toList: HTMLElement, slot: HTMLElement, after: HTMLElement | null): HTMLElement | null {
  const builder = builderOf(toList)!;
  const oldId = source.dataset.builderNode || '';
  const sourcePane = paneOf(fromList, oldId);
  const id = newId();
  const made = sourcePane ? fromTemplate(builder, source.dataset.type || '', toList.dataset.builderList || '', id) : null;
  if (!sourcePane || !made) return null;
  const from = `[${fromList.dataset.builderList}][${oldId}]`;
  const to = `[${toList.dataset.builderList}][${id}]`;
  // The items of lists inside the block (a slider's slides).
  sourcePane.querySelectorAll<HTMLElement>('[data-repeater]').forEach(repeater => {
    const name = (repeater.dataset.name || '').replace(from, to);
    const target = [...made.pane.querySelectorAll<HTMLElement>('[data-repeater]')].find(element => element.dataset.name === name);
    const template = target?.querySelector<HTMLTemplateElement>(':scope > template[data-repeater-template]');
    const items = target?.querySelector<HTMLElement>(':scope > [data-repeater-items]');
    if (!target || !template || !items) return;
    repeater.querySelectorAll<HTMLElement>(':scope > [data-repeater-items] > [data-repeater-item]').forEach((item, index) => {
      const holder = document.createElement('div');
      holder.innerHTML = template.innerHTML.split('__KEY__').join(item.dataset.key || '').split('__NUMBER__').join(String(index + 1));
      if (holder.firstElementChild) items.append(holder.firstElementChild);
    });
  });
  const controls = [...made.pane.querySelectorAll<Control>('[name]')];
  sourcePane.querySelectorAll<Control>('[name]').forEach(control => {
    // The place inputs are the new block's own (sync() fills them).
    if (control.parentElement === sourcePane) return;
    const name = control.name.replace(from, to);
    const radio = control instanceof HTMLInputElement && (control.type === 'radio' || control.type === 'checkbox');
    const target = controls.find(element => element.name === name && element.type === control.type && (!radio || element.value === control.value));
    if (!target) return;
    if (radio) (target as HTMLInputElement).checked = (control as HTMLInputElement).checked;
    else if (target instanceof HTMLSelectElement && control instanceof HTMLSelectElement) [...target.options].forEach((option, i) => { option.selected = control.options[i]?.selected ?? false; });
    else target.value = control.value;
  });
  // A rich editor keeps its text in the editor until the form is sent.
  sourcePane.querySelectorAll<HTMLElement>('[data-admin-rich-editor]').forEach((editor, index) => {
    const html = editor.querySelector<HTMLElement>('.ProseMirror, [contenteditable="true"]')?.innerHTML;
    const target = made.pane.querySelectorAll<HTMLElement>('[data-admin-rich-editor]')[index]?.querySelector<HTMLTextAreaElement>('textarea');
    if (html && target) target.value = html;
  });
  sourcePane.querySelectorAll<HTMLElement>('[data-admin-media-field]').forEach((media, index) => {
    const target = made.pane.querySelectorAll<HTMLElement>('[data-admin-media-field]')[index];
    if (target && media.dataset.items) target.dataset.items = media.dataset.items;
  });
  insert(toList, made.node, made.pane, slot, after);
  // Columns: the same number of columns before the blocks in them.
  if (source.dataset.type === 'columns') setColumns(toList, made.node, source.querySelectorAll(':scope > [data-builder-cols] > [data-builder-col]').length);
  prepare(builder, made.pane);
  enhance(made.node);
  source.querySelectorAll<HTMLElement>(':scope > [data-builder-cols] > [data-builder-col] > [data-builder-slot], :scope > [data-builder-slot]').forEach(childSlot => {
    const targetSlot = slotFor(toList, id, Number(childSlot.dataset.column || 0));
    if (!targetSlot) return;
    childNodes(childSlot).forEach(child => { copyNode(fromList, child, toList, targetSlot, null); });
  });
  return made.node;
}

function duplicate(list: HTMLElement, node: HTMLElement): void {
  const builder = builderOf(list)!;
  if (full(list, 1 + nodes(node).length)) { announce(builder, builder.dataset.fullText || ''); return; }
  const copy = copyNode(list, node, list, slotOf(node), node);
  if (!copy) return;
  changed(list);
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
  copyNode(list, node, target, slot, null);
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
  node.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-handle], :scope > .fa-builder-row [data-builder-select]')?.focus();
  const builder = builderOf(list)!;
  announce(builder, text(builder.dataset.movedText, { block: titleOf(list, node), position: String(target + 1) }));
}

/** Drag a block by its handle into any place it may sit. */
function drag(list: HTMLElement, node: HTMLElement, event: PointerEvent): void {
  const handle = event.target instanceof Element ? event.target.closest<HTMLElement>('[data-builder-handle]') : null;
  if (!handle) return;
  event.preventDefault();
  const pointer = event.pointerId;
  const type = node.dataset.type || '';
  const startSlot = slotOf(node);
  const startNext = node.nextElementSibling;
  node.classList.add('is-dragging');
  list.classList.add('is-dragging');
  const onMove = (moved: PointerEvent) => {
    if (moved.pointerId !== pointer) return;
    const under = document.elementFromPoint(moved.clientX, moved.clientY);
    if (!under || node.contains(under)) return;
    const row = under.closest<HTMLElement>('[data-builder-node]');
    const emptySlot = under.closest<HTMLElement>('[data-builder-slot]');
    if (row && list.contains(row) && !node.contains(row)) {
      const slot = slotOf(row);
      const box = row.querySelector<HTMLElement>(':scope > .fa-builder-row')!.getBoundingClientRect();
      // Over a container's own row: into it when it has room for this kind.
      if (row.hasAttribute('data-holds') && moved.clientY > box.top + box.height * 0.3 && moved.clientY < box.bottom) {
        const inner = row.querySelector<HTMLElement>(':scope > [data-builder-slot], :scope > [data-builder-cols] > [data-builder-col] > [data-builder-slot]');
        if (inner && allowed(list, type, inner) && childNodes(inner).length === 0) { inner.append(node); return; }
      }
      if (!allowed(list, type, slot) || moved.clientY > box.bottom) return;
      if (moved.clientY < box.top + box.height / 2) { if (row.previousElementSibling !== node) row.before(node); }
      else if (row.nextElementSibling !== node) row.after(node);
      return;
    }
    if (emptySlot && list.contains(emptySlot) && !node.contains(emptySlot) && childNodes(emptySlot).length === 0 && allowed(list, type, emptySlot)) emptySlot.append(node);
  };
  const onUp = (up: PointerEvent) => {
    if (up.pointerId !== pointer) return;
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onUp);
    node.classList.remove('is-dragging');
    list.classList.remove('is-dragging');
    if (up.type === 'pointercancel') { if (startNext && startNext.parentElement === startSlot) startSlot.insertBefore(node, startNext); else startSlot.append(node); }
    if (slotOf(node) !== startSlot || node.nextElementSibling !== startNext) {
      changed(list);
      const builder = builderOf(list)!;
      announce(builder, text(builder.dataset.movedText, { block: titleOf(list, node), position: String(childNodes(slotOf(node)).indexOf(node) + 1) }));
    }
    handle.focus();
  };
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
}

/**
 * A Columns block's columns in the structure follow its layout: a column
 * that goes away gives its blocks to the last remaining one.
 */
function setColumns(list: HTMLElement, node: HTMLElement, count: number): void {
  const holder = node.querySelector<HTMLElement>(':scope > [data-builder-cols]');
  if (!holder || count < 1) return;
  const builder = builderOf(list)!;
  let columns = [...holder.querySelectorAll<HTMLElement>(':scope > [data-builder-col]')];
  while (columns.length > count) {
    const last = columns.pop()!;
    const keep = columns[columns.length - 1].querySelector<HTMLElement>(':scope > [data-builder-slot]')!;
    childNodes(last.querySelector<HTMLElement>(':scope > [data-builder-slot]')!).forEach(child => keep.append(child));
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
      add.dataset.columnLabel = builder.dataset.addColumnText || '';
      add.removeAttribute('open');
      add.querySelectorAll<HTMLButtonElement>('button[value]').forEach(button => { button.value = button.value.replace(/:\d+$/, ':' + index); });
      const span = add.querySelector<HTMLElement>(':scope > summary > span');
      if (span && add.dataset.columnLabel) span.textContent = text(add.dataset.columnLabel, { number: String(index + 1) });
    });
    holder.append(copy);
    columns.push(copy);
  }
}

/** The kinds the picker offers for a place, and the picker opened there. */
function openPicker(list: HTMLElement, target: Target, anchor: HTMLElement): void {
  const details = list.querySelector<HTMLDetailsElement>(':scope > [data-builder-outline] > .fa-builder-add-main');
  if (!details) return;
  if (list.classList.contains('is-full')) { const builder = builderOf(list)!; announce(builder, builder.dataset.fullText || ''); return; }
  const slot = slotFor(list, target.parent, target.column);
  if (!slot) return;
  targets.set(list, target);
  details.querySelectorAll<HTMLElement>('[data-builder-choice]').forEach(choice => {
    choice.dataset.placeOk = String(allowed(list, choice.dataset.builderChoice || '', slot));
  });
  filter(details);
  const floating = anchor !== details.querySelector('summary');
  details.classList.toggle('is-floating', floating);
  details.classList.remove('is-up');
  const search = details.querySelector<HTMLInputElement>('[data-builder-search]');
  if (search && !details.open) { search.value = ''; filter(details); }
  details.open = true;
  const panel = details.querySelector<HTMLElement>('[data-builder-picker]')!;
  if (floating) {
    const box = anchor.getBoundingClientRect();
    const width = Math.min(600, document.documentElement.clientWidth - 24);
    const rtl = getComputedStyle(list).direction === 'rtl';
    const left = Math.max(12, Math.min(rtl ? box.right - width : box.left, document.documentElement.clientWidth - width - 12));
    panel.style.position = 'fixed';
    panel.style.left = left + 'px';
    panel.style.width = width + 'px';
    panel.style.top = '0px';
    // Below the button when it fits, else above it, always inside the window.
    const height = panel.offsetHeight;
    const below = box.bottom + 6;
    const top = below + height <= window.innerHeight - 12 ? below : box.top - 6 - height >= 12 ? box.top - 6 - height : Math.max(12, window.innerHeight - height - 12);
    panel.style.top = top + 'px';
  } else {
    panel.style.cssText = '';
    const box = anchor.getBoundingClientRect();
    details.classList.toggle('is-up', window.innerHeight - box.bottom < 380 && box.top > window.innerHeight - box.bottom);
  }
  details.querySelector<HTMLInputElement>('[data-builder-search]')?.focus();
}

function closePicker(details: HTMLDetailsElement, focus: HTMLElement | null = null): void {
  details.open = false;
  details.classList.remove('is-floating');
  const panel = details.querySelector<HTMLElement>('[data-builder-picker]');
  if (panel) panel.style.cssText = '';
  focus?.focus();
}

/** Search and category: the choices that match and may sit in the chosen place. */
function filter(details: HTMLElement): void {
  const query = (details.querySelector<HTMLInputElement>('[data-builder-search]')?.value || '').trim().toLowerCase();
  const category = details.querySelector<HTMLElement>('[data-builder-cat][aria-selected="true"]')?.dataset.builderCat || '';
  let shown = 0;
  details.querySelectorAll<HTMLElement>('[data-builder-picker] [data-builder-choice]').forEach(choice => {
    const visible = choice.dataset.placeOk !== 'false' && (!category || choice.dataset.category === category) && (!query || (choice.dataset.searchText || '').includes(query));
    choice.hidden = !visible;
    if (visible) shown++;
  });
  const none = details.querySelector<HTMLElement>('[data-builder-no-match]');
  if (none) none.hidden = shown > 0;
}

/** The parts that need JavaScript appear; the no-JavaScript buttons go. */
function enhance(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-builder-handle], [data-builder-js], [data-builder-action="collapse-all"]').forEach(element => {
    if (!element.closest('template')) element.hidden = false;
  });
  root.querySelectorAll<HTMLElement>('[data-builder] [data-nojs-only], [data-builder-node] [data-nojs-only]').forEach(element => {
    if (!element.closest('[data-repeater]')) element.hidden = true;
  });
}

/*
 * A list inside a block (a slider's slides, cards) opens one item at a time
 * in the narrow inspector: its fields fill the pane, with Back, "2 of 3" and
 * the previous and next item.
 */
function drillItems(repeater: HTMLElement): HTMLElement[] {
  return [...repeater.querySelectorAll<HTMLElement>(':scope > [data-repeater-items] > [data-repeater-item]')];
}

function drill(repeater: HTMLElement, item: HTMLElement | null): void {
  const builder = builderOf(repeater);
  drillItems(repeater).forEach(other => {
    const open = other === item;
    other.classList.toggle('is-drilled', open);
    const body = other.querySelector<HTMLElement>(':scope > .fa-repeater-body');
    if (body && open) { body.hidden = false; other.classList.remove('is-collapsed'); }
  });
  repeater.classList.toggle('is-drilling', item !== null);
  let bar = repeater.querySelector<HTMLElement>(':scope > [data-builder-drill]');
  if (item === null) { if (bar) bar.hidden = true; return; }
  if (!bar) {
    bar = document.createElement('div');
    bar.className = 'fa-builder-drill';
    bar.dataset.builderDrill = '';
    const label = (key: string) => (builder?.dataset[key] || '').replace(/[<>&"]/g, '');
    const button = (action: string, key: string, path: string) => `<button type="button" class="fa-builder-tool" data-drill="${action}" aria-label="${label(key)}" title="${label(key)}"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${path}"/></svg></button>`;
    bar.innerHTML = button('back', 'backText', 'M15 18l-6-6 6-6') + '<span class="fa-builder-drill-place" aria-live="polite"></span>'
      + button('prev', 'previousText', 'M18 15l-6-6-6 6') + button('next', 'nextText', 'M6 9l6 6 6-6');
    repeater.querySelector(':scope > [data-repeater-items]')?.before(bar);
  }
  bar.hidden = false;
  const list = drillItems(repeater);
  const index = list.indexOf(item);
  bar.querySelector<HTMLElement>('.fa-builder-drill-place')!.textContent = text(builder?.dataset.itemOfText, { number: String(index + 1), count: String(list.length) });
  bar.querySelector<HTMLButtonElement>('[data-drill="prev"]')!.disabled = index <= 0;
  bar.querySelector<HTMLButtonElement>('[data-drill="next"]')!.disabled = index >= list.length - 1;
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const repeater = event.target.closest<HTMLElement>('.fa-builder.is-ready [data-builder-pane] [data-repeater]:not(.is-table)');
  if (!repeater) return;
  const control = event.target.closest<HTMLElement>('[data-drill]');
  if (control && control.closest('[data-builder-drill]')?.parentElement === repeater) {
    const list = drillItems(repeater);
    const current = list.find(item => item.classList.contains('is-drilled')) ?? null;
    const index = current ? list.indexOf(current) : -1;
    if (control.dataset.drill === 'back') {
      drill(repeater, null);
      current?.querySelector<HTMLElement>('[data-repeater-handle], [data-repeater-action]')?.focus();
    } else {
      drill(repeater, list[index + (control.dataset.drill === 'prev' ? -1 : 1)] ?? current);
    }
    return;
  }
  // Add opens the new item; removing the open item goes back to the list.
  if (event.target.closest('[data-repeater-add]')) { const list = drillItems(repeater); if (list.length) drill(repeater, list[list.length - 1]); return; }
  if (event.target.closest('[data-repeater-action="remove"]')) { if (!drillItems(repeater).some(item => item.classList.contains('is-drilled'))) drill(repeater, null); return; }
  // A click on an item's head (not on its tools) opens it.
  const head = event.target.closest<HTMLElement>('.fa-repeater-item-head');
  const item = head?.closest<HTMLElement>('[data-repeater-item]');
  if (!head || !item || item.parentElement?.parentElement !== repeater || event.target.closest('button') || item.classList.contains('is-drilled')) return;
  drill(repeater, item);
  item.querySelector<HTMLElement>('.fa-repeater-body input:not([type="hidden"]), .fa-repeater-body select, .fa-repeater-body textarea')?.focus();
});

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
    const switching = builder.closest('[data-lang-scope]');
    const langs = builder.querySelector<HTMLElement>(':scope > [data-builder-langs]');
    if (langs && !switching) {
      langs.hidden = false;
      builder.classList.add('has-own-languages');
      showLanguage(builder, builder.querySelector<HTMLElement>('[data-builder-lang][aria-pressed="true"]')?.dataset.builderLang || builder.dataset.defaultLang || '');
    }
    builder.querySelectorAll<HTMLElement>('[data-builder-list]').forEach(list => {
      // The nested Add buttons open the main picker (their own lists are for no JavaScript).
      list.querySelectorAll<HTMLElement>('.fa-builder-add-in').forEach(add => {
        if (add.closest('[data-builder-col]')) add.dataset.columnLabel = builder.dataset.addColumnText || '';
      });
      sync(list);
      // A list in a block with an error opens its first item that has one.
      list.querySelectorAll<HTMLElement>('[data-builder-pane] [data-repeater]:not(.is-table)').forEach(repeater => {
        const wrong = drillItems(repeater).find(item => item.classList.contains('has-error'));
        if (wrong) drill(repeater, wrong);
      });
      const invalid = list.querySelector<HTMLElement>('[data-builder-node].has-error');
      select(list, invalid?.dataset.builderNode || null);
    });
  });
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const builder = builderOf(event.target);
  if (!builder || !builder.classList.contains('is-ready') || event.target.closest('template')) return;
  const list = listOf(event.target);

  // Close an open picker on a click elsewhere.
  builder.querySelectorAll<HTMLDetailsElement>('.fa-builder-add-main[open]').forEach(details => {
    if (!details.contains(event.target as Node) && !(event.target as Element).closest('[data-builder-add], [data-builder-action="insert"]')) closePicker(details);
  });

  const undoButton = event.target.closest('[data-builder-undo-button]');
  if (undoButton) { undo(builder); return; }
  const langButton = event.target.closest<HTMLElement>('[data-builder-lang]');
  if (langButton) { showLanguage(builder, langButton.dataset.builderLang || ''); return; }
  if (!list) return;

  const summary = event.target.closest<HTMLElement>('[data-builder-add] > summary');
  if (summary) {
    event.preventDefault();
    const add = summary.parentElement as HTMLDetailsElement;
    const main = add.classList.contains('fa-builder-add-main');
    if (main && add.open && !add.classList.contains('is-floating')) { closePicker(add); return; }
    openPicker(list, { list, parent: add.dataset.parent || '', column: Number(add.dataset.column || 0), after: null }, summary);
    return;
  }
  const choice = event.target.closest<HTMLButtonElement>('[data-builder-picker] [data-builder-choice]');
  if (choice) {
    event.preventDefault();
    const details = choice.closest<HTMLDetailsElement>('details')!;
    const target = targets.get(list) || { list, parent: '', column: 0, after: null };
    closePicker(details);
    add(list, choice.dataset.builderChoice || '', target);
    return;
  }
  const category = event.target.closest<HTMLElement>('[data-builder-cat]');
  if (category) {
    category.parentElement!.querySelectorAll<HTMLElement>('[data-builder-cat]').forEach(button => button.setAttribute('aria-selected', String(button === category)));
    filter(category.closest('details')!);
    return;
  }
  const copyAllButton = event.target.closest<HTMLElement>('[data-builder-copy-all]');
  if (copyAllButton) { event.preventDefault(); copyAll(list, copyAllButton.dataset.builderCopyAll || ''); return; }
  const copyTarget = event.target.closest<HTMLElement>('[data-builder-copy-to]');
  if (copyTarget) {
    const pane = copyTarget.closest<HTMLElement>('[data-builder-pane]');
    const node = pane ? list.querySelector<HTMLElement>(`[data-builder-node="${CSS.escape(pane.dataset.builderPane || '')}"]`) : null;
    const menu = copyTarget.closest<HTMLElement>('.fa-builder-copy-menu');
    if (menu) { menu.hidden = true; menu.previousElementSibling?.setAttribute('aria-expanded', 'false'); }
    if (node) copyTo(list, node, copyTarget.dataset.builderCopyTo || '');
    return;
  }
  const link = event.target.closest<HTMLElement>('[data-builder-select]');
  if (link) {
    event.preventDefault();
    const node = link.closest<HTMLElement>('[data-builder-node]')!;
    select(list, node.dataset.builderNode || null, true);
    return;
  }
  const action = event.target.closest<HTMLButtonElement>('[data-builder-action]');
  if (!action) return;
  const pane = action.closest<HTMLElement>('[data-builder-pane]');
  const node = action.closest<HTMLElement>('[data-builder-node]')
    ?? (pane ? list.querySelector<HTMLElement>(`[data-builder-node="${CSS.escape(pane.dataset.builderPane || '')}"]`) : null);
  event.preventDefault();
  if (action.disabled) return;
  switch (action.dataset.builderAction) {
    case 'collapse-all': {
      const collapse = !list.classList.contains('is-collapsed');
      list.classList.toggle('is-collapsed', collapse);
      action.setAttribute('aria-pressed', String(collapse));
      if (action.dataset.expandText && action.dataset.collapseText) action.textContent = collapse ? action.dataset.expandText : action.dataset.collapseText;
      break;
    }
    case 'close': select(list, null); node?.querySelector<HTMLElement>(':scope > .fa-builder-row [data-builder-select]')?.focus(); break;
    case 'remove': if (node) remove(list, node); break;
    case 'duplicate': if (node) duplicate(list, node); break;
    case 'up': if (node) move(list, node, -1); break;
    case 'down': if (node) move(list, node, 1); break;
    case 'insert': {
      if (!node) break;
      const slot = slotOf(node);
      openPicker(list, { list, parent: slot.dataset.parent || '', column: Number(slot.dataset.column || 0), after: node }, action);
      break;
    }
    case 'copy-menu': {
      const menu = action.nextElementSibling as HTMLElement | null;
      if (!menu) break;
      menu.hidden = !menu.hidden;
      action.setAttribute('aria-expanded', String(!menu.hidden));
      if (!menu.hidden) menu.querySelector<HTMLElement>('[data-builder-copy-to]:not([hidden])')?.focus();
      break;
    }
  }
});

document.addEventListener('pointerdown', event => {
  if (!(event.target instanceof Element) || event.button !== 0) return;
  const handle = event.target.closest<HTMLElement>('[data-builder-handle]');
  const node = handle?.closest<HTMLElement>('[data-builder-node]');
  const list = node ? listOf(node) : null;
  if (handle && node && list) drag(list, node, event);
});

document.addEventListener('keydown', event => {
  if (!(event.target instanceof HTMLElement)) return;
  const builder = builderOf(event.target);
  if (!builder || !builder.classList.contains('is-ready')) return;
  const list = listOf(event.target);
  // Escape closes the picker or the copy menu.
  if (event.key === 'Escape') {
    const details = event.target.closest<HTMLDetailsElement>('.fa-builder-add-main[open]');
    if (details) { event.preventDefault(); event.stopPropagation(); closePicker(details, details.querySelector('summary')); return; }
    const menu = event.target.closest<HTMLElement>('.fa-builder-copy-menu');
    if (menu) { event.preventDefault(); menu.hidden = true; (menu.previousElementSibling as HTMLElement | null)?.focus(); return; }
  }
  // Enter in the search adds the first choice shown.
  if (event.key === 'Enter' && event.target.matches('[data-builder-search]') && list) {
    event.preventDefault();
    (event.target.closest('details')?.querySelector<HTMLButtonElement>('[data-builder-picker] [data-builder-choice]:not([hidden])'))?.click();
    return;
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
  if (event.target.matches('[data-builder-search]')) { filter(event.target.closest('details')!); return; }
  const pane = event.target.closest<HTMLElement>('[data-builder-pane]');
  const list = pane ? listOf(pane) : null;
  if (pane && list) sync(list);
});

document.addEventListener('change', event => {
  if (!(event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement)) return;
  const pane = event.target.closest<HTMLElement>('[data-builder-pane]');
  const list = pane ? listOf(pane) : null;
  if (!pane || !list) return;
  // A Columns block's layout: its columns in the structure follow.
  if (pane.dataset.type === 'columns' && event.target.name.endsWith('[f][layout]') && event.target instanceof HTMLInputElement && event.target.checked) {
    const node = list.querySelector<HTMLElement>(`[data-builder-node="${CSS.escape(pane.dataset.builderPane || '')}"]`);
    const count = event.target.value.split('-').length;
    if (node) setColumns(list, node, count);
  }
  sync(list);
});
