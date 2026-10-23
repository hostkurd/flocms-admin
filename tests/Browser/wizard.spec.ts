import { test, expect, Page } from '@playwright/test';

/* Wizard forms (0.39): fixtures tests/Fixtures/Wizard (rentals), reset by /__test/rentals. */
const state = async (page: Page) => (await (await page.request.post('/__test/rentals-state')).json()) as Array<Record<string, string | null>>;
const shown = (page: Page) => page.locator('.fa-wizard-panel:not([hidden])');

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/rentals');
});

test('a new record goes step by step; Enter means Next and focus moves to the new step', async ({ page }) => {
  await page.goto('/en/admin/rentals/create');
  await expect(shown(page).locator('.fa-wizard-title')).toHaveText('Details');
  await expect(page.locator('.fa-wizard-item[aria-current="step"]')).toContainText('Details');
  await expect(page.locator('[data-wizard-finish]')).toHaveCount(0);

  await page.getByRole('button', { name: 'Next: Location' }).click();
  await expect(page.locator('#f-title-error')).toHaveText('Title is required.');
  await expect(page.locator('.fa-wizard-item.has-error')).toContainText('2 problems');
  await expect(page.locator('#f-city-error')).toHaveCount(0);

  await page.locator('#f-title').fill('Stone house');
  await page.locator('#f-type').selectOption('sale');
  await page.locator('#f-title').press('Enter');
  await expect(shown(page).locator('.fa-wizard-title')).toHaveText('Location');
  await expect(shown(page).locator('.fa-wizard-title')).toBeFocused();
  await expect(page.locator('.fa-wizard-count')).toHaveText('Step 2 of 4');

  // Back keeps what was typed; the done step is a button.
  await page.locator('#f-city').fill('Chania');
  await page.getByRole('button', { name: 'Back' }).click();
  await expect(page.locator('#f-title')).toHaveValue('Stone house');
  await page.locator('.fa-wizard-item').filter({ hasText: 'Location' }).getByRole('button').click();
  await expect(page.locator('#f-city')).toHaveValue('Chania');
  await page.getByRole('button', { name: 'Next: Pricing' }).click();
  await page.locator('#f-price').fill('465000');
  await page.getByRole('button', { name: 'Next: Review' }).click();
  await expect(shown(page)).toContainText('Stone house');
  await expect(shown(page)).toContainText('465000');
  expect((await state(page)).length).toBe(1);
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/rentals$/);
  const rows = await state(page);
  expect(rows[1].city).toBe('Chania');
  expect(rows[1].type).toBe('sale');
});

test('a file chosen on one step is kept through the later ones', async ({ page }) => {
  await page.goto('/en/admin/rentals/create');
  await page.locator('#f-title').fill('With brochure');
  await page.locator('#f-type').selectOption('sale');
  await page.getByRole('button', { name: 'Next: Location' }).click();
  await page.locator('#f-city').fill('Athens');
  await page.getByRole('button', { name: 'Next: Pricing' }).click();
  await page.locator('#f-brochure').setInputFiles({ name: 'brochure.txt', mimeType: 'text/plain', buffer: Buffer.from('Three rooms, sea view.') });
  // The price is missing: Next shows the error, the file stays chosen.
  await page.getByRole('button', { name: 'Next: Review' }).click();
  await expect(page.locator('#f-price-error')).toBeVisible();
  await page.locator('#f-price').fill('300000');
  await page.getByRole('button', { name: 'Back' }).click();
  await page.getByRole('button', { name: 'Next: Pricing' }).click();
  await page.getByRole('button', { name: 'Next: Review' }).click();
  await expect(shown(page)).toContainText('brochure.txt');
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/rentals$/);
  expect((await state(page))[1].brochure).toMatch(/\.txt$/);
});

test('the edit page opens any step and Save opens the step with an error', async ({ page }) => {
  await page.goto('/en/admin/rentals/edit/1');
  await page.locator('.fa-wizard-item').filter({ hasText: 'Rental terms' }).getByRole('button').click();
  await expect(shown(page).locator('.fa-wizard-title')).toHaveText('Rental terms');
  await page.locator('#f-lease_months').fill('');
  await page.locator('.fa-wizard-item').filter({ hasText: 'Pricing' }).getByRole('button').click();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(shown(page).locator('.fa-wizard-title')).toHaveText('Rental terms');
  await expect(page.locator('#f-lease_months')).toBeFocused();
  await page.locator('#f-lease_months').fill('24');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect.poll(async () => String((await state(page))[0].lease_months)).toBe('24');
});

test('a wizard in a dialog moves between steps without closing', async ({ page }) => {
  await page.goto('/en/admin/rental-dialogs');
  await page.getByRole('button', { name: /New Rental dialog/ }).click();
  const dialog = page.locator('dialog.fa-editor');
  await expect(dialog.locator('.fa-wizard-panel:not([hidden]) .fa-wizard-title')).toHaveText('Details');
  await expect(dialog.locator('.fa-dialog-foot button[type="submit"]')).toHaveCount(0);
  await dialog.getByRole('button', { name: 'Next: Location' }).click();
  await expect(dialog.locator('#fa-admin-resource-f-title-error')).toBeVisible();
  await dialog.locator('#fa-admin-resource-f-title').fill('Dialog flat');
  await dialog.locator('#fa-admin-resource-f-type').selectOption('sale');
  await dialog.getByRole('button', { name: 'Next: Location' }).click();
  await expect(dialog.locator('.fa-wizard-panel:not([hidden]) .fa-wizard-title')).toHaveText('Location');
  await dialog.locator('#fa-admin-resource-f-city').fill('Patras');
  await dialog.getByRole('button', { name: 'Next: Pricing' }).click();
  await dialog.locator('#fa-admin-resource-f-price').fill('120000');
  await dialog.getByRole('button', { name: 'Next: Review' }).click();
  await expect(dialog.locator('.fa-wizard-review')).toContainText('Patras');
  await dialog.locator('.fa-dialog-foot button[type="submit"]').click();
  await expect(dialog).toBeHidden();
  expect((await state(page))[1].city).toBe('Patras');
});

test('phones: "Step 1 of 4" with the steps in a menu, buttons fixed at the bottom', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/en/admin/rentals/create');
  const menu = page.locator('[data-wizard-menu]');
  await expect(menu).not.toHaveAttribute('open', '');
  await expect(page.locator('.fa-wizard-summary')).toContainText('Step 1 of 4 · Details');
  await expect(page.locator('.fa-wizard-list')).toBeHidden();
  await page.locator('.fa-wizard-summary').click();
  await expect(page.locator('.fa-wizard-list')).toBeVisible();
  const nav = page.locator('.fa-wizard-nav');
  await expect(nav).toHaveCSS('position', 'fixed');
});

test('without JavaScript every move still works', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto('/en/admin/rentals/create');
  await page.locator('#f-title').fill('No script');
  await page.locator('#f-type').selectOption('rent');
  await page.getByRole('button', { name: 'Next: Location' }).click();
  await expect(page.locator('input[name="_wizard_step"]')).toHaveValue('location');
  await expect(page.locator('#f-city')).toBeVisible();
  await expect(page.locator('#f-title')).toBeHidden();
  await context.close();
});
