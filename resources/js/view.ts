/*
 * The view page (0.27): Print in the More menu and Copy beside emails,
 * phone numbers, links and slugs. Both buttons stay hidden without JavaScript.
 */
export function initializeView(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-admin-print], [data-admin-copy]').forEach(button => button.removeAttribute('hidden'));
}

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const print = event.target.closest<HTMLButtonElement>('[data-admin-print]');
  if (print) {
    print.closest('details')?.removeAttribute('open');
    window.print();
    return;
  }
  const copy = event.target.closest<HTMLButtonElement>('[data-admin-copy]');
  if (!copy) return;
  const value = copy.dataset.adminCopy ?? '';
  const done = (): void => {
    const label = copy.getAttribute('aria-label') ?? '';
    copy.classList.add('is-copied');
    copy.setAttribute('aria-label', copy.dataset.copiedText || label);
    window.setTimeout(() => { copy.classList.remove('is-copied'); copy.setAttribute('aria-label', label); }, 1600);
  };
  if (navigator.clipboard) navigator.clipboard.writeText(value).then(done, () => undefined);
});
