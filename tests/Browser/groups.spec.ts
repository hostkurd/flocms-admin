import { test, expect, Page } from '@playwright/test';

/* Grouped lists (0.38): offers by city, status and month; reset by /__test/tables. */
const heads = (page: Page) => page.locator('tr.fa-group-row');
const headTitles = (page: Page) => page.locator('tr.fa-group-row .fa-group-title');
const menu = (page: Page) => page.locator('details.fa-group-menu');

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/tables');
});

test('the Group by menu groups the list, with counts and subtotals', async ({ page }) => {
  await page.goto('/en/admin/offers');
  await menu(page).locator('summary').click();
  await menu(page).getByRole('button', { name: 'City' }).click();
  await expect(menu(page).locator('summary')).toHaveText('Group by: City');
  await expect(headTitles(page)).toHaveText(['Athens', 'Erbil', 'Thessaloniki', 'No City']);
  const athens = heads(page).filter({ hasText: 'Athens' });
  await expect(athens).toContainText('4 rows');
  await expect(athens).toContainText('€ 4,130,000.00');
  // The order of the groups
  await menu(page).locator('summary').click();
  await menu(page).getByRole('button', { name: 'Z → A' }).click();
  await expect(headTitles(page)).toHaveText(['Thessaloniki', 'Erbil', 'Athens', 'No City']);
  // Remembered: a reload keeps the choice
  await page.reload();
  await expect(menu(page).locator('summary')).toHaveText('Group by: City');
  await expect(headTitles(page).first()).toHaveText('Thessaloniki');
  await menu(page).locator('summary').click();
  await menu(page).getByRole('button', { name: 'No grouping' }).click();
  await expect(heads(page)).toHaveCount(0);
});

test('collapse and expand a group, and all of them', async ({ page }) => {
  await page.goto('/en/admin/offers?admin-resource_group=status');
  const published = heads(page).filter({ hasText: 'Published' });
  const toggle = published.getByRole('button', { name: 'Collapse Published' });
  await expect(page.locator('tbody tr', { hasText: 'Villa in Kifisia' })).toBeVisible();
  await toggle.click();
  await expect(published.getByRole('button', { name: 'Expand Published' })).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('tbody tr', { hasText: 'Villa in Kifisia' })).toBeHidden();
  await expect(published.locator('[data-admin-group-hidden]')).toHaveText('4 on this page, hidden');
  // A save elsewhere redraws the list; the group stays collapsed
  await page.getByRole('switch', { name: 'Featured: Seaside flat, Glyfada' }).click();
  await expect(page.getByRole('switch', { name: 'Featured: Seaside flat, Glyfada' })).toHaveAttribute('aria-checked', 'true');
  await expect(page.locator('tbody tr', { hasText: 'Villa in Kifisia' })).toBeHidden();
  await published.getByRole('button', { name: 'Expand Published' }).click();
  await expect(page.locator('tbody tr', { hasText: 'Villa in Kifisia' })).toBeVisible();

  await menu(page).locator('summary').click();
  await menu(page).getByRole('button', { name: 'Collapse all' }).click();
  await expect(page.locator('tbody tr[data-admin-group-member]:visible')).toHaveCount(0);
  await menu(page).locator('summary').click();
  await menu(page).getByRole('button', { name: 'Expand all' }).click();
  await expect(page.locator('tbody tr[data-admin-group-member]:visible')).toHaveCount(8);
});

test('a group continues on the next page; its heading checkbox selects its rows', async ({ page }) => {
  await page.request.post('/__test/tables', { form: { per_page: '3' } });
  await page.goto('/en/admin/offers?admin-resource_group=city_id');
  await expect(headTitles(page)).toHaveText(['Athens']);
  await expect(heads(page)).toContainText('4 rows');
  await page.getByRole('button', { name: 'Page 2' }).click();
  await expect(heads(page).first()).toContainText('Athens');
  await expect(heads(page).first().locator('.fa-group-continued')).toHaveText('continued');
  await expect(heads(page).first()).toContainText('4 rows');

  await heads(page).filter({ hasText: 'Erbil' }).getByRole('checkbox').check();
  await expect(page.locator('[data-admin-selection-count]')).toHaveText(/^1 /);
  await heads(page).first().getByRole('checkbox').check();
  await expect(page.locator('[data-admin-selection-count]')).toHaveText(/^2 /);
});

test('the page list: Group by links and phones', async ({ page }) => {
  await page.request.post('/__test/tables', { form: { static: '1' } });
  await page.goto('/en/admin/offers');
  await menu(page).locator('summary').click();
  await menu(page).getByRole('link', { name: 'Month added' }).click();
  await expect(headTitles(page)).toHaveText(['September 2026', 'October 2026']);
  await menu(page).locator('summary').click();
  await expect(menu(page).getByRole('link', { name: 'Oldest first' })).toHaveAttribute('aria-current', 'true');
  await menu(page).locator('summary').click();
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(heads(page).first()).toBeVisible();
  await expect(heads(page).first()).toContainText('3 rows');
  await heads(page).first().getByRole('button', { name: 'Collapse September 2026' }).click();
  await expect(page.locator('tbody tr', { hasText: 'Villa in Kifisia' })).toBeHidden();
});
