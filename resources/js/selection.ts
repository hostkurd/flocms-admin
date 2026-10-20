const selectedInput = (root: Element) => root.querySelector<HTMLInputElement>('[data-rx-bind="selectedIds"]');
const rows = (root: Element) => [...root.querySelectorAll<HTMLInputElement>('[data-admin-select]')];

export function initializeSelection(root: Element): void {
  const standard = root.querySelector<HTMLFormElement>('[data-admin-standard-bulk]');
  if (standard) updateStandard(standard);
  const input = selectedInput(root);
  if (!input) return;
  const selected = new Set<string>(JSON.parse(input.value || '[]'));
  const pageRows = rows(root);
  pageRows.forEach(row => { row.checked = selected.has(row.value); });
  const master = root.querySelector<HTMLInputElement>('[data-admin-select-page]');
  if (master) {
    const count = pageRows.filter(row => row.checked).length;
    master.checked = count > 0 && count === pageRows.length;
    master.indeterminate = count > 0 && count < pageRows.length;
  }
  pageRows.forEach(row => row.closest('tr')?.classList.toggle('is-selected', row.checked));
  const form = root.querySelector<HTMLElement>('[data-admin-bulk]');
  const count = form?.querySelector<HTMLElement>('[data-admin-selection-count]');
  if (count) count.textContent = (form?.dataset.selectedText || ':count selected').replace(':count', String(selected.size));
  // The bulk bar appears only while records are selected.
  if (form) form.hidden = selected.size === 0;
  form?.querySelectorAll<HTMLButtonElement>('[data-admin-bulk-action], [data-admin-export-selected]').forEach(button => {
    button.disabled = selected.size === 0 || root.getAttribute('aria-busy') === 'true';
  });
}

function updateStandard(form: HTMLFormElement): void {
  const checkboxes = [...document.querySelectorAll<HTMLInputElement>(`input[form="${form.id}"][name="ids[]"]`)];
  const selected = checkboxes.filter(checkbox => checkbox.checked).length;
  const label = form.querySelector<HTMLElement>('[data-admin-selection-count]');
  if (label) label.textContent = (form.dataset.selectedText || ':count selected').replace(':count', String(selected));
  form.hidden = selected === 0;
  checkboxes.forEach(checkbox => checkbox.closest('tr')?.classList.toggle('is-selected', checkbox.checked));
  const master = document.querySelector<HTMLInputElement>('[data-admin-select-page-standard]');
  if (master) { master.checked = selected > 0 && selected === checkboxes.length; master.indeterminate = selected > 0 && selected < checkboxes.length; }
}

document.addEventListener('change', event => {
  const checkbox = event.target;
  if (checkbox instanceof HTMLInputElement && checkbox.matches('[data-admin-select-page-standard], input[form="fa-list-bulk"]')) {
    const form = document.querySelector<HTMLFormElement>('[data-admin-standard-bulk]');
    if (form) {
      if (checkbox.hasAttribute('data-admin-select-page-standard')) document.querySelectorAll<HTMLInputElement>('input[form="fa-list-bulk"][name="ids[]"]').forEach(input => { input.checked = checkbox.checked; });
      updateStandard(form);
    }
    return;
  }
  if (!(checkbox instanceof HTMLInputElement) || !checkbox.matches('[data-admin-select], [data-admin-select-page]')) return;
  const root = checkbox.closest('[data-rx-component]');
  if (!root) return;
  const input = selectedInput(root)!;
  if (root.getAttribute('aria-busy') === 'true') { initializeSelection(root); return; }
  const selected = new Set<string>(JSON.parse(input.value || '[]'));
  const affected = checkbox.hasAttribute('data-admin-select-page') ? rows(root) : [checkbox];
  affected.forEach(row => { if (checkbox.checked) selected.add(row.value); else selected.delete(row.value); });
  if (selected.size > 50) {
    initializeSelection(root);
    const message = root.querySelector<HTMLElement>('[data-rx-status]');
    if (message) message.textContent = root.querySelector<HTMLElement>('[data-admin-bulk]')?.dataset.limitText || 'Select at most 50 records.';
    return;
  }
  input.value = JSON.stringify([...selected].sort());
  initializeSelection(root);
  input.dispatchEvent(new Event('input', { bubbles: true }));
});

// Export selected (0.25): download the chosen records as CSV.
document.addEventListener('click', event => {
  const button = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-admin-export-selected]') : null;
  if (!button || button.disabled) return;
  const root = button.closest('[data-rx-component]');
  const input = root ? selectedInput(root) : null;
  const ids: string[] = input ? JSON.parse(input.value || '[]') : [];
  if (ids.length === 0) return;
  const url = new URL(button.dataset.url || '', window.location.href);
  url.searchParams.set('ids', ids.join(','));
  if (button.dataset.parent) url.searchParams.set('parent', button.dataset.parent);
  window.location.assign(url.toString());
});

// A bulk action that opens a page (0.28): go there with the chosen ids.
document.addEventListener('click', event => {
  const button = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-admin-bulk-link]') : null;
  if (!button || button.disabled) return;
  const root = button.closest('[data-rx-component]');
  const input = root ? selectedInput(root) : null;
  const ids: string[] = input ? JSON.parse(input.value || '[]') : [];
  if (ids.length === 0) return;
  const url = new URL(button.dataset.url || '', window.location.href);
  ids.forEach(id => url.searchParams.append('ids[]', id));
  window.location.assign(url.toString());
});
