/**
 * The record History drawer, the Changes dialog and auto-submitting filters (0.19).
 *
 * - a[data-admin-drawer="id"] opens the <dialog id="id"> on the page instead of
 *   following its link (the link is the page without JavaScript).
 * - a[data-admin-dialog-url] loads that page's dialog content (X-Flo-Admin: fragment)
 *   into #fa-changes-dialog; on any failure it follows the link instead.
 * - [data-admin-dialog-close] closes its dialog; so does a click on the backdrop.
 * - select[data-admin-autosubmit] submits its form when it changes.
 */

const panel = () => document.querySelector<HTMLElement>('.fa-panel');

function open(dialog: HTMLDialogElement, opener: HTMLElement): void {
  if (typeof dialog.showModal !== 'function') return;
  (dialog as any)._opener = opener;
  if (!dialog.open) dialog.showModal();
  dialog.querySelector<HTMLElement>('[data-admin-dialog-close]')?.focus();
}

let loading: AbortController | undefined;

async function openRemote(link: HTMLAnchorElement): Promise<void> {
  const dialog = document.getElementById('fa-changes-dialog') as HTMLDialogElement | null;
  const content = dialog?.querySelector<HTMLElement>('[data-admin-dialog-content]');
  if (!dialog || !content || typeof dialog.showModal !== 'function') { location.href = link.href; return; }
  loading?.abort();
  loading = new AbortController();
  link.setAttribute('aria-busy', 'true');
  try {
    const response = await fetch(link.href, { headers: { 'X-Flo-Admin': 'fragment' }, credentials: 'same-origin', signal: loading.signal });
    const type = response.headers.get('Content-Type') || '';
    if (!response.ok || !type.includes('text/html')) throw new Error(String(response.status));
    const html = await response.text();
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const changes = parsed.querySelector('[data-admin-changes]');
    if (!changes) throw new Error('No changes in the response.');
    // Only our own server-rendered markup; scripts in parsed HTML never run.
    parsed.querySelectorAll('script').forEach(script => script.remove());
    content.replaceChildren(document.importNode(changes, true));
    dialog.dir = panel()?.dir || document.documentElement.dir;
    open(dialog, link);
  } catch (error) {
    if ((error as Error).name !== 'AbortError') location.href = link.href;
  } finally {
    link.removeAttribute('aria-busy');
  }
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element) || event.defaultPrevented) return;
  const mouse = event as MouseEvent;
  if (mouse.button !== 0 || mouse.metaKey || mouse.ctrlKey || mouse.shiftKey || mouse.altKey) return;

  const drawerLink = event.target.closest<HTMLAnchorElement>('a[data-admin-drawer]');
  if (drawerLink) {
    const dialog = document.getElementById(drawerLink.dataset.adminDrawer || '') as HTMLDialogElement | null;
    if (dialog && typeof dialog.showModal === 'function') {
      event.preventDefault();
      event.stopImmediatePropagation();
      open(dialog, drawerLink);
    }
    return;
  }

  const remote = event.target.closest<HTMLAnchorElement>('a[data-admin-dialog-url]');
  if (remote) {
    event.preventDefault();
    event.stopImmediatePropagation();
    void openRemote(remote);
    return;
  }

  const close = event.target.closest<HTMLElement>('[data-admin-dialog-close]');
  if (close) {
    close.closest('dialog')?.close();
    return;
  }

  // A click on the backdrop (outside the dialog's box) closes the drawer and the Changes dialog.
  if (event.target instanceof HTMLDialogElement && event.target.matches('.fa-drawer, .fa-changes-dialog')) {
    const box = event.target.getBoundingClientRect();
    if (mouse.clientX < box.left || mouse.clientX > box.right || mouse.clientY < box.top || mouse.clientY > box.bottom) event.target.close();
  }
}, true);

document.addEventListener('close', event => {
  const dialog = event.target;
  if (!(dialog instanceof HTMLDialogElement) || !dialog.matches('.fa-drawer, .fa-changes-dialog')) return;
  const opener = (dialog as any)._opener as HTMLElement | undefined;
  if (opener?.isConnected) opener.focus();
}, true);

document.addEventListener('change', event => {
  const select = event.target;
  if (select instanceof HTMLSelectElement && select.hasAttribute('data-admin-autosubmit')) select.form?.requestSubmit();
});
