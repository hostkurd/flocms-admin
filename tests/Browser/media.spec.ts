import { test, expect, Page, Locator } from '@playwright/test';

const photo = 'tests/Browser/fixtures/photo.png';
const clip = 'tests/Browser/fixtures/clip.mp4';
const browser = (page: Page) => page.locator('.fa-mb.is-manage');
const tiles = (scope: Page | Locator) => scope.locator('.fa-mb-tile');
const tile = (scope: Page | Locator, name: string) => scope.getByRole('option', { name, exact: true });
const picker = (page: Page) => page.locator('dialog.fa-mb-dialog:not(.fa-mb-editor)');
const field = (page: Page, name: string) => page.locator(`[data-field="${name}"] [data-admin-media-field]`);
const details = (page: Page) => page.locator('.fa-mb.is-manage .fa-mb-details');
const queue = (page: Page) => page.locator('.fa-mb-queue');

type Media = { id: number | string; title: string; alt: string | null; path: string; folder_id?: number | string | null; focal_x?: number | string | null; focal_y?: number | string | null };
type State = { row: Record<string, string | null>; usage: Array<{ media_id: number | string; field: string }>; media: Media[]; folders?: Array<{ id: number | string; parent_id: number | string | null; name: string }> };
async function state(page: Page): Promise<State> { return (await page.request.post('/__test/listing')).json(); }
const usage = (s: State) => s.usage.map(u => `${u.media_id}:${u.field}`);
const names = (scope: Page | Locator) => tiles(scope).locator('.fa-mb-name');

async function upload(page: Page, files: string[]): Promise<void> {
  const chooser = page.waitForEvent('filechooser');
  await page.locator('[data-media-upload-button]').click();
  await (await chooser).setFiles(files);
}

async function newFolder(page: Page, name: string): Promise<void> {
  await page.locator('[data-media-new-folder]').click();
  const prompt = page.locator('dialog.fa-mb-confirm');
  await prompt.getByRole('textbox').fill(name);
  await prompt.getByRole('button', { name: 'Create folder' }).click();
  await expect(page.getByText(`Folder “${name}” created.`).first()).toBeVisible();
}

test.beforeEach(async ({ page }) => { await page.request.post('/__test/media', { form: { seed: '1' } }); });
// Other specs expect the library to be off, as the fixture app starts.
test.afterAll(async ({ request }) => { await request.post('/__test/media-off'); });

