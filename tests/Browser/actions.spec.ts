import { test, expect, Page } from '@playwright/test';
const editor = (page: Page) => page.locator('dialog.fa-editor');
const notices = (page: Page) => page.locator('[data-admin-notifications] [data-admin-notification]');
async function ready(page: Page, path = '/en/admin/posts') {
  await page.goto(path);
  await expect(page.locator('[data-rx-component]')).toBeVisible();
  await page.evaluate(() => { (window as any).documentMarker = 'actions'; });
}
async function stable(page: Page) {
  expect(await page.evaluate(() => (window as any).documentMarker)).toBe('actions');
}
// Row actions other than Edit sit in the row's "More actions" menu.
async function rowAction(page: Page, name: string, id: number, prefix = 'fa-admin-resource') {
  const button = page.locator(`[data-admin-action="${name}"][data-admin-record="${id}"]`);
  if (!(await button.isVisible())) await page.locator(`#${prefix}-menu-${id} > summary`).click();
  return button;
}
async function open(page: Page, name: string, id = 8) {
  await (await rowAction(page, name, id)).click();
  await expect(editor(page)).toBeVisible();
}
async function publish(page: Page) {
  await open(page, 'publish');
  await editor(page).locator('[name=reason]').fill('Content reviewed');
  await editor(page).getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(notices(page)).toContainText('Post published.');
}

test('publish action validates, preserves list state and delivers once across navigation', async ({ page }) => {
  await ready(page);
  await page.locator('.fa-content input[type=search]').fill('Studio');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await open(page, 'publish');
  await editor(page).getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(editor(page).locator('[name=reason]')).toHaveAttribute('aria-invalid', 'true');
  await expect(editor(page).locator('[name=reason]')).toBeFocused();
  await expect(notices(page)).toHaveCount(0);
  await editor(page).locator('[name=reason]').fill('Content reviewed');
  await editor(page).getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('.fa-content input[type=search]')).toHaveValue('Studio');
  await expect(page.locator('tbody')).toContainText('Published');
  await expect(page.locator('[data-admin-action=unpublish][data-admin-record="8"]')).toBeAttached();
  await expect(notices(page)).toHaveCount(1);
  const id = await notices(page).getAttribute('data-admin-notification');
  await page.locator('.fa-nav a[href="/en/admin/dashboard"]').click();
  await expect(page.locator('h1')).toHaveText('Dashboard');
  await expect(notices(page)).toHaveAttribute('data-admin-notification', id!);
  await page.goBack();
  await expect(page.locator('h1')).toHaveText('Posts');
  await page.getByRole('button', { name: 'Clear', exact: true }).click();
  await expect(page.locator('tbody tr')).toHaveCount(3);
  await expect(notices(page)).toHaveCount(1);
  await notices(page).getByRole('button', { name: 'Dismiss notification' }).focus();
  await page.keyboard.press('Enter');
  await expect(notices(page)).toHaveCount(0);
  await expect(page.locator('#fa-content')).toBeFocused();
  await stable(page);
});

