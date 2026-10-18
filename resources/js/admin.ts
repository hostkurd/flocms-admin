import { hasFiles, stageUploads } from './uploads';
import { receiveNotifications } from './notifications';
import { initializeLegacy } from './legacy.js';
import { initializeTranslations } from './translations';
import { initializeSelection } from './selection';
import { initializeLogin } from './login';
import { initializeForms } from './forms';
import { initializeEditors } from './editors';
import { initializeSettings } from './settings';
import { initializeRelations } from './relations';
import { initializeReorder } from './reorder';
import { initializeMedia } from './media';
import { initializeFileDrops } from './filedrop';
import { initializeRoles } from './roles';
import './popovers';
import './dialogs';
import './search';
import { startProgress, finishProgress } from './progress';
import { initializeRecord, isDirty, confirmLeave } from './record';
import '../assets/admin.css';
import '../assets/rich-text.css';

let navigating = false;
let navigation: AbortController | undefined;
let displayedUrl = location.href;
const panel = () => document.querySelector<HTMLElement>('.fa-panel');
const enabled = () => panel()?.dataset.adminNavigation === 'true';
const busy = () => Boolean(panel()?.querySelector('[data-rx-component][aria-busy="true"]'));

function internal(value: string): URL | undefined {
  const root = panel();
  if (!root) return;
  const url = new URL(value, location.href);
  const base = new URL(root.dataset.adminBase!, location.href);
  if (url.origin !== location.origin || !url.pathname.startsWith(base.pathname + '/')) return;
  // Authentication/logout and application controllers keep ordinary navigation.
  const route = url.pathname.slice(base.pathname.length + 1).split('/')[0];
  const known = new Set([...root.querySelectorAll<HTMLAnchorElement>('.fa-nav a, .fa-topbar-user')]
    .map(a => new URL(a.href).pathname.slice(base.pathname.length + 1).split('/')[0]));
  if (!known.has(route)) return;
  return url;
}

/** Announce a message; "busy" ones are for screen readers only (the progress bar shows loading). */
function status(message: string, busy = false): void {
  const element = panel()?.querySelector<HTMLElement>('[data-admin-status]');
  if (element) { element.textContent = message; element.classList.toggle('is-busy', busy); element.tabIndex = -1; element.focus(); }
}

function initialize(): void {
  initializeLogin();
  initializeLegacy();
  const root = panel();
  if (!root) return;
  initializeForms(root);
  initializeTranslations(root);
  initializeEditors(root);
  initializeSelection(root);
  initializeRecord(root);
  initializeSettings(root);
  initializeRelations(root);
  initializeRoles(root);
  initializeReorder(root);
  initializeMedia(root);
  initializeFileDrops(root);
  document.title = root.dataset.adminTitle || document.title;
  document.documentElement.lang = root.lang;
  document.documentElement.dir = root.dir;
  updateMenu(false);
  receiveNotifications();
}

