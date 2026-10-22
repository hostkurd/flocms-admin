// Tabs are progressive enhancement: without JS every section remains reachable.
const activeTabs = new Map<string, string>();
function activate(group: HTMLElement, id: string, focus = false): void {
  const tabs = [...group.querySelectorAll<HTMLButtonElement>(':scope > [data-admin-tab-list] > [data-admin-tab]')];
  tabs.forEach(tab => { const active = tab.dataset.adminTab === id; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; });
  group.querySelectorAll<HTMLElement>(':scope > [data-admin-tab-panel]').forEach(panel => { panel.hidden = panel.id !== id; });
  activeTabs.set(group.id, id);
  if (focus) tabs.find(tab => tab.dataset.adminTab === id)?.focus();
}
/**
 * shownWhen() (0.32): a field shown only while another field has one of some
 * values, switched here without reloading the form (block dialogs).
 */
function valueByName(name: string): string {
  const controls = [...document.getElementsByName(name)] as HTMLInputElement[];
  const radio = controls.find(control => control.type === 'radio');
  if (radio) return controls.find(control => control.type === 'radio' && control.checked)?.value ?? '';
  const box = controls.find(control => control.type === 'checkbox');
  if (box) return box.checked ? box.value : (controls.find(control => control.type === 'hidden')?.value ?? '');
  return controls[0]?.value ?? '';
}
export function applyShown(root: ParentNode, name?: string): void {
  root.querySelectorAll<HTMLElement>(name ? `[data-shown-when="${CSS.escape(name)}"]` : '[data-shown-when]').forEach(field => {
    const values = (field.dataset.shownValues || '').split(' ');
    // When the form is shown, a field with an error stays in view, so the error can be seen and fixed;
    // choosing another value later hides it as usual.
    if (!name && field.classList.contains('has-error')) { field.hidden = false; return; }
    field.hidden = !values.includes(valueByName(field.dataset.shownWhen || ''));
  });
}
document.addEventListener('change', event => {
  const target = event.target;
  if ((target instanceof HTMLInputElement || target instanceof HTMLSelectElement) && target.name) applyShown(document, target.name);
});

export function initializeForms(root: HTMLElement): void {
  applyShown(root);
  root.querySelectorAll<HTMLElement>('[data-admin-tabs]').forEach(group => {
    const list = group.querySelector<HTMLElement>(':scope > [data-admin-tab-list]');
    const tabs = [...group.querySelectorAll<HTMLButtonElement>(':scope > [data-admin-tab-list] > [data-admin-tab]')];
    if (!list || !tabs.length) return;
    list.hidden = false; list.setAttribute('role', 'tablist'); group.dataset.adminTabsReady = 'true';
    tabs.forEach(tab => {
      tab.setAttribute('role', 'tab'); tab.setAttribute('aria-controls', tab.dataset.adminTab!);
      const panel = document.getElementById(tab.dataset.adminTab!);
      panel?.setAttribute('role', 'tabpanel'); panel?.setAttribute('aria-labelledby', tab.id);
    });
    const invalid = [...group.querySelectorAll<HTMLElement>(':scope > [data-admin-tab-panel]')].find(panel => panel.querySelector('[aria-invalid=true],.has-error'));
    const previous = activeTabs.get(group.id);
    activate(group, invalid?.id || (tabs.some(tab => tab.dataset.adminTab === previous) ? previous! : tabs[0].dataset.adminTab!));
  });
  root.querySelectorAll<HTMLButtonElement>('button[name="_refresh"]').forEach(button => { button.hidden = true; });
  // Colour swatches (0.33): the colour is set here, as a strict CSP allows no inline styles.
  root.querySelectorAll<HTMLElement>('[data-swatch]').forEach(swatch => {
    const colour = swatch.dataset.swatch || '';
    if (/^#(?:[0-9a-f]{3}){1,2}$/i.test(colour)) swatch.style.backgroundColor = colour;
  });
}
document.addEventListener('click', event => {
  const tab = event.target instanceof Element ? event.target.closest<HTMLButtonElement>('[data-admin-tab]') : null;
  const group = tab?.closest<HTMLElement>('[data-admin-tabs]');
  if (tab && group) activate(group, tab.dataset.adminTab!, true);
});
document.addEventListener('keydown', event => {
  if (!(event.target instanceof HTMLButtonElement) || !event.target.matches('[data-admin-tab]')) return;
  const group = event.target.closest<HTMLElement>('[data-admin-tabs]')!;
  const tabs = [...group.querySelectorAll<HTMLButtonElement>(':scope > [data-admin-tab-list] > [data-admin-tab]')];
  const rtl = getComputedStyle(group).direction === 'rtl';
  const current = tabs.indexOf(event.target); let next = current;
  if (event.key === 'Home') next = 0;
  else if (event.key === 'End') next = tabs.length - 1;
  else if (event.key === 'ArrowRight') next = (current + (rtl ? -1 : 1) + tabs.length) % tabs.length;
  else if (event.key === 'ArrowLeft') next = (current + (rtl ? 1 : -1) + tabs.length) % tabs.length;
  else return;
  event.preventDefault(); event.stopPropagation(); activate(group, tabs[next].dataset.adminTab!, true);
});
// Use change (rather than per-keystroke refresh) to retain predictable focus and drafts.
document.addEventListener('change', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement || input instanceof HTMLTextAreaElement) || !input.closest('[data-admin-live]')) return;
  if (input.closest('.fa-lang-dialog') || input.matches('[data-relation-search]')) return;
  const form = input.closest<HTMLFormElement>('form');
  const button = form?.querySelector<HTMLButtonElement>('[data-admin-form-refresh]');
  if (!form || !button || form.closest('[aria-busy=true]')) return;
  if (form.hasAttribute('data-admin-draft')) {
    // The submit capture handler packages all fields before dispatching the explicit RPC.
    form.dataset.adminRefreshing = 'true';
    form.requestSubmit();
  } else form.requestSubmit(button);
});
