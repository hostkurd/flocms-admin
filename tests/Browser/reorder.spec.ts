import { test, expect, Page } from '@playwright/test';

const rows = (page: Page) => page.locator('[data-reorder-list] > [data-reorder-id]');
const ids = (page: Page) => rows(page).evaluateAll(items => items.map(item => Number((item as HTMLElement).dataset.reorderId)));
const status = (page: Page) => page.locator('[data-reorder-status]');
async function saved(page: Page): Promise<number[]> {
  return (await page.request.post('/__test/faq-order')).json();
}
async function faqs(page: Page, count = 5) {
  await page.request.post('/__test/faqs', { form: { count: String(count) } });
}
/** Drag a row's handle with the mouse so its centre ends at clientY. */
async function drag(page: Page, from: number, toY: number) {
  const handle = rows(page).nth(from).locator('[data-reorder-handle]');
  const box = (await handle.boundingBox())!;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2 + 4, { steps: 2 });
  await page.mouse.move(box.x + box.width / 2, toY, { steps: 12 });
  await page.mouse.up();
}
async function below(page: Page, index: number): Promise<number> {
  const box = (await rows(page).nth(index).boundingBox())!;
  return box.y + box.height * 0.8;
}

test.beforeEach(async ({ page }) => { await faqs(page); });

test('the Reorder button opens Reorder mode with handles, and Done returns to the list', async ({ page }) => {
  await page.goto('/en/admin/faqs');
  await page.getByRole('link', { name: 'Reorder' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/faqs\/reorder$/);
  await expect(page.getByRole('heading', { name: 'Reorder FAQs' })).toBeVisible();
  await expect(rows(page)).toHaveCount(5);
  await expect(page.locator('[data-reorder-handle]').first()).toBeVisible();
  await expect(page.locator('[data-reorder-steps]').first()).toBeHidden();
  await page.getByRole('link', { name: 'Done' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/faqs$/);
  await expect(page.getByRole('heading', { name: 'FAQs', exact: true })).toBeVisible();
});

test('dragging with the mouse saves the new order at once', async ({ page }) => {
  await page.goto('/en/admin/faqs/reorder');
  expect(await ids(page)).toEqual([1, 2, 3, 4, 5]);
  await drag(page, 0, await below(page, 2));
  await expect(status(page)).toHaveText('Saved');
  expect(await ids(page)).toEqual([2, 3, 1, 4, 5]);
  await expect(rows(page).nth(2).locator('[data-reorder-position]')).toHaveText('3');
  expect(await saved(page)).toEqual([2, 3, 1, 4, 5]);
  await expect(rows(page).first()).not.toHaveClass(/is-dragging/);

  await drag(page, 4, (await rows(page).nth(0).boundingBox())!.y + 2);
  await expect.poll(() => saved(page)).toEqual([5, 2, 3, 1, 4]);
  await page.reload();
  expect(await ids(page)).toEqual([5, 2, 3, 1, 4]);
});

test('dragging with a finger works and does not scroll the page', async ({ browser }) => {
  const context = await browser.newContext({ hasTouch: true, viewport: { width: 390, height: 800 } });
  const page = await context.newPage();
  await faqs(page);
  await page.goto('/en/admin/faqs/reorder');
  const handle = rows(page).nth(0).locator('[data-reorder-handle]');
  const box = (await handle.boundingBox())!;
  const target = await below(page, 3);
  const cdp = await context.newCDPSession(page);
  const x = box.x + box.width / 2;
  let y = box.y + box.height / 2;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
  while (y < target) { y = Math.min(target, y + 8); await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y }] }); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await expect(status(page)).toHaveText('Saved');
  expect(await ids(page)).toEqual([2, 3, 4, 1, 5]);
  expect(await page.evaluate(() => scrollY)).toBe(0);
  await context.close();
});

test('the keyboard picks up, moves, drops and cancels, and a screen reader hears each step', async ({ page }) => {
  await page.goto('/en/admin/faqs/reorder');
  const handle = page.locator('#fa-reorder-row-1 [data-reorder-handle]');
  await handle.focus();
  await page.keyboard.press('Space');
  await expect(status(page)).toHaveText('Where is my parcel? picked up, position 1 of 5.');
  await expect(handle).toHaveAttribute('aria-pressed', 'true');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await expect(status(page)).toHaveText('Where is my parcel?, moved to position 3 of 5.');
  await expect(handle).toBeFocused();
  await page.keyboard.press('Space');
  await expect(status(page)).toHaveText('Saved');
  expect(await saved(page)).toEqual([2, 3, 1, 4, 5]);

  let posts = 0;
  page.on('request', request => { if (request.method() === 'POST' && request.url().endsWith('/faqs/reorder')) posts++; });
  await page.keyboard.press('Enter');
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('ArrowUp');   // already at the top: stays
  await page.keyboard.press('Escape');
  await expect(status(page)).toHaveText('Move cancelled. Where is my parcel? is back at position 3.');
  expect(await ids(page)).toEqual([2, 3, 1, 4, 5]);
  await expect(handle).toHaveAttribute('aria-pressed', 'false');
  expect(posts).toBe(0);
});

test('dragging near the bottom edge scrolls a long list', async ({ browser }) => {
  const context = await browser.newContext({ viewport: { width: 1000, height: 520 } });
  const page = await context.newPage();
  await faqs(page, 40);
  await page.goto('/en/admin/faqs/reorder');
  await expect(rows(page)).toHaveCount(40);
  const handle = rows(page).nth(0).locator('[data-reorder-handle]');
  const box = (await handle.boundingBox())!;
  await page.mouse.move(box.x + 10, box.y + 10);
  await page.mouse.down();
  await page.mouse.move(box.x + 10, 515, { steps: 15 });
  await page.waitForTimeout(1200);
  await page.mouse.move(box.x + 10, 512);
  await page.mouse.up();
  await expect(status(page)).toHaveText('Saved');
  const order = await ids(page);
  expect(order.indexOf(1)).toBeGreaterThan(12);
  expect((await saved(page)).indexOf(1)).toBe(order.indexOf(1));
  await context.close();
});

