/**
 * Editable cells of the list (0.38): switches, selects and text inputs that
 * save one value at a time. Each cell is a small form that also works
 * without JavaScript (a Save button and a full page reload); this script
 * sends it in the background, shows saving / saved / error / conflict, and
 * refreshes the list's totals and groups afterwards.
 */
import { notify } from './notifications';

document.documentElement.classList.add('fa-js-cells');

type Answer = { ok: boolean; html?: string | null; message?: string; conflict?: boolean; confirm?: boolean };

const SAVED_FOR = 1500;
let lastSaved: { id: string; until: number } | null = null;

const panel = () => document.querySelector<HTMLElement>('.fa-panel');
const text = (key: string, fallback: string) => panel()?.dataset[key] || fallback;

function cellOf(element: Element | null): HTMLFormElement | null {
  return element?.closest<HTMLFormElement>('form[data-admin-cell]') ?? null;
}

function controlOf(form: HTMLFormElement): HTMLElement | null {
  return form.querySelector<HTMLElement>('[data-admin-cell-switch], [data-admin-cell-select], [data-admin-cell-input]');
}

function status(form: HTMLFormElement, message: string, kind: '' | 'saving' | 'saved' | 'error'): void {
  const element = form.querySelector<HTMLElement>('[data-admin-cell-status]');
  if (element) element.textContent = message;
  form.classList.toggle('is-saving', kind === 'saving');
  form.classList.toggle('is-saved', kind === 'saved');
  form.classList.toggle('is-error', kind === 'error');
}

function busy(form: HTMLFormElement, on: boolean): void {
  form.setAttribute('aria-busy', on ? 'true' : 'false');
  // aria-disabled, not disabled: a disabled control would lose the keyboard focus.
  form.querySelectorAll<HTMLButtonElement | HTMLSelectElement | HTMLInputElement>('button, select, input:not([type="hidden"])').forEach(control => {
    if (control instanceof HTMLInputElement) control.readOnly = on;
    if (on) control.setAttribute('aria-disabled', 'true'); else control.removeAttribute('aria-disabled');
  });
}

/* ---- Text cells: open, cancel, save ------------------------------------ */

function open(form: HTMLFormElement): void {
  if (form.classList.contains('is-editing') || form.getAttribute('aria-busy') === 'true') return;
  const input = form.querySelector<HTMLInputElement>('[data-admin-cell-input]');
  if (!input) return;
  input.dataset.original = input.dataset.original ?? input.value;
  form.classList.add('is-editing');
  form.closest('tr')?.classList.add('is-cell-editing');
  input.focus();
  input.select();
}

function close(form: HTMLFormElement, restore: boolean): void {
  const input = form.querySelector<HTMLInputElement>('[data-admin-cell-input]');
  if (input && restore && input.dataset.original !== undefined) input.value = input.dataset.original;
  form.classList.remove('is-editing');
  form.closest('tr')?.classList.remove('is-cell-editing');
  if (restore) status(form, '', '');
}

function changed(form: HTMLFormElement): boolean {
  const input = form.querySelector<HTMLInputElement>('[data-admin-cell-input]');
  return Boolean(input && input.dataset.original !== undefined && input.value !== input.dataset.original);
}

/** The editable controls of the list, in reading order (Tab from a text cell goes to the next one). */
function neighbour(form: HTMLFormElement, step: 1 | -1): HTMLElement | null {
  const table = form.closest('table');
  if (!table) return null;
  const all = [...table.querySelectorAll<HTMLElement>('[data-admin-cell-display], [data-admin-cell-switch], [data-admin-cell-select]')]
    .filter(element => element.offsetParent !== null);
  const own = form.querySelector<HTMLElement>('[data-admin-cell-display]');
  const index = own ? all.indexOf(own) : -1;
  return index < 0 ? null : all[index + step] ?? null;
}

/* ---- Sending ------------------------------------------------------------ */

