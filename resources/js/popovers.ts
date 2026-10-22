/*
 * Row "More" menus and the Filters popover are native <details> elements, so
 * they open and close without JavaScript. This adds the behaviour people expect
 * from a menu: one open at a time, close on outside click or Escape, close after
 * choosing an item, focus back on the menu button, and staying open while a
 * Reactive update re-renders the list underneath.
 */
const SELECTOR = 'details[data-admin-popover]';
let lastMenu: string | null = null;
const openBeforeUpdate = new Set<string>();

function summary(details: HTMLDetailsElement): HTMLElement | null {
  return details.querySelector<HTMLElement>(':scope > summary');
}

function closeAll(except?: Element | null): void {
  document.querySelectorAll<HTMLDetailsElement>(`${SELECTOR}[open]`).forEach(details => {
    if (details !== except && !details.contains(except ?? null)) details.open = false;
  });
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const owner = event.target.closest<HTMLDetailsElement>(SELECTOR);
  closeAll(owner);
  if (!owner) return;
  // Open a row menu and pin its panel in the same frame: left to the browser,
  // the panel is painted once inside the table first and the page scrollbar flickers.
  const toggle = summary(owner);
  if (toggle && !event.defaultPrevented && event.target.closest('summary') === toggle && !owner.open && owner.matches('details.fa-menu')) {
    event.preventDefault();
    owner.open = true;
    place(owner);
    return;
  }
  // Choosing a menu item closes the menu; remember it so focus can return to
  // its button when the action's dialog closes.
  const item = event.target.closest('.fa-menu-item:not(.is-disabled)');
  if (item && owner.classList.contains('fa-menu')) {
    lastMenu = owner.id || null;
    owner.open = false;
  }
});

document.addEventListener('keydown', event => {
  if (event.key !== 'Escape' || event.defaultPrevented) return;
  const open = [...document.querySelectorAll<HTMLDetailsElement>(`${SELECTOR}[open]`)];
  const details = open.find(item => item.contains(document.activeElement)) ?? open[open.length - 1];
  if (!details) return;
  event.preventDefault();
  details.open = false;
  summary(details)?.focus();
});

// Applying filters closes the popover; validation errors re-open it from the server.
document.addEventListener('submit', event => {
  const form = event.target;
  if (form instanceof HTMLFormElement && (form.hasAttribute('data-admin-filters') || form.hasAttribute('data-admin-export'))) {
    const details = form.closest<HTMLDetailsElement>(SELECTOR);
    if (details) details.open = false;
  }
}, true);

document.addEventListener('flo-reactive:before-update', event => {
  openBeforeUpdate.clear();
  (event.target as Element).querySelectorAll<HTMLDetailsElement>(`${SELECTOR}[open]`).forEach(details => {
    if (details.id) openBeforeUpdate.add(details.id);
  });
});

document.addEventListener('flo-reactive:updated', event => {
  // DOM morphing updates attributes, not what a control currently shows. Reset
  // closed filter popovers to the filters the server just applied, so a removed
  // chip cannot come back with the next "Apply".
  (event.target as Element).querySelectorAll<HTMLDetailsElement>('details.fa-popover[data-admin-popover]').forEach(details => {
    if (openBeforeUpdate.has(details.id)) return;
    details.querySelectorAll<HTMLSelectElement>('select[data-admin-filter]').forEach(select => {
      const chosen = [...select.options].find(option => option.defaultSelected) ?? select.options[0];
      if (chosen) select.value = chosen.value;
    });
    details.querySelectorAll<HTMLInputElement>('input[data-admin-filter]').forEach(input => { if (input.type === 'checkbox') input.checked = input.defaultChecked; else input.value = input.defaultValue; });
  });
  openBeforeUpdate.forEach(id => {
    const details = document.getElementById(id);
    if (details instanceof HTMLDetailsElement) { details.open = true; if (details.matches('details.fa-menu')) place(details); }
  });
  openBeforeUpdate.clear();
});

