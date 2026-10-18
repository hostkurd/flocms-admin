import { test, expect, Page } from '@playwright/test';

/* Page-level translation (0.23): fixtures tests/Fixtures/Translations (villas with rooms), reset by /__test/villas. */
const form = (page: Page) => page.locator('form[data-lang-scope]');
const lang = (page: Page, code: string) => form(page).locator(`.fa-lang-switch .fa-seg-btn[data-lang="${code}"]`);

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/villas');
});

test('the switch edits one language at a time, counts live and saves every language', async ({ page }) => {
  await page.goto('/en/admin/villas/edit/1');
  await expect(form(page)).toHaveClass(/is-switching/);
  await expect(lang(page, 'en')).toHaveAttribute('aria-pressed', 'true');
  await expect(lang(page, 'ar').locator('[data-lang-count]')).toHaveText('3/4');
  await expect(page.locator('[name="title[ar]"]')).toBeHidden();
  await expect(page.locator('[data-lang-other]')).toBeHidden();

  await lang(page, 'ku').click();
  await expect(page).toHaveURL(/editing=ku/);
  await expect(page.locator('[name="title[en]"]')).toBeHidden();
  const kurdish = page.locator('[name="title[ku]"]');
  await expect(kurdish).toBeVisible();
  await expect(kurdish).toHaveAttribute('dir', 'rtl');
  // The English text as a reference under the empty input, gone once it is filled
  const reference = page.locator('[data-ref-line][data-ref-input="f-title-ku"]');
  await expect(reference).toBeVisible();
  await expect(reference).toContainText('English: Cliff top villa');
  await kurdish.fill('ڤیلای سەر بەرد');
  await expect(reference).toBeHidden();
  await expect(lang(page, 'ku').locator('[data-lang-count]')).toHaveText('1/4');
  await expect(page.locator('[data-field="title"] .fa-label .fa-dot.is-current')).toHaveClass(/is-filled/);
  // Shared fields are locked outside English
  await expect(page.locator('[data-field="price"]')).toHaveClass(/is-locked/);
  expect(await page.locator('#f-price').evaluate(el => el.closest<HTMLElement>('[inert]') !== null)).toBe(true);
  await expect(page.locator('[data-field="price"] .fa-same-note')).toBeVisible();
  await expect(page.locator('[data-lang-other]')).toContainText('You are editing the');
  // Switching languages is not a change; typing is
  await expect(form(page).locator('[data-admin-record-state]')).toHaveText('Unsaved changes');

  await lang(page, 'en').click();
  await expect(page).not.toHaveURL(/editing=/);
  await page.locator('#f-price').fill('430000');
  await lang(page, 'ku').click();
  await page.keyboard.press('ControlOrMeta+s');
  await expect(page.getByText('Villa saved.')).toBeVisible();
  // Back in Kurdish, everything saved
  await expect(page).toHaveURL(/editing=ku/);
  await expect(lang(page, 'ku')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[name="title[ku]"]')).toHaveValue('ڤیلای سەر بەرد');
  await lang(page, 'en').click();
  await expect(page.locator('#f-price')).toHaveValue('430000');
  // Remembered: the next villa opens in Kurdish, a new one in English
  await page.goto('/en/admin/villas/edit/3');
  await expect(lang(page, 'ku')).toHaveAttribute('aria-pressed', 'true');
  await page.goto('/en/admin/villas/create');
  await expect(lang(page, 'en')).toHaveAttribute('aria-pressed', 'true');
});

test('Next missing walks the empty fields of the language, rich text follows the switch', async ({ page }) => {
  await page.goto('/en/admin/villas/edit/1?editing=ar');
  await expect(lang(page, 'ar')).toHaveAttribute('aria-pressed', 'true');
  const next = form(page).locator('[data-next-missing]');
  await expect(next).toBeVisible();
  // The rich text editor shows Arabic, without its own language tabs
  const arabicPanel = page.locator('[data-field="description"] .fa-rich-panel[data-lang="ar"]');
  await expect(arabicPanel).toBeVisible();
  await expect(page.locator('[data-field="description"] .fa-rich-tabs')).toBeHidden();
  await expect(page.locator('[data-ref-line][data-ref-input="f-description-ar"]')).toContainText('A bright villa');
  await next.click();
  await expect(arabicPanel.locator('[contenteditable="true"]')).toBeFocused();
  await page.keyboard.type('فيلا مشرقة');
  await expect(page.locator('[data-ref-line][data-ref-input="f-description-ar"]')).toBeHidden();
  await expect(lang(page, 'ar').locator('[data-lang-count]')).toHaveText('4/4');
  await expect(next).toBeHidden();

  await lang(page, 'ku').click();
  await next.click();
  await expect(page.locator('[name="title[ku]"]')).toBeFocused();
  await next.click();
  await expect(page.locator('[name="summary[ku]"]')).toBeFocused();
});

test('an error in another language shows in the switch and opens that language', async ({ page }) => {
  await page.goto('/en/admin/villas/edit/1');
  await lang(page, 'ar').click();
  await page.locator('[name="title[ar]"]').fill('ف'.repeat(130));
  await lang(page, 'en').click();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Please check: Title (العربية).')).toBeVisible();
  await expect(lang(page, 'ar')).toHaveAttribute('aria-pressed', 'true');
  await expect(lang(page, 'ar').locator('[data-lang-errors]')).toHaveText('1');
  await expect(page.locator('[name="title[ar]"]')).toHaveAttribute('aria-invalid', 'true');
  // A corrected value no longer counts as an error
  await page.locator('[name="title[ar]"]').fill('فيلا');
  await expect(lang(page, 'ar').locator('[data-lang-errors]')).toBeHidden();
});

test('a room dialog has its own switch and opens in the language of the page', async ({ page }) => {
  await page.goto('/en/admin/villas/edit/1');
  await lang(page, 'ar').click();
  await page.locator('#fa-rel-rooms-edit-1').click();
  const dialog = page.locator('dialog.fa-editor');
  await expect(dialog).toBeVisible();
  const switcher = dialog.locator('.fa-lang-switch');
  await expect(switcher).toBeVisible();
  await expect(switcher.locator('.fa-seg-btn[data-lang="ar"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(dialog.locator('[name="name[ar]"]')).toHaveValue('البنتهاوس');
  await expect(dialog.locator('[data-field="size"]')).toHaveClass(/is-locked/);
  await switcher.locator('.fa-seg-btn[data-lang="ku"]').click();
  await expect(dialog.locator('[data-ref-line][data-lang="ku"]')).toContainText('Penthouse');
  await dialog.locator('[name="name[ku]"]').fill('پێنتهاوس');
  await expect(switcher.locator('.fa-seg-btn[data-lang="ku"] [data-lang-count]')).toHaveText('1/1');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog).toBeHidden();
  // Reopened: again in the page's language, with the Kurdish value kept
  await page.locator('#fa-rel-rooms-edit-1').click();
  await expect(dialog.locator('.fa-seg-btn[data-lang="ar"]')).toHaveAttribute('aria-pressed', 'true');
  await dialog.locator('.fa-seg-btn[data-lang="ku"]').click();
  await expect(dialog.locator('[name="name[ku]"]')).toHaveValue('پێنتهاوس');
  await dialog.locator('.fa-dialog-foot').getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();
  // On a page in the default language, the dialog opens in the language last saved in
  await lang(page, 'en').click();
  await page.locator('#fa-rel-rooms-edit-1').click();
  await expect(dialog.locator('.fa-seg-btn[data-lang="ku"]')).toHaveAttribute('aria-pressed', 'true');
});

test('panes keep their own direction in an Arabic panel', async ({ page }) => {
  await page.goto('/ar/admin/villas/edit/1');
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  const english = page.locator('[name="title[en]"]');
  await expect(english).toBeVisible();
  await expect(english).toHaveAttribute('dir', 'ltr');
  expect(await english.evaluate(el => getComputedStyle(el).direction)).toBe('ltr');
});

test('the list marks translations and filters what is missing', async ({ page }) => {
  await page.goto('/en/admin/villas');
  const row = page.locator('tr', { hasText: 'Harbour residences' });
  await expect(row.locator('.fa-tr-mark.is-full')).toHaveCount(3);
  await page.getByText('Filters', { exact: true }).click();
  await page.getByLabel('Missing in').selectOption('ku');
  await page.getByRole('button', { name: 'Apply filters' }).click();
  await expect(page.locator('tr', { hasText: 'Harbour residences' })).toHaveCount(0);
  await expect(page.locator('tr', { hasText: 'Cliff top villa' })).toHaveCount(1);
});

test('on phones the switch is a full-width row', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/en/admin/villas/edit/1');
  const seg = form(page).locator('.fa-seg');
  const box = await seg.boundingBox();
  expect(box!.width).toBeGreaterThan(330);
  await lang(page, 'ar').click();
  await expect(page.locator('[name="title[ar]"]')).toBeVisible();
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('every language is shown with its code and saved', async ({ page }) => {
    await page.goto('/en/admin/villas/edit/1');
    await expect(page.locator('.fa-lang-switch')).toBeHidden();
    await expect(page.locator('[name="title[en]"]')).toBeVisible();
    await expect(page.locator('[name="title[ku]"]')).toBeVisible();
    await expect(page.locator('#f-price')).toBeEditable();
    await page.locator('[name="title[ku]"]').fill('ڤیلا');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.locator('[name="title[ku]"]')).toHaveValue('ڤیلا');
  });
});

