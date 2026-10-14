import { test, expect, Page } from '@playwright/test';
const editor = (page: Page) => page.locator('dialog.fa-editor');
const selected = (page: Page) => page.locator('[data-admin-selection-count]');
async function ready(page: Page) {
  await page.goto('/en/admin/posts');
  await page.evaluate(() => { (window as any).documentMarker = 'tables'; });
}
async function select(page: Page, id: number) {
  await page.locator(`[data-admin-select][value="${id}"]`).check();
  await expect(page.locator('[data-rx-component]')).toHaveAttribute('aria-busy', 'false');
}

test('filters restore through bookmarks and back/forward without replacing the document', async ({ page }) => {
  await ready(page);
  await page.locator('[data-admin-filter=status]').selectOption('published');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(page.locator('tbody tr')).toHaveCount(0);
  await expect(page).toHaveURL(/admin-resource_filters=/);
  const url = page.url();
  await page.getByRole('button', { name: 'Clear filters', exact: true }).click();
  await expect(page.locator('tbody tr')).toHaveCount(3);
  await page.goBack();
  await expect(page.locator('[data-admin-filter=status]')).toHaveValue('published');
  await expect(page.locator('tbody tr')).toHaveCount(0);
  await page.goForward();
  await expect(page.locator('tbody tr')).toHaveCount(3);
  expect(await page.evaluate(() => (window as any).documentMarker)).toBe('tables');
  await page.goto(url);
  await expect(page.locator('[data-admin-filter=status]')).toHaveValue('published');
  await expect(page.locator('tbody tr')).toHaveCount(0);
});

test('selection persists across pages; select this page does not include other matching rows', async ({ page }) => {
  await ready(page);
  await page.locator('[data-admin-select-page]').check();
  await expect(selected(page)).toHaveText('3 records selected');
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(page.locator('tbody')).toContainText('Lake house');
  await expect(selected(page)).toHaveText('3 records selected');
  await page.locator('[data-admin-select-page]').check();
  await expect(selected(page)).toHaveText('6 records selected');
  await page.locator('[data-admin-bulk-name]').selectOption('delete');
  await page.getByRole('button', { name: 'Review action', exact: true }).click();
  await expect(editor(page)).toContainText('6 selected records');
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
  await expect(selected(page)).toHaveText('6 records selected');
  expect(await page.evaluate(() => (window as any).documentMarker)).toBe('tables');
});

test('bulk publish validates then updates only the explicit selection', async ({ page }) => {
  await ready(page);
  await select(page, 8);
  await expect(selected(page)).toHaveText('1 records selected');
  await page.locator('[data-admin-bulk-name]').selectOption('publish');
  await page.getByRole('button', { name: 'Review action', exact: true }).click();
  await editor(page).getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(editor(page).locator('[name=reason]')).toHaveAttribute('aria-invalid', 'true');
  await editor(page).locator('[name=reason]').fill('Reviewed selection');
  await editor(page).getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('#fa-admin-resource-row-8')).toContainText('Published');
  await expect(page.locator('#fa-admin-resource-row-7')).toContainText('Draft');
  await expect(selected(page)).toHaveText('0 records selected');
  await expect(page.locator('[data-admin-notice]')).toContainText('Action completed for 1');
  expect(await page.evaluate(() => (window as any).documentMarker)).toBe('tables');
});

test('stale bulk selection keeps every other row and Cancel remains available', async ({ page }) => {
  await ready(page);
  await page.locator('[data-admin-select-page]').check();
  await expect(selected(page)).toHaveText('3 records selected');
  await page.locator('[data-admin-bulk-name]').selectOption('delete');
  await page.getByRole('button', { name: 'Review action', exact: true }).click();
  await expect(editor(page)).toBeVisible();
  await page.request.post('/__test/change', { form: { id: 7 } });
  await editor(page).getByRole('button', { name: 'Delete selected', exact: true }).click();
  await expect(editor(page)).toContainText('No changes were saved');
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('tbody tr')).toHaveCount(3);
  await expect(page.locator('#fa-admin-resource-row-8')).toContainText('Studio');
});

test('filter and search changes clear selection', async ({ page }) => {
  await ready(page); await select(page, 8);
  await page.locator('[data-admin-filter=featured]').selectOption('0');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(selected(page)).toHaveText('0 records selected');
  await page.getByRole('button', { name: 'Clear filters', exact: true }).click();
  await expect(page.locator('tbody tr')).toHaveCount(3); await select(page, 8);
  await page.locator('input[type=search]').fill('Studio');
  await expect(selected(page)).toHaveText('0 records selected');
  await expect(page.locator('tbody tr')).toHaveCount(1);
});

test('full-page filters and bulk deletion work with JavaScript disabled', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await page.goto('http://127.0.0.1:8083/en/admin/posts?static=1');
    await page.locator('[name="filter[status]"]').selectOption('draft');
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page).toHaveURL(/static=1/);
    await page.locator('[name="ids[]"][value="8"]').check();
    await page.locator('[name=action]').selectOption('delete');
    await page.getByRole('button', { name: 'Review action', exact: true }).click();
    await expect(page.locator('form')).toContainText('1 selected records');
    await page.getByRole('button', { name: 'Delete selected', exact: true }).click();
    await expect(page.locator('tbody')).not.toContainText('Studio');
  } finally { await context.close(); }
});

for (const lang of ['ar', 'ku']) test(`mobile ${lang} filters and selection remain within the panel`, async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/${lang}/admin/posts`);
  await page.locator('[data-admin-select-page]').check();
  await expect(selected(page)).toContainText('3');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

test('bulk unpublish leaves unselected published rows intact', async ({ page }) => {
  await ready(page);
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await page.locator('[data-admin-select-page]').check();
  await expect(selected(page)).toHaveText('3 records selected');
  await page.locator('[data-admin-bulk-name]').selectOption('publish');
  await page.getByRole('button', { name: 'Review action', exact: true }).click();
  await editor(page).locator('[name=reason]').fill('Approved for publication');
  await editor(page).getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await select(page, 5);
  await page.locator('[data-admin-bulk-name]').selectOption('unpublish');
  await page.getByRole('button', { name: 'Review action', exact: true }).click();
  await editor(page).getByRole('button', { name: 'Unpublish', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('#fa-admin-resource-row-5')).toContainText('Draft');
  await expect(page.locator('#fa-admin-resource-row-3')).toContainText('Published');
});
