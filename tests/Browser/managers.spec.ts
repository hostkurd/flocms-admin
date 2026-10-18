import { test, expect, Page } from '@playwright/test';

/* Relation managers (0.22), fixture tests/Fixtures/Managers: estates with units (dialogs) and enquiries (read-only). */
const units = (page: Page) => page.locator('#fa-manager-units');
const enquiries = (page: Page) => page.locator('#fa-manager-enquiries');

test.beforeEach(async ({ page }) => { await page.request.post('/__test/managers'); });

test('tabs with counts below the form, and adding a unit in a dialog updates the count', async ({ page }) => {
  await page.goto('/en/admin/estates/edit/1');
  const tabs = page.locator('.fa-manager-tabs');
  await expect(tabs).toBeVisible();
  await expect(page.getByRole('tab', { name: /Units/ })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('[data-admin-manager-count-for="fa-manager-units"]').first()).toHaveText('5');
  await expect(units(page).locator('tbody tr')).toHaveCount(5);
  await expect(units(page)).not.toContainText('Harbour A');
  await expect(enquiries(page)).toBeHidden();

  await units(page).getByRole('button', { name: 'New Unit' }).click();
  const dialog = page.locator('#fa-rel-units-editor');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('[data-field="estate_id"]')).toHaveCount(0);
  await dialog.getByLabel('Name').fill('Garden flat');
  await dialog.getByLabel('Bedrooms').fill('2');
  await dialog.getByRole('button', { name: 'Create' }).click();
  await expect(dialog).toBeHidden();
  await expect(units(page).locator('tbody')).toContainText('Garden flat');
  await expect(page.locator('[data-admin-manager-count-for="fa-manager-units"]').first()).toHaveText('6');
  // The parent's own form was not touched
  await expect(page.locator('[data-admin-record-state]')).toHaveText('');

  // Search inside the manager
  await units(page).getByRole('searchbox').fill('pent');
  await units(page).getByRole('searchbox').press('Enter');
  await expect(units(page).locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('[data-admin-manager-count-for="fa-manager-units"]').first()).toHaveText('6', { timeout: 2000 });
});

test('editing and deleting a unit stay on the parent page; the read-only tab has no actions', async ({ page }) => {
  await page.goto('/en/admin/estates/edit/1');
  const row = units(page).locator('tbody tr', { hasText: 'Flat 2' });
  await row.getByRole('button', { name: 'Edit' }).click();
  const dialog = page.locator('#fa-rel-units-editor');
  await dialog.getByLabel('Name').fill('Flat 2B');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog).toBeHidden();
  await expect(units(page)).toContainText('Flat 2B');
  await expect(page).toHaveURL(/\/en\/admin\/estates\/edit\/1/);

  const flat4 = units(page).locator('tbody tr', { hasText: 'Flat 4' });
  await flat4.locator('summary[aria-label="More actions"]').click();
  await flat4.getByRole('button', { name: 'Delete' }).click();
  await dialog.getByRole('button', { name: 'Delete' }).click();
  await expect(units(page).locator('tbody tr')).toHaveCount(4);
  await expect(page.locator('[data-admin-manager-count-for="fa-manager-units"]').first()).toHaveText('4');

  await page.getByRole('tab', { name: /Enquiries/ }).click();
  await expect(enquiries(page)).toBeVisible();
  await expect(enquiries(page)).toContainText('No enquiries yet.');
  await expect(enquiries(page).getByRole('button', { name: /New/ })).toHaveCount(0);
  // The tab is remembered across a save of the parent
  await page.getByLabel('Title').fill('Bay view villa II');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByRole('tab', { name: /Enquiries/ })).toHaveAttribute('aria-selected', 'true');
});

test('the create page says the managers come after saving; Save then shows them', async ({ page }) => {
  await page.goto('/en/admin/estates/create');
  await expect(page.locator('.fa-manager-pending')).toContainText('Add units and enquiries after saving');
  await page.getByLabel('Title').fill('Garden court');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page).toHaveURL(/estates\/edit\/4/);
  await expect(units(page)).toContainText('No units yet.');
});

test('the parent list counts units; the sidebar has no Units entry', async ({ page }) => {
  await page.goto('/en/admin/estates');
  await expect(page.locator('tbody tr', { hasText: 'Bay view villa' }).locator('.fa-count-cell')).toHaveText('5');
  await expect(page.locator('tbody tr', { hasText: 'Old town house' }).locator('.fa-count-cell')).toHaveText('0');
  await expect(page.locator('.fa-nav a', { hasText: 'Units' })).toHaveCount(0);
  await expect(page.locator('.fa-nav a', { hasText: 'Estates' })).toHaveCount(1);
});

test('without JavaScript the manager links to the units of this estate, and Back returns to it', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.request.post('/__test/managers');
  await page.goto('/en/admin/estates/edit/1');
  await page.locator('#fa-manager-units').getByRole('link', { name: 'Open Units on their own page' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Units · Bay view villa');
  await expect(page.locator('tbody tr')).toHaveCount(5);
  await page.getByRole('link', { name: 'New Unit' }).click();
  await expect(page.locator('[data-field="estate_id"]')).toHaveCount(0);
  await page.getByLabel('Name').fill('Studio');
  await page.getByRole('button', { name: 'Save and close' }).click();
  await expect(page).toHaveURL(/units\?static=1&parent=estates%3A1%3Aunits/);
  await expect(page.locator('tbody')).toContainText('Studio');
  await page.getByRole('link', { name: 'Back to Bay view villa' }).click();
  await expect(page).toHaveURL(/estates\/edit\/1$/);
  await context.close();
});

test('Arabic and phone: tabs fit and the dialog opens', async ({ browser }) => {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  await page.request.post('/__test/managers');
  await page.goto('/ar/admin/estates/edit/1');
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('.fa-manager-tabs')).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
  await page.locator('#fa-manager-units .fa-list-toolbar .fa-btn-primary').click();
  await expect(page.locator('#fa-rel-units-editor')).toBeVisible();
  await context.close();
});
