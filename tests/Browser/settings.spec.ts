import { test, expect, Page } from '@playwright/test';
import { fileURLToPath } from 'node:url';

/* Settings pages (fixture: tests/Fixtures/Settings/settings.php), panel settings, My preferences and the Columns button. */
const form = (page: Page) => page.locator('form[data-admin-settings]');
const photo = fileURLToPath(new URL('./fixtures/photo.png', import.meta.url));

test('the language switch edits one language at a time and saves them all', async ({ page }) => {
  await page.goto('/en/admin/settings');
  await expect(page).toHaveURL(/\/en\/admin\/settings\/edit\/site$/);
  await expect(page.locator('.fa-settings-tab[aria-current="page"]')).toContainText('General');
  const title = page.getByLabel('Title');
  await title.fill('Acme Homes');
  await expect(form(page).locator('[data-admin-record-state]')).toHaveText('Unsaved changes');

  await page.locator('[data-settings-language]').selectOption('ku');
  await expect(page.locator('[name="title[en]"]')).toBeHidden();
  const kurdish = page.locator('[name="title[ku]"]');
  await expect(kurdish).toBeVisible();
  await expect(kurdish).toHaveAttribute('dir', 'rtl');
  await kurdish.fill('ئەکمی');
  // Values that are the same in every language are locked in other languages.
  await expect(page.locator('[data-field="phone"]')).toHaveClass(/is-locked/);
  expect(await page.locator('#f-phone').evaluate(el => el.closest<HTMLElement>('[inert]') !== null)).toBe(true);
  await expect(page.locator('[data-settings-other]')).toContainText('You are editing');
  await expect(page.locator('[data-field="title"] .fa-label .fa-dot[data-lang="ku"]')).toHaveClass(/is-filled/);

  await page.locator('[data-settings-language]').selectOption('en');
  await expect(title).toHaveValue('Acme Homes');
  await page.locator('#f-phone').fill('+30 210 000');
  await page.keyboard.press('ControlOrMeta+s');
  await expect(page.getByText('Settings saved.')).toBeVisible();
  await expect(page.locator('[name="title[ku]"]')).toHaveValue('ئەکمی');
  await expect(page.locator('#f-phone')).toHaveValue('+30 210 000');
  await expect(form(page).locator('[data-admin-record-state]')).toBeHidden();
});

test('a failed save shows the language with the error', async ({ page }) => {
  await page.goto('/en/admin/settings/edit/site');
  await page.getByLabel('Title').fill('Acme');
  await page.locator('[data-settings-language]').selectOption('ar');
  await page.locator('[name="title[ar]"]').fill('x'.repeat(70));
  await page.locator('[data-settings-language]').selectOption('en');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('[data-settings-language]')).toHaveValue('ar');
  await expect(page.locator('[name="title[ar]"]')).toBeVisible();
  await expect(page.locator('[name="title[ar]"]')).toHaveAttribute('aria-invalid', 'true');
});

test('logos are uploaded per language and others fall back to the default one', async ({ page }) => {
  await page.goto('/en/admin/settings/edit/site');
  await page.getByLabel('Title').fill('Acme');
  await page.locator('[name="_uploads[logo][en]"]').setInputFiles(photo);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();
  const preview = page.locator('[data-lang="en"] .fa-upload-preview');
  await expect(preview).toBeVisible();
  expect(await preview.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);

  await page.locator('[data-settings-language]').selectOption('ku');
  await expect(page.locator('[data-lang="ku"] .fa-upload-fallback')).toBeVisible();
  await page.locator('[name="_uploads[logo][ku]"]').setInputFiles(photo);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();
  await expect(page.locator('[data-settings-language]')).toHaveValue('ku');
  await expect(page.locator('[data-lang="ku"] .fa-upload-fallback')).toHaveCount(0);
  const urls = await page.locator('.fa-upload-preview:not(.is-fallback)').evaluateAll(images => images.map(img => (img as HTMLImageElement).getAttribute('src')));
  expect(new Set(urls).size).toBe(2);
});

test('on phones the page list is a select', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/en/admin/settings/edit/site');
  await expect(page.locator('.fa-settings-tabs')).toBeHidden();
  await page.locator('[data-settings-nav]').selectOption({ label: 'Contact & locations' });
  await expect(page).toHaveURL(/\/settings\/edit\/contact$/);
  await expect(page.locator('h1')).toHaveText('Contact & locations');
});

test('panel settings and my preferences change the look', async ({ page }) => {
  await page.goto('/en/admin/settings/edit/panel');
  await page.locator('#f-density').selectOption('compact');
  await page.locator('#f-primary_color').fill('#7c3aed');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();
  await expect(page.locator('.fa-panel')).toHaveAttribute('data-density', 'compact');
  // The new brand colour applies at once (no reload).
  await expect.poll(() => page.locator('.fa-panel').evaluate(el => getComputedStyle(el).getPropertyValue('--fa-primary').trim())).toBe('#7c3aed');

  await page.goto('/en/admin/profile/preferences');
  await page.locator('#f-theme').selectOption('dark');
  await page.locator('#f-density').selectOption('comfortable');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Preferences saved.')).toBeVisible();
  await expect(page.locator('.fa-panel')).toHaveAttribute('data-theme', 'dark');
  await expect(page.locator('.fa-panel')).toHaveAttribute('data-density', 'comfortable');

  await page.locator('#f-language').selectOption('ar');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page).toHaveURL(/\/ar\/admin\/profile\/preferences$/);
  await page.goto('/en/admin/dashboard');
  await expect(page).toHaveURL(/\/ar\/admin\/dashboard$/);
  // The top-bar language menu changes the preference.
  await page.locator('#fa-language-menu summary').click();
  await page.locator('#fa-language-menu a[hreflang="en"]').click();
  await expect(page).toHaveURL(/\/en\/admin\/dashboard$/);
  await page.goto('/ku/admin/dashboard');
  await expect(page).toHaveURL(/\/en\/admin\/dashboard$/);
});

test('the Columns button hides and restores columns', async ({ page }) => {
  await page.goto('/en/admin/properties');
  await expect(page.locator('.fa-panel')).toBeVisible();
  const menu = page.locator('[id$="-columns-menu"]');
  await expect(menu).toHaveCount(1);
  await menu.locator('summary').click();
  const boxes = menu.locator('input[type=checkbox]');
  expect(await boxes.count()).toBeGreaterThan(0);
  const first = boxes.first();
  const label = (await first.locator('xpath=..').innerText()).trim();
  await first.uncheck();
  await menu.getByRole('button', { name: 'Apply' }).click();
  await expect(page.locator(`th:has-text("${label}")`)).toHaveCount(0);
  await page.reload();
  await expect(page.locator(`th:has-text("${label}")`)).toHaveCount(0);
  await menu.locator('summary').click();
  await menu.getByRole('button', { name: 'Reset' }).click();
  await expect(page.locator(`th:has-text("${label}")`)).toHaveCount(1);
});
