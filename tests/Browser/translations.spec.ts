import { test, expect, Page } from '@playwright/test';

const editor = (page: Page) => page.locator('dialog.fa-editor');
const translation = (page: Page, field = 'title') => editor(page).locator(`[data-field="${field}"] .fa-lang-dialog`);
const badge = (page: Page, field = 'title') => editor(page).locator(`[data-field="${field}"] .fa-lang-badge`);
async function open(page: Page) {
  await page.goto('/en/admin/posts');
  await page.evaluate(() => { (window as any).documentMarker = 'translations'; });
  await page.locator('#fa-admin-resource-edit-8').click();
  await expect(editor(page)).toBeVisible();
}

test('modal translations use the same compact controls as full-page forms', async ({ page }) => {
  await open(page);
  await expect(badge(page)).toBeVisible();
  await expect(badge(page, 'body')).toBeVisible();
  await expect(translation(page)).not.toBeVisible();
  await expect(editor(page).locator('.fa-translations-inline')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Open standard view' })).toHaveCount(0);
});

test('text and textarea translations save, reopen and persist without a reload', async ({ page }) => {
  await open(page);
  await editor(page).locator('[name="title[en]"]').fill('Updated studio');
  await badge(page).click();
  await expect(translation(page).locator('[data-mirror]')).toHaveValue('Updated studio');
  await translation(page).locator('[name="title[ar]"]').fill('استوديو');
  await translation(page).locator('[name="title[ku]"]').fill('ستۆدیۆ');
  await translation(page).locator('[data-dialog-save]').click();
  await expect(badge(page).locator('.is-filled')).toHaveCount(2);
  await editor(page).locator('[name="body[en]"]').fill('English description');
  await badge(page, 'body').click();
  await translation(page, 'body').locator('[name="body[ar]"]').fill('وصف العقار');
  await translation(page, 'body').locator('[name="body[ku]"]').fill('وەسفی خانوو');
  await translation(page, 'body').locator('[data-dialog-save]').click();
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await page.locator('#fa-admin-resource-edit-8').click();
  await expect(editor(page).locator('[name="body[en]"]')).toHaveValue('English description');
  await badge(page).click();
  await expect(translation(page).locator('[name="title[ar]"]')).toHaveValue('استوديو');
  await translation(page).locator('[data-dialog-save]').click();
  await badge(page, 'body').click();
  await expect(translation(page, 'body').locator('[name="body[ku]"]')).toHaveValue('وەسفی خانوو');
  expect(await page.evaluate(() => (window as any).documentMarker)).toBe('translations');
});

test('translation Cancel and Escape restore drafts, contain focus and leave the editor modal', async ({ page }) => {
  await open(page);
  await badge(page).click();
  await translation(page).locator('[data-mirror]').fill('Discard title');
  await translation(page).locator('[name="title[ar]"]').fill('Discard Arabic');
  await translation(page).locator('[data-dialog-cancel]').click();
  await expect(editor(page).locator('[name="title[en]"]')).toHaveValue('Studio');
  await expect(badge(page)).toBeFocused();
  await badge(page).click();
  await expect(translation(page).locator('[name="title[ar]"]')).toHaveValue('منزل 7');
  // Save is the last control; Tab wraps to the first language input and back.
  await translation(page).locator('[data-dialog-save]').focus();
  await page.keyboard.press('Tab');
  await expect(translation(page).locator('[data-mirror]')).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(translation(page).locator('[data-dialog-save]')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(translation(page)).not.toBeVisible();
  expect(await editor(page).evaluate(dialog => dialog.matches(':modal'))).toBe(true);
  await expect(badge(page)).toBeFocused();
  await badge(page, 'body').click();
  await translation(page, 'body').locator('[data-mirror]').fill('Discard description');
  await translation(page, 'body').locator('[name="body[ar]"]').fill('Discard body');
  await page.keyboard.press('Escape');
  await badge(page, 'body').click();
  await expect(translation(page, 'body').locator('[data-mirror]')).toHaveValue('A place to call home.');
  await expect(translation(page, 'body').locator('[name="body[ar]"]')).toHaveValue('');
});

test('secondary-language validation opens the translation dialog and supports correction', async ({ page }) => {
  await open(page);
  await badge(page).click();
  await translation(page).locator('[name="title[ar]"]').fill('x'.repeat(21));
  await translation(page).locator('[data-dialog-save]').click();
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(translation(page)).toBeVisible();
  await expect(translation(page).locator('[name="title[ar]"]')).toBeFocused();
  await expect(translation(page).locator('[role=alert]')).toContainText('20');
  await translation(page).locator('[name="title[ar]"]').fill('Corrected');
  await page.keyboard.press('Enter');
  await expect(translation(page)).not.toBeVisible();
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await page.locator('#fa-admin-resource-edit-8').click();
  await badge(page).click();
  await expect(translation(page).locator('[name="title[ar]"]')).toHaveValue('Corrected');
});

test('full-page translation controls retain Save and Cancel behavior after panel navigation', async ({ page }) => {
  await page.goto('/en/admin/posts?static=1');
  await page.evaluate(() => { (window as any).documentMarker = 'translations'; });
  await page.locator('a[href="/en/admin/posts/edit/8"]').click();
  await expect(page.locator('[name="title[en]"]')).toBeVisible();
  await page.locator('[data-dialog="d-title"]').click();
  await page.locator('#d-title [name="title[ar]"]').fill('Discarded');
  await page.locator('#d-title [data-dialog-cancel]').click();
  await page.locator('[data-dialog="d-title"]').click();
  await expect(page.locator('#d-title [name="title[ar]"]')).toHaveValue('منزل 7');
  await page.locator('#d-title [name="title[ar]"]').fill('Discard with Escape');
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-dialog="d-title"]')).toBeFocused();
  await page.locator('[data-dialog="d-title"]').click();
  await expect(page.locator('#d-title [name="title[ar]"]')).toHaveValue('منزل 7');
  await page.locator('#d-title [data-mirror]').fill('Full-page studio');
  await page.locator('#d-title [name="title[ar]"]').fill('محفوظ');
  await page.locator('#d-title [data-dialog-save]').click();
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await page.locator('#fa-admin-resource-edit-8').click();
  await expect(editor(page).locator('[name="title[en]"]')).toHaveValue('Full-page studio');
  await badge(page).click();
  await expect(translation(page).locator('[name="title[ar]"]')).toHaveValue('محفوظ');
  expect(await page.evaluate(() => (window as any).documentMarker)).toBe('translations');
});

test('oversized drafts retain text and expose the full-page fallback only when needed', async ({ page }) => {
  await open(page);
  await expect(editor(page).locator('[data-admin-full-page]')).not.toBeVisible();
  await editor(page).locator('[name="body[en]"]').fill('x'.repeat(65000));
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page).locator('[data-rx-status]')).toContainText('Copy any unsaved text');
  await expect(editor(page).locator('[name="body[en]"]')).toHaveValue('x'.repeat(65000));
  await editor(page).getByRole('link', { name: 'Continue on full page' }).click();
  await page.locator('a[href="/en/admin/posts/edit/8"]').click();
  await page.locator('[name="body[en]"]').fill('x'.repeat(65000));
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await page.locator('#fa-admin-resource-edit-8').click();
  await expect(page.locator('#fa-admin-resource-error')).toContainText('too large');
  await expect(page.getByRole('link', { name: 'Continue on full page' })).toBeVisible();
  expect(await page.evaluate(() => (window as any).documentMarker)).toBe('translations');
});

