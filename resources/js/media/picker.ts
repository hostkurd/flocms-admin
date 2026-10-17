/**
 * The picker (0.18): the media browser in a dialog, choosing for a media field,
 * the rich text editor or a settings page. Resolves with the chosen items, or
 * null when it is closed without choosing.
 */
import { button, element, preview, t, config, type MediaItem } from './api';
import { MediaBrowser } from './browser';

export type PickOptions = {
  kind: 'image' | 'video' | null;
  multiple: boolean;
  max?: number | null;
  /** Ids already in the field: shown as added, not chosen again. */
  already?: number[];
  /** Open in this folder (a field's ->folder()). */
  folder?: number | null;
};

export function pick(options: PickOptions): Promise<MediaItem[] | null> {
  const cfg = config();
  if (!cfg) return Promise.resolve(null);
  return new Promise(resolve => {
    const opener = document.activeElement as HTMLElement | null;
    const dialog = element('dialog', 'fa-mb-dialog');
    const titleKey = options.kind === 'video' ? 'choose_title_video' : options.multiple ? (options.kind === 'image' ? 'choose_title_many' : 'choose_title_media') : options.kind === 'image' ? 'choose_title_image' : 'choose_title_media';
    const head = element('div', 'fa-mb-dialog-head');
    const title = element('h2', 'fa-mb-dialog-title', t(cfg, titleKey));
    title.id = `fa-mb-pick-${Math.random().toString(36).slice(2)}`;
    dialog.setAttribute('aria-labelledby', title.id);
    const room = options.max ? Math.max(0, options.max - (options.already?.length ?? 0)) : null;
    const limit = element('span', 'fa-badge fa-badge-gray', !options.multiple ? t(cfg, 'limit_one') : room !== null ? t(cfg, 'limit_many', { max: room }) : '');
    limit.hidden = limit.textContent === '';
    const close = button('fa-icon-btn', t(cfg, 'close'), 'x', true);
    head.append(title, limit, element('span', 'fa-mb-spacer'), close);

    const body = element('div', 'fa-mb-dialog-body');
    const foot = element('div', 'fa-mb-dialog-foot');
    const chips = element('div', 'fa-mb-chips');
    const count = element('span', 'fa-mb-chosen', t(cfg, 'nothing_chosen'));
    count.setAttribute('aria-live', 'polite');
    const keys = element('span', 'fa-mb-keys', t(cfg, 'keys_help'));
    const cancel = element('button', 'fa-btn', t(cfg, 'cancel'));
    cancel.type = 'button';
    const use = element('button', 'fa-btn fa-btn-primary', options.multiple ? t(cfg, 'select') : t(cfg, 'choose_one'));
    use.type = 'button';
    use.disabled = true;
    foot.append(chips, count, keys, element('span', 'fa-mb-spacer'), cancel, use);
    dialog.append(head, body, foot);

    let settled = false;
    const finish = (items: MediaItem[] | null) => {
      if (settled) return;
      settled = true;
      resolve(items);
      dialog.close();
    };
    const browser = new MediaBrowser(body, cfg, {
      mode: 'choose', kind: options.kind, multiple: options.multiple, max: options.max ?? null,
      already: options.already ?? [], query: { folder: options.folder ?? null }, queueHost: dialog,
      onSelection: items => {
        use.disabled = items.length === 0;
        chips.replaceChildren(...items.slice(-6).map(item => { const chip = preview(item, 'fa-mb-chip'); chip.title = item.label; return chip; }));
        count.textContent = items.length === 0 ? t(cfg, 'nothing_chosen') : options.multiple ? t(cfg, items.length === 1 ? 'selected_one' : 'selected_many', { count: items.length }) : items[0].label;
        if (options.multiple) use.textContent = items.length ? `${t(cfg, 'select')} (${items.length})` : t(cfg, 'select');
      },
      onChoose: items => finish(items),
    });
    use.addEventListener('click', () => finish(browser.selection()));
    cancel.addEventListener('click', () => finish(null));
    close.addEventListener('click', () => finish(null));
    dialog.addEventListener('cancel', event => {
      // Escape closes a menu or the folder name dialog first; the picker only when nothing else is open.
      if (document.querySelector('.fa-mb-menu')) { event.preventDefault(); return; }
      finish(null);
    });
    dialog.addEventListener('close', () => {
      if (!settled) { settled = true; resolve(null); }
      dialog.remove();
      opener?.focus({ preventScroll: true });
    });
    (document.querySelector('.fa-panel') ?? document.body).append(dialog);
    dialog.showModal();
    browser.focusSearch();
  });
}
