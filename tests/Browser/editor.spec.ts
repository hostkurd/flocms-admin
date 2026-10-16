import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

const photo = readFileSync(new URL('./fixtures/photo.png', import.meta.url));
const clip = readFileSync(new URL('./fixtures/clip.mp4', import.meta.url));
const dialog = (page: Page) => page.locator('dialog.fa-editor');
const body = (page: Page) => dialog(page).locator('[data-field=body]');
const content = (page: Page, lang = 'en') => body(page).locator(`.fa-rich-panel[data-lang=${lang}] .fa-rich-content`);
const source = (page: Page, lang = 'en') => body(page).locator(`textarea[name="body[${lang}]"]`);

async function open(page: Page, lang = 'en') {
  await page.goto(`/${lang}/admin/articles`);
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(dialog(page)).toBeVisible();
  await expect(body(page).locator('.fa-rich')).toHaveClass(/is-ready/);
}
async function save(page: Page) {
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(dialog(page)).not.toBeVisible();
}
const tool = (page: Page, name: string) => body(page).getByRole('toolbar').getByRole('button', { name, exact: true });

test('formatting and every language save as clean HTML, with tabs, dots and RTL content', async ({ page }) => {
  await open(page);
  const tabs = body(page).getByRole('tablist');
  await expect(tabs.getByRole('tab')).toHaveCount(3);
  await expect(tabs.getByRole('tab', { name: /^en/i })).toHaveAttribute('aria-selected', 'true');
  await content(page).click();
  await page.keyboard.press('ControlOrMeta+End');
  await page.keyboard.press('Enter');
  await tool(page, 'Heading').click();
  await page.keyboard.type('Location');
  await page.keyboard.press('Enter');
  await page.keyboard.press('ControlOrMeta+b');
  await page.keyboard.type('Near the sea');
  await expect(tool(page, 'Bold')).toHaveAttribute('aria-pressed', 'true');
  await expect(source(page)).toHaveValue('<p>Hello <strong>world</strong></p><h2>Location</h2><p><strong>Near the sea</strong></p>');

  // Kurdish was empty: its dot is hollow until something is written.
  const ku = tabs.getByRole('tab', { name: /^ku/i });
  await expect(ku.locator('.fa-dot')).toHaveClass(/is-empty/);
  await ku.click();
  await expect(content(page, 'ku')).toBeVisible();
  await expect(content(page)).toBeHidden();
  await expect(content(page, 'ku')).toHaveAttribute('dir', 'rtl');
  await expect(content(page, 'ku')).toBeFocused();
  await page.keyboard.type('خانووی سەر دەریا');
  await expect(ku.locator('.fa-dot')).toHaveClass(/is-filled/);
  // Arrow keys move between the language tabs.
  await ku.focus();
  await page.keyboard.press('ArrowLeft');
  await expect(tabs.getByRole('tab', { name: /^ar/i })).toBeFocused();
  await save(page);

  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(source(page)).toHaveValue('<p>Hello <strong>world</strong></p><h2>Location</h2><p><strong>Near the sea</strong></p>');
  await expect(source(page, 'ku')).toHaveValue('<p>خانووی سەر دەریا</p>');
  await expect(source(page, 'ar')).toHaveValue('<p>مرحبا</p>');
});

test('the toolbar is one tab stop, arrow keys move inside it, and links are checked', async ({ page }) => {
  await open(page);
  await content(page).click();
  await page.keyboard.press('ControlOrMeta+a');
  const toolbar = body(page).getByRole('toolbar');
  await toolbar.getByRole('button').first().focus();
  await page.keyboard.press('ArrowRight');
  await expect(tool(page, 'Subheading')).toBeFocused();
  // Undo and Redo are disabled with nothing to undo: End stops at the last usable button.
  await expect(tool(page, 'Redo')).toBeDisabled();
  await page.keyboard.press('End');
  await expect(tool(page, 'YouTube or Vimeo video')).toBeFocused();

  await tool(page, 'Link').click();
  const panel = body(page).locator('[data-insert=link]');
  await expect(panel).toBeVisible();
  await expect(panel.getByLabel('Link address')).toBeFocused();
  await panel.getByLabel('Link address').fill('javascript:alert(1)');
  await panel.getByRole('button', { name: 'Apply' }).click();
  await expect(panel.locator('[data-insert-status]')).toContainText('Use a web address');
  await expect(panel.getByLabel('Link address')).toHaveAttribute('aria-invalid', 'true');
  await panel.getByLabel('Link address').fill('https://fectumhellas.test/villas');
  await panel.getByLabel('Open in a new tab').check();
  await page.keyboard.press('Enter');
  await expect(panel).toBeHidden();
  await expect(source(page)).toHaveValue('<p><a target="_blank" href="https://fectumhellas.test/villas">Hello <strong>world</strong></a></p>');
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  // The server adds rel="noopener noreferrer" to links that open a new tab.
  await expect(source(page)).toHaveValue('<p><a href="https://fectumhellas.test/villas" target="_blank" rel="noopener noreferrer">Hello <strong>world</strong></a></p>');
});

