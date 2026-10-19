import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

type Product = { id: number; code: string; title: string; category: string; price: number | null; in_stock: number; city_id: number | null; released_on: string | null };
async function state(page: Page): Promise<{ products: Product[]; features: { product_id: number; feature_id: number }[] }> {
  return (await page.request.post('/__test/products-state')).json();
}
const csv = (lines: string[]) => Buffer.from('﻿' + lines.join('\r\n') + '\r\n', 'utf8');
const FILE = csv([
  'code,Title (English),title.ar,category,price,In stock,city,features.names,released_on',
  'P-100,Garden hose XL,,garden,21.5,yes,Erbil,Pool|Sea view,05/04/2026',
  'P-200,Shovel,مجرفة,Garden,9,no,Athens,,',
  'P-201,,,toys,abc,maybe,Nowhere,,31/31/2026',
]);

async function upload(page: Page, buffer = FILE, name = 'products.csv') {
  await page.goto('/en/admin/products');
  await page.getByRole('link', { name: 'Import', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Import Products' })).toBeVisible();
  await page.locator('[data-import-file]').setInputFiles({ name, mimeType: 'text/csv', buffer });
  await expect(page).toHaveURL(/\/import\/\d+\/match$/);
}

/** Without JavaScript each click on Continue runs one batch. */
async function continueUntil(page: Page, selector: string) {
  for (let i = 0; i < 5 && !(await page.locator(selector).count()); i++) {
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
  }
}

test.beforeEach(async ({ page }) => { await page.request.post('/__test/products'); });

test('Export downloads the list as CSV, with readable headings and the template', async ({ page }) => {
  await page.goto('/en/admin/products');
  const menu = page.locator('#fa-admin-resource-export-menu');
  await menu.locator('summary').click();
  await expect(menu).toContainText('Download CSV · 3 Products');
  const [download] = await Promise.all([page.waitForEvent('download'), menu.getByRole('button', { name: /Download CSV/ }).click()]);
  expect(download.suggestedFilename()).toMatch(/^products-\d{4}-\d{2}-\d{2}\.csv$/);
  const text = readFileSync((await download.path())!, 'utf8');
  expect(text.startsWith('﻿"id","code","title.en","title.ar"')).toBe(true);
  expect(text).toContain('"\'=Danger, ""quoted"""');
  expect(text).toContain('"Pool | Garden"');
  await expect(menu).not.toHaveAttribute('open', '');
  await expect(page.locator('tbody')).toContainText('Hammer', { timeout: 1000 });

  await menu.locator('summary').click();
  await menu.getByLabel(/Readable headings/).check();
  const [readable] = await Promise.all([page.waitForEvent('download'), menu.getByRole('button', { name: /Download CSV/ }).click()]);
  expect(readFileSync((await readable.path())!, 'utf8')).toContain('"Title (English)","Title (العربية)"');

  await menu.locator('summary').click();
  const [template] = await Promise.all([page.waitForEvent('download'), menu.getByRole('link', { name: /Empty template/ }).click()]);
  expect(readFileSync((await template.path())!, 'utf8')).toBe('﻿"code","title.en","title.ar","title.ku","summary","category","price","in_stock","city_id","features","released_on"\r\n');
});

test('Export selected downloads only the ticked rows', async ({ page }) => {
  // The selection checkboxes come with bulk actions: Posts has them.
  await page.goto('/en/admin/posts');
  await page.locator('[data-admin-select][value="8"]').check();
  const button = page.locator('[data-admin-export-selected]');
  await expect(button).toBeVisible();
  const [download] = await Promise.all([page.waitForEvent('download'), button.click()]);
  const text = readFileSync((await download.path())!, 'utf8');
  expect(text).toContain('"post-8"');
  expect(text).not.toContain('"post-7"');
});

test('import: upload, match, check, import, result and undo', async ({ page }) => {
  await upload(page);
  const rows = page.locator('.fa-import-columns tbody tr');
  await expect(rows).toHaveCount(9);
  await expect(rows.nth(6)).toContainText('Erbil · Athens · Nowhere');
  await expect(rows.nth(6).locator('select')).toHaveValue('city_id');
  await expect(rows.nth(6).locator('[data-import-state]')).toHaveText('✓ Matched');
  await rows.nth(7).locator('select').selectOption('');
  await expect(rows.nth(7).locator('[data-import-state]')).toHaveText('Skipped');
  await rows.nth(7).locator('select').selectOption('features');
  await expect(page.locator('.fa-import-ok')).toContainText('Every required field has a column.');

  await page.getByLabel(/Create new and update existing/).check();
  await expect(page.locator('#fa-import-key')).toHaveValue('code');
  await page.getByRole('button', { name: 'Next: check rows' }).click();
  await expect(page).toHaveURL(/\/preview$/);
  await expect(page.locator('.fa-import-tile').first()).toContainText('2');
  await expect(page.locator('.fa-import-tile').first()).toContainText('rows ready: 1 new, 1 to update');
  await expect(page.locator('.fa-import-errors')).toContainText('No City called “Nowhere”.');
  await expect(page.locator('.fa-import-errors tr').filter({ hasText: 'Nowhere' })).toHaveCount(1);
  await expect(page.locator('.fa-alert-warning')).toContainText('Empty cells will clear values');

  await page.getByRole('button', { name: 'Import rows (2)' }).click();
  await expect(page).toHaveURL(/\/result$/);
  await expect(page.locator('.fa-import-result-title')).toHaveText('Imported 2 Products');
  const db = await state(page);
  expect(db.products.find(p => p.code === 'P-100')).toMatchObject({ price: 21.5, released_on: '2026-04-05', city_id: 1 });
  expect(JSON.parse(db.products.find(p => p.code === 'P-200')!.title)).toEqual({ en: 'Shovel', ar: 'مجرفة' });
  expect(db.features.filter(f => f.product_id === 1).map(f => f.feature_id)).toEqual([1, 3]);

  await page.getByRole('button', { name: 'Undo import' }).click();
  const confirm = page.locator('dialog[open]');
  // The trash is off here, so the new records are deleted for good.
  await expect(confirm).toContainText('New records are deleted for good and updated ones get their old values back.');
  await confirm.getByRole('button', { name: 'Undo import' }).click();
  await expect(page.locator('.fa-import-result-title')).toHaveText('Import undone');
  const after = await state(page);
  expect(after.products.map(p => p.code)).toEqual(['P-100', 'P-101', 'P-102']);
  expect(after.products[0]).toMatchObject({ price: 19.5, released_on: '2026-03-01' });

  await page.goto('/en/admin/imports');
  await expect(page.locator('.fa-imports tbody tr').first()).toContainText('products.csv');
  await expect(page.locator('.fa-imports tbody tr').first()).toContainText('Undone');
});

test('a file that is not CSV is refused on the upload screen', async ({ page }) => {
  await page.goto('/en/admin/products/import');
  await page.locator('[data-import-file]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: readFileSync('tests/Browser/fixtures/photo.png') });
  await expect(page.locator('[data-import-error]')).toBeVisible();
  await expect(page).toHaveURL(/\/import$/);
});

test('Arabic: the steps and the match screen read right to left', async ({ page }) => {
  await upload(page);
  const url = page.url().replace('/en/', '/ar/');
  await page.goto(url);
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('.fa-step.is-current')).toHaveAttribute('aria-current', 'step');
  await page.screenshot({ path: 'test-results/import-match-ar.png', fullPage: true });
});

test('phone: the upload and match screens fit the screen', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await upload(page);
  const width = await page.evaluate(() => document.documentElement.scrollWidth);
  expect(width).toBeLessThanOrEqual(390);
  await page.screenshot({ path: 'test-results/import-match-phone.png', fullPage: true });
});

test('without JavaScript the import works with the Continue button', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, baseURL: 'http://127.0.0.1:8083' });
  const page = await context.newPage();
  await page.request.post('/__test/products');
  await page.goto('/en/admin/products/import');
  await page.locator('[data-import-file]').setInputFiles({ name: 'products.csv', mimeType: 'text/csv', buffer: FILE });
  await page.getByRole('button', { name: 'Next: match columns' }).click();
  await expect(page).toHaveURL(/\/match$/);
  await page.getByRole('button', { name: 'Next: check rows' }).click();
  // The check runs one batch per click without JavaScript.
  await continueUntil(page, '.fa-import-tile');
  // The file has the import key (code), so existing rows are updated by default.
  await expect(page.locator('.fa-import-tile').first()).toContainText('2');
  await page.getByRole('button', { name: 'Import rows (2)' }).click();
  await continueUntil(page, '.fa-import-result');
  await expect(page.locator('.fa-import-result-title')).toContainText('Imported 2 Products');
  await context.close();
});
