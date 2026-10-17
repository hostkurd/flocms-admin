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
  let left = seconds * 1000;
  let started = 0;
  let timer = 0;
  const start = () => { started = Date.now(); timer = window.setTimeout(() => closeNotification(message), left); };
  const pause = () => { if (!timer) return; window.clearTimeout(timer); timer = 0; left = Math.max(1500, left - (Date.now() - started)); };
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
document.addEventListener('flo-reactive:updated', receiveNotifications);
document.addEventListener('flo-admin:navigated', receiveNotifications);