test('a failed save puts the rows back and says so', async ({ page }) => {
  await page.goto('/en/admin/faqs/reorder');
  await page.route('**/en/admin/faqs/reorder', route => route.request().method() === 'POST'
    ? route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }) : route.continue());
  await drag(page, 0, await below(page, 2));
  await expect(status(page)).toHaveText('The order could not be saved. Try again.');
  await expect(status(page)).toHaveClass(/is-error/);
  expect(await ids(page)).toEqual([1, 2, 3, 4, 5]);
  await expect(rows(page).nth(0).locator('[data-reorder-position]')).toHaveText('1');
  expect(await saved(page)).toEqual([1, 2, 3, 4, 5]);
});

test('when another editor changed the list, the page reloads and explains', async ({ page }) => {
  await page.goto('/en/admin/faqs/reorder');
  await page.request.post('/__test/faq-add');
  await drag(page, 0, await below(page, 1));
  await expect(page.getByText('The list changed, so it was reloaded. Try again.')).toBeVisible();
  await expect(rows(page)).toHaveCount(6);
  expect((await saved(page)).slice(0, 5)).toEqual([1, 2, 3, 4, 5]);
});

test('filtered Reorder mode moves only that group among its own positions', async ({ page }) => {
  await page.goto('/en/admin/faqs/reorder?filter[category]=billing');
  expect(await ids(page)).toEqual([2, 4]);
  await expect(page.locator('.fa-reorder-chips')).toContainText('Billing');
  await drag(page, 1, (await rows(page).nth(0).boundingBox())!.y + 2);
  await expect(status(page)).toHaveText('Saved');
  expect(await saved(page)).toEqual([1, 4, 3, 2, 5]);
});

test('Arabic: handles sit on the right, titles are translated and dragging works', async ({ page }) => {
  await page.goto('/ar/admin/faqs/reorder');
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  const first = rows(page).nth(0);
  await expect(first.locator('.fa-reorder-title')).toHaveText('أين طردي؟');
  const handle = (await first.locator('[data-reorder-handle]').boundingBox())!;
  const title = (await first.locator('.fa-reorder-title').boundingBox())!;
  expect(handle.x).toBeGreaterThan(title.x + title.width);
  // A Latin title keeps its punctuation on its own side on a right-to-left page.
  await expect(first.locator('.fa-reorder-title')).toHaveCSS('unicode-bidi', 'plaintext');
  await page.screenshot({ path: 'test-results/reorder-ar.png', fullPage: true });
  await drag(page, 0, await below(page, 1));
  await expect(status(page)).toHaveText('تم الحفظ');
  expect(await saved(page)).toEqual([2, 1, 3, 4, 5]);
});

test('without JavaScript the arrow buttons move a row one place and keep focus', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await faqs(page);
  await page.goto('/en/admin/faqs/reorder');
  await expect(page.locator('[data-reorder-handle]').first()).toBeHidden();
  await page.getByRole('button', { name: 'Move Where is my parcel? down' }).click();
  await expect(page).toHaveURL(/moved=1&step=down/);
  expect(await ids(page)).toEqual([2, 1, 3, 4, 5]);
  await expect(page.getByRole('button', { name: 'Move Where is my parcel? down' })).toBeFocused();
  await page.getByRole('button', { name: 'Move How do I pay? up' }).isDisabled();
  expect(await saved(page)).toEqual([2, 1, 3, 4, 5]);
  await context.close();
});

test('Done waits for a slow save, so the list shows the new order', async ({ page }) => {
  await page.goto('/en/admin/faqs/reorder');
  await page.route('**/en/admin/faqs/reorder', async route => {
    if (route.request().method() !== 'POST') return route.continue();
    await new Promise(resolve => setTimeout(resolve, 800));
    await route.continue();
  });
  await drag(page, 0, await below(page, 2));
  await page.getByRole('link', { name: 'Done' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/faqs$/);
  await expect(page.locator('tbody tr').first()).toContainText('How do I pay?');
  expect(await saved(page)).toEqual([2, 3, 1, 4, 5]);
});

test('a lost pointer capture ends the drag and puts the row back', async ({ page }) => {
  await page.goto('/en/admin/faqs/reorder');
  const handle = page.locator('#fa-reorder-row-1 [data-reorder-handle]');
  const box = (await handle.boundingBox())!;
  await page.mouse.move(box.x + 10, box.y + 10);
  await page.mouse.down();
  await page.mouse.move(box.x + 10, await below(page, 2), { steps: 8 });
  // As when the system takes the pointer away mid-drag.
  await handle.evaluate(element => { for (let id = 0; id < 4; id++) if (element.hasPointerCapture(id)) element.releasePointerCapture(id); });
  await page.mouse.move(box.x + 12, await below(page, 2));   // the browser reports a lost capture with the next pointer event
  await expect(page.locator('[data-reorder-list]')).not.toHaveClass(/is-sorting/);
  expect(await ids(page)).toEqual([1, 2, 3, 4, 5]);
  await page.mouse.up();
  await page.keyboard.press('Tab');
  // The page still works: the next drag saves.
  await drag(page, 0, await below(page, 1));
  await expect(status(page)).toHaveText('Saved');
});