// A dialog opened from a menu item cannot return focus to that item (the menu
// is closed again), so focus the menu's button instead, like any menu.
document.addEventListener('flo-reactive:dialog-closed', () => {
  const id = lastMenu;
  if (!id) return;
  setTimeout(() => {
    const active = document.activeElement;
    if (active && active !== document.body && active.isConnected && active.closest('dialog') === null) return;
    const details = document.getElementById(id);
    if (details instanceof HTMLDetailsElement) summary(details)?.focus();
    lastMenu = null;
  }, 0);
});

// Row menus live inside the table's horizontal scroll box, which would clip an
// absolutely positioned panel. While open, pin the panel to the viewport next
// to its button (opening upwards near the bottom edge).
function place(details: HTMLDetailsElement): void {
  const panel = details.querySelector<HTMLElement>(':scope > .fa-menu-panel');
  const button = summary(details);
  if (!panel || !button) return;
  if (!details.open) { panel.removeAttribute('style'); delete panel.dataset.placed; return; }
  // Out of the page's flow first: in its place in the table, the open panel can
  // make the page's scrollbar appear for a moment and shift the button.
  panel.style.position = 'fixed';
  panel.style.insetInline = 'auto';
  panel.style.insetBlock = 'auto';
  // Measured at the viewport's corner: at its old place near the edge the panel
  // would be squeezed and measure too narrow (and then overflow the edge).
  panel.style.top = '0px';
  panel.style.left = '0px';
  // Just opened: hidden until its place is settled in the next frame, so it never jumps.
  if (!panel.dataset.placed) {
    panel.dataset.placed = '1';
    panel.style.visibility = 'hidden';
    requestAnimationFrame(() => { place(details); panel.style.visibility = ''; });
  }
  // A fixed panel inside an element with a transform, filter or containment is placed
  // from that element's corner, not the viewport's: measure where 0,0 really is.
  const origin = panel.getBoundingClientRect();
  const rect = button.getBoundingClientRect();
  const width = panel.offsetWidth, height = panel.offsetHeight;
  // The page's width without its scrollbar; 12px from the edge.
  const viewWidth = document.documentElement.clientWidth || window.innerWidth;
  const viewHeight = document.documentElement.clientHeight || window.innerHeight;
  let top = rect.bottom + 4;
  if (top + height > viewHeight - 12 && rect.top - 4 - height >= 12) top = rect.top - 4 - height;
  const rtl = getComputedStyle(details).direction === 'rtl';
  let left = rtl ? rect.left : rect.right - width;
  left = Math.max(12, Math.min(left, viewWidth - width - 12));
  panel.style.top = `${Math.round(top - origin.top)}px`;
  panel.style.left = `${Math.round(left - origin.left)}px`;
  // Tooltips of greyed-out items go beside the menu when there is room, else below the item.
  const room = rtl ? viewWidth - (left + width) : left;
  panel.classList.toggle('is-tips-below', room < 240);
}

document.addEventListener('toggle', event => {
  const details = event.target;
  if (details instanceof HTMLDetailsElement && details.matches('details.fa-menu[data-admin-popover]')) place(details);
}, true);

// Keep an open menu attached to its button while the page or table scrolls.
const reposition = () => document.querySelectorAll<HTMLDetailsElement>('details.fa-menu[data-admin-popover][open]').forEach(place);
window.addEventListener('resize', reposition);
document.addEventListener('scroll', reposition, { capture: true, passive: true });

// A greyed-out menu item shows why on hover and focus (CSS); a tap shows it too.
document.addEventListener('click', event => {
  const target = event.target instanceof Element ? event.target : null;
  const item = target?.closest<HTMLElement>('[data-admin-tip]');
  document.querySelectorAll<HTMLElement>('[data-admin-tip].is-tip-open').forEach(open => { if (open !== item) open.classList.remove('is-tip-open'); });
  if (!item) return;
  event.preventDefault();
  item.classList.toggle('is-tip-open');
  item.focus();
});