async function navigate(url: URL, options: { method?: string; body?: URLSearchParams | FormData; replace?: boolean; history?: boolean } = {}): Promise<void> {
  if (navigating || busy()) { status(panel()?.dataset.adminBusy || 'Please wait.'); return; }
  navigating = true;
  navigation?.abort();
  navigation = new AbortController();
  const current = panel();
  const focusedId = options.body?.has('_refresh') ? document.activeElement?.id : '';
  current?.setAttribute('aria-busy', 'true');
  status(current?.dataset.adminBusy || 'Loading…', true);
  startProgress();
  if (current) current.inert = true;
  const timeout = setTimeout(() => navigation?.abort(), 30000);
  try {
    const response = await fetch(url, { method: options.method || 'GET', body: options.body, credentials: 'same-origin',
      cache: 'no-store', headers: { 'X-Flo-Admin': 'navigate' }, signal: navigation.signal });
    const html = await response.text();
    const documentNext = new DOMParser().parseFromString(html, 'text/html');
    const next = documentNext.querySelector<HTMLElement>('.fa-panel');
    if (!response.ok || !next) {
      // A normal login redirect needs the site's login UI, outside this panel.
      if (response.redirected && !next && new URL(response.url).origin === location.origin) { location.assign(response.url); return; }
      throw new Error('Invalid panel response');
    }
    // Navigation never evaluates HTML scripts and never caches private page bodies.
    next.querySelectorAll('script, link[rel="stylesheet"]').forEach(element => element.remove());
    current?.replaceWith(next);
    syncBrandColour(documentNext);
    initialize();
    const finalUrl = response.url || url.href;
    if (options.history !== false) history[options.replace || options.method === 'POST' ? 'replaceState' : 'pushState'](null, '', finalUrl);
    displayedUrl = finalUrl;
    const invalid = next.querySelector<HTMLElement>('[aria-invalid="true"]');
    (invalid || (focusedId ? next.querySelector<HTMLElement>(`#${CSS.escape(focusedId)}`) : null) || next.querySelector<HTMLElement>('#fa-content'))?.focus();
    if (!invalid) window.scrollTo(0, 0);
    document.dispatchEvent(new CustomEvent('flo-admin:navigated'));
  } catch {
      if (current) current.inert = false;
      status(panel()?.dataset.adminError || 'Unable to load this page. Please try again.');
      // A failed popstate must not show old content under a different URL.
      if (options.history === false) history.replaceState(null, '', displayedUrl);
  } finally {
    finishProgress();
    clearTimeout(timeout);
    navigating = false;
    if (current) current.inert = false;
    if (panel()) panel()!.inert = false;
    panel()?.removeAttribute('aria-busy');
  }
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  if (event.target.closest('[data-admin-menu]')) { updateMenu(!panel()?.classList.contains('is-menu-open')); return; }
  if (event.target.closest('[data-admin-menu-close]')) { updateMenu(false); return; }
  const anchor = event.target.closest<HTMLAnchorElement>('a[href]');
  if (!enabled() || !anchor || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey
    || anchor.target || anchor.hasAttribute('download') || anchor.hasAttribute('data-no-navigate') || anchor.hash) return;
  const url = internal(anchor.href);
  if (!url || (event.target instanceof Element && event.target.closest('[data-admin-file-link]'))) return;
  event.preventDefault();
  // Unsaved changes on a record page: ask first.
  if (isDirty()) { void confirmLeave().then(leave => { if (leave) void navigate(url); }); return; }
  void navigate(url);
});