test('an uploaded image is resized for the web, shown, saved and served from the media folder', async ({ page }) => {
  await open(page);
  await content(page).click();
  await tool(page, 'Image').click();
  const panel = body(page).locator('[data-insert=image]');
  await panel.getByLabel('Description (alt text)').fill('Sea view from the terrace');
  const response = page.waitForResponse(r => r.url().endsWith('/articles/media/body/image'));
  await panel.locator('[data-insert-file]').setInputFiles({ name: 'terrace.png', mimeType: 'image/png', buffer: photo });
  const result = await (await response).json() as { url: string; width: number; height: number };
  expect(result.url).toMatch(/^\/uploads\/editor\/articles\/body\/\d{4}\/\d{2}\/web\/[a-z0-9]+\.png$/);
  expect([result.width, result.height]).toEqual([1920, 960]);
  const image = content(page).locator('img');
  await expect(image).toHaveAttribute('alt', 'Sea view from the terrace');
  await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBe(1920);
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(source(page)).toHaveValue(new RegExp(`<img src="${result.url}" alt="Sea view from the terrace" width="1920" height="960" loading="lazy">`));
  const served = await page.request.get(result.url);
  expect(served.headers()['content-type']).toBe('image/png');
});

test('a file that is not an image is refused with a clear message', async ({ page }) => {
  await open(page);
  await content(page).click();
  await tool(page, 'Image').click();
  const panel = body(page).locator('[data-insert=image]');
  await panel.locator('[data-insert-file]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from('<?php echo 1;') });
  await expect(panel.locator('[data-insert-status]')).toHaveText(/This image cannot be used/);
  await expect(content(page).locator('img')).toHaveCount(0);
});

test('a video uploads in parts with progress and is saved as a playable video', async ({ page }) => {
  await open(page);
  await content(page).click();
  await tool(page, 'Video').click();
  const panel = body(page).locator('[data-insert=video]');
  const steps: string[] = [];
  page.on('request', request => { const match = /\/articles\/media\/body\/(\w+)$/.exec(request.url()); if (match) steps.push(match[1]); });
  await panel.locator('[data-insert-file]').setInputFiles({ name: 'tour.mp4', mimeType: 'video/mp4', buffer: clip });
  const video = content(page).locator('video');
  await expect(video).toHaveAttribute('src', /^\/uploads\/editor\/articles\/body\/\d{4}\/\d{2}\/[a-z0-9]+\.mp4$/);
  expect(steps).toEqual(['start', 'chunk', 'complete']);
  await expect(panel).toBeHidden();
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(source(page)).toHaveValue(/<video src="\/uploads\/editor\/articles\/body\/[^"]+\.mp4" controls preload="metadata"><\/video>/);
});

test('YouTube and Vimeo links become embeds; other links are refused', async ({ page }) => {
  await open(page);
  await content(page).click();
  await tool(page, 'YouTube or Vimeo video').click();
  const panel = body(page).locator('[data-insert=embed]');
  await panel.getByLabel(/link/).fill('https://example.test/watch?v=dQw4w9WgXcQ');
  await panel.getByRole('button', { name: 'Insert' }).click();
  await expect(panel.locator('[data-insert-status]')).toHaveText('Use a YouTube or Vimeo video address.');
  await panel.getByLabel(/link/).fill('https://youtu.be/dQw4w9WgXcQ?t=42');
  await panel.getByRole('button', { name: 'Insert' }).click();
  // Inside the admin an embed is a card, not an iframe (no third-party frames here).
  await expect(content(page).locator('.fa-rich-embed')).toContainText('YouTube video');
  await expect(content(page).locator('iframe')).toHaveCount(0);
  await tool(page, 'YouTube or Vimeo video').click();
  await panel.getByLabel(/link/).fill('vimeo.com/76979871');
  await page.keyboard.press('Enter');
  await expect(content(page).locator('.fa-rich-embed[data-provider=vimeo]')).toBeVisible();
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(source(page)).toHaveValue(/<div data-embed="youtube" data-id="dQw4w9WgXcQ\?start=42"><iframe src="https:\/\/www\.youtube-nocookie\.com\/embed\/dQw4w9WgXcQ\?start=42"[^>]* sandbox="[^"]+"><\/iframe><\/div>/);
  await expect(source(page)).toHaveValue(/<div data-embed="vimeo" data-id="76979871"><iframe src="https:\/\/player\.vimeo\.com\/video\/76979871\?dnt=1"/);
  await expect(content(page).locator('.fa-rich-embed')).toHaveCount(2);
});

test('the editor survives validation errors on other fields, and an empty body is reported in the editor', async ({ page }) => {
  await open(page);
  await content(page).click();
  await page.keyboard.press('ControlOrMeta+End');
  await page.keyboard.type(' and more');
  await content(page).evaluate(element => { (element as HTMLElement).dataset.marker = 'same-editor'; });
  await dialog(page).locator('[name="title[en]"]').fill('');
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(dialog(page).locator('[name="title[en]"]')).toHaveAttribute('aria-invalid', 'true');
  await expect(content(page)).toHaveAttribute('data-marker', 'same-editor');
  await expect(content(page)).toContainText('Hello world and more');
  await tool(page, 'Undo').click();
  await expect(content(page)).toHaveText('Hello world');

  await dialog(page).locator('[name="title[en]"]').fill('Welcome');
  await content(page).click();
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.press('Delete');
  await expect(source(page)).toHaveValue('');
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(body(page).locator('.fa-error')).toHaveText('Body is required.');
  await expect(content(page)).toHaveAttribute('aria-invalid', 'true');
  await expect(content(page)).toBeFocused();
  await expect(body(page).getByRole('tab', { name: /^en/i }).locator('.fa-dot')).toHaveClass(/is-invalid/);
});

test('a single-language field has no tabs, only its own buttons, and the label focuses it', async ({ page }) => {
  await open(page);
  const note = dialog(page).locator('[data-field=note]');
  await expect(note.getByRole('tablist')).toHaveCount(0);
  await expect(note.getByRole('toolbar').getByRole('button')).toHaveText(['', '', '', '']);
  await expect(note.getByRole('toolbar').getByRole('button')).toHaveCount(4);
  await note.locator('.fa-label').click();
  await expect(note.locator('.fa-rich-content')).toBeFocused();
  await page.keyboard.type('Call the owner first');
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(dialog(page).locator('textarea[name=note]')).toHaveValue('<p>Call the owner first</p>');
});

test('the full-page form uses the same editor and saves without Reactive', async ({ page }) => {
  await page.goto('/en/admin/articles/edit/1');
  const field = page.locator('[data-field=body]');
  await expect(field.locator('.fa-rich')).toHaveClass(/is-ready/);
  await field.locator('.fa-rich-panel[data-lang=en] .fa-rich-content').click();
  await page.keyboard.press('ControlOrMeta+End');
  await page.keyboard.type('!');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await page.goto('/en/admin/articles/edit/1');
  await expect(field.locator('textarea[name="body[en]"]')).toHaveValue('<p>Hello <strong>world!</strong></p>');
});

test('Kurdish interface: toolbar, tabs and insert panel read right to left', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page, 'ku');
  await expect(body(page).getByRole('toolbar')).toHaveAttribute('aria-label', /ڕێکخستن/);
  await tool(page, 'بەستەر').click();
  await expect(body(page).locator('[data-insert=link]').getByLabel('ناونیشانی بەستەر')).toBeFocused();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