for (const language of ['ar', 'ku']) test(`mobile ${language} translation dialogs fit the viewport and restore focus`, async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/${language}/admin/posts`);
  await page.locator('#fa-admin-resource-edit-8').click();
  await badge(page, 'body').click();
  await expect(translation(page, 'body').locator(`[name="body[${language}]"]`)).toHaveAttribute('dir', 'rtl');
  const box = await translation(page, 'body').boundingBox();
  expect(box!.width).toBeLessThanOrEqual(390);
  expect(box!.x).toBeGreaterThanOrEqual(0);
  await page.keyboard.press('Escape');
  await expect(badge(page, 'body')).toBeFocused();
  expect(await editor(page).evaluate(dialog => dialog.matches(':modal'))).toBe(true);
});

test('the language badge sits at the end of the content text, also on right-to-left pages', async ({ page }) => {
  await page.goto('/ar/admin/posts/edit/8');
  const input = page.locator('[name="title[en]"]');
  const badge = page.locator('[data-field="title"] .fa-lang-badge');
  const [field, mark] = [await input.boundingBox(), await badge.boundingBox()];
  // English content: the badge is inside the input on its right, clear of the text's start.
  expect(mark!.x).toBeGreaterThan(field!.x + field!.width / 2);
  expect(mark!.x + mark!.width).toBeLessThanOrEqual(field!.x + field!.width);
  await expect(badge.locator('.fa-dot')).toHaveCount(2);
});