async function send(form: HTMLFormElement, submitter: HTMLElement | null): Promise<void> {
  if (form.getAttribute('aria-busy') === 'true') return;
  const data = new FormData(form);
  const toggle = submitter?.hasAttribute('data-admin-cell-switch') ? submitter as HTMLButtonElement : null;
  if ((form as any)._confirmed) data.set('confirmed', '1');
  data.set('_prefix', form.dataset.prefix || 'fa-cell');
  const select = form.querySelector<HTMLSelectElement>('[data-admin-cell-select]');
  const previous = select?.dataset.current;
  const hadFocus = form.contains(document.activeElement);
  // The switch moves at once; it moves back if the save fails.
  if (toggle) toggle.setAttribute('aria-checked', toggle.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
  busy(form, true);
  status(form, text('adminCellSaving', 'Saving…'), 'saving');
  let response: Response;
  let answer: Answer;
  try {
    response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store',
      headers: { 'X-Flo-Admin-Cell': '1', Accept: 'application/json' } });
    answer = await response.json() as Answer;
  } catch {
    fail(form, toggle, previous, text('adminError', 'Unable to save. Please try again.'));
    return;
  }
  if (response.ok && answer.ok && answer.html) {
    const next = replace(form, answer.html, hadFocus);
    if (next) saved(next);
    refreshList(next ?? form);
    return;
  }
  if (answer.confirm) {
    // No dialog could ask: the server's confirm page does.
    busy(form, false);
    HTMLFormElement.prototype.submit.call(form);
    return;
  }
  if (answer.conflict) {
    const message = answer.message || text('adminCellConflict', 'Somebody changed this value meanwhile.');
    const next = answer.html ? replace(form, answer.html, hadFocus) : null;
    const target = next ?? form;
    busy(target, false);
    target.classList.add('is-conflict');
    status(target, message, 'error');
    notify(message, 'warning');
    // Nothing was saved: the list is not redrawn, so the message stays beside the cell.
    return;
  }
  fail(form, toggle, previous, answer.message || text('adminError', 'Unable to save. Please try again.'), response.status === 422);
}

function fail(form: HTMLFormElement, toggle: HTMLButtonElement | null, previous: string | undefined, message: string, invalid = false): void {
  busy(form, false);
  if (toggle) toggle.setAttribute('aria-checked', toggle.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
  const select = form.querySelector<HTMLSelectElement>('[data-admin-cell-select]');
  if (select && previous !== undefined) { select.value = previous; colour(select); }
  status(form, message, 'error');
  const input = form.querySelector<HTMLInputElement>('[data-admin-cell-input]');
  if (input && invalid) {
    // The typed value stays, with the message under it.
    input.setAttribute('aria-invalid', 'true');
    form.classList.add('is-editing');
    input.focus();
  } else {
    notify(message, 'danger');
    (toggle ?? select)?.focus();
  }
}

/** Swap in the server's new cell, keeping focus on its control. */
function replace(form: HTMLFormElement, html: string, focused: boolean): HTMLFormElement | null {
  const template = document.createElement('template');
  template.innerHTML = html.trim();
  const next = template.content.firstElementChild;
  if (!(next instanceof HTMLFormElement) || !next.hasAttribute('data-admin-cell')) return null;
  form.closest('tr')?.classList.remove('is-cell-editing');
  form.replaceWith(next);
  prepare(next);
  if (focused) (next.querySelector<HTMLElement>('[data-admin-cell-display], [data-admin-cell-switch], [data-admin-cell-select]'))?.focus();
  return next;
}

function saved(form: HTMLFormElement): void {
  status(form, text('adminCellSaved', 'Saved'), 'saved');
  const control = controlOf(form);
  if (control?.id) lastSaved = { id: control.id, until: Date.now() + SAVED_FOR };
  setTimeout(() => { if (form.isConnected && form.classList.contains('is-saved')) status(form, '', ''); }, SAVED_FOR);
}

/* ---- Totals and groups after a save ------------------------------------- */

function refreshList(form: HTMLFormElement): void {
  const wrap = form.closest<HTMLElement>('[data-admin-cells-refresh]');
  if (!wrap) return;
  // A Reactive list re-renders itself; a page list fetches its own page again.
  const component = form.closest<HTMLElement>('[data-rx-component]');
  if (component) {
    component.querySelector<HTMLButtonElement>('[data-admin-cells-reload]')?.click();
    return;
  }
  void reloadTable(wrap);
}

async function reloadTable(wrap: HTMLElement): Promise<void> {
  try {
    const response = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Flo-Admin': 'navigate' } });
    if (!response.ok) return;
    const next = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector<HTMLElement>(`#${CSS.escape(wrap.id)}`);
    if (!next || !wrap.isConnected) return;
    // Never take over a cell that is being edited or saved.
    if (wrap.querySelector('form[data-admin-cell].is-editing, form[data-admin-cell][aria-busy="true"]')) return;
    const focusedId = (document.activeElement as HTMLElement | null)?.id || '';
    next.querySelectorAll('script').forEach(script => script.remove());
    wrap.replaceWith(next);
    next.querySelectorAll<HTMLFormElement>('form[data-admin-cell]').forEach(prepare);
    if (focusedId) document.getElementById(focusedId)?.focus();
    restoreSaved();
    document.dispatchEvent(new CustomEvent('flo-admin:list-refreshed', { detail: { wrap: next } }));
  } catch {
    // The cell itself is saved; the totals catch up on the next visit.
  }
}

/** After a list re-render, the cell just saved keeps its "Saved" mark for the rest of its time. */
function restoreSaved(): void {
  if (!lastSaved || Date.now() > lastSaved.until) return;
  const control = document.getElementById(lastSaved.id);
  const form = cellOf(control);
  if (!form) return;
  status(form, text('adminCellSaved', 'Saved'), 'saved');
  const left = lastSaved.until - Date.now();
  setTimeout(() => { if (form.isConnected) status(form, '', ''); }, left);
}

/* ---- Select colours ------------------------------------------------------ */

function colour(select: HTMLSelectElement): void {
  const wrap = select.closest<HTMLElement>('[data-admin-cell-select-wrap]');
  if (!wrap) return;
  const option = select.selectedOptions[0];
  [...wrap.classList].filter(name => name.startsWith('fa-badge-')).forEach(name => wrap.classList.remove(name));
  wrap.classList.add(`fa-badge-${option?.dataset.color || 'gray'}`);
}

function prepare(form: HTMLFormElement): void {
  const select = form.querySelector<HTMLSelectElement>('[data-admin-cell-select]');
  if (select) select.dataset.current = select.value;
}

/* ---- Events --------------------------------------------------------------- */

// Bubbling, after the confirmation dialog (legacy.js) had its chance to stop the submit.
document.addEventListener('submit', event => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-admin-cell') || event.defaultPrevented) return;
  event.preventDefault();
  void send(form, (event.submitter as HTMLElement | null) ?? null);
});

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const display = event.target.closest<HTMLElement>('[data-admin-cell-display]');
  if (display) { const form = cellOf(display); if (form) { event.preventDefault(); open(form); } return; }
  const cancel = event.target.closest<HTMLElement>('[data-admin-cell-cancel]');
  if (cancel) {
    const form = cellOf(cancel);
    if (form) { event.preventDefault(); close(form, true); form.querySelector<HTMLElement>('[data-admin-cell-display]')?.focus(); }
  }
});