test('the Media page uploads images and videos through the queue, newest first', async ({ page }) => {
  await page.goto('/en/admin/media');
  await expect(page.getByRole('link', { name: 'Media', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(tiles(browser(page))).toHaveCount(4);
  await expect(page.locator('[data-media-upload-form]')).toBeHidden();
  await expect(page.locator('.fa-mb-stats')).toContainText('4 items');
  // Hold the requests so the queue can be seen while it works.
  let release!: () => void;
  const held = new Promise<void>(resolve => { release = resolve; });
  await page.route(/\/admin\/media\/(upload|video)/, async route => { await held; await route.continue(); });
  await upload(page, [photo, clip]);
  await expect(queue(page)).toBeVisible();
  await expect(queue(page).locator('.fa-mb-queue-title')).toHaveText('Uploading 1 of 2');
  await expect(queue(page).locator('.fa-mb-queue-name')).toHaveText(['photo.png', 'clip.mp4']);
  await expect(queue(page).locator('.fa-mb-queue-status').nth(1)).toHaveText('Waiting');
  release();
  await expect(queue(page).locator('.fa-mb-queue-title')).toHaveText('2 of 2 uploaded');
  await expect(names(browser(page))).toHaveText(['clip.mp4', 'photo.png', 'Tour', 'Hall', 'garden.png', 'Sea view']);
  await expect(tiles(browser(page)).nth(1).locator('img')).toHaveAttribute('src', /\/uploads\/editor\/library\/\d{4}\/\d{2}\/thumb\/[A-Za-z0-9]+\.png$/);
  const thumb = await tiles(browser(page)).nth(1).locator('img').getAttribute('src');
  expect((await page.request.get(thumb!)).status()).toBe(200);
  await expect(page.locator('.fa-mb-stats')).toContainText('6 items');
  const media = (await state(page)).media;
  expect(media).toHaveLength(6);
  expect(media[5].path).toMatch(/^library\/\d{4}\/\d{2}\/[A-Za-z0-9]+\.mp4$/);
  // The queue can be put away.
  await queue(page).getByRole('button', { name: 'Close' }).click();
  await expect(queue(page)).toBeHidden();
});

test('a file already in the library: use the existing item, or upload it anyway', async ({ page }) => {
  await page.goto('/en/admin/media');
  await upload(page, [photo]);
  await expect(queue(page).locator('.fa-mb-queue-title')).toHaveText('1 of 1 uploaded');
  await upload(page, [photo]);
  await expect(queue(page).locator('.fa-mb-queue-row').nth(1)).toContainText('photo.png is already in the library as “photo.png”.');
  await expect(queue(page).locator('.fa-mb-queue-title')).toHaveText('1 waiting for your choice');
  await queue(page).getByRole('button', { name: 'Use “photo.png”' }).click();
  await expect(queue(page).locator('.fa-mb-queue-status').nth(1)).toHaveText('Already in the library: using “photo.png”.');
  expect((await state(page)).media).toHaveLength(5);
  await upload(page, [photo]);
  await queue(page).getByRole('button', { name: 'Upload anyway' }).click();
  await expect(queue(page).locator('.fa-mb-queue-status').nth(2)).toHaveText('Uploaded');
  expect((await state(page)).media).toHaveLength(6);
  await expect(tiles(browser(page))).toHaveCount(6);
});

test('search, filters and sort narrow the grid and stay in the address', async ({ page }) => {
  await page.goto('/en/admin/media');
  const search = browser(page).getByRole('searchbox');
  await search.fill('الغروب');
  await expect(names(browser(page))).toHaveText(['Sea view']);
  await expect(page).toHaveURL(/q=%D8%A7%D9%84/);
  await search.fill('');
  await expect(tiles(browser(page))).toHaveCount(4);
  await browser(page).getByLabel('Type').selectOption('video');
  await expect(names(browser(page))).toHaveText(['Tour']);
  await browser(page).getByLabel('Type').selectOption('');
  await browser(page).getByLabel('Status').selectOption('no_alt');
  await expect(names(browser(page))).toHaveText(['garden.png']);
  await browser(page).getByLabel('Status').selectOption('');
  await browser(page).getByLabel('Sort').selectOption('oldest');
  await expect(names(browser(page))).toHaveText(['Sea view', 'garden.png', 'Hall', 'Tour']);
  await expect(page).toHaveURL(/sort=oldest/);
  await page.reload();
  await expect(names(browser(page))).toHaveText(['Sea view', 'garden.png', 'Hall', 'Tour']);
  // The header's "without alt text" number filters too.
  await page.locator('.fa-mb-stat.is-warning').click();
  await expect(names(browser(page))).toHaveText(['garden.png']);
  // The list view is remembered.
  await browser(page).getByRole('button', { name: 'List' }).click();
  await expect(browser(page).locator('.fa-mb-grid')).toHaveClass(/is-list/);
  await page.reload();
  await expect(browser(page).locator('.fa-mb-grid')).toHaveClass(/is-list/);
});

test('folders: create, move items by menu and by dragging, rename, nest and delete', async ({ page }) => {
  await page.goto('/en/admin/media');
  await newFolder(page, 'Homes');
  const tree = browser(page).locator('.fa-mb-tree');
  await expect(tree.locator('.fa-mb-folder.is-active')).toContainText('Homes');
  await expect(browser(page).locator('.fa-mb-status')).toHaveText('This folder is empty. Upload files here, or move items into it.');
  await tree.getByRole('button', { name: /All media/ }).click();

  await tile(browser(page), 'Hall').click();
  await tile(browser(page), 'Sea view').click({ modifiers: ['Control'] });
  await expect(browser(page).locator('.fa-mb-batch-count')).toHaveText('2 selected');
  await browser(page).getByRole('button', { name: 'Move to folder' }).click();
  await page.getByRole('menuitem', { name: 'Homes' }).click();
  await expect(page.getByText('2 moved to Homes.').first()).toBeVisible();
  await expect(tree.locator('[data-folder]', { hasText: 'Homes' }).locator('.fa-mb-count')).toHaveText('2');
  await expect(tree.locator('[data-folder="unfiled"] .fa-mb-count')).toHaveText('2');

  // Drag a tile onto a folder.
  await tile(browser(page), 'Tour, video').dragTo(tree.locator('[data-folder]', { hasText: 'Homes' }));
  await expect(page.getByText('1 moved to Homes.').first()).toBeVisible();
  await tree.locator('[data-folder]', { hasText: 'Homes' }).click();
  await expect(names(browser(page))).toHaveText(['Tour', 'Hall', 'Sea view']);

  // A folder inside, renamed, through the ⋯ menu.
  await tree.getByRole('button', { name: 'Folder Homes: options' }).click();
  await page.getByRole('menuitem', { name: 'New folder inside' }).click();
  await page.locator('dialog.fa-mb-confirm').getByRole('textbox').fill('Villas');
  await page.locator('dialog.fa-mb-confirm').getByRole('button', { name: 'Create folder' }).click();
  await expect(tree.locator('.fa-mb-folder.is-active')).toContainText('Villas');
  await tree.getByRole('button', { name: 'Folder Villas: options' }).click();
  await page.getByRole('menuitem', { name: 'Rename folder' }).click();
  await page.locator('dialog.fa-mb-confirm').getByRole('textbox').fill('Big villas');
  await page.locator('dialog.fa-mb-confirm').getByRole('button', { name: 'Save' }).click();
  await expect(tree.locator('.fa-mb-folder.is-active')).toContainText('Big villas');
  await expect(tree.locator('.fa-mb-folder-row', { hasText: 'Big villas' })).toHaveCSS('padding-inline-start', '14px');
  // Collapsing hides the folders inside.
  await tree.getByRole('button', { name: 'Close Homes' }).click();
  await expect(tree.getByText('Big villas')).toBeHidden();
  await tree.getByRole('button', { name: 'Open Homes' }).click();

  // Deleting a folder moves what is in it up one level.
  await tree.locator('[data-folder]', { hasText: 'Homes' }).click();
  await tree.getByRole('button', { name: 'Folder Homes: options' }).click();
  await page.getByRole('menuitem', { name: 'Delete folder' }).click();
  await page.locator('dialog.fa-mb-confirm').getByRole('button', { name: 'Delete folder' }).click();
  await expect(page.getByText('Folder deleted; what was in it moved up one level.').first()).toBeVisible();
  await expect(tree.locator('[data-folder="unfiled"] .fa-mb-count')).toHaveText('4');
  await expect(tree.locator('.fa-mb-folder', { hasText: 'Big villas' })).toBeVisible();
  const s = await state(page);
  expect(s.media.every(m => m.folder_id === null)).toBe(true);
});

test('keyboard: arrows move, Shift extends, Ctrl+A selects all, Delete asks first', async ({ page }) => {
  await page.goto('/en/admin/media');
  await tiles(browser(page)).first().focus();
  await page.keyboard.press('ArrowRight');
  await expect(tile(browser(page), 'Hall')).toBeFocused();
  await expect(details(page).locator('.fa-mb-details-title')).toHaveText('Hall');
  await page.keyboard.press('Space');
  await expect(tile(browser(page), 'Hall')).toHaveAttribute('aria-selected', 'true');
  await page.keyboard.press('Shift+ArrowRight');
  await expect(browser(page).locator('.fa-mb-batch-count')).toHaveText('2 selected');
  await page.keyboard.press('Control+a');
  await expect(browser(page).locator('.fa-mb-batch-count')).toHaveText('4 selected');
  await page.keyboard.press('Delete');
  const confirm = page.locator('dialog.fa-mb-confirm');
  await expect(confirm.locator('.fa-mb-confirm-text')).toHaveText('Delete 4 items? This cannot be undone.');
  await expect(confirm.getByRole('button', { name: 'Cancel' })).toBeFocused();
  await confirm.getByRole('button', { name: 'Delete' }).click();
  await expect(tiles(browser(page))).toHaveCount(0);
  await expect(browser(page).locator('.fa-mb-status')).toHaveText('No media yet. Upload images or videos to start.');
  expect((await state(page)).media).toHaveLength(0);
});

test('the details panel saves title, alt text per language, folder and the focal point', async ({ page }) => {
  await page.goto('/en/admin/media');
  await tile(browser(page), 'garden.png').click();
  const panel = details(page);
  await expect(panel.locator('.fa-mb-hint.is-warning')).toHaveText('No alt text: screen readers and search engines get nothing for this image.');
  await panel.getByLabel('Title').fill('Garden');
  await panel.getByLabel('Alt text (English)').fill('Green garden');
  await panel.getByLabel('Alt text (العربية)').fill('حديقة خضراء');
  await panel.getByRole('button', { name: 'Focal point' }).click();
  await expect(panel.getByRole('button', { name: 'Done' })).toHaveAttribute('aria-pressed', 'true');
  const frame = panel.locator('.fa-mb-preview');
  const box = (await frame.locator('img').boundingBox())!;
  // The picture is drawn letterboxed (contain): measure where it is.
  const drawn = await frame.locator('img').evaluate((img: HTMLImageElement) => {
    const r = img.getBoundingClientRect(); const ratio = img.naturalWidth / img.naturalHeight;
    const w = Math.min(r.width, r.height * ratio); const h = w / ratio;
    return { x: r.left + (r.width - w) / 2, y: r.top + (r.height - h) / 2, w, h };
  });
  await page.mouse.click(drawn.x + drawn.w * 0.25, drawn.y + drawn.h * 0.75);
  void box;
  await expect(frame.locator('.fa-mb-focal')).toBeVisible();
  await frame.press('ArrowRight');
  await panel.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Media item saved.').first()).toBeVisible();
  await expect(tile(browser(page), 'Garden').locator('.fa-mb-noalt')).toHaveCount(0);
  const item = (await state(page)).media.find(m => Number(m.id) === 2)!;
  expect([item.title, JSON.parse(item.alt!), Number(item.focal_x), Number(item.focal_y)]).toEqual(['Garden', { en: 'Green garden', ar: 'حديقة خضراء' }, 27, 75]);
  await expect(page.locator('.fa-mb-stat.is-warning')).toHaveCount(0);
});

test('Edit details steps through several items, with Save and next', async ({ page }) => {
  await page.goto('/en/admin/media');
  await tile(browser(page), 'Hall').click();
  await tile(browser(page), 'garden.png').click({ modifiers: ['Shift'] });
  await browser(page).getByRole('button', { name: 'Edit details' }).click();
  const editor = page.locator('dialog.fa-mb-editor');
  await expect(editor.locator('.fa-mb-dialog-head .fa-badge')).toHaveText('1 of 2');
  await expect(editor.getByLabel('Title', { exact: true })).toHaveValue('Hall');
  await editor.getByLabel('Alt text (English)').fill('The hall');
  await editor.getByRole('button', { name: 'Save and next' }).click();
  await expect(editor.locator('.fa-mb-dialog-head .fa-badge')).toHaveText('2 of 2');
  await expect(editor.getByLabel('Alt text (English)')).toBeFocused();
  await editor.getByLabel('Alt text (English)').fill('A garden');
  await editor.getByLabel('Folder').selectOption({ label: 'Unfiled' });
  await page.keyboard.press('Control+Enter');
  await expect(editor).toHaveCount(0);
  await expect(page.getByText('Details saved for 2 item(s).').first()).toBeVisible();
  const media = (await state(page)).media;
  expect(JSON.parse(media.find(m => Number(m.id) === 3)!.alt!).en).toBe('The hall');
  expect(JSON.parse(media.find(m => Number(m.id) === 2)!.alt!).en).toBe('A garden');
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

test('media fields: choose a cover, add and reorder a gallery, save; usage is recorded', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  await field(page, 'cover_id').getByRole('button', { name: 'Choose from the library' }).click();
  await expect(picker(page)).toBeVisible();
  await expect(picker(page).locator('.fa-mb-dialog-title')).toHaveText('Choose an image');
  await expect(tiles(picker(page))).toHaveCount(3, { timeout: 5000 });
  await expect(picker(page).getByRole('searchbox')).toBeFocused();
  await picker(page).getByRole('searchbox').fill('sea');
  await expect(tiles(picker(page))).toHaveCount(1);
  await tile(picker(page), 'Sea view').click();
  await expect(picker(page).locator('.fa-mb-chosen')).toHaveText('Sea view');
  await picker(page).getByRole('button', { name: 'Choose', exact: true }).click();
  await expect(picker(page)).toHaveCount(0);
  await expect(field(page, 'cover_id').locator('.fa-mf-name')).toHaveText('Sea view');
  await expect(field(page, 'cover_id').locator('.fa-mf-preview')).toHaveCSS('aspect-ratio', '16 / 9');
  await expect(field(page, 'cover_id').getByRole('button', { name: 'Change' })).toBeFocused();

  await field(page, 'gallery').getByRole('button', { name: 'Choose from the library' }).click();
  await expect(picker(page).locator('.fa-mb-dialog-head .fa-badge')).toHaveText('Up to 3');
  await tile(picker(page), 'garden.png').click();
  await tile(picker(page), 'Hall').click();
  await picker(page).getByRole('button', { name: 'Use selected (2)' }).click();
  const gallery = field(page, 'gallery').locator('.fa-mf-tile');
  await expect(gallery).toHaveCount(2);
  await expect(gallery.nth(0)).toHaveAttribute('aria-label', 'garden.png, 1 of 2');
  // Alt + arrow moves the focused tile; the live region says where it went.
  await gallery.nth(0).focus();
  await page.keyboard.press('Alt+ArrowRight');
  await expect(gallery.nth(1)).toHaveAttribute('aria-label', 'garden.png, 2 of 2');
  await expect(gallery.nth(1)).toBeFocused();
  await expect(field(page, 'gallery').locator('[aria-live]')).toHaveText('garden.png, position 2 of 2.');
  // The ⋯ menu moves too.
  await gallery.nth(1).hover();
  await field(page, 'gallery').getByRole('button', { name: 'Options for garden.png' }).click();
  await page.getByRole('menuitem', { name: 'Move earlier' }).click();
  await expect(gallery.nth(0)).toHaveAttribute('aria-label', 'garden.png, 1 of 2');
  // Drag the second tile before the first.
  const [first, second] = [await gallery.nth(0).boundingBox(), await gallery.nth(1).boundingBox()];
  await page.mouse.move(second!.x + 60, second!.y + 60);
  await page.mouse.down();
  await page.mouse.move(first!.x + 10, first!.y + 60, { steps: 10 });
  await page.mouse.up();
  await expect(gallery.nth(0)).toHaveAttribute('aria-label', 'Hall, 1 of 2');
  // Items already in the field are marked, not chosen again.
  await field(page, 'gallery').locator('.fa-mf-add').click();
  await expect(tile(picker(page), 'Hall')).toHaveAttribute('aria-disabled', 'true');
  await expect(picker(page).locator('.fa-mb-dialog-head .fa-badge')).toHaveText('Up to 1');
  await page.keyboard.press('Escape');

  await field(page, 'video_id').getByRole('button', { name: 'Choose from the library' }).click();
  await expect(tiles(picker(page))).toHaveCount(1);
  await tile(picker(page), 'Tour, video').dblclick();
  await expect(field(page, 'video_id').locator('.fa-mf-name')).toHaveText('Tour');

  await page.getByRole('button', { name: 'Save and close' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/listings$/);
  const saved = await state(page);
  expect([saved.row.cover_id, saved.row.gallery, saved.row.video_id].map(String)).toEqual(['1', '["3","2"]', '4']);
  expect(usage(saved)).toEqual(['1:cover_id', '2:gallery', '3:gallery', '4:video_id']);
  await expect(page.locator('tbody tr').first().locator('img.fa-media-thumb')).toHaveCount(3);

  await page.goto('/en/admin/media/edit/1');
  await expect(page.getByRole('link', { name: 'Listings · Villa · Cover' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Delete' })).toBeDisabled();
});

test('a field uploads into its folder, reuses a file already there, and edits details in place', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  const cover = field(page, 'cover_id');
  await cover.locator('input[type=file]').setInputFiles(photo);
  await expect(cover.locator('.fa-mf-name')).toHaveText('photo.png');
  await expect(cover.locator('.fa-mf-noalt')).toBeVisible();
  let s = await state(page);
  expect(s.media).toHaveLength(5);
  const folder = s.folders!.find(f => f.name === 'Covers')!;
  expect(s.folders!.find(f => Number(f.id) === Number(folder.parent_id))!.name).toBe('Listings');
  expect(Number(s.media[4].folder_id)).toBe(Number(folder.id));

  // The same file again: the library's item is used, no copy is made.
  await field(page, 'gallery').locator('input[type=file]').setInputFiles(photo);
  await expect(page.getByText('Already in the library: using “photo.png”.').first()).toBeVisible();
  await expect(field(page, 'gallery').locator('.fa-mf-tile')).toHaveCount(1);
  s = await state(page);
  expect(s.media).toHaveLength(5);

  // "No alt text" opens the details editor.
  await cover.locator('.fa-mf-noalt').click();
  const editor = page.locator('dialog.fa-mb-editor');
  await editor.getByLabel('Alt text (English)').fill('A photo');
  await editor.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(editor).toHaveCount(0);
  await expect(cover.locator('.fa-mf-noalt')).toHaveCount(0);
});

test('a failed upload keeps the field\'s item; Ctrl+A in the picker stops at the field\'s room', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  const cover = field(page, 'cover_id');
  await cover.getByRole('button', { name: 'Choose from the library' }).click();
  await tile(picker(page), 'Hall').dblclick();
  await expect(cover.locator('.fa-mf-name')).toHaveText('Hall');
  await page.route(/\/admin\/media\/upload/, route => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ items: [], errors: ['photo.png: refused'], duplicates: [] }) }));
  await cover.locator('input[type=file]').setInputFiles(photo);
  await expect(page.getByText(/refused/).first()).toBeVisible();
  await expect(cover.locator('.fa-mf-name')).toHaveText('Hall');
  await expect(cover.locator('[data-media-value]')).toHaveValue('3');

  await field(page, 'gallery').getByRole('button', { name: 'Choose from the library' }).click();
  await tiles(picker(page)).first().focus();
  await page.keyboard.press('Control+a');
  await expect(picker(page).getByRole('button', { name: 'Use selected (3)' })).toBeVisible();
});

test('the picker uploads: the new image is selected, ready to choose', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  await field(page, 'cover_id').getByRole('button', { name: 'Choose from the library' }).click();
  await picker(page).locator('input[type=file]').setInputFiles(photo);
  await expect(tile(picker(page), 'photo.png')).toHaveAttribute('aria-selected', 'true');
  await picker(page).getByRole('button', { name: 'Choose', exact: true }).click();
  await expect(field(page, 'cover_id').locator('.fa-mf-name')).toHaveText('photo.png');
  await expect(field(page, 'cover_id').locator('img')).toHaveAttribute('src', /library\/\d{4}\/\d{2}\/web\//);
});

test('Escape closes the picker without changes and focus returns', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  const choose = field(page, 'cover_id').getByRole('button', { name: 'Choose from the library' });
  await choose.click();
  await expect(picker(page)).toBeVisible();
  await tile(picker(page), 'Hall').click();
  await page.keyboard.press('Escape');
  await expect(picker(page)).toHaveCount(0);
  await expect(choose).toBeFocused();
  await expect(field(page, 'cover_id').locator('.fa-mf-empty')).toBeVisible();
});

test('the rich editor inserts an image from the library with its alt text, and usage follows', async ({ page }) => {
  await page.goto('/en/admin/listings/edit/1');
  const body = page.locator('[data-field=body]');
  await expect(body.locator('.fa-rich')).toHaveClass(/is-ready/);
  await body.locator('.fa-rich-content').first().click();
  await body.getByRole('button', { name: 'Image', exact: true }).click();
  await page.getByRole('menuitem', { name: /Choose from the library/ }).click();
  await tile(picker(page), 'Hall').dblclick();
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
  await expect(field(page, 'cover_id').getByRole('button', { name: 'Upload' })).toHaveCount(0);
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

test('Arabic: the library, the picker and the field work right to left', async ({ page }) => {
  await page.goto('/ar/admin/media');
  await expect(page.locator('.fa-panel')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByRole('heading', { name: 'الوسائط' })).toBeVisible();
  const [tree, pane] = [await browser(page).locator('.fa-mb-tree').boundingBox(), await browser(page).locator('.fa-mb-pane').boundingBox()];
  expect(tree!.x).toBeGreaterThan(pane!.x);
  // Arrow keys follow the reading direction.
  await tiles(browser(page)).first().focus();
  await page.keyboard.press('ArrowLeft');
  await expect(tiles(browser(page)).nth(1)).toBeFocused();
  await page.goto('/ar/admin/listings/edit/1');
  await field(page, 'gallery').getByRole('button', { name: 'اختيار من المكتبة' }).click();
  await tile(picker(page), 'garden.png').click();
  await tile(picker(page), 'Hall').click();
  await picker(page).getByRole('button', { name: /استخدام المحدد/ }).click();
  const chips = field(page, 'gallery').locator('.fa-mf-tile');
  const [a, b] = [await chips.nth(0).boundingBox(), await chips.nth(1).boundingBox()];
  expect(a!.x).toBeGreaterThan(b!.x);
  await chips.nth(0).focus();
  await page.keyboard.press('Alt+ArrowLeft');
  await expect(chips.nth(1)).toHaveAttribute('aria-label', 'garden.png، 2 من 2');
});

test('without JavaScript: the field is a select; the Media page has folders, upload and batch forms', async ({ browser }) => {
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
  await expect(page.locator('.fa-mb-tile')).toHaveCount(5);
  await page.locator('#fa-mb-folder-name').fill('Homes');
  await page.getByRole('button', { name: 'Create folder' }).click();
  await expect(page.getByText('Folder “Homes” created.')).toBeVisible();
  await expect(page.locator('.fa-mb-folder.is-active')).toContainText('Homes');
  await page.locator('.fa-mb-tree').getByRole('link', { name: /All media/ }).click();
  await page.getByLabel('Select Hall').check();
  await page.getByLabel('Select garden.png').check();
  await page.locator('#fa-mb-move-to').selectOption({ label: 'Homes' });
  await page.getByRole('button', { name: 'Move', exact: true }).click();
  await expect(page.getByText('2 moved to Homes.')).toBeVisible();
  await page.locator('.fa-mb-tree').getByRole('link', { name: /Homes/ }).click();
  await expect(page.locator('.fa-mb-tile')).toHaveCount(2);
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
