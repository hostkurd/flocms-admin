/*
 * The full-page record form: it remembers that something changed, asks before
 * leaving with unsaved changes (panel links, Back, closing the tab), and saves
 * with Ctrl/⌘+S. A form shown again after errors already holds unsaved input,
 * so the server marks it dirty.
 */
const recordForm = () => document.querySelector<HTMLFormElement>('.fa-panel form[data-admin-record-form]');

export function isDirty(): boolean {
  return recordForm()?.dataset.adminDirty === 'true';
}

function markDirty(form: HTMLFormElement, dirty: boolean): void {
  form.dataset.adminDirty = String(dirty);
  const state = form.querySelector<HTMLElement>('[data-admin-record-state]');
  if (state) state.textContent = dirty ? state.dataset.unsaved || '' : '';
}

export function initializeRecord(root: HTMLElement): void {
  const form = root.querySelector<HTMLFormElement>('form[data-admin-record-form]');
  if (form) markDirty(form, form.dataset.adminDirty === 'true');
}

function track(event: Event): void {
  const target = event.target;
  if (!(target instanceof Element)) return;
  const form = target.closest<HTMLFormElement>('form[data-admin-record-form]');
  // Inputs inside a dialog (translations, editor links) count once the dialog applies them.
  if (!form || target.closest('dialog') || target.closest('[data-admin-untracked]') || (target as HTMLInputElement).type === 'hidden') return;
  if (form.dataset.adminDirty !== 'true') markDirty(form, true);
}
document.addEventListener('input', track);
document.addEventListener('change', track);

// Saving is leaving on purpose; a refresh of dependent fields keeps the changes.
document.addEventListener('submit', event => {
  const form = event.target;
  if (form instanceof HTMLFormElement && form.matches('[data-admin-record-form]')) {
    const submitter = (event as SubmitEvent).submitter as HTMLButtonElement | null;
    if (submitter?.name !== '_refresh' && form.dataset.adminRefreshing !== 'true') form.dataset.adminDirty = 'false';
  }
  // Deleting the record discards its changes too.
  if (form instanceof HTMLFormElement && form.id === 'fa-record-delete') { const record = recordForm(); if (record) record.dataset.adminDirty = 'false'; }
}, true);

let pending: Promise<boolean> | null = null;
/** Resolves true when there is nothing to lose or the person chose to leave. */
export function confirmLeave(): Promise<boolean> {
  if (!isDirty()) return Promise.resolve(true);
  const dialog = document.querySelector<HTMLDialogElement>('.fa-panel #fa-leave');
  if (!dialog) return Promise.resolve(window.confirm(recordForm()?.dataset.adminLeave || 'Leave without saving?'));
  if (pending) return pending;
  pending = new Promise<boolean>(resolve => {
    const opener = document.activeElement as HTMLElement | null;
    const finish = (leave: boolean) => {
      dialog.removeEventListener('cancel', onCancel);
      dialog.removeEventListener('click', onClick);
      dialog.close();
      pending = null;
      if (leave) { const form = recordForm(); if (form) form.dataset.adminDirty = 'false'; }
      else opener?.focus();
      resolve(leave);
    };
    const onCancel = (event: Event) => { event.preventDefault(); finish(false); };
    const onClick = (event: MouseEvent) => {
      const target = event.target as Element;
      if (target.closest('[data-leave-go]')) finish(true);
      else if (target.closest('[data-leave-stay]') || target === dialog) finish(false);
    };
    dialog.addEventListener('cancel', onCancel);
    dialog.addEventListener('click', onClick);
    dialog.showModal();
    dialog.querySelector<HTMLButtonElement>('[data-leave-stay]')?.focus();
  });
  return pending;
}

window.addEventListener('beforeunload', event => {
  if (isDirty()) { event.preventDefault(); event.returnValue = ''; }
});

// Ctrl/⌘+S saves the open dialog form, or the page form (and stays on it).
document.addEventListener('keydown', event => {
  if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey || event.key.toLowerCase() !== 's') return;
  const panel = document.querySelector('.fa-panel');
  if (!panel) return;
  const dialogForm = panel.querySelector<HTMLFormElement>('dialog.fa-editor[open] form[data-admin-draft]');
  const form = dialogForm ?? recordForm();
  if (!form) return;
  event.preventDefault();
  if (form.closest('[aria-busy="true"]')) return;
  const save = dialogForm ? null : form.querySelector<HTMLButtonElement>('[data-admin-save]') ?? form.querySelector<HTMLButtonElement>('button[name="_close"]');
  // A field being typed in commits its value first (the rich editor syncs on input).
  form.requestSubmit(save ?? undefined);
});
