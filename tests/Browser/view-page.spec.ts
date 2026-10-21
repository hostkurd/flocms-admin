import { test, expect } from '@playwright/test';

/* The view page (0.27): projects have sections, an aside, rich text and translations. */

test('the view page shows the record as values, with Edit, More and Print', async ({ page }) => {
  await page.goto('/en/admin/projects/view/1');
  await expect(page.locator('h1')).toHaveText('Harbour bridge');
  await expect(page.locator('.fa-view-card-title')).toHaveText(['Project', 'SEO', 'Publishing', 'Timeline']);
  await expect(page.locator('[data-field=summary] dd')).toHaveText('Summary 1');
  await expect(page.locator('[data-field=detail] .fa-view-rich p')).toHaveText('Detail');
  await expect(page.locator('.fa-view input:not([type=hidden])')).toHaveCount(0);
  // The aside is on the end side
  const main = await page.locator('.fa-view-main').boundingBox();
  const aside = await page.locator('.fa-view-aside').boundingBox();
  expect(aside!.x).toBeGreaterThan(main!.x + main!.width - 1);

  // More: the record's actions, Print, then Delete after a divider
  await page.getByText('More', { exact: true }).click();
  const menu = page.locator('.fa-view-more .fa-menu-panel');
  await expect(menu.getByRole('button', { name: 'Print' })).toBeVisible();
  await expect(menu.locator('.fa-menu-divider + form button')).toContainText('Delete');
  await page.keyboard.press('Escape');

  await page.locator('.fa-view-actions [data-admin-action=edit]').click();
  await expect(page).toHaveURL(/\/admin\/projects\/edit\/1/);
});

test('the copy button copies the slug and says so', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.goto('/en/admin/projects/view/1');
  const copy = page.locator('[data-field=slug] .fa-view-copy');
  await expect(copy).toBeVisible();
  await copy.click();
  await expect(copy).toHaveAttribute('aria-label', 'Copied');
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('project-1');
});

test('the language switch shows each language and marks what is not translated', async ({ page }) => {
  await page.goto('/en/admin/projects/view/1');
  const arabic = page.locator('.fa-view-switch a[lang=ar]');
  await expect(arabic.locator('.fa-view-dot')).toHaveCount(1);
  await arabic.click();
  await expect(page).toHaveURL(/showing=ar/);
  await expect(page.locator('[data-field=name] dd')).toHaveText('مشروع 0');
  await expect(page.locator('[data-field=name] dd')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('[data-field=detail] .fa-view-missing')).toHaveText('Not translated');
  await expect(page.locator('.fa-view-switch a[aria-current=true]')).toHaveAttribute('lang', 'ar');
});

test('a viewer opens records from the list on the view page, without Edit', async ({ page }) => {
  await page.request.post('/__test/readonly');
  await page.goto('/en/admin/posts');
  const title = page.locator('tbody .fa-row-link').first();
  await expect(title).toHaveAttribute('href', /\/admin\/posts\/view\/\d+$/);
  await title.click();
  await expect(page).toHaveURL(/\/admin\/posts\/view\/\d+$/);
  await expect(page.locator('.fa-view-actions [data-admin-action=edit]')).toHaveCount(0);
  await page.getByText('More', { exact: true }).click();
  await expect(page.locator('.fa-view-more .fa-menu-panel .fa-menu-item')).toHaveText(['Print']);
});

test('an editor\'s row title opens Edit and the row menu offers View', async ({ page }) => {
  await page.goto('/en/admin/projects');
  await expect(page.locator('tbody .fa-row-link').first()).toHaveAttribute('href', /\/admin\/projects\/edit\/\d+/);
  await page.locator('tbody tr').first().locator('.fa-row-actions summary, .fa-row-actions [aria-haspopup]').first().click();
  await expect(page.locator('tbody tr').first().locator('[data-admin-action=view]')).toBeVisible();
});

test('printing shows the values only, with a printed-by line', async ({ page }) => {
  await page.goto('/en/admin/projects/view/1');
  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('.fa-sidebar')).toBeHidden();
  await expect(page.locator('.fa-view-actions')).toBeHidden();
  await expect(page.locator('.fa-view-switch')).toBeHidden();
  await expect(page.locator('.fa-view-print-line')).toBeVisible();
  await expect(page.locator('.fa-view-print-line')).toContainText('by Sara');
});

test('on a phone the actions sit at the bottom and More opens upwards', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/ar/admin/projects/view/1');
  const bar = await page.locator('.fa-view-actions').boundingBox();
  expect(bar!.y + bar!.height).toBeGreaterThan(830);
  const more = await page.locator('.fa-view-more > summary').boundingBox();
  await page.locator('.fa-view-more > summary').click();
  const panel = await page.locator('.fa-view-more .fa-menu-panel').boundingBox();
  expect(panel!.y + panel!.height).toBeLessThanOrEqual(more!.y);
  await page.screenshot({ path: 'test-results/view-phone-menu.png' });
  expect(panel!.y).toBeGreaterThanOrEqual(0);
  // One column
  const fields = page.locator('.fa-view-main .fa-view-field');
  const a = await fields.nth(0).boundingBox(); const b = await fields.nth(1).boundingBox();
  expect(b!.y).toBeGreaterThan(a!.y);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('the page works; Print and Copy stay hidden', async ({ page }) => {
    await page.goto('/en/admin/projects/view/1');
    await expect(page.locator('h1')).toHaveText('Harbour bridge');
    await expect(page.locator('[data-admin-print]')).toBeHidden();
    await expect(page.locator('[data-admin-copy]').first()).toBeHidden();
    await page.locator('.fa-view-switch a[lang=ar]').click();
    await expect(page.locator('[data-field=name] dd')).toHaveText('مشروع 0');
  });
});

test('More opens under its button, inside the window (the sticky header is blurred)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/en/admin/projects/view/1');
  const button = page.locator('.fa-view-more > summary');
  await button.click();
  const panel = page.locator('.fa-view-more .fa-menu-panel');
  await expect(panel).toBeVisible();
  await expect.poll(async () => (await panel.boundingBox())!.x + (await panel.boundingBox())!.width).toBeLessThanOrEqual(1440 - 12);
  const b = (await button.boundingBox())!, p = (await panel.boundingBox())!;
  expect(Math.abs(p.x + p.width - (b.x + b.width))).toBeLessThan(2);
  expect(p.y).toBeGreaterThan(b.y + b.height - 1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});
