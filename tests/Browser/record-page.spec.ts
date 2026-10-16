import { test, expect, Page } from '@playwright/test';

/* Projects use the default form mode: Create and Edit open as full pages with a sidebar. */
const form = (page: Page) => page.locator('form[data-admin-record-form]');
async function ready(page: Page, path = '/en/admin/projects') {
  await page.goto(path);
  await expect(page.locator('.fa-panel')).toBeVisible();
  await page.evaluate(() => { (window as any).documentMarker = 'kept'; });
}
const kept = (page: Page) => expect.poll(() => page.evaluate(() => (window as any).documentMarker)).toBe('kept');

test('edit opens a full page with a sidebar and returns to the same list page', async ({ page }) => {
  await ready(page);
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(page).toHaveURL(/admin-resource_page=2/);
  // Newest first, five per page: page 2 holds projects 2 and 1.
  await page.locator('#fa-admin-resource-edit-2').click();
  await expect(page.locator('h1')).toHaveText('Edit Project');
  await expect(page).toHaveURL(/\/admin\/projects\/edit\/2\?back=/);
  await expect(page.locator('dialog.fa-editor[open]')).toHaveCount(0);
  // Main card on the start side, sidebar sections on the end side.
  const main = await page.locator('.fa-form-main').boundingBox();
  const aside = await page.locator('.fa-form-aside').boundingBox();
  expect(aside!.x).toBeGreaterThan(main!.x + main!.width - 1);
  await expect(page.locator('.fa-form-aside .fa-section-title')).toHaveText(['Publishing', 'Timeline']);
  // SEO starts folded and opens on click.
  const seo = page.locator('details.fa-form-section', { hasText: 'SEO' });
  await expect(seo).not.toHaveAttribute('open', '');
  await seo.locator('summary').click();
  await expect(page.locator('[name=meta_title]')).toBeVisible();

  await page.locator('[name="name[en]"]').fill('City library annex');
  await expect(form(page).locator('[data-admin-record-state]')).toHaveText('Unsaved changes');
  await page.getByRole('button', { name: 'Save and close', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/projects\?admin-resource_page=2$/);
  await expect(page.locator('tbody')).toContainText('City library annex');
  await kept(page);
});

test('Save and Ctrl+S stay on the record; Cancel goes back', async ({ page }) => {
  await ready(page, '/en/admin/projects/edit/3');
  await page.locator('[name=summary]').fill('Saved with the button');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Project saved.')).toBeVisible();
  await expect(page).toHaveURL(/\/admin\/projects\/edit\/3$/);
  await expect(page.locator('[name=summary]')).toHaveValue('Saved with the button');
  await expect(form(page).locator('[data-admin-record-state]')).toBeHidden();

  await page.locator('[name=summary]').fill('Saved with the keyboard');
  await page.keyboard.press('ControlOrMeta+s');
  await expect(page.locator('[name=summary]')).toHaveValue('Saved with the keyboard');
  await expect(form(page)).toHaveAttribute('data-admin-dirty', 'false');
  await page.reload();
  await expect(page.locator('[name=summary]')).toHaveValue('Saved with the keyboard');

  await page.getByRole('link', { name: 'Cancel', exact: true }).click();
  await expect(page.locator('h1')).toHaveText('Projects');
});

test('create offers Save and create another, and Enter saves', async ({ page }) => {
  await ready(page);
  await page.getByRole('link', { name: 'New Project' }).click();
  await expect(page.locator('h1')).toHaveText('New Project');
  await page.locator('[name="name[en]"]').fill('Water plant');
  await page.locator('[name=slug]').fill('water-plant');
  await page.getByRole('button', { name: 'Save and create another', exact: true }).click();
  await expect(page.locator('h1')).toHaveText('New Project');
  await expect(page.locator('[name="name[en]"]')).toHaveValue('');
  // Enter in a field: the default button saves and stays on the new record.
  await page.locator('[name="name[en]"]').fill('Wind farm');
  await page.locator('[name=slug]').fill('wind-farm');
  await page.locator('[name=slug]').press('Enter');
  await expect(page.locator('h1')).toHaveText('Edit Project');
  await expect(page.locator('[name="name[en]"]')).toHaveValue('Wind farm');
  await page.getByRole('link', { name: 'Cancel', exact: true }).click();
  await expect(page.locator('tbody')).toContainText('Water plant');
  await kept(page);
});

test('leaving with unsaved changes asks first, also with the Back button', async ({ page }) => {
  await ready(page);
  await page.locator('#fa-admin-resource-edit-7').click();
  await page.locator('[name=summary]').fill('Not saved yet');
  await page.locator('.fa-nav a[href="/en/admin/dashboard"]').click();
  const leave = page.locator('#fa-leave');
  await expect(leave).toBeVisible();
  await expect(leave).toContainText('Your changes on this page will be lost.');
  await leave.getByRole('button', { name: 'Keep editing' }).click();
  await expect(leave).toBeHidden();
  await expect(page.locator('[name=summary]')).toHaveValue('Not saved yet');

  await page.goBack();
  await expect(leave).toBeVisible();
  await expect(page).toHaveURL(/\/edit\/7/);
  await page.keyboard.press('Escape');
  await expect(page.locator('[name=summary]')).toHaveValue('Not saved yet');

  await page.locator('.fa-nav a[href="/en/admin/dashboard"]').click();
  await leave.getByRole('button', { name: 'Leave' }).click();
  await expect(page.locator('h1')).toContainText('Dashboard');
  await kept(page);
});

test('errors keep the input, open a folded section and mark the page unsaved', async ({ page }) => {
  await ready(page, '/en/admin/projects/edit/4');
  await page.locator('[name="name[en]"]').fill('');
  await page.locator('details.fa-form-section summary', { hasText: 'SEO' }).click();
  await page.locator('[name=meta_title]').fill('x'.repeat(80));
  await page.locator('details.fa-form-section summary', { hasText: 'SEO' }).click();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('[name="name[en]"]')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('details.fa-form-section', { hasText: 'SEO' })).toHaveAttribute('open', '');
  await expect(form(page)).toHaveAttribute('data-admin-dirty', 'true');
  await expect(form(page).locator('[data-admin-record-state]')).toHaveText('Unsaved changes');
});

