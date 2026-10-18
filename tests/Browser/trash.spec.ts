import { test, expect, Page } from '@playwright/test';

const editor = (page: Page) => page.locator('dialog.fa-editor');
const toasts = (page: Page) => page.locator('[data-admin-notifications] [data-admin-notification]');

async function history(page: Page) {
  await page.request.post('/__test/history');
}
async function state(page: Page): Promise<{ trash: { resource: string; record_id: string }[]; activity: { action: string }[] }> {
  return (await page.request.post('/__test/trash')).json();
}
async function deleteRow(page: Page, id: number) {
  const button = page.locator(`[data-admin-action="delete"][data-admin-record="${id}"]`);
  if (!(await button.isVisible())) await page.locator(`#fa-admin-resource-menu-${id} > summary`).click();
  await button.click();
  await expect(editor(page)).toBeVisible();
}

test.beforeEach(async ({ page }) => { await history(page); });
test.afterEach(async ({ page }) => { await page.request.post('/__test/history-off'); });

test('a deleted row goes to the trash, and Undo in the toast brings it back', async ({ page }) => {
  await page.goto('/en/admin/posts');
  await expect(page.locator('tbody')).toContainText('Studio');
  await deleteRow(page, 8);
  await expect(editor(page)).toContainText('It goes to the trash for 30 days and can be restored from there.');
  await editor(page).getByRole('button', { name: 'Delete', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('tbody')).not.toContainText('Studio');

  const toast = toasts(page).filter({ hasText: 'Post deleted.' });
  await expect(toast).toBeVisible();
  await expect(toast).toContainText('“Studio” is in the trash for 30 days.');
  await expect(toast.locator('.fa-notification-timer')).toBeVisible();
  await expect(toast.getByRole('button', { name: 'Undo' })).toBeVisible();
  expect((await state(page)).trash).toEqual([expect.objectContaining({ resource: 'posts', record_id: '8' })]);

  // Hovering pauses the countdown
  await toast.hover();
  await expect(toast).toHaveClass(/is-paused/);

  await toast.getByRole('button', { name: 'Undo' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/posts/);
  await expect(page.locator('tbody')).toContainText('Studio');
  await expect(toasts(page).filter({ hasText: 'Post restored.' })).toBeVisible();
  expect((await state(page)).trash).toEqual([]);
  expect((await state(page)).activity.map(entry => entry.action)).toEqual(['deleted', 'restored']);
});

test('the list\'s Trash restores and deletes for good, asking first', async ({ page }) => {
  await page.goto('/en/admin/posts');
  await deleteRow(page, 8);
  await editor(page).getByRole('button', { name: 'Delete', exact: true }).click();
  await expect(page.locator('tbody')).not.toContainText('Studio');

  const link = page.locator('a.fa-trash-link');
  await expect(link).toContainText('Trash');
  await expect(link.locator('.fa-btn-count')).toHaveText('1');
  await link.click();
  await expect(page).toHaveURL(/\/en\/admin\/posts\/trash$/);
  await expect(page.getByRole('heading', { name: 'Posts trash' })).toBeVisible();
  const row = page.locator('tbody tr').filter({ hasText: 'Studio' });
  await expect(row).toContainText('Sara');
  await expect(row).toContainText('30 days');

  await row.getByRole('button', { name: 'Restore' }).click();
  await expect(toasts(page).filter({ hasText: 'Post restored: “Studio”.' })).toBeVisible();
  await expect(page.locator('.fa-blank')).toContainText('The trash is empty.');

  // Delete again, then for good
  await page.goto('/en/admin/posts');
  await deleteRow(page, 8);
  await editor(page).getByRole('button', { name: 'Delete', exact: true }).click();
  await page.goto('/en/admin/posts/trash');
  await page.locator('tbody tr').filter({ hasText: 'Studio' }).getByRole('button', { name: 'Delete for good: Studio' }).click();
  const confirm = page.locator('#fa-confirm');
  await expect(confirm).toBeVisible();
  await expect(confirm.locator('.fa-dialog-title')).toHaveText('Delete for good?');
  await expect(confirm.locator('.fa-confirm-text')).toHaveText('“Studio” and its files will be deleted. This cannot be undone.');
  await confirm.getByRole('button', { name: 'Delete for good' }).click();
  await expect(toasts(page).filter({ hasText: '“Studio” deleted for good.' })).toBeVisible();
  expect((await state(page)).trash).toEqual([]);

  // The default wording is back for the next confirmation
  await page.goto('/en/admin/posts/edit/1?static=1');
  await page.locator('.fa-record-actions').getByRole('button', { name: 'Delete' }).click();
  await expect(confirm.locator('.fa-dialog-title')).toHaveText('Delete this record?');
  await expect(confirm.locator('.fa-confirm-text')).toHaveText('It goes to the trash for 30 days and can be restored from there.');
  await confirm.getByRole('button', { name: 'Cancel' }).click();
});

test('bulk restore from Site → Trash', async ({ page }) => {
  for (const id of [7, 8]) {
    await page.goto('/en/admin/posts');
    await deleteRow(page, id);
    await editor(page).getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(editor(page)).not.toBeVisible();
  }
  await page.locator('.fa-nav').getByRole('link', { name: 'Trash' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/trash$/);
  await expect(page.getByRole('heading', { name: 'Trash', exact: true })).toBeVisible();
  await page.locator('[data-admin-select-page-standard]').check();
  const bar = page.locator('#fa-list-bulk');
  await expect(bar).toBeVisible();
  await expect(bar).toContainText('2 records selected');
  await bar.getByRole('button', { name: 'Restore' }).click();
  await expect(toasts(page).filter({ hasText: '2 records restored.' })).toBeVisible();
  expect((await state(page)).trash).toEqual([]);
});

test('History opens in a drawer, and Show changes opens the before → after table', async ({ page }) => {
  await page.goto('/en/admin/posts/edit/1?static=1');
  await page.locator('select[name=status]').selectOption('published');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(toasts(page).filter({ hasText: 'Post saved.' })).toBeVisible();

  await page.locator('a[data-admin-drawer]').click();
  const drawer = page.locator('#fa-history-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer).toContainText('Sara edited 1 field');
  await expect(drawer).toContainText('Status');
  await expect(drawer.locator('.fa-change-old')).toHaveText('Draft');
  await expect(drawer.locator('.fa-change-new')).toHaveText('Published');

  await drawer.getByRole('link', { name: 'Show changes' }).click();
  const changes = page.locator('#fa-changes-dialog');
  await expect(changes).toBeVisible();
  await expect(changes.getByRole('heading', { name: 'Changes · Sea view villa' })).toBeVisible();
  await expect(changes.locator('tbody tr')).toHaveCount(1);
  await expect(changes.locator('.fa-diff-old')).toHaveText('Draft');
  await expect(changes.locator('.fa-diff-new')).toHaveText('Published');
  await page.keyboard.press('Escape');
  await expect(changes).not.toBeVisible();
  await expect(drawer).toBeVisible();
  await drawer.getByRole('button', { name: 'Close' }).click();
  await expect(drawer).not.toBeVisible();
  await expect(page.locator('a[data-admin-drawer]')).toBeFocused();
});

test('the Activity page filters by action and opens the changes', async ({ page }) => {
  await page.goto('/en/admin/posts/edit/2?static=1');
  await page.locator('select[name=status]').selectOption('published');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await page.goto('/en/admin/posts');
  await deleteRow(page, 8);
  await editor(page).getByRole('button', { name: 'Delete', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();

  await page.locator('.fa-nav').getByRole('link', { name: 'Activity' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/activity$/);
  const table = page.locator('.fa-activity-table');
  await expect(table).toContainText('Mountain cabin');
  await expect(table).toContainText('Studio');
  await page.locator('.fa-activity-actions').getByRole('link', { name: 'Deleted' }).click();
  await expect(table).toContainText('Studio');
  await expect(table).not.toContainText('Mountain cabin');
  await page.locator('.fa-activity-actions').getByRole('link', { name: 'Edited' }).click();
  await table.getByRole('link', { name: 'Show changes' }).click();
  await expect(page.locator('#fa-changes-dialog')).toContainText('Published');
});

test('Arabic: the toast and the drawer read right to left', async ({ page }) => {
  await page.goto('/ar/admin/posts');
  await deleteRow(page, 8);
  await editor(page).locator('.fa-dialog-foot button[type=submit]').click();
  const toast = toasts(page).filter({ hasText: 'تم حذف' });
  await expect(toast).toBeVisible();
  await expect(toast.getByRole('button', { name: 'تراجع' })).toBeVisible();
  await expect(page.locator('[data-admin-notifications]')).toHaveAttribute('dir', 'rtl');

  await page.goto('/ar/admin/posts/edit/1?static=1');
  await page.locator('a[data-admin-drawer]').click();
  const drawer = page.locator('#fa-history-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByRole('heading', { name: 'السجل' })).toBeVisible();
  const box = (await drawer.boundingBox())!;
  expect(box.x).toBeLessThan(10);
});
