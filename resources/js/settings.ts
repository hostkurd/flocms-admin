/*
 * Settings pages: the page list as a select on phones, and colour pickers.
 * The "Editing: language" switch is shared with record pages (translations.ts).
 * Without JavaScript the page list stays a list.
 */
export function initializeSettings(root: HTMLElement): void {
  const form = root.querySelector<HTMLFormElement>('form[data-admin-settings]');
  if (!form || form.dataset.settingsReady === 'true') return;
  form.dataset.settingsReady = 'true';

  const picker = form.querySelector<HTMLElement>('[data-settings-picker]');
  const pages = picker?.querySelector<HTMLSelectElement>('[data-settings-nav]');
  if (picker && pages) {
    picker.hidden = false;
    pages.addEventListener('change', () => {
      // Follow the page's own link: the panel's navigation asks first when there are unsaved changes.
      const target = new URL(pages.value, location.href).href;
      const link = [...form.querySelectorAll<HTMLAnchorElement>('.fa-settings-tab')].find(a => a.href === target);
      const current = form.querySelector<HTMLAnchorElement>('.fa-settings-tab[aria-current="page"]');
      if (current) pages.value = current.href;
      link?.click();
    });
  }
}

document.addEventListener('input', event => {
  const target = event.target;
  // Colour pickers: the swatch and the text input show the same colour.
  if (target instanceof HTMLInputElement && target.matches('[data-color-swatch]')) {
    const text = target.closest('[data-admin-color]')?.querySelector<HTMLInputElement>('[data-color-text]');
    if (text) { text.value = target.value; text.dispatchEvent(new Event('input', { bubbles: true })); }
  } else if (target instanceof HTMLInputElement && target.matches('[data-color-text]')) {
    const swatch = target.closest('[data-admin-color]')?.querySelector<HTMLInputElement>('[data-color-swatch]');
    const value = target.value.trim().toLowerCase();
    const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/.exec(value);
    if (swatch && match) {
      const hex = match[1].length === 3 ? match[1].split('').map(c => c + c).join('') : match[1];
      swatch.value = '#' + hex;
    }
  }
});
