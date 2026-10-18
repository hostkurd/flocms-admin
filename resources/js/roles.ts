/**
 * The Roles page (0.21): the permission grid's group switches, View ticked with
 * Create/Edit/Delete, live counts, and adding names under Other. The server
 * applies the same rules; without JavaScript the page still saves.
 */

const PATTERN = /^(\*|[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*(\.\*)?)$/;

const roleForm = (element: Element | null) => element?.closest<HTMLFormElement>('form[data-admin-role]') ?? null;

function boxes(group: Element): HTMLInputElement[] {
  return [...group.querySelectorAll<HTMLInputElement>('input.fa-role-check')];
}

function updateCount(group: Element): void {
  const count = group.querySelector<HTMLElement>('[data-role-count]');
  const template = count?.dataset.template;
  if (!count || !template) return;
  const all = boxes(group);
  const on = group.querySelector<HTMLInputElement>('[data-role-all]')?.checked ?? false;
  const granted = all.filter(box => box.checked).length;
  count.textContent = template.replace(':count', String(granted)).replace(':total', String(all.length));
}

/** As FloCMS\\Core\\Auth matches a granted pattern against a permission. */
function matches(granted: string, name: string): boolean {
  return granted === '*' || granted === name || (granted.endsWith('.*') && name.startsWith(granted.slice(0, -1)));
}

function applySwitch(group: HTMLElement, on: boolean): void {
  group.toggleAttribute('data-role-all-on', on);
  group.querySelector('.fa-role-all')?.classList.toggle('is-on', on);
  const form = roleForm(group);
  const wildcard = group.querySelector<HTMLInputElement>('[data-role-all]')?.value || '';
  // A switch covers every box it matches, in any group ("All content" also covers the media library).
  const touched = new Set<Element>([group]);
  for (const box of form ? [...form.querySelectorAll<HTMLInputElement>('input.fa-role-check')] : boxes(group)) {
    if (!matches(wildcard, box.value)) continue;
    // A box the person may not change stays as the server drew it.
    if (box.dataset.roleLocked === undefined) box.dataset.roleLocked = box.disabled && !box.classList.contains('is-covered') ? '1' : '0';
    if (box.dataset.roleLocked === '1') continue;
    if (on) { box.checked = true; box.disabled = true; box.classList.add('is-covered'); }
    else { box.disabled = false; box.classList.remove('is-covered'); }
    const owner = box.closest('[data-role-group]');
    if (owner) touched.add(owner);
  }
  group.querySelector<HTMLElement>('[data-role-covered-note]')?.toggleAttribute('hidden', !on);
  group.querySelector<HTMLElement>('[data-role-view-note]')?.toggleAttribute('hidden', on);
  touched.forEach(updateCount);
}

function addPattern(form: HTMLFormElement): void {
  const input = form.querySelector<HTMLInputElement>('[data-role-add]');
  const chips = form.querySelector<HTMLElement>('[data-role-chips]');
  if (!input || !chips) return;
  const names = input.value.split(/[\s,]+/).map(name => name.trim()).filter(Boolean);
  const rejected: string[] = [];
  for (const name of names) {
    if (!PATTERN.test(name) || name.length > 120) { rejected.push(name); continue; }
    const existing = [...form.querySelectorAll<HTMLInputElement>('input[name="perm[]"]')].find(box => box.value === name);
    if (existing) { if (!existing.disabled) { existing.checked = true; existing.dispatchEvent(new Event('change', { bubbles: true })); } continue; }
    const label = document.createElement('label');
    label.className = 'fa-role-chip';
    const box = document.createElement('input');
    box.type = 'checkbox'; box.name = 'perm[]'; box.value = name; box.checked = true;
    box.setAttribute('aria-label', (chips.dataset.removeLabel || ':name').replace(':name', name));
    const text = document.createElement('span');
    text.className = 'fa-ltr'; text.textContent = name;
    label.append(box, text);
    chips.append(label);
  }
  input.value = rejected.join(' ');
  input.setAttribute('aria-invalid', rejected.length > 0 ? 'true' : 'false');
  input.dispatchEvent(new Event('input', { bubbles: true }));
}

document.addEventListener('change', event => {
  const target = event.target;
  if (!(target instanceof HTMLInputElement) || !roleForm(target)) return;
  const group = target.closest<HTMLElement>('[data-role-group]');
  if (!group) return;
  if (target.matches('[data-role-all]')) { applySwitch(group, target.checked); return; }
  if (!target.matches('input.fa-role-check')) return;
  const row = target.closest('[data-role-row]');
  const ability = target.dataset.roleAbility;
  if (row && ability) {
    const view = row.querySelector<HTMLInputElement>('input[data-role-ability="view"]');
    if (ability !== 'view' && target.checked && view && !view.checked && !view.disabled) view.checked = true;
    if (ability === 'view' && !target.checked) {
      row.querySelectorAll<HTMLInputElement>('input.fa-role-check:not([data-role-ability="view"])').forEach(box => { if (!box.disabled) box.checked = false; });
    }
  }
  updateCount(group);
});

document.addEventListener('click', event => {
  const button = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-role-add-button]') : null;
  const form = roleForm(button);
  if (button && form) { event.preventDefault(); addPattern(form); }
});

document.addEventListener('keydown', event => {
  const input = event.target;
  if (event.key !== 'Enter' || !(input instanceof HTMLInputElement) || !input.matches('[data-role-add]')) return;
  const form = roleForm(input);
  if (!form || input.value.trim() === '') return;
  event.preventDefault();
  addPattern(form);
});

/** Show the Add button (the field also works without it: its names are saved with the role). */
export function initializeRoles(root: HTMLElement): void {
  root.querySelectorAll<HTMLButtonElement>('form[data-admin-role] [data-role-add-button]').forEach(button => { button.hidden = false; });
}
