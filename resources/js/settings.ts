/*
 * Settings pages: the page-level "Editing: language" switch, the page list as
 * a select on phones, and colour pickers. Without JavaScript every language's
 * input is shown with its language code, and the page list stays a list.
 */
type Control = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

function apply(form: HTMLFormElement, lang: string): void {
  form.dataset.editing = lang;
  const value = form.querySelector<HTMLInputElement>('[data-settings-editing-value]');
  if (value) value.value = lang;
  const isDefault = lang === form.dataset.defaultLang;
  form.querySelectorAll<HTMLElement>('[data-lang-panes]').forEach(group => {
    let current: HTMLElement | null = null;
    group.querySelectorAll<HTMLElement>(':scope > .fa-lang-pane').forEach(pane => {
      const selected = pane.dataset.lang === lang;
      pane.classList.toggle('is-current', selected);
      if (selected) current = pane;
    });
    const label = group.closest('[data-field]')?.querySelector<HTMLLabelElement>(':scope > .fa-label');
    const input = (current as HTMLElement | null)?.dataset.input;
    if (label && input && document.getElementById(input)) label.htmlFor = input;
  });
  // Values that are the same in every language are changed in the default language only.
  form.querySelectorAll<HTMLElement>('[data-same-all]').forEach(field => {
    field.classList.toggle('is-locked', !isDefault);
    [...field.children].forEach(child => {
      if (child.matches('.fa-label')) return;
      if (child.matches('.fa-same-note')) { (child as HTMLElement).hidden = isDefault; return; }
      (child as HTMLElement).inert = !isDefault;
    });
  });
  const other = form.querySelector<HTMLElement>('[data-settings-other]');
  const name = form.querySelector<HTMLSelectElement>('[data-settings-language]')?.selectedOptions[0]?.textContent?.split(' · ')[0] ?? lang;
  if (other) { other.hidden = isDefault || !form.querySelector('[data-same-all]'); other.textContent = (other.dataset.template || '').replace(':language', name); }
  form.querySelectorAll<HTMLElement>('[data-admin-rich-editor]').forEach(editor => {
    editor.dispatchEvent(new CustomEvent('flo-admin:editing-language', { detail: lang }));
  });
}

function refreshDot(control: Control): void {
  const pane = control.closest<HTMLElement>('.fa-lang-pane');
  const field = pane?.closest<HTMLElement>('[data-field]');
  if (!pane || !field || control.type === 'file' || control.type === 'checkbox') return;
  const dot = field.querySelector<HTMLElement>(`:scope > .fa-label .fa-dot[data-lang="${CSS.escape(pane.dataset.lang || '')}"]`);
  if (!dot) return;
  const filled = control.value.trim() !== '';
  dot.classList.toggle('is-filled', filled);
  dot.classList.toggle('is-empty', !filled);
  dot.classList.remove('is-invalid');
}

export function initializeSettings(root: HTMLElement): void {
  const form = root.querySelector<HTMLFormElement>('form[data-admin-settings]');
  if (!form || form.dataset.settingsReady === 'true') return;
  form.dataset.settingsReady = 'true';

  const select = form.querySelector<HTMLSelectElement>('[data-settings-language]');
  const switcher = form.querySelector<HTMLElement>('[data-settings-switch]');
  if (select && switcher) {
    switcher.hidden = false;
    form.classList.add('is-switching');
    apply(form, form.dataset.editing || select.value);
    select.addEventListener('change', () => apply(form, select.value));
  }

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
  if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
    if (target.closest('form[data-admin-settings]')) refreshDot(target);
  }
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