test('small records open in a slide-over panel at the end of the window', async ({ page }) => {
  await ready(page, '/en/admin/post-tags');
  await page.locator('#fa-admin-resource-edit-1').click();
  const panel = page.locator('dialog.fa-editor.is-slide-over[open]');
  await expect(panel).toBeVisible();
  // After the slide-in animation its end edge is the window's.
  await expect.poll(async () => { const box = await panel.boundingBox(); return Math.round(box!.x + box!.width); }).toBe(page.viewportSize()!.width);
  expect((await panel.boundingBox())!.height).toBe(page.viewportSize()!.height);
  await panel.locator('[name=name]').fill('Courtyard');
  await page.keyboard.press('ControlOrMeta+s');
  await expect(panel).toHaveCount(0);
  await expect(page.locator('tbody')).toContainText('Courtyard');
});

test('content uses the full width of the window', async ({ page }) => {
  await page.setViewportSize({ width: 1600, height: 900 });
  await ready(page, '/en/admin/projects/edit/1');
  const content = await page.locator('#fa-content').boundingBox();
  const main = await page.locator('.fa-main').boundingBox();
  expect(Math.round(content!.width)).toBe(Math.round(main!.width));
  await expect(page.locator('.fa-panel')).toHaveAttribute('data-width', 'full');
});

for (const language of ['ku', 'ar']) test(`mobile ${language} record page keeps its buttons in a bar at the bottom`, async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await ready(page, `/${language}/admin/projects/edit/1`);
  const bar = await page.locator('.fa-record-actions').boundingBox();
  expect(Math.round(bar!.y + bar!.height)).toBe(844);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  // The sidebar follows the main fields on a phone.
  const main = await page.locator('.fa-form-main').boundingBox();
  const aside = await page.locator('.fa-form-aside').boundingBox();
  expect(aside!.y).toBeGreaterThan(main!.y + main!.height - 1);
  await expect(page.locator('.fa-record-actions').getByRole('button', { name: language === 'ku' ? 'سڕینەوە' : 'حذف' })).toBeVisible();
});

test('without JavaScript the record page saves and returns to the list', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await page.goto('http://127.0.0.1:8083/en/admin/projects/create');
    await page.locator('[name="name[en]"]').fill('Bus depot');
    await page.locator('[name=slug]').fill('bus-depot');
    await page.getByRole('button', { name: 'Save and close', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/projects$/);
    await expect(page.locator('body')).toContainText('Bus depot');
  } finally { await context.close(); }
});
