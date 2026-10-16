/*
 * A thin bar along the top of the window while the panel loads a page or a
 * Reactive component updates. It appears only after 150 ms (fast responses
 * show nothing), creeps towards 90 % while waiting and completes when done.
 * The bar is decoration: the status region still announces loading to
 * screen readers. Widths are set through CSSOM, which a strict CSP allows.
 */
let bar: HTMLElement | null = null;
let active = 0;
let width = 0;
let showTimer: ReturnType<typeof setTimeout> | undefined;
let trickle: ReturnType<typeof setInterval> | undefined;
let hideTimer: ReturnType<typeof setTimeout> | undefined;

function element(): HTMLElement {
  if (bar && bar.isConnected) return bar;
  bar = document.createElement('div');
  bar.className = 'fa-progress';
  bar.setAttribute('aria-hidden', 'true');
  bar.dataset.adminProgress = '';
  bar.append(document.createElement('span'));
  document.body.append(bar);
  return bar;
}

function set(value: number): void {
  width = value;
  (element().firstElementChild as HTMLElement).style.transform = `scaleX(${value / 100})`;
}

export function startProgress(): void {
  active++;
  if (active > 1) return;
  if (hideTimer) { clearTimeout(hideTimer); hideTimer = undefined; }
  showTimer = setTimeout(() => {
    const node = element();
    // The bar lives outside the panel; take the panel's (brand) colour.
    const panel = document.querySelector('.fa-panel');
    const color = panel ? getComputedStyle(panel).getPropertyValue('--fa-primary').trim() : '';
    if (color) node.style.setProperty('--fa-progress', color);
    node.classList.remove('is-done');
    set(8);
    node.classList.add('is-active');
    trickle = setInterval(() => { if (width < 90) set(width + (90 - width) * 0.08); }, 200);
  }, 150);
}

export function finishProgress(): void {
  if (active === 0) return;
  active--;
  if (active > 0) return;
  if (showTimer) { clearTimeout(showTimer); showTimer = undefined; }
  if (trickle) { clearInterval(trickle); trickle = undefined; }
  if (!bar || !bar.classList.contains('is-active')) return;
  set(100);
  bar.classList.add('is-done');
  hideTimer = setTimeout(() => { bar?.classList.remove('is-active', 'is-done'); set(0); }, 350);
}

// Reactive updates inside the panel (search, pages, dialogs, saves). The
// request's element may be replaced before it ends, so remember the request.
const counted = new WeakSet<object>();
document.addEventListener('htmx:beforeRequest', ((event: CustomEvent) => {
  const source = event.detail?.elt as Element | undefined;
  const request = event.detail?.xhr as object | undefined;
  if (!request || !source?.closest?.('.fa-panel')) return;
  counted.add(request);
  startProgress();
}) as EventListener);
const done = ((event: CustomEvent) => {
  const request = event.detail?.xhr as object | undefined;
  if (request && counted.delete(request)) finishProgress();
}) as EventListener;
['htmx:afterRequest', 'htmx:sendError', 'htmx:timeout', 'htmx:xhr:abort'].forEach(name => document.addEventListener(name, done));
