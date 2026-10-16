import { test, expect, Page } from '@playwright/test';

async function ready(page: Page, path = '/en/admin/posts') {
  await page.goto(path);
  await expect(page.locator('.fa-panel')).toBeVisible();
  await page.evaluate(() => { (window as any).documentMarker = 'persistent'; });
}
async function stable(page: Page) {
  await expect.poll(() => page.evaluate(() => (window as any).documentMarker)).toBe('persistent');
}
const editor = (page: Page) => page.locator('dialog.fa-editor');
async function open(page: Page, id = 8) {
  await page.locator(`#fa-admin-resource-edit-${id}`).click();
  await expect(editor(page)).toBeVisible();
}

test('navigation, live search, sorting, pagination and back/forward keep the document', async ({ page }) => {
  await ready(page, '/en/admin/dashboard');
  await page.locator('.fa-nav a[href="/en/admin/posts"]').click();
  await expect(page.locator('h1')).toHaveText('Posts');
  await stable(page);
  await page.locator('input[type=search]').fill('villa');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody')).toContainText('Sea view villa');
  await page.getByRole('button', { name: 'Clear', exact: true }).click();
  await expect(page.locator('tbody tr')).toHaveCount(3);
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(page).toHaveURL(/admin-resource_page=2/);
  await page.goBack();
  await expect(page.locator('tbody')).toContainText('Studio');
  await page.goForward();
  await expect(page).toHaveURL(/admin-resource_page=2/);
  await expect(page.locator('tbody')).toContainText('Lake house');
  await page.goBack();
  await expect(page.locator('tbody')).toContainText('Studio');
  await stable(page);
  await page.getByRole('button', { name: 'Title', exact: true }).click();
  await expect(page.locator('th[aria-sort=ascending]')).toHaveText('Title');
  await page.locator('.fa-nav a[href="/en/admin/dashboard"]').click();
  await expect(page.locator('h1')).toContainText('Dashboard');
  await page.goBack();
  await expect(page.locator('h1')).toHaveText('Posts');
  await stable(page);
});

test('modal edit, translated validation, correction and save preserve list state', async ({ page }) => {
  await ready(page);
  await page.locator('input[type=search]').fill('Studio');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await open(page);
  await editor(page).locator('[name="title[en]"]').fill('');
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page).locator('[name="title[en]"]')).toHaveAttribute('aria-invalid', 'true');
  await expect(editor(page).locator('[name="title[en]"]')).toBeFocused();
  await editor(page).locator('[name="title[en]"]').fill('Studio updated');
  await editor(page).locator('[data-field="title"] .fa-lang-badge').click();
  await editor(page).locator('[name="title[ar]"]').fill('استوديو');
  await editor(page).locator('[data-field="title"] [data-dialog-save]').click();
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('[data-admin-notice]')).toContainText('saved');
  await expect(page.locator('input[type=search]')).toHaveValue('Studio');
  await expect(page.locator('tbody')).toContainText('Studio updated');
  await stable(page);
});

test('create and delete use modal actions and no reload', async ({ page }) => {
  await ready(page);
  await page.locator('[data-admin-action=create]').click();
  await editor(page).locator('[name="title[en]"]').fill('New property');
  await editor(page).locator('[name=status]').selectOption('draft');
  await editor(page).getByRole('button', { name: 'Create', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('tbody')).toContainText('New property');
  await page.locator('#fa-admin-resource-menu-9 > summary').click();
  await page.locator('#fa-admin-resource-delete-9').click();
  await expect(editor(page)).toBeVisible();
  await editor(page).getByRole('button', { name: 'Delete', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('tbody')).not.toContainText('New property');
  await stable(page);
});

test('keyboard modal containment, Escape discard and opener focus', async ({ page }) => {
  await ready(page);
  await open(page);
  const cancel = editor(page).locator('[data-rx-dialog-close]').last();
  await cancel.focus();
  await page.keyboard.press('Tab');
  await expect(editor(page).locator('[data-rx-dialog-close]').first()).toBeFocused();
  await editor(page).locator('[name="title[en]"]').fill('Discard this');
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('#fa-admin-resource-edit-8')).toBeFocused();
  await open(page);
  await expect(editor(page).locator('[name="title[en]"]')).toHaveValue('Studio');
  await stable(page);
});

test('concurrent edit and deletion produce recoverable errors', async ({ page }) => {
  await ready(page);
  await open(page);
  await page.request.post('/__test/change', { form: { id: 8 } });
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page)).toContainText('changed since you opened');
  await page.request.post('/__test/delete', { form: { id: 8 } });
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page)).toContainText('no longer available');
  await expect(page.locator('[data-rx-component]')).toHaveAttribute('aria-busy', 'false');
  expect(await editor(page).evaluate(dialog => dialog.matches(':modal'))).toBe(true);
  await expect(editor(page).locator('[name="title[en]"]')).toHaveValue('Studio');
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
  await stable(page);
});

