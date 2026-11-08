import { test, expect, Page } from '@playwright/test';

/* The dashboard of 0.42: greeting, period buttons, Refresh, quick buttons, headline
   cards, the chart with the previous period, donut, lists and recent activity. */

const card = (page: Page, title: string) => page.locator('[data-admin-widget]').filter({ has: page.getByRole('heading', { name: title }) });

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/history');
  await page.request.post('/__test/dashboard');
});
test.afterEach(async ({ page }) => { await page.request.post('/__test/history-off'); });

test('the header, the headline cards and every widget type', async ({ page }) => {
  await page.goto('/en/admin/dashboard');
  await expect(page.locator('h1')).toHaveText(/^Good (morning|afternoon|evening), Sara$/);
  await expect(page.locator('.fa-period.is-current')).toHaveText('30 days');
  await expect(page.locator('.fa-dash-actions .fa-btn-primary')).toHaveText('New Post');
  const cards = page.locator('.fa-widget-cards .fa-widget-stat');
  await expect(cards).toHaveCount(4);
  await expect(cards.nth(1).locator('.fa-trend')).toHaveClass(/is-good/);
  await expect(cards.nth(1).locator('.fa-sparkline')).toBeVisible();
  await expect(cards.nth(0).locator('.fa-help')).toHaveClass(/fa-stat-note-warning/);
  await expect(card(page, 'Posts per day').locator('.fa-chart-previous')).toHaveCount(1);
  await expect(card(page, 'Posts per day').locator('.fa-widget-sub')).toHaveText('Last 30 days');
  await expect(card(page, 'Posts by status').locator('.fa-donut-legend li')).toHaveCount(2);
  await expect(card(page, 'Needs attention').locator('.fa-list-item')).toHaveCount(2);
  await expect(card(page, 'Projects').locator('.fa-progress-item')).toHaveCount(3);
  await expect(card(page, 'Visitors by language').locator('.fa-barlist-row')).toHaveCount(3);
  // Nothing spills out of its card
  const overflow = await page.evaluate(() => [...document.querySelectorAll('[data-admin-widget]')].filter(e => e.scrollWidth > e.clientWidth + 1).length);
  expect(overflow).toBe(0);
});

test('the period buttons change every card, and Refresh keeps the period', async ({ page }) => {
  await page.goto('/en/admin/dashboard');
  const newPosts = page.locator('.fa-widget-cards .fa-widget-stat').nth(1).locator('.fa-stat-value');
  const thirty = await newPosts.textContent();
  await page.locator('.fa-periods').getByRole('link', { name: '7 days' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/dashboard\?period=7$/);
  await expect(page.locator('.fa-period.is-current')).toHaveText('7 days');
  await expect(card(page, 'Posts per day').locator('.fa-widget-sub')).toHaveText('Last 7 days');
  await expect(card(page, 'Posts per day').locator('.fa-chart-x span')).toHaveCount(7);
  await page.locator('.fa-periods').getByRole('link', { name: '12 months' }).click();
  await expect(page.locator('.fa-period.is-current')).toHaveText('12 months');
  expect(Number(await newPosts.textContent())).toBeGreaterThanOrEqual(Number(thirty));
  await page.getByRole('button', { name: 'Refresh dashboard' }).click();
  await expect(page.locator('.fa-period.is-current')).toHaveText('12 months');
  await expect(card(page, 'Posts per day').locator('.fa-widget-sub')).toHaveText('Last 12 months');
});

test('a quick button opens the create page; recent activity shows the change', async ({ page }) => {
  await page.goto('/en/admin/dashboard');
  await page.locator('.fa-dash-actions').getByRole('link', { name: 'New tag' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/(post-)?tags\/create$/);
  await page.locator('[name="name"]').fill('Balcony');
  await page.getByRole('button', { name: 'Save', exact: true }).first().click();
  await page.goto('/en/admin/dashboard');
  await expect(card(page, 'Recent activity')).toContainText('Balcony');
  await expect(card(page, 'Recent activity').getByRole('link', { name: 'See all' })).toHaveAttribute('href', /\/en\/admin\/activity$/);
});

test('Arabic mirrors the dashboard', async ({ page }) => {
  await page.goto('/ar/admin/dashboard');
  await expect(page.locator('.fa-period.is-current')).toHaveText('30 يومًا');
  const head = await page.locator('.fa-dash-head h1').boundingBox();
  const tools = await page.locator('.fa-dash-tools').boundingBox();
  expect(tools!.x).toBeLessThan(head!.x);
});

test('on a phone the cards go two by two and nothing scrolls sideways', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/en/admin/dashboard');
  const first = await page.locator('.fa-widget-cards .fa-widget-stat').nth(0).boundingBox();
  const second = await page.locator('.fa-widget-cards .fa-widget-stat').nth(1).boundingBox();
  expect(Math.abs(first!.y - second!.y)).toBeLessThan(2);
  const tools = await page.locator('.fa-periods').boundingBox();
  const refresh = await page.locator('#fa-dashboard-refresh').boundingBox();
  expect(Math.abs(tools!.y - refresh!.y)).toBeLessThan(6);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

test('without JavaScript the period buttons and Refresh still work', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.request.post('/__test/dashboard');
  await page.goto('/en/admin/dashboard');
  await page.locator('.fa-periods').getByRole('link', { name: '90 days' }).click();
  await expect(page.locator('.fa-period.is-current')).toHaveText('90 days');
  await page.getByRole('button', { name: 'Refresh dashboard' }).click();
  await expect(page).toHaveURL(/period=90/);
  await expect(page.locator('.fa-period.is-current')).toHaveText('90 days');
  await context.close();
});
