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
  host.setAttribute('aria-label', panel.dataset.adminNotificationsLabel || 'Notifications');
  document.querySelectorAll<HTMLElement>('[data-admin-notification-payload]').forEach(payload => {
    payload.querySelectorAll<HTMLElement>('[data-admin-notification]').forEach(message => {
      const id = message.dataset.adminNotification!;
      if (seen.has(id)) return;
      seen.add(id);
      if (seen.size > 200) seen.delete(seen.values().next().value!);
      host!.append(message);
      // Notifications persist until dismissed; bound the visible history.
      while (host!.childElementCount > 10) host!.firstElementChild?.remove();
    });
    // Keep the keyed delivery container stable for Reactive DOM morphing.
    payload.replaceChildren();
  });
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const dismiss = event.target.closest('[data-admin-notification-dismiss]');
  if (!dismiss) return;
  const message = dismiss.closest<HTMLElement>('[data-admin-notification]');
  const focused = message?.contains(document.activeElement);
  message?.remove();
  if (focused) document.querySelector<HTMLElement>('#fa-content')?.focus();
});
document.addEventListener('flo-reactive:updated', receiveNotifications);
document.addEventListener('flo-admin:navigated', receiveNotifications);
