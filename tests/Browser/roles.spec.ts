import { test, expect, Page } from '@playwright/test';

type State = { roles: { role: number; name: string; level: number; admin_access: number; permissions: string }[]; users: { full_name: string; role: number }[] };
async function state(page: Page): Promise<State> { return (await page.request.post('/__test/roles-state')).json(); }
const box = (page: Page, name: string) => page.locator(`form[data-admin-role] input[name="perm[]"][value="${name}"]`);

test.beforeEach(async ({ page }) => { await page.request.post('/__test/roles'); });
test.afterEach(async ({ page }) => { await page.request.post('/__test/roles-off'); });

test('the list shows every role, the locked top role and where each comes from', async ({ page }) => {
  await page.goto('/en/admin/roles');
  const rows = page.locator('.fa-roles-table tbody tr');
  await expect(rows.filter({ hasText: 'Super Admin' })).toContainText('Locked');
  await expect(rows.filter({ hasText: 'Author' })).toContainText('Custom');
  await expect(rows.filter({ hasText: 'Editor' })).toContainText('From config');
  await expect(page.locator('.fa-nav-link.is-active')).toContainText('Roles');
});

test('All content covers every content box and the media library, and saving keeps the wildcard', async ({ page }) => {
  await page.goto('/en/admin/roles/edit/5');
  const media = box(page, 'content.media.delete');
  await expect(media).not.toBeChecked();
  await page.locator('[data-role-group="content"] [data-role-all]').check();
  await expect(box(page, 'content.posts.delete')).toBeChecked();
  await expect(box(page, 'content.posts.delete')).toBeDisabled();
  await expect(media).toBeChecked();
  await expect(media).toBeDisabled();
  await expect(page.locator('[data-role-group="media"] [data-role-count]')).toHaveText('4 of 4');
  await expect(page.locator('[data-role-group="content"] [data-role-covered-note]')).toBeVisible();
  await expect(page.locator('[data-admin-record-state]')).toContainText('Unsaved');

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('[data-admin-notifications]')).toContainText('Role saved.');
  const author = (await state(page)).roles.find(r => r.role === 5)!;
  expect(JSON.parse(author.permissions)).toEqual(['content.*']);

  // Off again: the boxes stay ticked and can be changed one by one
  await page.locator('[data-role-group="content"] [data-role-all]').uncheck();
  await expect(box(page, 'content.posts.delete')).toBeEnabled();
  await expect(box(page, 'content.posts.delete')).toBeChecked();
});

test('Edit ticks View, unticking View clears the row, and names are added under Other', async ({ page }) => {
  await page.goto('/en/admin/roles/edit/1');
  await box(page, 'content.listings.update').check();
  await expect(box(page, 'content.listings.view')).toBeChecked();
  await box(page, 'content.listings.view').uncheck();
  await expect(box(page, 'content.listings.update')).not.toBeChecked();
  await box(page, 'content.articles.delete').check();

  const add = page.locator('[data-role-add]');
  await add.fill('reports.export, bad..name');
  await add.press('Enter');
  await expect(page.locator('[data-role-chips]')).toContainText('reports.export');
  await expect(add).toHaveValue('bad..name');
  await add.fill('');

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('[data-admin-notifications]')).toContainText('Role saved.');
  const editor = (await state(page)).roles.find(r => r.role === 1)!;
  expect(JSON.parse(editor.permissions).sort()).toEqual(['content.articles.delete', 'content.articles.view', 'content.posts.view', 'reports.export']);
  await expect(page.locator('.fa-role-title .fa-badge')).toHaveText('From config · changed');

  // Reset asks first, then brings the config values back
  await page.getByRole('button', { name: 'Reset to default' }).click();
  await page.locator('dialog.fa-confirm[open]').getByRole('button', { name: 'Reset to default' }).click();
  await expect(page.locator('[data-admin-notifications]')).toContainText('config values again');
  expect((await state(page)).roles.find(r => r.role === 1)).toBeUndefined();
});

test('a new role, and deleting a role moves its users', async ({ page }) => {
  await page.goto('/en/admin/roles');
  await page.getByRole('link', { name: 'New role' }).click();
  await page.locator('input[name="name[en]"]').fill('Translator');
  await box(page, 'content.posts.update').check();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('[data-admin-notifications]')).toContainText('Role created.');
  await expect(page.locator('h1')).toContainText('Translator');
  const created = (await state(page)).roles.find(r => JSON.parse(r.name).en === 'Translator')!;
  expect(JSON.parse(created.permissions).sort()).toEqual(['content.posts.update', 'content.posts.view']);

  await page.goto('/en/admin/roles');
  await page.locator('.fa-roles-table tr', { hasText: 'Author' }).getByRole('link', { name: 'Delete' }).click();
  const dialog = page.locator('dialog.fa-role-delete[open]');
  await expect(dialog).toContainText('2 users have this role');
  await expect(dialog.locator('select')).toHaveValue('1');
  await dialog.getByRole('button', { name: 'Move users and delete' }).click();
  await expect(page.locator('[data-admin-notifications]')).toContainText('Role deleted.');
  const after = await state(page);
  expect(after.roles.find(r => r.role === 5)).toBeUndefined();
  expect(after.users.filter(u => ['Lana Aziz', 'Rebin Kamal'].includes(u.full_name)).map(u => u.role)).toEqual([1, 1]);
});

test('Arabic: the page is right to left', async ({ page }) => {
  await page.goto('/ar/admin/roles/edit/5');
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('h1')).toContainText('كاتب');
  await expect(page.locator('[data-role-group="content"] .fa-role-all')).toContainText('كل المحتوى');
});
