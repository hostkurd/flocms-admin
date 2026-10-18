/*
 * Relation managers (0.22): each manager's list reports its number of records
 * after every change; the tab (or heading) count follows it.
 */
export function updateManagerCounts(root: ParentNode = document): void {
  root.querySelectorAll<HTMLElement>('[data-admin-manager]').forEach(manager => {
    const count = manager.querySelector<HTMLElement>('[data-admin-manager-count]')?.dataset.adminManagerCount;
    if (count === undefined || !manager.id) return;
    document.querySelectorAll<HTMLElement>(`[data-admin-manager-count-for="${CSS.escape(manager.id)}"]`).forEach(element => {
      if (element.textContent !== count) element.textContent = count;
    });
  });
}
document.addEventListener('flo-reactive:updated', () => updateManagerCounts());