test('settings pages use the same switch, with counts and Next missing', async ({ page }) => {
  await page.goto('/en/admin/settings/edit/site');
  await page.getByLabel('Title').fill('Acme Homes');
  const kurdish = page.locator('#fa-settings-switch .fa-seg-btn[data-lang="ku"]');
  await expect(kurdish.locator('[data-lang-count]')).toHaveText(/^\d+\/\d+$/);
  await kurdish.click();
  const next = page.locator('form[data-admin-settings] [data-next-missing]');
  await expect(next).toBeVisible();
  await next.click();
  await expect(page.locator('[name="title[ku]"]')).toBeFocused();
  await expect(page.locator('[data-ref-line][data-ref-input="f-title-ku"]')).toContainText('Acme Homes');
});

test('empty inputs are dashed only while translating; the rich text toolbar keeps its rounded top', async ({ page }) => {
  await page.goto('/en/admin/villas/create');
  const title = page.locator('[name="title[en]"]');
  expect(await title.evaluate(el => getComputedStyle(el).borderTopStyle)).toBe('solid');
  const toolbar = page.locator('[data-field="description"] .fa-rich-toolbar');
  await expect(toolbar).toBeVisible();
  expect(await toolbar.evaluate(el => getComputedStyle(el).borderTopLeftRadius)).not.toBe('0px');
  await lang(page, 'ar').click();
  expect(await page.locator('[name="title[ar]"]').evaluate(el => getComputedStyle(el).borderTopStyle)).toBe('dashed');
});
