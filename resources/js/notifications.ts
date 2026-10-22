let host: HTMLElement | undefined;
const seen = new Set<string>();

/** Move response deliveries outside replaceable panels/components. No HTML is evaluated. */
export function receiveNotifications(): void {
  const panel = document.querySelector<HTMLElement>('.fa-panel');
  if (!panel) return;
  if (!host?.isConnected) {
    host = document.createElement('aside');
    host.className = 'fa-toasts';
    host.setAttribute('data-admin-notifications', '');
    host.setAttribute('aria-label', panel.dataset.adminNotificationsLabel || 'Notifications');
    document.body.append(host);
  }
  host.dir = panel.dir;
  host.dataset.theme = panel.dataset.theme || 'light';
  host.setAttribute('aria-label', panel.dataset.adminNotificationsLabel || 'Notifications');
  document.querySelectorAll<HTMLElement>('[data-admin-notification-payload]').forEach(payload => {
    payload.querySelectorAll<HTMLElement>('[data-admin-notification]').forEach(message => {
      const id = message.dataset.adminNotification!;
      if (seen.has(id)) return;
      seen.add(id);
      if (seen.size > 200) seen.delete(seen.values().next().value!);
      host!.append(message);
      autoClose(message);
      // Bound the visible history.
      while (host!.childElementCount > 10) host!.firstElementChild?.remove();
    });
    // Keep the keyed delivery container stable for Reactive DOM morphing.
    payload.replaceChildren();
  });
}

/**
 * Success and info notifications close after their duration (data-admin-notification-duration,
 * seconds; 0 = until dismissed). Hovering or focusing one pauses its timer.
 */
function autoClose(message: HTMLElement): void {
  const seconds = Number(message.dataset.adminNotificationDuration || '0');
  if (!(seconds > 0)) return;
  // A notification with an action (Undo) shows its time running out; hovering or focusing it pauses the bar too.
  const bar = message.querySelector<HTMLElement>('.fa-notification-timer i');
  if (bar) bar.style.animationDuration = `${seconds}s`;
  let left = seconds * 1000;
  let started = 0;
  let timer = 0;
  const start = () => { started = Date.now(); message.classList.remove('is-paused'); timer = window.setTimeout(() => closeNotification(message), left); };
  const pause = () => { if (!timer) return; window.clearTimeout(timer); timer = 0; message.classList.add('is-paused'); left = Math.max(1500, left - (Date.now() - started)); };
  const resume = () => { if (!timer && !message.matches(':hover') && !message.contains(document.activeElement)) start(); };
  message.addEventListener('mouseenter', pause);
  message.addEventListener('focusin', pause);
  message.addEventListener('mouseleave', resume);
  message.addEventListener('focusout', () => window.setTimeout(resume));
  start();
}

/** Fade out, then remove; focus moves to the content if it was inside. */
function closeNotification(message: HTMLElement): void {
  if (!message.isConnected || message.classList.contains('is-leaving')) return;
  const focused = message.contains(document.activeElement);
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  message.classList.add('is-leaving');
  window.setTimeout(() => message.remove(), reduced ? 0 : 200);
  if (focused) document.querySelector<HTMLElement>('#fa-content')?.focus();
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const dismiss = event.target.closest('[data-admin-notification-dismiss]');
  if (!dismiss) return;
  const message = dismiss.closest<HTMLElement>('[data-admin-notification]');
  if (message) closeNotification(message);
});
// The action (Undo) returns to the page the person is on.
document.addEventListener('submit', event => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-admin-notification-action')) return;
  const back = form.querySelector<HTMLInputElement>('[data-admin-notification-back]');
  if (back) back.value = location.pathname + location.search;
  form.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach(button => { button.disabled = true; });
  // The button's own request; the panel's page navigation never takes it.
  event.stopImmediatePropagation();
  window.setTimeout(() => form.querySelectorAll<HTMLButtonElement>('button').forEach(button => { button.disabled = false; }), 8000);
}, true);
document.addEventListener('flo-reactive:updated', receiveNotifications);
document.addEventListener('flo-admin:navigated', receiveNotifications);

let counter = 0;
/** A notification from the browser (media screens): the same look and timing as the server's. */
export function notify(title: string, level: 'success' | 'danger' | 'warning' | 'info' = 'success'): void {
  const panel = document.querySelector<HTMLElement>('.fa-panel');
  if (!panel || !title) return;
  const payload = document.createElement('div');
  payload.dataset.adminNotificationPayload = '';
  payload.hidden = true;
  const message = document.createElement('div');
  message.className = `fa-notification fa-alert-${level}`;
  message.setAttribute('role', level === 'danger' || level === 'warning' ? 'alert' : 'status');
  message.dataset.adminNotice = '';
  message.dataset.adminNotification = `browser-${Date.now()}-${++counter}`;
  // 0.34: warnings and errors close after 12 seconds, as the server's do, with the line showing the time left.
  message.dataset.adminNotificationDuration = level === 'success' || level === 'info' ? '5' : '12';
  const text = document.createElement('div');
  const line = document.createElement('p');
  line.className = 'fa-notification-title';
  line.textContent = title;
  text.append(line);
  const dismiss = document.createElement('button');
  dismiss.type = 'button';
  dismiss.className = 'fa-icon-btn';
  dismiss.dataset.adminNotificationDismiss = '';
  dismiss.setAttribute('aria-label', panel.dataset.adminDismiss || 'Dismiss');
  dismiss.textContent = '×';
  message.append(text, dismiss);
  if (level === 'danger' || level === 'warning') {
    const timer = document.createElement('span');
    timer.className = 'fa-notification-timer';
    timer.setAttribute('aria-hidden', 'true');
    timer.append(document.createElement('i'));
    message.append(timer);
  }
  payload.append(message);
  document.body.append(payload);
  receiveNotifications();
  payload.remove();
}
