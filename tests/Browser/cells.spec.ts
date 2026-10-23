import { test, expect, Page } from '@playwright/test';

/* Editable cells and totals (0.38): fixtures tests/Fixtures/Tables (offers, buildings, suites), reset by /__test/tables. */
type Offer = Record<string, string | number | null>;
const state = async (page: Page) => (await (await page.request.post('/__test/tables-state')).json()) as { offers: Offer[]; suites: Offer[] };
const offer = async (page: Page, id: number) => (await state(page)).offers.find(row => Number(row.id) === id)!;
const row = (page: Page, title: string) => page.locator('tbody tr', { hasText: title });
const totals = (page: Page, kind: 'page' | 'all' = 'all') => page.locator(`tr.fa-totals-row.is-${kind}`);

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/tables');
});

test('a switch saves at once and the totals follow', async ({ page }) => {
  await page.goto('/en/admin/offers');
  await expect(totals(page)).toContainText('Featured 3');
  const toggle = page.getByRole('switch', { name: 'Featured: Seaside flat, Glyfada' });
  await expect(toggle).toHaveAttribute('aria-checked', 'false');
  await toggle.click();
  await expect(page.getByRole('switch', { name: 'Featured: Seaside flat, Glyfada' })).toHaveAttribute('aria-checked', 'true');
  await expect(row(page, 'Seaside flat, Glyfada').locator('.fa-cell.is-saved')).toContainText('Saved');
  expect(Number((await offer(page, 2)).featured)).toBe(1);
  await expect(totals(page)).toContainText('Featured 4');
  // The keyboard: Space on the focused switch
  await page.getByRole('switch', { name: 'Featured: Seaside flat, Glyfada' }).focus();
  await page.keyboard.press('Space');
  await expect(page.getByRole('switch', { name: 'Featured: Seaside flat, Glyfada' })).toHaveAttribute('aria-checked', 'false');
  await expect.poll(async () => Number((await offer(page, 2)).featured)).toBe(0);
});

test('a text cell opens, saves with Enter, cancels with Escape and keeps a refused value', async ({ page }) => {
  await page.goto('/en/admin/offers');
  const cell = row(page, 'Townhouse, Pangrati').locator('form[data-admin-cell]').filter({ has: page.locator('input[name="column"][value="price"]') });
  await expect(cell.locator('[data-admin-cell-display]')).toHaveText(/€ 395,000\.00/);
  await expect(cell.locator('[data-admin-cell-input]')).toBeHidden();

  await cell.locator('[data-admin-cell-display]').click();
  const input = cell.locator('[data-admin-cell-input]');
  await expect(input).toBeFocused();
  await input.fill('410000');
  await page.keyboard.press('Escape');
  await expect(cell.locator('[data-admin-cell-display]')).toBeFocused();
  await expect(cell.locator('[data-admin-cell-display]')).toHaveText(/€ 395,000\.00/);

  await cell.locator('[data-admin-cell-display]').press('Enter');
  await input.fill('abc');
  await page.keyboard.press('Enter');
  const again = row(page, 'Townhouse, Pangrati').locator('form[data-admin-cell].is-error');
  await expect(again.locator('[data-admin-cell-status]')).toContainText('Price');
  await expect(again.locator('[data-admin-cell-input]')).toHaveValue('abc');
  await expect(again.locator('[data-admin-cell-input]')).toHaveAttribute('aria-invalid', 'true');
  expect(Number((await offer(page, 4)).price)).toBe(395000);

  await again.locator('[data-admin-cell-input]').fill('410000');
  await page.keyboard.press('Enter');
  await expect(row(page, 'Townhouse, Pangrati').locator('.fa-cell.is-saved')).toContainText('Saved');
  await expect(row(page, 'Townhouse, Pangrati')).toContainText('€ 410,000.00');
  expect(Number((await offer(page, 4)).price)).toBe(410000);
});

