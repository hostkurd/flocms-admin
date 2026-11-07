import { test, expect, Page } from '@playwright/test';

// Duplicate (0.40): the row menu opens the New form filled from the record; nothing is saved until Create.
type Lots = { lots: Array<{ id: number | string; title: string; slug: string; code: string | null; price: string | null; brochure: string | null }>; features: Array<{ lot_id: number | string; feature_id: number | string }>; files: boolean[] };
const dialog = (page: Page) => page.locator('dialog.fa-editor');
async function lots(page: Page): Promise<Lots> { return (await page.request.post('/__test/lots')).json(); }
async function openCopy(page: Page, lang = 'en') {
  await page.goto(`/${lang}/admin/lots`);
  await page.locator('#fa-admin-resource-menu-1 summary').click();
  await page.locator('#fa-admin-resource-duplicate-1').click();
  await expect(dialog(page)).toBeVisible();
}

test('the row menu opens a copy in the dialog and Create saves a new record with its own file', async ({ page }) => {
  await page.goto('/en/admin/lots');
  await page.locator('#fa-admin-resource-menu-1 summary').click();
  // Its own group just before Delete
  await expect(page.locator('#fa-admin-resource-menu-1 .fa-menu-panel > *').nth(-3)).toHaveAttribute('id', 'fa-admin-resource-duplicate-1');
  await page.locator('#fa-admin-resource-duplicate-1').click();
  await expect(dialog(page)).toBeVisible();
  await expect(page.locator('#fa-admin-resource-editor-title')).toHaveText('Duplicate “Villa A1”');
  await expect(dialog(page).locator('.fa-duplicate-note').first()).toHaveText('This is a copy of “Villa A1”. Nothing is saved until you save it. Records linked to the original are not copied.');
  await expect(dialog(page).locator('[name=slug]')).toHaveValue('villa-a1-copy');
  await expect(dialog(page).locator('[name=code]')).toHaveValue('');
  await expect(dialog(page).locator('[name=price]')).toHaveValue('250000');
  await expect(dialog(page).locator('[name=brochure]')).toHaveValue(/^@upload:/);
  expect((await lots(page)).lots).toHaveLength(1);
  await dialog(page).locator('[name=code]').fill('A2');
  await dialog(page).getByRole('button', { name: 'Create', exact: true }).click();
  await expect(dialog(page)).not.toBeVisible();
  await expect(page.getByText('Lot created.').first()).toBeVisible();
  const state = await lots(page);
  expect(state.lots.map(l => [l.slug, l.code, l.price])).toEqual([['villa-a1', 'A1', '250000'], ['villa-a1-copy', 'A2', '250000']]);
  expect(state.lots[1].brochure).not.toBe(state.lots[0].brochure);
  expect(state.files).toEqual([true, true]);
  expect(state.features.filter(f => String(f.lot_id) === '2').map(f => String(f.feature_id))).toEqual(['1', '3']);
});

test('a new file chosen in the copy is uploaded for the new record only', async ({ page }) => {
  await openCopy(page);
  const response = page.waitForResponse(r => r.url().endsWith('/lots/upload'));
  await dialog(page).locator('[data-upload-field=brochure]').setInputFiles({ name: 'new.txt', mimeType: 'text/plain', buffer: Buffer.from('Brochure of the copy') });
  expect((await response).status()).toBe(200);
  await expect(dialog(page).locator('[name=brochure]')).toHaveValue(/^@upload:/);
  await expect(dialog(page).locator('form[data-admin-draft]')).not.toHaveAttribute('data-upload-busy', 'true');
  await dialog(page).locator('[name=code]').fill('A2');
  await dialog(page).getByRole('button', { name: 'Create', exact: true }).click();
  await expect(dialog(page)).not.toBeVisible();
  const state = await lots(page);
  expect(state.lots).toHaveLength(2);
  expect(state.lots[1].brochure).not.toBe(state.lots[0].brochure);
  expect(state.files).toEqual([true, true]);
});

test('Create and duplicate again reopens a fresh copy of the same record', async ({ page }) => {
  await openCopy(page);
  await dialog(page).locator('[name=code]').fill('A2');
  await dialog(page).getByRole('button', { name: 'Create and duplicate again' }).click();
  await expect(dialog(page).locator('[name=slug]')).toHaveValue('villa-a1-copy-2');
  await expect(dialog(page)).toBeVisible();
  await expect(dialog(page).locator('[name=code]')).toHaveValue('');
  await dialog(page).locator('[name=code]').fill('A3');
  await dialog(page).getByRole('button', { name: 'Create', exact: true }).click();
  await expect(dialog(page)).not.toBeVisible();
  expect((await lots(page)).lots.map(l => l.slug)).toEqual(['villa-a1', 'villa-a1-copy', 'villa-a1-copy-2']);
});

test('Arabic: the copy opens right to left with Arabic wording', async ({ page }) => {
  await openCopy(page, 'ar');
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('#fa-admin-resource-editor-title')).toHaveText('تكرار «فيلا أ1»');
  await expect(dialog(page).locator('.fa-duplicate-note').first()).toContainText('هذه نسخة من «فيلا أ1»');
  await expect(dialog(page).getByRole('button', { name: 'إنشاء وتكرار مرة أخرى' })).toBeVisible();
  await expect(dialog(page).locator('[name="title[ar]"]')).toHaveValue('فيلا أ1');
  await dialog(page).locator('[name=code]').fill('A2');
  await dialog(page).getByRole('button', { name: 'إنشاء', exact: true }).click();
  await expect(dialog(page)).not.toBeVisible();
  expect((await lots(page)).lots).toHaveLength(2);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('the page form saves a copy and Create and duplicate again opens the next one', async ({ page }) => {
    // The standard list (the Reactive one links here too)
    await page.goto('/en/admin/lots?static=1');
    await page.locator('#fa-admin-resource-menu-1 summary').click();
    await page.locator('#fa-admin-resource-duplicate-1').click();
    await expect(page).toHaveURL(/\/en\/admin\/lots\/create\?duplicate=1/);
    await expect(page.locator('h1.fa-page-title')).toHaveText('Duplicate “Villa A1”');
    await page.locator('[name=code]').fill('A2');
    await page.getByRole('button', { name: 'Create and duplicate again' }).click();
    await expect(page).toHaveURL(/\/en\/admin\/lots\/create\?duplicate=1/);
    await expect(page.locator('[name=slug]')).toHaveValue('villa-a1-copy-2');
    expect((await lots(page)).lots.map(l => l.code)).toEqual(['A1', 'A2']);
  });
});
