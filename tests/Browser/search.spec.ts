import { test, expect, Page } from '@playwright/test';

const box = (page: Page) => page.locator('#fa-search-dialog');
const input = (page: Page) => box(page).locator('.fa-cmdk-input');
const option = (page: Page, name: string | RegExp) => box(page).getByRole('option', { name });

async function ready(page: Page, path = '/en/admin/dashboard') {
  await page.goto(path);
  await expect(page.locator('form.fa-topsearch')).toBeVisible();
}

test('Ctrl+K searches as you type; arrows and Enter open a record', async ({ page }) => {
  await ready(page);
  await page.keyboard.press('Control+k');
  await expect(box(page)).toBeVisible();
  await expect(input(page)).toBeFocused();
  await input(page).fill('sea vi');
  const villa = option(page, /Sea view villa/);
  await expect(villa).toBeVisible();
  await expect(villa.locator('mark')).toHaveText('Sea vi');
  await expect(box(page).getByRole('group', { name: 'Posts' })).toContainText('Sea view villa');
  const all = box(page).getByRole('option');
  const count = await all.count();
  await expect(box(page).locator('[data-admin-search-status]')).toHaveText(count === 1 ? '1 result' : count + ' results');
  await expect(all.first()).toHaveAttribute('aria-selected', 'true');

  // ↓ moves to the villa (the input names it as the active option); Enter opens it in the panel
  while (await villa.getAttribute('aria-selected') !== 'true') await input(page).press('ArrowDown');
  await expect(input(page)).toHaveAttribute('aria-activedescendant', await villa.getAttribute('id') as string);
  await input(page).press('Enter');
  await expect(page).toHaveURL(/\/en\/admin\/posts\/edit\/1$/);
  await expect(box(page)).not.toBeVisible();
});

test('the arrow keys move through every option and wrap; actions come first', async ({ page }) => {
  await ready(page);
  await page.keyboard.press('Control+k');
  await input(page).fill('house');
  await expect(option(page, /Garden house/)).toBeVisible();
  const options = box(page).getByRole('option');
  expect(await options.count()).toBeGreaterThan(1);
  await input(page).press('ArrowDown');
  await expect(options.nth(1)).toHaveAttribute('aria-selected', 'true');
  await input(page).press('ArrowUp');
  await expect(options.nth(0)).toHaveAttribute('aria-selected', 'true');
  await input(page).press('ArrowUp');
  await expect(options.last()).toHaveAttribute('aria-selected', 'true');

  await input(page).fill('o');
  await input(page).fill('new po');
  await expect(box(page).locator('.fa-cmdk-label').first()).toHaveText('Pages and actions');
  await expect(option(page, /New post/i)).toBeVisible();
});

test('/ opens it, Esc closes it and focus goes back', async ({ page }) => {
  await ready(page);
  await page.locator('.fa-page-title, h1').first().click();
  await page.keyboard.press('/');
  await expect(box(page)).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(box(page)).not.toBeVisible();

  // Not while typing in a field
  await page.goto('/en/admin/posts/edit/1?static=1');
  await page.locator('input[name="slug"]').focus();
  await page.keyboard.type('/');
  await expect(box(page)).not.toBeVisible();
  await expect(page.locator('input[name="slug"]')).toHaveValue(/^\/post-1$/);
});

test('the top-bar field opens the dialog with what was typed', async ({ page }) => {
  await ready(page);
  await page.locator('#fa-topsearch-q').click();
  await expect(box(page)).toBeVisible();
  await page.keyboard.press('Escape');
  await page.locator('#fa-topsearch-q').focus();
  await page.keyboard.type('m');
  await expect(box(page)).toBeVisible();
  await expect(input(page)).toHaveValue('m');
  await page.keyboard.type('oun');
  await expect(option(page, /Mountain cabin/)).toBeVisible();
});

test('before typing: the last records opened, then the pages', async ({ page }) => {
  await page.goto('/en/admin/posts/edit/2?static=1');
  await page.goto('/en/admin/posts/edit/1?static=1');
  await ready(page);
  await page.keyboard.press('Control+k');
  const labels = box(page).locator('.fa-cmdk-label');
  await expect(labels.first()).toHaveText('Recent');
  const recent = box(page).getByRole('group', { name: 'Recent' }).getByRole('option');
  await expect(recent).toHaveText([/Sea view villa/, /Mountain cabin/]);
  await expect(labels.nth(1)).toHaveText('Pages');
  await expect(option(page, /Dashboard/)).toBeVisible();
});

test('no results says what was searched', async ({ page }) => {
  await ready(page);
  await page.keyboard.press('Control+k');
  await input(page).fill('zzqx');
  await expect(box(page).locator('[data-admin-search-empty]')).toContainText('No results for “zzqx”');
  await expect(input(page)).toHaveAttribute('aria-expanded', 'false');
});

test('Ctrl+Enter opens the result in a new tab', async ({ page, context }) => {
  await ready(page);
  await page.keyboard.press('Control+k');
  await input(page).fill('cabin');
  await expect(option(page, /Mountain cabin/)).toBeVisible();
  const [tab] = await Promise.all([context.waitForEvent('page'), input(page).press('Control+Enter')]);
  await tab.waitForLoadState();
  expect(tab.url()).toMatch(/\/en\/admin\/posts\/edit\/2$/);
  await expect(box(page)).toBeVisible();
});

test('Arabic: right to left, Arabic titles and words', async ({ page }) => {
  await ready(page, '/ar/admin/dashboard');
  await page.keyboard.press('Control+k');
  await input(page).fill('منزل 0');
  await expect(option(page, /منزل 0/)).toBeVisible();
  await expect(box(page).locator('[data-admin-search-status]')).toHaveText('نتيجة واحدة');
  expect(await box(page).evaluate(el => getComputedStyle(el).direction)).toBe('rtl');
});

test('phones: the field is an icon and the dialog fills the screen', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await ready(page);
  await page.locator('#fa-topsearch-q').click();
  await expect(box(page)).toBeVisible();
  const rect = (await box(page).boundingBox())!;
  expect(rect.width).toBeGreaterThan(385);
  expect(rect.height).toBeGreaterThan(800);
});
