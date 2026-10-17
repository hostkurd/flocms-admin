type Control = HTMLInputElement | HTMLTextAreaElement;
const initialized = new WeakSet<HTMLDialogElement>();
const snapshots = new WeakMap<HTMLDialogElement, string[]>();
const controls = (dialog: HTMLDialogElement) => [...dialog.querySelectorAll<Control>('input, textarea')];
const main = (dialog: HTMLDialogElement) => document.getElementById(dialog.dataset.main || '') as Control | null;
const badge = (dialog: HTMLDialogElement) => [...document.querySelectorAll<HTMLButtonElement>('.fa-lang-badge')]
  .find(button => button.dataset.dialog === dialog.id);

function refresh(dialog: HTMLDialogElement): void {
  const button = badge(dialog);
  if (!button) return;
  const missing: string[] = [];
  controls(dialog).forEach(input => {
    const filled = input.value.trim() !== '';
    if (!filled) missing.push(input.lang);
    const dot = [...button.querySelectorAll<HTMLElement>('.fa-dot')].find(dot => dot.dataset.lang === input.lang);
    if (dot) {
      dot.classList.toggle('is-filled', filled);
      dot.classList.toggle('is-empty', !filled);
    }
  });
  button.title = missing.length ? (button.dataset.missingText || 'Missing: ') + missing.join(', ') : (button.dataset.completeText || '');
  // The dots are hidden from assistive technology; the label carries the same status.
  if (button.dataset.openText) button.setAttribute('aria-label', `${button.dataset.openText}. ${button.title}`);
}

function open(dialog: HTMLDialogElement): void {
  if (dialog.open) return;
  const mirror = dialog.querySelector<Control>('[data-mirror]');
  if (mirror && main(dialog)) mirror.value = main(dialog)!.value;
  snapshots.set(dialog, controls(dialog).map(input => input.value));
  dialog.showModal();
  (dialog.querySelector<HTMLElement>('[aria-invalid="true"]') || controls(dialog)[0])?.focus();
}

function close(dialog: HTMLDialogElement, save: boolean): void {
  const snapshot = snapshots.get(dialog);
  if (!save && snapshot) controls(dialog).forEach((input, index) => { input.value = snapshot[index]; });
  if (save) {
    const mirror = dialog.querySelector<Control>('[data-mirror]');
    const input = main(dialog);
    if (input && mirror) {
      input.value = mirror.value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    refresh(dialog);
  }
  snapshots.delete(dialog);
  dialog.close();
  badge(dialog)?.focus();
}

// Delegation survives Reactive morphs: badges and inputs can be replaced without
// keeping event handlers that point at a detached translation dialog.
document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const button = event.target.closest<HTMLButtonElement>('.fa-panel [data-dialog]');
  const target = button ? document.getElementById(button.dataset.dialog || '') : null;
  if (target instanceof HTMLDialogElement && target.classList.contains('fa-lang-dialog')) { open(target); return; }
  const dialog = event.target.closest<HTMLDialogElement>('.fa-panel .fa-lang-dialog');
  if (dialog && event.target.closest('[data-dialog-save], [data-dialog-cancel]')) {
    close(dialog, Boolean(event.target.closest('[data-dialog-save]')));
  }
});
document.addEventListener('input', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) || !input.closest('.fa-panel')) return;
  const dialog = [...document.querySelectorAll<HTMLDialogElement>('.fa-lang-dialog')].find(dialog => dialog.dataset.main === input.id);
  const mirror = dialog?.querySelector<Control>('[data-mirror]');
  if (dialog && mirror && !dialog.open) { mirror.value = input.value; refresh(dialog); }
});

/** The input keeps room for its language badge: the badge's own width, measured once it is visible. */
const badgeSpace = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(entries => {
  for (const entry of entries) {
    const badge = entry.target as HTMLElement;
    const width = badge.offsetWidth;
    if (width > 0) badge.parentElement?.style.setProperty('--fa-lang-badge-space', `${width + 13}px`);
  }
});

export function initializeTranslations(root: HTMLElement): void {
  root.querySelectorAll<HTMLElement>('.fa-input-row > .fa-lang-badge').forEach(badge => badgeSpace?.observe(badge));
  root.querySelectorAll<HTMLDialogElement>('.fa-lang-dialog').forEach(dialog => {
    if (initialized.has(dialog)) return;
    initialized.add(dialog);
    dialog.addEventListener('cancel', event => {
      event.preventDefault(); event.stopPropagation(); close(dialog, false);
    });
    dialog.addEventListener('keydown', event => {
      // The resource editor must not trap a child dialog's Tab or Escape.
      event.stopPropagation();
      if (event.key === 'Enter' && event.target instanceof HTMLInputElement) { event.preventDefault(); close(dialog, true); }
      if (event.key === 'Tab') {
        const focusable = [...dialog.querySelectorAll<HTMLElement>('input:not(:disabled), textarea:not(:disabled), button:not(:disabled)')]
          .filter(control => control.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable.at(-1);
        if (first && last && event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (first && last && !event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
  });
  const invalid = root.querySelector<HTMLDialogElement>('.fa-lang-dialog[data-open-on-load]');
  if (invalid && !invalid.open) {
    const editor = invalid.closest<HTMLDialogElement>('.fa-editor');
    if (!editor || editor.open) open(invalid);
  }
}
