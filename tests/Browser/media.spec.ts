import { test, expect, Page } from '@playwright/test';

const photo = 'tests/Browser/fixtures/photo.png';
const clip = 'tests/Browser/fixtures/clip.mp4';
const tiles = (page: Page) => page.locator('.fa-media-grid .fa-media-tile');
const picker = (page: Page) => page.locator('dialog.fa-media-dialog');
const field = (page: Page, name: string) => page.locator(`[data-field="${name}"] [data-admin-media-field]`);

type State = { row: Record<string, string | null>; usage: Array<{ media_id: number | string; field: string }>; media: Array<{ id: number | string; title: string; alt: string | null; path: string }> };
async function state(page: Page): Promise<State> { return (await page.request.post('/__test/listing')).json(); }
const usage = (s: State) => s.usage.map(u => `${u.media_id}:${u.field}`);

test.beforeEach(async ({ page }) => { await page.request.post('/__test/media', { form: { seed: '1' } }); });
// Other specs expect the library to be off, as the fixture app starts.
test.afterAll(async ({ request }) => { await request.post('/__test/media-off'); });

test('the Media page uploads images and videos with progress and lists them newest first', async ({ page }) => {
  await page.goto('/en/admin/media');
  await expect(page.getByRole('link', { name: 'Media', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(tiles(page)).toHaveCount(4);
  await expect(page.locator('[data-media-send]')).toBeHidden();
  // Slow the requests down so the progress list can be seen before the page reloads.
  let release!: () => void;
  const held = new Promise<void>(resolve => { release = resolve; });
  await page.route(/\/admin\/media\/(upload|video)/, async route => { await held; await route.continue(); });
  await page.locator('[data-media-files]').setInputFiles([photo, clip]);
  await expect(page.locator('.fa-media-progress-item')).toHaveText(['Uploading photo.png…', 'Waiting: clip.mp4']);
  release();
  await expect(tiles(page)).toHaveCount(6);
  await expect(tiles(page).nth(0).locator('.fa-media-name')).toHaveText('clip.mp4');
  await expect(tiles(page).nth(1).locator('.fa-media-name')).toHaveText('photo.png');
  await expect(tiles(page).nth(1).locator('img')).toHaveAttribute('src', /\/uploads\/editor\/library\/\d{4}\/\d{2}\/thumb\/[A-Za-z0-9]+\.png$/);
  const thumb = await tiles(page).nth(1).locator('img').getAttribute('src');
  expect((await page.request.get(thumb!)).status()).toBe(200);
  const media = (await state(page)).media;
  expect(media).toHaveLength(6);
  expect(media[5].path).toMatch(/^library\/\d{4}\/\d{2}\/[A-Za-z0-9]+\.mp4$/);
});

test('search and filters narrow the grid; Not used shows boxes for deleting several', async ({ page }) => {
  await page.goto('/en/admin/media');
  await page.getByPlaceholder('Search media').fill('الغروب');
  await page.getByRole('button', { name: 'Apply filters' }).click();
  await expect(tiles(page)).toHaveCount(1);
  await expect(tiles(page).locator('.fa-media-name')).toHaveText('Sea view');
  await page.getByRole('link', { name: 'Clear filters' }).click();
  await expect(tiles(page)).toHaveCount(4);
  await page.locator('#fa-media-kind').selectOption('video');
  await page.getByRole('button', { name: 'Apply filters' }).click();
  await expect(tiles(page)).toHaveCount(1);
  await expect(page.locator('.fa-media-pick')).toHaveCount(0);
  await page.goto('/en/admin/media?unused=1');
  await page.getByLabel('Select garden.png').check();
  await page.getByLabel('Select Tour').check();
  await page.getByRole('button', { name: 'Delete selected' }).click();
  await page.locator('#fa-confirm [data-confirm-yes]').click();
  await expect(page.getByText('2 deleted.')).toBeVisible();
  await expect(tiles(page)).toHaveCount(2);
});

test('the details page saves the title and the alt text per language', async ({ page }) => {
  await page.goto('/en/admin/media/edit/2');
  await expect(page.getByRole('heading', { name: 'garden.png' })).toBeVisible();
  await page.getByLabel('Title').fill('Garden');
  await page.locator('[name="alt[en]"]').fill('Green garden');
  await page.locator('[data-field="alt"] .fa-lang-badge').click();
  await page.locator('[data-field="alt"] .fa-lang-dialog [name="alt[ar]"]').fill('حديقة خضراء');
  await page.locator('[data-field="alt"] .fa-lang-dialog [data-dialog-save]').click();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Media item saved.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Garden' })).toBeVisible();
  const item = (await state(page)).media.find(m => Number(m.id) === 2)!;
  expect(JSON.parse(item.alt!)).toEqual({ en: 'Green garden', ar: 'حديقة خضراء' });
});

test('picker fields: choose a cover, add and reorder a gallery, save; usage is recorded', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  await field(page, 'cover_id').getByRole('button', { name: 'Choose image' }).click();
  await expect(picker(page)).toBeVisible();
  await expect(picker(page).locator('.fa-media-option')).toHaveCount(3, { timeout: 5000 });
  await expect(picker(page).locator('input[type=search]')).toBeFocused();
  await picker(page).locator('input[type=search]').fill('sea');
  await expect(picker(page).locator('.fa-media-option')).toHaveCount(1);
  await picker(page).locator('.fa-media-option').click();
  await expect(picker(page)).toHaveCount(0);
  await expect(field(page, 'cover_id').locator('.fa-media-chip-name')).toHaveText('Sea view');
  await expect(field(page, 'cover_id').getByRole('button', { name: 'Change' })).toBeFocused();

  await field(page, 'gallery').getByRole('button', { name: 'Add media' }).click();
  await picker(page).locator('.fa-media-option', { hasText: 'garden.png' }).click();
  await picker(page).locator('.fa-media-option', { hasText: 'Hall' }).click();
  await expect(picker(page).getByRole('button', { name: 'Use selected (2)' })).toBeEnabled();
  await picker(page).getByRole('button', { name: 'Use selected (2)' }).click();
  const names = field(page, 'gallery').locator('.fa-media-chip-name');
  await expect(names).toHaveText(['garden.png', 'Hall']);
  await field(page, 'gallery').getByRole('button', { name: 'Move garden.png later' }).click();
  await expect(names).toHaveText(['Hall', 'garden.png']);
  await expect(field(page, 'gallery').getByRole('button', { name: 'Move garden.png earlier' })).toBeFocused();
  await expect(field(page, 'gallery').locator('[aria-live]')).toHaveText('garden.png, position 2 of 2.');
  // Drag the second thumbnail before the first
  const [first, second] = [await field(page, 'gallery').locator('.fa-media-chip').nth(0).boundingBox(), await field(page, 'gallery').locator('.fa-media-chip').nth(1).boundingBox()];
  await page.mouse.move(second!.x + 30, second!.y + 30);
  await page.mouse.down();
  await page.mouse.move(first!.x + 10, first!.y + 30, { steps: 10 });
  await page.mouse.up();
  await expect(names).toHaveText(['garden.png', 'Hall']);
  await field(page, 'video_id').getByRole('button', { name: 'Choose video' }).click();
  await expect(picker(page).locator('.fa-media-option')).toHaveCount(1);
  await picker(page).locator('.fa-media-option').click();

  await page.getByRole('button', { name: 'Save and close' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/listings$/);
  const saved = await state(page);
  expect([saved.row.cover_id, saved.row.gallery, saved.row.video_id].map(String)).toEqual(['1', '["2","3"]', '4']);
  expect(usage(saved)).toEqual(['1:cover_id', '2:gallery', '3:gallery', '4:video_id']);
  await expect(page.locator('tbody tr').first().locator('img.fa-media-thumb')).toHaveCount(3);

  await page.goto('/en/admin/media/edit/1');
  await expect(page.getByRole('link', { name: 'Listings · Villa · Cover' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Delete' })).toBeDisabled();
});

test('the picker can upload: a new image is chosen at once', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  await field(page, 'cover_id').getByRole('button', { name: 'Choose image' }).click();
  await picker(page).locator('input[type=file]').setInputFiles(photo);
  await expect(picker(page)).toHaveCount(0);
  await expect(field(page, 'cover_id').locator('.fa-media-chip-name')).toHaveText('photo.png');
  await expect(field(page, 'cover_id').locator('img')).toHaveAttribute('src', /library\/\d{4}\/\d{2}\/thumb\//);
});

test('Escape closes the picker without changes and focus returns', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  const choose = field(page, 'cover_id').getByRole('button', { name: 'Choose image' });
  await choose.click();
  await expect(picker(page)).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(picker(page)).toHaveCount(0);
  await expect(choose).toBeFocused();
  await expect(field(page, 'cover_id').locator('.fa-media-chip')).toHaveCount(0);
});

test('the rich editor inserts an image from the library with its alt text, and usage follows', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  const body = page.locator('[data-field=body]');
  await expect(body.locator('.fa-rich')).toHaveClass(/is-ready/);
  await body.locator('.fa-rich-content').first().click();
  await body.getByRole('button', { name: 'Image', exact: true }).click();
  await page.getByRole('menuitem', { name: /Choose from the library/ }).click();
  await picker(page).locator('.fa-media-option', { hasText: 'Hall' }).click();
  await expect(body.locator('.fa-rich-content img')).toHaveAttribute('src', '/uploads/editor/library/2026/10/web/hall.webp');
  await expect(body.locator('.fa-rich-content img')).toHaveAttribute('alt', 'Entrance hall');
  // The library's square tiles must not style the editor's image frame.
  await expect(body.locator('.fa-rich-content .fa-media-frame')).not.toHaveCSS('aspect-ratio', '1 / 1');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText(/saved/i).first()).toBeVisible();
  expect(usage(await state(page))).toEqual(['3:body']);
  await page.goto('/en/admin/media/edit/3');
  await expect(page.getByText(/shown inside rich text/)).toBeVisible();
  await expect(page.locator('#fa-media-replace')).toHaveCount(0);
});

test('without media create, editor uploads stay with the field and do not join the library', async ({ page }) => {
  await page.request.post('/__test/writer');
  await page.goto('/en/admin/listings/edit/1');
  const body = page.locator('[data-field=body]');
  await expect(body.locator('.fa-rich')).toHaveClass(/is-ready/);
  await body.locator('.fa-rich-content').first().click();
  const chooser = page.waitForEvent('filechooser');
  await body.getByRole('button', { name: 'Image', exact: true }).click();
  await page.getByRole('menuitem', { name: /Upload an image/ }).click();
  await (await chooser).setFiles(photo);
  await expect(body.locator('.fa-rich-content img')).toHaveAttribute('src', /^\/uploads\/editor\/listings\/body\/\d{4}\//);
  expect((await state(page)).media).toHaveLength(4);
});

test('Arabic: the library and the field work right to left', async ({ page }) => {
  await page.goto('/ar/admin/media');
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByRole('heading', { name: 'الوسائط' })).toBeVisible();
  await page.screenshot({ path: 'test-results/media-ar.png' });
  await page.goto('/ar/admin/listings/edit/1');
  await field(page, 'gallery').getByRole('button', { name: 'إضافة وسائط' }).click();
  await picker(page).locator('.fa-media-option', { hasText: 'garden.png' }).click();
  await picker(page).locator('.fa-media-option', { hasText: 'Hall' }).click();
  await picker(page).getByRole('button', { name: /استخدام المحدد/ }).click();
  const chips = field(page, 'gallery').locator('.fa-media-chip');
  const [a, b] = [await chips.nth(0).boundingBox(), await chips.nth(1).boundingBox()];
  expect(a!.x).toBeGreaterThan(b!.x);
  await expect(field(page, 'gallery').locator('.fa-media-chip-name').first()).toHaveCSS('unicode-bidi', 'plaintext');
  await page.screenshot({ path: 'test-results/media-form-ar.png' });
});

test('without JavaScript the field is a select and the Media page uploads with a plain form', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.request.post('/__test/media', { form: { seed: '1' } });
  await page.goto('/en/admin/listings/edit/1');
  await page.locator('#fa-f-cover_id, [name="cover_id"]').first().selectOption('3');
  await page.getByRole('button', { name: 'Save and close' }).click();
  expect(String((await state(page)).row.cover_id)).toBe('3');
  await page.goto('/en/admin/media');
  await page.locator('[data-media-files]').setInputFiles(photo);
  await page.getByRole('button', { name: 'Send files' }).click();
  await expect(page.getByText('1 uploaded.')).toBeVisible();
  await expect(tiles(page)).toHaveCount(5);
  await context.close();
});

test('with the library switched off there is no Media page, picker or editor entry', async ({ page }) => {
  await page.request.post('/__test/media-off');
  await page.goto('/en/admin/listings/edit/1');
  await expect(page.getByRole('link', { name: 'Media', exact: true })).toHaveCount(0);
  await expect(page.getByText('The media library is not set up').first()).toBeVisible();
  await page.locator('[data-field=body] .fa-rich-content').first().click();
  await page.locator('[data-field=body]').getByRole('button', { name: 'Image', exact: true }).click();
  await expect(page.getByRole('menuitem', { name: /Choose from the library/ })).toHaveCount(0);
  expect((await page.request.get('/en/admin/media')).status()).toBe(404);
});