test('unpublish confirmation cancels with Escape and executes on explicit confirmation', async ({ page }) => {
  await ready(page); await publish(page);
  await open(page, 'unpublish');
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
  // Focus returns to the row menu that opened the dialog.
  await expect(page.locator('#fa-admin-resource-menu-8 > summary')).toBeFocused();
  await expect(page.locator('tbody')).toContainText('Published');
  await expect(notices(page)).toHaveCount(1);
  await open(page, 'unpublish');
  await editor(page).getByRole('button', { name: 'Unpublish', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('#fa-admin-resource-publish-8')).toBeAttached();
  await expect(notices(page)).toHaveCount(2);
  await expect(notices(page).last()).toHaveAttribute('role', 'alert');
  await expect(notices(page).last()).toContainText('Post unpublished.');
  await stable(page);
});

test('page and toolbar definitions use the shared action renderer and notification levels', async ({ page }) => {
  await ready(page);
  await page.locator('[data-admin-action=announce][data-admin-placement=page]').click();
  await expect(editor(page)).toHaveAccessibleName('Announcement');
  await editor(page).getByRole('button', { name: 'Announcement', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(notices(page)).toContainText('Review pending drafts.');
  await page.locator('[data-admin-action=help][data-admin-placement=table]').click();
  await expect(notices(page)).toHaveCount(2);
  await expect(notices(page).last()).toHaveAttribute('role', 'status');
  await expect(notices(page).last()).toContainText('Publish a draft after reviewing its content.');
  await expect(editor(page)).not.toBeVisible();
  await stable(page);
});

test('info and success close by themselves (hover pauses), warnings after 12 seconds (0.34)', async ({ page }) => {
  await page.clock.install();
  await ready(page);
  // Warning: 12 seconds, with its countdown line
  await page.locator('[data-admin-action=announce][data-admin-placement=page]').click();
  await editor(page).getByRole('button', { name: 'Announcement', exact: true }).click();
  await expect(notices(page)).toHaveAttribute('data-admin-notification-duration', '12');
  await expect(notices(page).locator('.fa-notification-timer')).toHaveCount(1);
  // Info: 5 seconds, paused while the pointer is on it
  await page.locator('[data-admin-action=help][data-admin-placement=table]').click();
  await expect(notices(page)).toHaveCount(2);
  const info = notices(page).last();
  await expect(info).toHaveAttribute('data-admin-notification-duration', '5');
  await info.hover();
  await page.clock.runFor(8000);
  await expect(notices(page)).toHaveCount(2);
  await page.mouse.move(5, 5);
  await page.clock.runFor(3000);
  await expect(notices(page)).toHaveCount(1);
  await expect(notices(page)).toContainText('Review pending drafts.');
  await page.clock.runFor(60000);
  await expect(notices(page)).toHaveCount(0);
  await stable(page);
});

test('duplicate attempts do not repeat a custom mutation and stale snapshots are rejected', async ({ page }) => {
  await ready(page); await open(page, 'publish');
  await editor(page).locator('[name=reason]').fill('Content reviewed');
  let attempts = 0; let requestBody = ''; let release: (() => void) | undefined;
  const gate = new Promise<void>(resolve => { release = resolve; });
  await page.route('**/flo-reactive/update', async route => {
    const body = new URLSearchParams(route.request().postData() || '');
    if (body.get('_action') === 'execute') { attempts++; requestBody = body.toString(); await gate; }
    await route.continue();
  });
  const submit = editor(page).getByRole('button', { name: 'Publish', exact: true });
  await submit.click();
  await expect(submit).toBeDisabled();
  await submit.click({ force: true });
  expect(attempts).toBe(1);
  release!();
  await expect(editor(page)).not.toBeVisible();
  await expect(notices(page)).toHaveCount(1);
  const replay = await page.request.post('/en/flo-reactive/update', { data: requestBody, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
  expect(replay.status()).toBe(409);
  await stable(page);
});

test('custom action permission and network failures retain the draft and allow cancellation', async ({ page }) => {
  await ready(page); await open(page, 'publish');
  await editor(page).locator('[name=reason]').fill('Keep this reason');
  await page.route('**/flo-reactive/update', async route => {
    if (new URLSearchParams(route.request().postData() || '').get('_action') === 'execute') await route.abort();
    else await route.continue();
  });
  await editor(page).getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(editor(page).locator('[data-rx-status]')).toContainText('failed');
  await expect(editor(page).locator('[name=reason]')).toHaveValue('Keep this reason');
  await expect(notices(page)).toHaveCount(0);
  await page.unroute('**/flo-reactive/update');
  await page.request.post('/__test/readonly');
  await editor(page).getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(editor(page).locator('[data-rx-status]')).toContainText('permission');
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();
  await expect(notices(page)).toHaveCount(0);
  await stable(page);
});

test('Kurdish action form and notification remain contained and localized on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await ready(page, '/ku/admin/posts'); await open(page, 'publish');
  await expect(editor(page)).toHaveAccessibleName('بڵاوکردنەوە');
  await editor(page).locator('[name=reason]').fill('پشکنراوە');
  await editor(page).getByRole('button', { name: 'بڵاوکردنەوە', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(notices(page)).toContainText('بابەتەکە بڵاوکرایەوە.');
  await expect(notices(page)).toHaveAttribute('dir', 'rtl');
  await expect(notices(page)).toHaveAttribute('lang', 'ku');
  const box = await notices(page).boundingBox();
  expect(box!.width).toBeLessThanOrEqual(390); expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await stable(page);
});

test('standard custom action forms also validate and save with JavaScript disabled', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await page.goto('http://127.0.0.1:8083/en/admin/posts?static=1');
    await page.locator('#fa-admin-resource-menu-8 > summary').click();
    await page.locator('a[href="/en/admin/posts/action/row/publish/8"]').click();
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.locator('[name=reason]')).toHaveAttribute('aria-invalid', 'true');
    await page.locator('[name=reason]').fill('Content reviewed');
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.locator('[data-admin-notification]')).toContainText('Post published.');
    await page.getByRole('link', { name: 'Continue on full page' }).click();
    await expect(page.locator('a[href="/en/admin/posts/action/row/unpublish/8"]')).toBeAttached();
  } finally { await context.close(); }
});

test('dialogs fit their content; an action can keep room in its body', async ({ page }) => {
  await ready(page);
  // A one-record bulk confirmation: no empty space, and the wording is singular
  await page.locator('[data-admin-select][value="8"]').check();
  await page.locator('[data-admin-bulk-action="delete"]').click();
  await expect(editor(page)).toBeVisible();
  await expect(editor(page).locator('.fa-confirm-text')).toHaveText('Delete the selected record? This cannot be undone.');
  const confirm = (await editor(page).boundingBox())!;
  expect(confirm.height).toBeLessThan(220);
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();

  // ->modalMinHeight(240) on Publish
  await open(page, 'publish');
  const body = editor(page).locator('.fa-dialog-body');
  await expect(editor(page)).toHaveAttribute('data-admin-dialog-min-height', '240px');
  expect((await body.boundingBox())!.height).toBeGreaterThanOrEqual(239);
  await page.keyboard.press('Escape');
  await expect(editor(page)).not.toBeVisible();

  // Edit has no minimum: its body is as tall as its fields
  await (await rowAction(page, 'edit', 8)).click();
  await expect(editor(page)).toBeVisible();
  await expect(editor(page)).not.toHaveAttribute('data-admin-dialog-min-height', /.*/);
  expect(await editor(page).evaluate(el => (el as HTMLElement).style.getPropertyValue('--fa-dialog-body-min-height'))).toBe('');
  await stable(page);
});