// Capture runs before Reactive's submit handler; pack ordinary Form controls into
// the single declared scalar draft. No arbitrary property/action names are sent.
document.addEventListener('submit', event => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  if (form.hasAttribute('data-admin-bulk')) {
    // Bulk actions are buttons that set selectedBulk themselves; keep an
    // application's own <select data-admin-bulk-name> working too.
    const select = form.querySelector<HTMLSelectElement>('[data-admin-bulk-name]');
    if (select) form.querySelector<HTMLInputElement>('[data-rx-bind="selectedBulk"]')!.value = select.value;
    return;
  }
  if (form.hasAttribute('data-admin-columns')) {
    const chosen = [...form.querySelectorAll<HTMLInputElement>('[data-admin-column]')].filter(box => box.checked).map(box => box.value);
    form.querySelector<HTMLInputElement>('[data-rx-bind="columns"]')!.value = JSON.stringify(chosen);
    return;
  }
  if (form.hasAttribute('data-admin-filters')) {
    const values: Record<string, string | Record<string, string>> = {};
    form.querySelectorAll<HTMLInputElement | HTMLSelectElement>('[data-admin-filter]').forEach(input => {
      const [name, part] = input.dataset.adminFilter!.split('.');
      if (part) {
        const range = typeof values[name] === 'object' ? values[name] as Record<string, string> : {};
        range[part] = input.value; values[name] = range;
      } else values[name] = input.value;
    });
    form.querySelector<HTMLInputElement>('[data-rx-bind="filters"]')!.value = JSON.stringify(values);
    return;
  }
  if (form.hasAttribute('data-admin-draft')) {
    if (form.dataset.uploadBusy === 'true') { event.preventDefault(); event.stopImmediatePropagation(); return; }
    if (hasFiles(form)) {
      event.preventDefault(); event.stopImmediatePropagation();
      const submitter = event.submitter as HTMLButtonElement | null;
      void stageUploads(form).then(() => { if (form.isConnected) form.requestSubmit(submitter); }).catch(() => {});
      return;
    }
    // The Remove button of a FileDrop ticks this box; Reactive forms send the field's value, so mark it.
    form.querySelectorAll<HTMLInputElement>('[data-upload-remove], [data-filedrop-remove-box]').forEach(remove => {
      const value = remove.closest('[data-admin-upload]')?.querySelector<HTMLInputElement>('[data-upload-value]');
      if (!value) return;
      if (remove.checked) value.value = '@remove'; else if (value.value === '@remove') value.value = '';
    });
    if (navigating || busy()) { event.preventDefault(); event.stopImmediatePropagation(); return; }
    const values: Record<string, string | Record<string, string>> = {};
    for (const [name, value] of new FormData(form)) {
      if (typeof value !== 'string' || name.startsWith('_upload_control[') || name.startsWith('_remove_upload[')) continue;
      const translated = /^([A-Za-z_][A-Za-z0-9_]*)\[([a-z0-9-]+)\]$/.exec(name);
      if (translated) {
        const current = values[translated[1]];
        const languages = typeof current === 'object' ? current : {};
        languages[translated[2]] = value; values[translated[1]] = languages;
      } else if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(name)) values[name] = value;
    }
    const input = form.querySelector<HTMLInputElement>('[data-rx-bind="draft"]')!;
    input.value = JSON.stringify(values);
    if (form.dataset.adminRefreshing === 'true') {
      delete form.dataset.adminRefreshing;
      if (new TextEncoder().encode(input.value).length <= 64000) {
        const original = form.dataset.rxSubmit;
        form.dataset.rxSubmit = 'refreshForm';
        queueMicrotask(() => { form.dataset.rxSubmit = original; });
      } else {
        event.preventDefault(); event.stopImmediatePropagation();
        const message = form.closest('dialog')?.querySelector<HTMLElement>('[data-rx-status]');
        if (message) message.textContent = panel()?.dataset.adminLargeForm || 'This form is too large.';
        const fallback = form.closest('dialog')?.querySelector<HTMLElement>('[data-admin-full-page]');
        if (fallback) fallback.hidden = false;
      }
      return;
    }
    if (new TextEncoder().encode(input.value).length > 64000) {
      event.preventDefault(); event.stopImmediatePropagation();
      const message = form.closest('dialog')?.querySelector<HTMLElement>('[data-rx-status]');
      if (message) message.textContent = panel()?.dataset.adminLargeForm || 'This form is too large for the dialog. Copy any unsaved text before continuing on the full page.';
      const fallback = form.closest('dialog')?.querySelector<HTMLElement>('[data-admin-full-page]');
      if (fallback) fallback.hidden = false;
    }
    return;
  }
  // Legacy delete confirmation owns its initial submit, then requests a new one.
  // data-admin-local: a form that a script sends itself (the media details panel and editor).
  const asks = form.hasAttribute('data-confirm') || Boolean((event.submitter as HTMLElement | null)?.hasAttribute('data-admin-confirm-submit'));
  if (!enabled() || form.hasAttribute('data-rx-submit') || form.hasAttribute('data-admin-local') || (asks && !(form as any)._confirmed)) return;
  const url = internal(form.action);
  if (!url || (event.target instanceof Element && event.target.closest('[data-admin-file-link]'))) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  const values = form.method.toUpperCase() === 'GET' ? new URLSearchParams() : new FormData(form);
  if (values instanceof URLSearchParams) new FormData(form).forEach((value, name) => { if (typeof value === 'string') values.append(name, value); });
  const submitter = event.submitter as HTMLButtonElement | null;
  if (submitter?.name) values.append(submitter.name, submitter.value);
  if (form.method.toUpperCase() === 'GET') {
    url.search = values.toString(); void navigate(url);
  } else void navigate(url, { method: 'POST', body: values });
}, true);

// The panel owns its browser history; Reactive's public-page reload fallback
// must not run here. Re-fetch on back/forward, including component query state.
window.addEventListener('popstate', event => {
  if (!enabled()) return;
  event.stopImmediatePropagation();
  const url = internal(location.href);
  if (url) {
    if (navigating || busy()) { history.replaceState(null, '', displayedUrl); return; }
    if (isDirty()) {
      // Stay on the record page's address until the person decides.
      history.pushState(null, '', displayedUrl);
      void confirmLeave().then(leave => { if (leave) void navigate(url); });
      return;
    }
    void navigate(url, { history: false });
  } else location.reload();
}, true);

// Detached component responses cannot change the URL after panel navigation.
document.addEventListener('htmx:beforeSwap', ((event: CustomEvent) => {
  const element = event.detail.elt as HTMLElement | undefined;
  if (element?.hasAttribute('data-rx-component') && !element.isConnected) event.stopImmediatePropagation();
}) as EventListener, true);