test('Tab saves and moves to the next editable cell', async ({ page }) => {
  await page.goto('/en/admin/offers');
  const first = row(page, 'Loft near Ladadika').locator('form[data-admin-cell]').filter({ has: page.locator('input[name="column"][value="price"]') });
  await first.locator('[data-admin-cell-display]').click();
  await first.locator('[data-admin-cell-input]').fill('215000');
  await page.keyboard.press('Tab');
  // The next editable control of the row: the stock cell's text
  await expect(page.locator(':focus')).toHaveAttribute('data-admin-cell-display', '');
  await expect.poll(async () => Number((await offer(page, 5)).price)).toBe(215000);
  // The save's answer does not pull the focus back
  await expect(row(page, 'Loft near Ladadika').locator('.fa-cell.is-saved')).toBeVisible();
  await expect(page.locator(':focus')).toHaveAttribute('data-admin-cell-display', '');
  await expect(page.locator(':focus').locator('xpath=ancestor::form[1]').locator('input[name="column"]')).toHaveValue('stock');
});

test('a select stepped with the keyboard saves once, on Enter', async ({ page }) => {
  await page.goto('/en/admin/offers');
  const status = page.getByLabel('Status: Garden flat, Erbil');
  await status.focus();
  await page.keyboard.press('ArrowUp');
  await page.waitForTimeout(300);
  expect((await offer(page, 7)).status).toBe('draft');
  await page.keyboard.press('Enter');
  await expect(row(page, 'Garden flat, Erbil').locator('.fa-cell.is-saved')).toBeVisible();
  expect((await offer(page, 7)).status).not.toBe('draft');
});

test('a select saves on change; somebody else\'s change is a conflict', async ({ page }) => {
  await page.goto('/en/admin/offers');
  const status = page.getByLabel('Status: Garden flat, Erbil');
  await status.selectOption('published');
  await expect(row(page, 'Garden flat, Erbil').locator('.fa-cell.is-saved')).toContainText('Saved');
  expect((await offer(page, 7)).status).toBe('published');

  await page.request.post('/__test/offer-change', { form: { id: '6', column: 'status', value: 'sold' } });
  await page.getByLabel('Status: Family home, Kalamaria').selectOption('draft');
  const cell = row(page, 'Family home, Kalamaria').locator('.fa-cell.is-conflict');
  await expect(cell).toContainText('somebody changed Status meanwhile');
  await expect(page.getByLabel('Status: Family home, Kalamaria')).toHaveValue('sold');
  await expect(page.locator('.fa-toasts')).toContainText('somebody changed Status meanwhile');
  expect((await offer(page, 6)).status).toBe('sold');
});

test('a switch that asks first', async ({ page }) => {
  await page.goto('/en/admin/offers');
  await page.getByRole('switch', { name: 'Live: Villa in Kifisia' }).click();
  const dialog = page.locator('#fa-confirm');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.fa-dialog-title')).toHaveText('Unpublish Villa in Kifisia?');
  await expect(dialog.locator('[data-confirm-yes]')).toHaveText('Unpublish');
  await expect(dialog.locator('[data-confirm-yes]')).toHaveClass(/fa-btn-danger/);
  await dialog.locator('[data-confirm-no]').click();
  await expect(page.getByRole('switch', { name: 'Live: Villa in Kifisia' })).toHaveAttribute('aria-checked', 'true');
  expect((await offer(page, 1)).published).toBe('published');

  await page.getByRole('switch', { name: 'Live: Villa in Kifisia' }).click();
  await dialog.locator('[data-confirm-yes]').click();
  await expect(page.getByRole('switch', { name: 'Live: Villa in Kifisia' })).toHaveAttribute('aria-checked', 'false');
  await expect.poll(async () => (await offer(page, 1)).published).toBe('draft');
});

test('the page list saves cells and refreshes its totals', async ({ page }) => {
  await page.request.post('/__test/tables', { form: { static: '1', per_page: '3' } });
  await page.goto('/en/admin/offers?group=none');
  await expect(totals(page, 'page')).toContainText('This page');
  await expect(totals(page, 'all')).toContainText('All 8 rows');
  await expect(totals(page, 'all')).toContainText('Stock, Total 14');
  const cell = row(page, 'Plot with permit').locator('form[data-admin-cell]').filter({ has: page.locator('input[name="column"][value="stock"]') });
  await cell.locator('[data-admin-cell-display]').click();
  await cell.locator('[data-admin-cell-input]').fill('6');
  await page.keyboard.press('Enter');
  await expect(totals(page, 'all')).toContainText('Stock, Total 19');
  await expect(totals(page, 'page')).toContainText('Stock, Total 11');
  expect(Number((await offer(page, 8)).stock)).toBe(6);
});

