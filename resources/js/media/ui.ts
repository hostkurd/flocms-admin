/** Small building blocks of the media screens: a confirm dialog, a prompt, a menu of folders, a status line. */
import { element, icon, type Config, type Folders, t } from './api';
import { notify } from '../notifications';

/** A modal question with OK and Cancel (Escape or the backdrop cancel). */
export function confirmDialog(cfg: Config, message: string, ok: string, danger = false): Promise<boolean> {
  return new Promise(resolve => {
    const dialog = element('dialog', 'fa-dialog fa-mb-confirm');
    const panel = element('div', 'fa-dialog-panel');
    const text = element('p', 'fa-mb-confirm-text', message);
    text.id = `fa-mb-confirm-${Math.random().toString(36).slice(2)}`;
    dialog.setAttribute('aria-labelledby', text.id);
    const actions = element('div', 'fa-mb-confirm-actions');
    const cancel = element('button', 'fa-btn', t(cfg, 'cancel'));
    cancel.type = 'button';
    const yes = element('button', `fa-btn ${danger ? 'fa-btn-danger' : 'fa-btn-primary'}`, ok);
    yes.type = 'button';
    actions.append(cancel, yes);
    panel.append(text, actions);
    dialog.append(panel);
    const opener = document.activeElement as HTMLElement | null;
    let answer = false;
    yes.addEventListener('click', () => { answer = true; dialog.close(); });
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => { dialog.remove(); opener?.focus({ preventScroll: true }); resolve(answer); });
    (document.querySelector('.fa-panel') ?? document.body).append(dialog);
    dialog.showModal();
    (danger ? cancel : yes).focus();
  });
}

/** A modal one-line text question; resolves with the trimmed text, or null when cancelled. */
export function promptDialog(cfg: Config, label: string, value: string, ok: string): Promise<string | null> {
  return new Promise(resolve => {
    const dialog = element('dialog', 'fa-dialog fa-mb-confirm');
    // Not a <form>: the panel's navigation handles form submissions.
    const form = element('div', 'fa-dialog-panel');
    const id = `fa-mb-prompt-${Math.random().toString(36).slice(2)}`;
    const caption = element('label', 'fa-label', label);
    caption.htmlFor = id;
    const input = element('input', 'fa-input');
    input.id = id; input.value = value; input.maxLength = 120; input.required = true; input.autocomplete = 'off'; input.dir = 'auto';
    const actions = element('div', 'fa-mb-confirm-actions');
    const cancel = element('button', 'fa-btn', t(cfg, 'cancel'));
    cancel.type = 'button';
    const yes = element('button', 'fa-btn fa-btn-primary', ok);
    yes.type = 'button';
    actions.append(cancel, yes);
    form.append(caption, input, actions);
    dialog.append(form);
    dialog.setAttribute('aria-label', label);
    const opener = document.activeElement as HTMLElement | null;
    let answer: string | null = null;
    const submit = () => { if (input.value.trim() === '') { input.focus(); return; } answer = input.value.trim(); dialog.close(); };
    yes.addEventListener('click', submit);
    input.addEventListener('keydown', event => { if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); submit(); } });
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { dialog.remove(); opener?.focus({ preventScroll: true }); resolve(answer); });
    (document.querySelector('.fa-panel') ?? document.body).append(dialog);
    dialog.showModal();
    input.select();
  });
}

type MenuItem = { label: string; icon?: string; danger?: boolean; disabled?: boolean; depth?: number; run: () => void };

/** The menu open now, closed properly before another opens. */
let openOne: { anchor: HTMLElement; close: (focus?: boolean) => void } | null = null;

/** A small menu under a button; arrow keys move, Escape closes and returns focus. A second click on its button closes it. */
export function openMenu(anchor: HTMLElement, items: MenuItem[], label: string): void {
  if (openOne) {
    const same = openOne.anchor === anchor;
    openOne.close();
    if (same) return;
  }
  const menu = element('div', 'fa-mb-menu');
  menu.setAttribute('role', 'menu');
  menu.setAttribute('aria-label', label);
  const buttons: HTMLButtonElement[] = [];
  for (const item of items) {
    if (item.label === '-') { menu.append(element('div', 'fa-mb-menu-sep')); continue; }
    const entry = element('button', `fa-mb-menu-item${item.danger ? ' is-danger' : ''}`);
    entry.type = 'button';
    entry.setAttribute('role', 'menuitem');
    entry.tabIndex = -1;
    entry.disabled = Boolean(item.disabled);
    if (item.depth) entry.style.setProperty('--depth', String(item.depth));
    if (item.icon) entry.insertAdjacentHTML('beforeend', icon(item.icon));
    entry.append(element('span', '', item.label));
    entry.addEventListener('click', () => { close(); item.run(); });
    buttons.push(entry);
    menu.append(entry);
  }
  const close = (focus = false) => {
    if (openOne?.close === close) openOne = null;
    menu.remove();
    document.removeEventListener('pointerdown', outside, true);
    anchor.setAttribute('aria-expanded', 'false');
    if (focus) anchor.focus();
  };
  // A click on the button itself (or its icon) is left to the button, which closes the menu.
  const outside = (event: Event) => { if (!menu.contains(event.target as Node) && !anchor.contains(event.target as Node)) close(); };
  menu.addEventListener('keydown', event => {
    const enabled = buttons.filter(entry => !entry.disabled);
    const index = enabled.indexOf(document.activeElement as HTMLButtonElement);
    if (event.key === 'ArrowDown') { event.preventDefault(); enabled[(index + 1) % enabled.length]?.focus(); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); enabled[(index - 1 + enabled.length) % enabled.length]?.focus(); }
    else if (event.key === 'Home') { event.preventDefault(); enabled[0]?.focus(); }
    else if (event.key === 'End') { event.preventDefault(); enabled[enabled.length - 1]?.focus(); }
    else if (event.key === 'Escape' || event.key === 'Tab') { event.preventDefault(); event.stopPropagation(); close(true); }
  });
  // Placed in the anchor's dialog (top layer) or the panel, under the anchor, kept on screen.
  (anchor.closest('dialog') ?? document.querySelector('.fa-panel') ?? document.body).append(menu);
  const box = anchor.getBoundingClientRect();
  const rtl = getComputedStyle(anchor).direction === 'rtl';
  menu.style.top = `${Math.min(box.bottom + 4, innerHeight - menu.offsetHeight - 8)}px`;
  const left = rtl ? box.right - menu.offsetWidth : box.left;
  menu.style.left = `${Math.max(8, Math.min(left, innerWidth - menu.offsetWidth - 8))}px`;
  anchor.setAttribute('aria-expanded', 'true');
  openOne = { anchor, close };
  setTimeout(() => document.addEventListener('pointerdown', outside, true));
  buttons.find(entry => !entry.disabled)?.focus();
}

/** The folder choices of a "Move to" menu: Unfiled, then every folder indented. */
export function folderMenu(cfg: Config, folders: Folders, current: number | null | undefined, pick: (folder: number | null) => void): MenuItem[] {
  return [
    { label: t(cfg, 'unfiled'), icon: 'folder', disabled: current === null, run: () => pick(null) },
    ...folders.tree.map(folder => ({ label: folder.name, icon: 'folder', depth: folder.depth, disabled: current === folder.id, run: () => pick(folder.id) })),
  ];
}

/** A notification (success and info close by themselves, like the server's). */
export function announce(message: string, tone: 'success' | 'danger' | 'warning' | 'info' = 'success'): void {
  if (message) notify(message, tone);
}
