/*
 * The notification bell (0.41). The server draws it on every panel page; this
 * keeps an open panel up to date: every data-admin-bell-poll seconds, while the
 * tab is visible, it asks /admin/notifications/poll for the count and the menu.
 * The tab title starts with "(3)" while something is unread. Without
 * JavaScript the bell is an ordinary <details> menu and the count is read on
 * each page load.
 */
let timer: ReturnType<typeof setTimeout> | undefined;
let stopped = false;
let inFlight = false;
let pending: PollAnswer | undefined;
const TITLE_PREFIX = /^\(\d+\+?\)\s/;

const bell = () => document.querySelector<HTMLDetailsElement>('[data-admin-bell]');

function setTitle(badge: string): void {
  const base = document.title.replace(TITLE_PREFIX, '');
  document.title = badge !== '' ? `(${badge}) ${base}` : base;
}

/** The "back" inputs of the menu's forms return to the page the person is on. */
function fillBack(root: ParentNode): void {
  root.querySelectorAll<HTMLInputElement>('[data-admin-bell-back]').forEach(input => { input.value = location.href; });
}

interface PollAnswer { count: number; badge: string; label: string; menu: string }

function apply(answer: PollAnswer): void {
  const element = bell();
  if (!element) return;
  const badge = element.querySelector<HTMLElement>('[data-admin-bell-badge]');
  if (badge) { badge.textContent = answer.badge; badge.hidden = answer.badge === ''; }
  element.querySelector<HTMLElement>('[data-admin-bell-button]')?.setAttribute('aria-label', answer.label);
  setTitle(answer.badge);
  // An open menu is left alone: it is refreshed the next time it is closed.
  const menu = element.querySelector<HTMLElement>('[data-admin-bell-menu]');
  if (element.open) { pending = answer; return; }
  pending = undefined;
  if (menu && typeof answer.menu === 'string') {
    const parsed = new DOMParser().parseFromString(answer.menu, 'text/html');
    parsed.querySelectorAll('script').forEach(script => script.remove());
    menu.replaceChildren(...[...parsed.body.childNodes].map(node => document.importNode(node, true)));
    fillBack(menu);
  }
}

async function poll(): Promise<void> {
  const element = bell();
  const url = element?.dataset.adminBellUrl;
  if (!element || !url || inFlight) return;
  inFlight = true;
  try {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Flo-Admin': 'bell' } });
    // Signed out, or the bell was switched off: stop asking until the next page.
    if (!response.ok || response.redirected || !(response.headers.get('Content-Type') || '').includes('json')) { stopped = true; return; }
    apply(await response.json() as PollAnswer);
  } catch {
    // A network hiccup: try again next time.
  } finally {
    inFlight = false;
  }
}

function schedule(): void {
  if (timer) clearTimeout(timer);
  timer = undefined;
  const seconds = Number(bell()?.dataset.adminBellPoll || 0);
  if (stopped || !Number.isFinite(seconds) || seconds <= 0) return;
  timer = setTimeout(() => {
    timer = undefined;
    if (document.visibilityState === 'visible') void poll().finally(schedule);
    else schedule();
  }, seconds * 1000);
}

/** Run on each panel page (also after a reactive navigation, which redraws the bell). */
export function initializeBell(): void {
  const element = bell();
  if (!element) { setTitle(''); if (timer) clearTimeout(timer); timer = undefined; return; }
  stopped = false;
  pending = undefined;
  fillBack(element);
  element.addEventListener('toggle', () => { if (!element.open && pending) apply(pending); });
  setTitle(element.querySelector<HTMLElement>('[data-admin-bell-badge]')?.textContent?.trim() || '');
  schedule();
}

// Back to a tab after a while: ask at once instead of waiting a full interval.
let hiddenAt = 0;
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') { hiddenAt = Date.now(); return; }
  const seconds = Number(bell()?.dataset.adminBellPoll || 0);
  if (!stopped && seconds > 0 && hiddenAt > 0 && Date.now() - hiddenAt >= seconds * 1000) { void poll().finally(schedule); }
});