test('viewers see the values only', async ({ page }) => {
  await page.request.post('/__test/tables', { form: { viewer: '1' } });
  await page.goto('/en/admin/offers');
  await expect(page.getByRole('switch')).toHaveCount(0);
  await expect(page.locator('form[data-admin-cell]')).toHaveCount(0);
  await expect(row(page, 'Villa in Kifisia')).toContainText('Yes');
  await expect(totals(page)).toContainText('All 8 rows');
});

test('a relation manager edits its rows and totals inside the parent', async ({ page }) => {
  await page.goto('/en/admin/buildings/edit/1');
  const manager = page.locator('#rx-rel-suites');
  await expect(manager.locator('tr.fa-totals-row')).toContainText('Rent, Total € 6,200');
  await expect(page.locator('#rx-rel-suite-overview form[data-admin-cell]')).toHaveCount(0);
  const cell = manager.locator('tbody tr', { hasText: 'A2' }).locator('form[data-admin-cell]').first();
  await cell.locator('[data-admin-cell-display]').click();
  await cell.locator('[data-admin-cell-input]').fill('1200');
  await page.keyboard.press('Enter');
  await expect(manager.locator('tr.fa-totals-row')).toContainText('Rent, Total € 6,300');
  await manager.getByRole('switch', { name: 'Available: C1' }).click();
  await expect(manager.locator('tr.fa-totals-row')).toContainText('Available 3');
  const suites = (await state(page)).suites;
  expect(Number(suites[1].rent)).toBe(1200);
  expect(Number(suites[4].available)).toBe(1);
});

test('phones: stacked rows, Cancel and Save on an open text cell, a totals card', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/en/admin/offers');
  const cell = row(page, 'Villa in Kifisia').locator('form[data-admin-cell]').filter({ has: page.locator('input[name="column"][value="price"]') });
  await cell.locator('[data-admin-cell-display]').click();
  await expect(cell.getByRole('button', { name: 'Cancel' })).toBeVisible();
  await expect(cell.locator('[data-admin-cell-save]')).toBeVisible();
  await cell.locator('[data-admin-cell-input]').fill('1260000');
  await cell.locator('[data-admin-cell-save]').click();
  await expect(row(page, 'Villa in Kifisia')).toContainText('€ 1,260,000.00');
  await expect(page.locator('tfoot.fa-totals')).toBeVisible();
  await expect(page.locator('tfoot .fa-total-column').first()).toBeVisible();
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('each cell is a small form with a Save button', async ({ page }) => {
    await page.request.post('/__test/tables', { form: { static: '1' } });
    await page.goto('/en/admin/offers?group=none');
    const cell = row(page, 'Seaside flat, Glyfada').locator('form[data-admin-cell]').filter({ has: page.locator('input[name="column"][value="stock"]') });
    await expect(cell.locator('[data-admin-cell-display]')).toBeHidden();
    await cell.locator('[data-admin-cell-input]').fill('5');
    await cell.locator('[data-admin-cell-save]').click();
    await expect(page.getByText('Saved: Stock for “Seaside flat, Glyfada”.')).toBeVisible();
    expect(Number((await offer(page, 2)).stock)).toBe(5);

    // A switch is a submit button; the one that asks first goes to a confirm page
    await page.getByRole('switch', { name: 'Live: Villa in Kifisia' }).click();
    await expect(page.getByRole('heading', { name: 'Unpublish Villa in Kifisia?' })).toBeVisible();
    await page.getByRole('button', { name: 'Unpublish' }).click();
    await expect(page.getByText('Saved: Live for “Villa in Kifisia”.')).toBeVisible();
    expect((await offer(page, 1)).published).toBe('draft');
  });
});
