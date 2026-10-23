/**
 * Grouped lists (0.38): collapse and expand a group's rows, Collapse all /
 * Expand all, and a heading checkbox that selects the group's rows on this
 * page. Without JavaScript every group stays open. Collapsed groups stay
 * collapsed while the list re-renders (a save, a Reactive update), not after
 * a reload.
 */
const collapsed = new Set<string>();

function members(id: string): HTMLTableRowElement[] {
  return [...document.querySelectorAll<HTMLTableRowElement>(`tr[data-admin-group-member="${CSS.escape(id)}"]`)];
}

function setGroup(head: HTMLTableRowElement, open: boolean): void {
  const id = head.dataset.adminGroup!;
  const button = head.querySelector<HTMLButtonElement>('[data-admin-group-toggle]');
  if (!button) return;
  button.setAttribute('aria-expanded', open ? 'true' : 'false');
  button.setAttribute('aria-label', (open ? button.dataset.collapseLabel : button.dataset.expandLabel) || button.getAttribute('aria-label') || '');
  head.classList.toggle('is-collapsed', !open);
  const hidden = head.querySelector<HTMLElement>('[data-admin-group-hidden]');
  if (hidden) hidden.hidden = open;
  members(id).forEach(row => { row.hidden = !open; });
  if (open) collapsed.delete(id); else collapsed.add(id);
}

/** Re-apply collapsed groups and the heading checkboxes after the list was drawn again. */
function restore(root: ParentNode = document): void {
  root.querySelectorAll<HTMLTableRowElement>('tr[data-admin-group]').forEach(head => {
    if (collapsed.has(head.dataset.adminGroup!)) setGroup(head, false);
    syncCheckbox(head);
  });
  root.querySelectorAll<HTMLElement>('[data-admin-groups-all]').forEach(element => { element.hidden = false; });
}

function boxes(id: string): HTMLInputElement[] {
  return members(id).map(row => row.querySelector<HTMLInputElement>('td.fa-selection-col input[type="checkbox"]')).filter((box): box is HTMLInputElement => Boolean(box));
}

function syncCheckbox(head: HTMLTableRowElement): void {
  const box = head.querySelector<HTMLInputElement>('[data-admin-select-group]');
  if (!box) return;
  const all = boxes(head.dataset.adminGroup!);
  const ticked = all.filter(item => item.checked).length;
  box.checked = all.length > 0 && ticked === all.length;
  box.indeterminate = ticked > 0 && ticked < all.length;
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const toggle = event.target.closest<HTMLButtonElement>('[data-admin-group-toggle]');
  if (toggle) {
    const head = toggle.closest<HTMLTableRowElement>('tr[data-admin-group]');
    if (head) setGroup(head, toggle.getAttribute('aria-expanded') !== 'true');
    return;
  }
  // A choice in the Group by menu closes it (the list is drawn again).
  const choice = event.target.closest<HTMLElement>('.fa-group-choice, .fa-group-directions .fa-btn');
  if (choice) { choice.closest('details')?.removeAttribute('open'); return; }
  const all = event.target.closest<HTMLButtonElement>('[data-admin-groups-collapse], [data-admin-groups-expand]');
  if (all) {
    const open = all.hasAttribute('data-admin-groups-expand');
    const list = all.closest('.fa-card, .fa-manager-list, [data-rx-component]') ?? document;
    list.querySelectorAll<HTMLTableRowElement>('tr[data-admin-group]').forEach(head => setGroup(head, open));
    all.closest('details')?.removeAttribute('open');
  }
});

document.addEventListener('change', event => {
  const box = event.target;
  if (!(box instanceof HTMLInputElement)) return;
  if (box.hasAttribute('data-admin-select-group')) {
    // Each row's own checkbox changes, so the bulk bar counts them as usual.
    boxes(box.dataset.adminSelectGroup!).forEach(item => {
      if (item.checked === box.checked) return;
      item.checked = box.checked;
      item.dispatchEvent(new Event('change', { bubbles: true }));
    });
    return;
  }
  const row = box.closest<HTMLTableRowElement>('tr[data-admin-group-member]');
  if (row) {
    const head = document.getElementById(row.dataset.adminGroupMember!) as HTMLTableRowElement | null;
    if (head) syncCheckbox(head);
  }
});

document.addEventListener('flo-reactive:updated', () => restore());
document.addEventListener('flo-admin:list-refreshed', () => restore());

export function initializeGroups(root: ParentNode): void {
  restore(root);
}