let asking: HTMLSelectElement | null = null;

document.addEventListener('change', event => {
  const select = event.target;
  if (!(select instanceof HTMLSelectElement) || !select.hasAttribute('data-admin-cell-select')) return;
  const form = cellOf(select);
  if (!form) return;
  if (form.getAttribute('aria-busy') === 'true') { select.value = select.dataset.current ?? select.value; return; }
  colour(select);
  // With a confirmation the dialog runs on submit; cancelling it restores the old choice.
  if (form.hasAttribute('data-confirm')) asking = select;
  form.requestSubmit();
});

// The confirmation dialog closed: a select whose change was not confirmed goes back.
document.addEventListener('close', event => {
  if (!(event.target instanceof HTMLDialogElement) || event.target.id !== 'fa-confirm' || !asking) return;
  const select = asking;
  asking = null;
  setTimeout(() => {
    const form = cellOf(select);
    if (select.isConnected && form && form.getAttribute('aria-busy') !== 'true' && select.dataset.current !== undefined) { select.value = select.dataset.current; colour(select); }
  }, 0);
}, true);

document.addEventListener('keydown', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-admin-cell-input')) return;
  const form = cellOf(input);
  if (!form || form.getAttribute('aria-busy') === 'true') return;
  if (event.key === 'Escape') {
    event.preventDefault();
    event.stopPropagation();
    close(form, true);
    form.querySelector<HTMLElement>('[data-admin-cell-display]')?.focus();
  } else if (event.key === 'Enter') {
    event.preventDefault();
    if (changed(form)) form.requestSubmit(); else { close(form, false); form.querySelector<HTMLElement>('[data-admin-cell-display]')?.focus(); }
  } else if (event.key === 'Tab') {
    const target = neighbour(form, event.shiftKey ? -1 : 1);
    if (!target) return;
    event.preventDefault();
    if (changed(form)) form.requestSubmit(); else close(form, false);
    target.focus();
  }
});

// Leaving a text cell saves it (an unchanged value just closes the input).
document.addEventListener('focusout', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-admin-cell-input')) return;
  const form = cellOf(input);
  if (!form || !form.classList.contains('is-editing') || form.getAttribute('aria-busy') === 'true') return;
  // Moving to the cell's own Cancel/Save buttons (phones) keeps it open.
  const next = (event as FocusEvent).relatedTarget;
  if (next instanceof Node && form.contains(next)) return;
  if (document.querySelector('#fa-confirm[open]')) return;
  if (changed(form)) form.requestSubmit(); else close(form, false);
});

document.addEventListener('flo-reactive:updated', () => {
  document.querySelectorAll<HTMLFormElement>('form[data-admin-cell]').forEach(prepare);
  restoreSaved();
});

export function initializeCells(root: ParentNode): void {
  root.querySelectorAll<HTMLFormElement>('form[data-admin-cell]').forEach(prepare);
}
