import { test, expect, type Page } from '@playwright/test';

// Custom panel pages (0.36): a report with filters, widgets, a view and a table; a tool page
// with a form; a help page; the sidebar groups; Arabic, dark and a phone.
async function open(page: Page, path = '/en/admin/sales-report') {
  await page.goto('/en/admin/dashboard');
  await page.request.post('/__test/custom-pages');
  await page.goto(path);
}

const sidebar = (page: Page) => page.locator('#fa-sidebar');

test('the report shows filters, widgets, its view and a table that searches, sorts and links', async ({ page }) => {
  await open(page);
  await expect(page.getByRole('heading', { level: 1, name: 'Sales report' })).toBeVisible();
  await expect(page.locator('.fa-page-eyebrow')).toHaveText('Reports');
  await expect(page.getByRole('heading', { name: 'Page stats' })).toBeVisible();
  await expect(page.locator('.fa-page-view')).toContainText('Monthly sales');
  await expect(page.locator('.fa-page-table tbody tr')).toHaveCount(8);
  // Sidebar: the page is current in its group, with its badge.
  const item = sidebar(page).getByRole('link', { name: /Sales report/ });
  await expect(item).toHaveAttribute('aria-current', 'page');
  await expect(item.locator('.fa-nav-badge')).toHaveText('3');
  await expect(sidebar(page).locator('.fa-nav-heading', { hasText: 'Tools' })).toBeVisible();

  // Filters stay in the address and reach the view and the widget.
  await page.getByRole('search', { name: 'Filters' }).getByLabel('Status').selectOption('draft');
  await page.getByRole('button', { name: 'Apply' }).click();
  await expect(page).toHaveURL(/filter%5Bstatus%5D=draft|filter\[status\]=draft/);
  await expect(page.locator('.fa-page-view .fa-stat-value')).toHaveText('draft');
  await expect(page.locator('[data-admin-widget]')).toContainText('filter:draft');
  await page.reload();
  await expect(page.getByRole('search', { name: 'Filters' }).getByLabel('Status')).toHaveValue('draft');

  // Search and sort keep the filter.
  await page.getByRole('searchbox', { name: 'Search' }).last().fill('post-3');
  await page.getByRole('searchbox', { name: 'Search' }).last().press('Enter');
  await expect(page.locator('.fa-page-table tbody tr')).toHaveCount(1);
  await expect(page).toHaveURL(/status/);
  await page.locator('.fa-page-table .fa-search-clear').click();
  await page.locator('.fa-page-table th a', { hasText: 'Slug' }).click();
  await page.locator('.fa-page-table th a', { hasText: 'Slug' }).click();
  await expect(page.locator('.fa-page-table tbody tr').first()).toContainText('post-8');
  await expect(page.getByRole('link', { name: 'Reset' })).toBeVisible();

  // The row link opens the record.
  await page.locator('.fa-page-table tbody tr').first().getByRole('link').first().click();
  await expect(page).toHaveURL(/\/en\/admin\/posts\/edit\//);
});

test('the CSV download, a header action with a form and one that runs at once', async ({ page }) => {
  await open(page);
  const download = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download CSV' }).click();
  expect((await download).suggestedFilename()).toMatch(/^sales-report-\d{4}-\d{2}-\d{2}\.csv$/);

  await page.getByRole('link', { name: 'Email this report' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Email this report' })).toBeVisible();
  await page.getByLabel('Email').fill('not-an-address');
  await page.getByRole('button', { name: 'Email this report' }).click();
  await expect(page.locator('.has-error')).toBeVisible();
  await page.getByLabel('Email').fill('boss@example.test');
  await page.getByRole('button', { name: 'Email this report' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Sales report' })).toBeVisible();
  await expect(page.locator('[data-admin-notification]', { hasText: 'Done: Email this report.' })).toBeVisible();

  await page.getByRole('button', { name: 'Recount' }).click();
  await expect(page.locator('[data-admin-notification]', { hasText: 'Recounted.' })).toBeVisible();
});

test('a tool page validates, saves and warns about unsaved changes', async ({ page }) => {
  await open(page, '/en/admin/exchange-rates');
  await expect(page.locator('.fa-custom-page')).toHaveAttribute('data-width', 'narrow');
  const rate = page.getByRole('spinbutton', { name: 'Rate' });
  await expect(rate).toHaveValue('1460');
  await rate.fill('-1');
  await page.getByRole('button', { name: 'Save rates' }).click();
  await expect(page.getByText('Enter a rate above zero.')).toBeVisible();
  await page.getByRole('spinbutton', { name: 'Rate' }).fill('1500');
  await page.getByRole('button', { name: 'Save rates' }).click();
  await expect(page.locator('[data-admin-notification]', { hasText: 'Rates saved.' })).toBeVisible();
  await expect(page.getByRole('spinbutton', { name: 'Rate' })).toHaveValue('1500');
  // Leaving with a change asks first.
  await page.getByRole('spinbutton', { name: 'Rate' }).fill('1600');
  await sidebar(page).getByRole('link', { name: /Sales report/ }).click();
  await expect(page.locator('#fa-leave')).toBeVisible();
  await page.getByRole('button', { name: 'Keep editing' }).click();
  await expect(page).toHaveURL(/exchange-rates/);
});

test('the help page, Ctrl+K search and pages under Content', async ({ page }) => {
  await open(page, '/en/admin/getting-started');
  await expect(page.locator('.fa-page-view')).toContainText('Invite your team');
  const help = sidebar(page).getByRole('link', { name: 'Getting started' });
  await expect(help).toHaveAttribute('aria-current', 'page');
  await page.keyboard.press('Control+k');
  await page.locator('#fa-cmdk-q').fill('exchange');
  await expect(page.locator('#fa-cmdk-list')).toContainText('Exchange rates');
  await page.locator('#fa-cmdk-list [role="option"]', { hasText: 'Exchange rates' }).click();
  await expect(page).toHaveURL(/\/admin\/exchange-rates$/);
});

test('Arabic, dark and phone layouts', async ({ page }) => {
  await open(page, '/ar/admin/sales-report');
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('.fa-page-table')).toBeVisible();
  const box = await page.locator('.fa-page-filters').boundingBox();
  expect(box).not.toBeNull();

  await page.emulateMedia({ colorScheme: 'dark' });
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto('/en/admin/sales-report');
  // The filter fields stack and nothing scrolls sideways.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
  const fields = page.locator('.fa-page-filters .fa-field');
  const first = await fields.nth(0).boundingBox();
  const second = await fields.nth(1).boundingBox();
  expect(second!.y).toBeGreaterThan(first!.y);
  await expect(page.locator('.fa-page-table td[data-label="Slug"]').first()).toBeVisible();
});
