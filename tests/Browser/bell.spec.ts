import { test, expect, Page } from '@playwright/test';

/* The notification bell (0.41): the badge, the menu, opening, marking read,
   the Notifications page, live updates, RTL and the menu without JavaScript. */

const bell = (page: Page) => page.locator('#fa-bell');
const badge = (page: Page) => page.locator('[data-admin-bell-badge]');
const menu = (page: Page) => page.locator('[data-admin-bell-menu]');
async function state(page: Page): Promise<{ id: number; title: string; url: string | null; read_at: string | null }[]> {
  return (await page.request.post('/__test/bell-state')).json();
}

test.beforeEach(async ({ page }) => { await page.request.post('/__test/bell'); });
test.afterEach(async ({ page }) => { await page.request.post('/__test/bell-off'); });

test('the badge counts unread notifications and the menu lists the latest', async ({ page }) => {
  await page.goto('/en/admin/dashboard');
  await expect(badge(page)).toHaveText('2');
  await expect(bell(page).locator('summary')).toHaveAttribute('aria-label', 'Notifications, 2 unread');
  await expect(page).toHaveTitle(/^\(2\) /);
  // The badge sits on the bell's corner: the icon stays visible
  const icon = await bell(page).locator('summary svg').boundingBox();
  const mark = await badge(page).boundingBox();
  expect(mark!.x).toBeGreaterThan(icon!.x + icon!.width / 2);
  expect(mark!.y).toBeLessThan(icon!.y + icon!.height / 2);

  await bell(page).locator('summary').click();
  await expect(menu(page)).toBeVisible();
  const items = menu(page).locator('.fa-bell-item');
  await expect(items).toHaveCount(3);
  await expect(items.nth(0)).toContainText('New inquiry from Maria Papadopoulou');
  await expect(items.nth(0)).toContainText('Property · Urban86 · FH-2026-0143');
  await expect(items.nth(0)).toContainText('Just now');
  await expect(items.nth(1)).toContainText('1 hour ago');
  await expect(items.nth(0)).toHaveClass(/is-unread/);
  await expect(items.nth(2)).not.toHaveClass(/is-unread/);
  await expect(menu(page)).toContainText('2 unread');

  // Escape closes it and focus returns to the bell
  await page.keyboard.press('Escape');
  await expect(menu(page)).not.toBeVisible();
  await expect(bell(page).locator('summary')).toBeFocused();
});

test('opening a notification marks it read and opens its page', async ({ page }) => {
  await page.goto('/en/admin/dashboard');
  await bell(page).locator('summary').click();
  await menu(page).locator('.fa-bell-item').first().click();
  await expect(page).toHaveURL(/\/en\/admin\/posts\/edit\/1$/);
  await expect(badge(page)).toHaveText('1');
  const rows = await state(page);
  expect(rows.find(row => row.title === 'New inquiry from :name' && row.url === 'posts/edit/1')!.read_at).not.toBeNull();
});

test('mark all as read from the menu keeps the person on their page', async ({ page }) => {
  // Reached inside the panel (no page load): the menu still returns here
  await page.goto('/en/admin/dashboard');
  await page.locator('.fa-nav').getByRole('link', { name: 'Posts', exact: true }).click();
  await expect(page).toHaveURL(/\/en\/admin\/posts$/);
  await bell(page).locator('summary').click();
  await menu(page).getByRole('button', { name: 'Mark all as read' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/posts$/);
  await expect(badge(page)).toBeHidden();
  await expect(page).not.toHaveTitle(/^\(\d/);
  expect((await state(page)).every(row => row.read_at !== null)).toBe(true);
  await bell(page).locator('summary').click();
  await expect(menu(page)).not.toContainText('Mark all as read');
});

test('the Notifications page: tabs, mark read and delete', async ({ page }) => {
  await page.goto('/en/admin/dashboard');
  await bell(page).locator('summary').click();
  await menu(page).getByRole('link', { name: 'See all notifications' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/notifications$/);
  await expect(page.locator('h1')).toHaveText('Notifications');
  await expect(page.locator('.fa-inbox-row')).toHaveCount(3);
  await page.getByRole('link', { name: /Unread/ }).click();
  await expect(page.locator('.fa-inbox-row')).toHaveCount(2);

  await page.locator('.fa-inbox-row').first().getByRole('button', { name: 'Mark as read' }).click();
  await expect(page.locator('.fa-inbox-row')).toHaveCount(1);
  await expect(badge(page)).toHaveText('1');

  await page.goto('/en/admin/notifications');
  await page.locator('.fa-inbox-row').last().getByRole('button', { name: 'Delete notification' }).click();
  await expect(page.locator('[data-admin-notification]').filter({ hasText: 'Notification deleted.' })).toBeVisible();
  await expect(page.locator('.fa-inbox-row')).toHaveCount(2);
});

test('an open panel picks up new notifications without reloading', async ({ page }) => {
  await page.clock.install();
  await page.goto('/en/admin/posts');
  await expect(badge(page)).toHaveText('2');
  await page.request.post('/__test/bell-add');
  await page.clock.fastForward(61_000);
  await expect(badge(page)).toHaveText('3');
  await expect(page).toHaveTitle(/^\(3\) /);
  await bell(page).locator('summary').click();
  await expect(menu(page).locator('.fa-bell-item').first()).toContainText('New inquiry from Elena Vasileiou');
  // The website page it points to opens on the site
  const link = menu(page).locator('.fa-bell-item').first();
  await expect(link).toHaveAttribute('href', /\/en\/admin\/notifications\/open\/\d+$/);
});

test('Arabic: the bell and its menu mirror', async ({ page }) => {
  await page.goto('/ar/admin/dashboard');
  await expect(bell(page).locator('summary')).toHaveAttribute('aria-label', 'الإشعارات، 2 غير مقروءة');
  const icon = await bell(page).locator('summary svg').boundingBox();
  const mark = await badge(page).boundingBox();
  expect(mark!.x + mark!.width).toBeLessThan(icon!.x + icon!.width / 2 + 1);
  await bell(page).locator('summary').click();
  await expect(menu(page)).toContainText('تحديد الكل كمقروء');
  const panel = await menu(page).boundingBox();
  const button = await bell(page).locator('summary').boundingBox();
  // The menu opens towards the page, its start edge under the bell
  expect(Math.abs(panel!.x - button!.x)).toBeLessThan(2);
});

test('without JavaScript the bell is a working menu', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.request.post('/__test/bell');
  await page.goto('/en/admin/dashboard');
  await expect(badge(page)).toHaveText('2');
  await bell(page).locator('summary').click();
  await expect(menu(page)).toBeVisible();
  await menu(page).getByRole('button', { name: 'Mark all as read' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/dashboard$/);
  await expect(badge(page)).toBeHidden();
  await page.request.post('/__test/bell-off');
  await context.close();
});

test('on a phone the menu fits the screen', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/en/admin/dashboard');
  await bell(page).locator('summary').click();
  const panel = await menu(page).boundingBox();
  expect(panel!.x).toBeGreaterThanOrEqual(0);
  expect(panel!.x + panel!.width).toBeLessThanOrEqual(390);
});