/**
 * The brand colour is a small versioned stylesheet next to the panel (its name
 * changes with the colour). After saving a new colour in Settings → Admin panel,
 * use the stylesheet of the page just loaded, or none for the default colour.
 */
function syncBrandColour(next: Document): void {
  const selector = 'link[rel="stylesheet"][href*="/flo-admin/asset/theme-"]';
  const wanted = next.querySelector<HTMLLinkElement>(selector);
  const current = [...document.querySelectorAll<HTMLLinkElement>(selector)];
  if (wanted && current.some(link => link.getAttribute('href') === wanted.getAttribute('href'))) return;
  if (wanted) {
    const href = new URL(wanted.getAttribute('href') || '', location.href);
    if (href.origin !== location.origin || !/\/flo-admin\/asset\/theme-[a-f0-9]{12}\.css$/.test(href.pathname)) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href.href;
    // After the panel's own stylesheet, so the brand colour overrides its defaults.
    const main = document.querySelector<HTMLLinkElement>('link[rel="stylesheet"][href*="/flo-admin/asset/admin-v"]');
    if (main) main.after(link); else document.head.appendChild(link);
  }
  current.forEach(link => link.remove());
}

function updateMenu(open: boolean): void {
  const root = panel();
  const button = root?.querySelector<HTMLElement>('[data-admin-menu]');
  const sidebar = root?.querySelector<HTMLElement>('.fa-sidebar');
  root?.classList.toggle('is-menu-open', open);
  button?.setAttribute('aria-expanded', String(open));
  const mobile = matchMedia('(max-width: 900px)').matches;
  if (sidebar) sidebar.inert = mobile && !open;
  const content = root?.querySelector<HTMLElement>('#fa-content');
  if (content) content.inert = mobile && open;
  if (open) sidebar?.querySelector<HTMLElement>('a')?.focus();
}
document.addEventListener('keydown', event => {
  if (event.key === 'Tab' && panel()?.classList.contains('is-menu-open')) {
    const elements = [...panel()!.querySelectorAll<HTMLElement>('.fa-sidebar a, [data-admin-menu]')];
    const index = elements.indexOf(document.activeElement as HTMLElement);
    if (index >= 0) { event.preventDefault(); elements[(index + (event.shiftKey ? -1 : 1) + elements.length) % elements.length].focus(); }
  }
  if (event.key === 'Escape' && panel()?.classList.contains('is-menu-open')) {
    updateMenu(false); panel()?.querySelector<HTMLElement>('[data-admin-menu]')?.focus();
  }
});
window.addEventListener('resize', () => updateMenu(false));
document.addEventListener('flo-reactive:error', ((event: CustomEvent) => {
  const root = panel();
  if (!root || !(event.target instanceof HTMLElement) || !root.contains(event.target)) return;
  const messages: Record<number, string | undefined> = {
    401: root.dataset.adminExpired, 419: root.dataset.adminExpired,
    403: root.dataset.adminForbidden, 409: root.dataset.adminOperationExpired, 404: root.dataset.adminMissing,
  };
  event.target.querySelectorAll<HTMLElement>('[data-rx-status]').forEach(element => {
    element.textContent = messages[event.detail.status] || root.dataset.adminError || '';
  });
}) as EventListener);
document.addEventListener('flo-reactive:updated', () => {
  displayedUrl = location.href;
  const root = panel();
  if (root) { initializeForms(root); initializeTranslations(root); initializeSelection(root); initializeEditors(root); initializeRelations(root); initializeMedia(root); initializeFileDrops(root); }
});

// Ordinary modal controls have no scalar binding. Keep them inert during the
// mutation while the request is in flight, while the Reactive adapter owns buttons.
document.addEventListener('htmx:beforeRequest', ((event: CustomEvent) => {
  if (!event.detail.elt?.hasAttribute('data-rx-component')) return;
  const form = event.detail.elt.querySelector('form[data-admin-draft]');
  if (form) form.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach((control: HTMLInputElement) => {
    control.dataset.adminWasDisabled = String(control.disabled); control.disabled = true;
  });
}) as EventListener);
document.addEventListener('htmx:afterRequest', ((event: CustomEvent) => {
  event.detail.elt?.querySelectorAll('[data-admin-was-disabled]').forEach((control: HTMLInputElement) => {
    control.disabled = control.dataset.adminWasDisabled === 'true'; delete control.dataset.adminWasDisabled;
  });
}) as EventListener);

initialize();