test('revoked permissions keep cancel available', async ({ page }) => {
  await ready(page);
  await open(page);
  await page.request.post('/__test/readonly');
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page).locator('[data-rx-status]')).toContainText('permission');
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
  await stable(page);
});

test('expired CSRF never writes and Escape closes locally', async ({ page }) => {
  await ready(page);
  await open(page);
  await editor(page).locator('[name="title[en]"]').fill('Must not save');
  await page.request.post('/__test/expire');
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page).locator('[data-rx-status]')).toContainText('expired');
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
  await stable(page);
  await page.reload();
  await expect(page.locator('tbody')).toContainText('Studio');
});

test('standard large forms also save without replacing the document', async ({ page }) => {
  await ready(page, '/en/admin/posts?static=1');
  await page.locator('a[href="/en/admin/posts/edit/8"]').click();
  await page.locator('[name="body[en]"]').fill('x'.repeat(9000));
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(page.locator('h1')).toHaveText('Posts');
  await stable(page);
});

test('network failure never repeats a save and modal stays usable', async ({ page }) => {
  await ready(page);
  await open(page);
  let writes = 0;
  await page.route('**/flo-reactive/update', async route => {
    const body = new URLSearchParams(route.request().postData() || '');
    if (body.get('_action') === 'save') { writes++; await route.abort(); } else await route.continue();
  });
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page).locator('[data-rx-status]')).toContainText('failed');
  expect(writes).toBe(1);
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
});

test('accounts and profile forms navigate and save without reload', async ({ page }) => {
  await ready(page, '/en/admin/dashboard');
  await page.locator('.fa-nav a[href="/en/admin/accounts"]').click();
  await expect(page.locator('h1')).toHaveText('Users');
  await page.locator('.fa-topbar-user').click();
  await expect(page.locator('[name=full_name]')).toHaveValue('Sara');
  await page.locator('[name=full_name]').fill('Sara Updated');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(page.locator('.fa-topbar-user')).toHaveText('Sara Updated');
  await stable(page);
});

for (const language of ['ku', 'ar']) test(`mobile ${language} has RTL, keyboard menu and contained dialog`, async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await ready(page, `/${language}/admin/posts`);
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.locator('[data-admin-menu]').focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-admin-menu]')).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-admin-menu]')).toBeFocused();
  await open(page);
  const box = await editor(page).boundingBox();
  expect(box!.width).toBeLessThanOrEqual(390);
  expect(box!.x).toBeGreaterThanOrEqual(0);
  await page.keyboard.press('Escape');
  await stable(page);
});

test('strict CSP has no inline scripts or unsafe evaluation', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', message => { if (/Content Security Policy|Refused to execute|unsafe-eval/.test(message.text())) violations.push(message.text()); });
  await ready(page);
  await open(page);
  await page.keyboard.press('Escape');
  expect(violations).toEqual([]);
  await expect(page.locator('script:not([src])')).toHaveCount(0);
});

test('standard translations and mobile navigation remain usable without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  try {
    await page.goto('http://127.0.0.1:8083/en/admin/posts/edit/8');
    await expect(page.locator('.fa-nav a[href="/en/admin/dashboard"]')).toBeVisible();
    await expect(page.locator('[name="title[ar]"]')).toBeVisible();
    await page.locator('[name="title[ar]"]').fill('عنوان جديد');
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    await page.getByRole('link', { name: 'Continue on full page' }).click();
    await page.locator('a[href="/en/admin/posts/edit/8"]').click();
    await expect(page.locator('[name="title[ar]"]')).toHaveValue('عنوان جديد');
  } finally { await context.close(); }
});
