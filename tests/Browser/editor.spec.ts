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
/** Put the cursor at the end of the text (clicking the empty area below it may place it at the start). */
async function toEnd(page: Page, editor = content(page)) {
  await editor.locator(':scope > *').last().click();
  await expect(editor).toBeFocused();
  // Check that the caret really is after the last character before typing.
  await expect.poll(async () => {
    await page.keyboard.press('ControlOrMeta+End');
    return editor.evaluate(element => {
      const selection = getSelection();
      if (!selection || !selection.isCollapsed || !selection.anchorNode || !element.contains(selection.anchorNode)) return false;
      const range = document.createRange();
      range.selectNodeContents(element);
      range.setStart(selection.anchorNode, selection.anchorOffset);
      return range.toString() === '';
    });
  }).toBe(true);
}
async function save(page: Page) {
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(dialog(page)).not.toBeVisible();
}
const tool = (page: Page, name: string) => body(page).getByRole('toolbar').getByRole('button', { name, exact: true });
const richDialog = (page: Page, name: string) => body(page).locator(`dialog[data-rich-dialog=${name}]`);

test('formatting and every language save as clean HTML, with tabs, dots and RTL content', async ({ page }) => {
  await open(page);
  const tabs = body(page).getByRole('tablist');
  await expect(tabs.getByRole('tab')).toHaveCount(3);
  await expect(tabs.getByRole('tab', { name: /^en/i })).toHaveAttribute('aria-selected', 'true');
  await toEnd(page);
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
  // A modal dialog above the record dialog, not a panel inside the editor.
  const link = richDialog(page, 'link');
  await expect(link).toBeVisible();
  await expect(link.getByRole('heading')).toHaveText('Insert link');
  await expect(link.getByLabel('Link address')).toBeFocused();
  await expect(link.getByRole('button', { name: 'Remove link' })).toBeHidden();
  await link.getByLabel('Link address').fill('javascript:alert(1)');
  await link.getByRole('button', { name: 'Insert' }).click();
  await expect(link.locator('[data-rich-error]')).toContainText('Use a web address');
  await expect(link.getByLabel('Link address')).toHaveAttribute('aria-invalid', 'true');
  await link.getByLabel('Link address').fill('https://fectumhellas.test/villas');
  await link.getByLabel('Open in a new tab').check();
  await link.getByLabel('Link address').press('Enter');
  await expect(link).toBeHidden();
  await expect(content(page)).toBeFocused();
  await expect(source(page)).toHaveValue('<p><a target="_blank" href="https://fectumhellas.test/villas">Hello <strong>world</strong></a></p>');

  // Ctrl+K edits the link; Escape closes only the link dialog, not the record dialog.
  await page.keyboard.press('ControlOrMeta+k');
  await expect(link.getByRole('heading')).toHaveText('Edit link');
  await expect(link.getByLabel('Link address')).toHaveValue('https://fectumhellas.test/villas');
  await expect(link.getByRole('button', { name: 'Remove link' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(link).toBeHidden();
  await expect(dialog(page)).toBeVisible();
  await expect(content(page)).toBeFocused();
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  // The server adds rel="noopener noreferrer" to links that open a new tab.
  await expect(source(page)).toHaveValue('<p><a href="https://fectumhellas.test/villas" target="_blank" rel="noopener noreferrer">Hello <strong>world</strong></a></p>');
});

test('the image button offers Upload or From a link; upload opens the file picker at once', async ({ page }) => {
  await open(page);
  await toEnd(page);
  await tool(page, 'Image').click();
  const menu = body(page).getByRole('menu');
  await expect(menu).toBeVisible();
  await expect(tool(page, 'Image')).toHaveAttribute('aria-expanded', 'true');
  await expect(menu.getByRole('menuitem').first()).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: /Image from a link/ })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();
  await expect(dialog(page)).toBeVisible();
  await expect(tool(page, 'Image')).toBeFocused();

  const chooser = page.waitForEvent('filechooser');
  await tool(page, 'Image').click();
  await menu.getByRole('menuitem', { name: /Upload an image/ }).click();
  const response = page.waitForResponse(r => r.url().endsWith('/articles/media/body/image'));
  await (await chooser).setFiles({ name: 'terrace.png', mimeType: 'image/png', buffer: photo });
  const result = await (await response).json() as { url: string; width: number; height: number };
  expect(result.url).toMatch(/^\/uploads\/editor\/articles\/body\/\d{4}\/\d{2}\/web\/[a-z0-9]+\.png$/);
  expect([result.width, result.height]).toEqual([1920, 960]);
  await expect(body(page).locator('[data-rich-status-text]')).toHaveText('Upload complete.');
  const image = content(page).locator('img');
  await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBe(1920);

  // Double-click an image to describe it.
  await image.dblclick();
  const edit = richDialog(page, 'image');
  await expect(edit.getByRole('heading')).toHaveText('Edit image');
  await expect(edit.getByLabel('Image address')).toHaveValue(result.url);
  await edit.getByLabel('Description (alt text)').fill('Sea view from the terrace');
  await edit.getByRole('button', { name: 'Save' }).click();
  await expect(edit).toBeHidden();
  await expect(image).toHaveAttribute('alt', 'Sea view from the terrace');
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(source(page)).toHaveValue(new RegExp(`<img src="${result.url}" alt="Sea view from the terrace" width="1920" height="960" loading="lazy">`));
  const served = await page.request.get(result.url);
  expect(served.headers()['content-type']).toBe('image/png');
});

test('an image from a link is checked and inserted from a modal dialog', async ({ page }) => {
  await open(page);
  await toEnd(page);
  await tool(page, 'Image').click();
  await body(page).getByRole('menuitem', { name: /Image from a link/ }).click();
  const image = richDialog(page, 'image');
  await expect(image.getByRole('heading')).toHaveText('Image from a link');
  await expect(image.getByLabel('Image address')).toBeFocused();
  await image.getByLabel('Image address').fill('javascript:alert(1)');
  await image.getByRole('button', { name: 'Insert' }).click();
  await expect(image.locator('[data-rich-error]')).toHaveText(/This image cannot be used/);
  await image.getByLabel('Image address').fill('/uploads/sea.png');
  await image.getByLabel('Description (alt text)').fill('The sea');
  await image.getByLabel('Description (alt text)').press('Enter');
  await expect(image).toBeHidden();
  await expect(source(page)).toHaveValue(/<img src="\/uploads\/sea\.png" alt="The sea">$/);
});

test('a file that is not an image is refused with a clear message', async ({ page }) => {
  await open(page);
  await content(page).click();
  const chooser = page.waitForEvent('filechooser');
  await tool(page, 'Image').click();
  await body(page).getByRole('menuitem', { name: /Upload an image/ }).click();
  await (await chooser).setFiles({ name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from('<?php echo 1;') });
  await expect(body(page).locator('[data-rich-status-text]')).toHaveText(/This image cannot be used/);
  await expect(content(page).locator('img')).toHaveCount(0);
  await body(page).getByRole('button', { name: 'Cancel', exact: true }).last().click();
  await expect(body(page).locator('[data-rich-status]')).toBeHidden();
});

test('a pasted image is uploaded through the media endpoint', async ({ page }) => {
  await open(page);
  await toEnd(page);
  const response = page.waitForResponse(r => r.url().endsWith('/articles/media/body/image'));
  await content(page).evaluate((element, bytes) => {
    const data = new DataTransfer();
    data.items.add(new File([new Uint8Array(bytes)], 'pasted.png', { type: 'image/png' }));
    element.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
  }, [...photo]);
  expect((await response).status()).toBe(200);
  await expect(content(page).locator('img')).toHaveCount(1);
});

test('a video uploads in parts with progress and is saved as a playable video', async ({ page }) => {
  await open(page);
  await content(page).click();
  const steps: string[] = [];
  page.on('request', request => { const match = /\/articles\/media\/body\/(\w+)$/.exec(request.url()); if (match) steps.push(match[1]); });
  // The Video button opens the file picker directly.
  const chooser = page.waitForEvent('filechooser');
  await tool(page, 'Video').click();
  await (await chooser).setFiles({ name: 'tour.mp4', mimeType: 'video/mp4', buffer: clip });
  const video = content(page).locator('video');
  await expect(video).toHaveAttribute('src', /^\/uploads\/editor\/articles\/body\/\d{4}\/\d{2}\/[a-z0-9]+\.mp4$/);
  expect(steps).toEqual(['start', 'chunk', 'complete']);
  await expect(body(page).locator('[data-rich-status-text]')).toHaveText('Upload complete.');
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(source(page)).toHaveValue(/<video src="\/uploads\/editor\/articles\/body\/[^"]+\.mp4" controls preload="metadata"><\/video>/);
});

test('YouTube and Vimeo links become embeds; other links are refused', async ({ page }) => {
  await open(page);
  await content(page).click();
  await tool(page, 'YouTube or Vimeo video').click();
  const embed = richDialog(page, 'embed');
  await expect(embed).toBeVisible();
  await embed.getByLabel(/link/).fill('https://example.test/watch?v=dQw4w9WgXcQ');
  await embed.getByRole('button', { name: 'Insert' }).click();
  await expect(embed.locator('[data-rich-error]')).toHaveText('Use a YouTube or Vimeo video address.');
  await embed.getByLabel(/link/).fill('https://youtu.be/dQw4w9WgXcQ?t=42');
  await embed.getByRole('button', { name: 'Insert' }).click();
  // Inside the admin an embed is a card, not an iframe (no third-party frames here).
  await expect(content(page).locator('.fa-rich-embed')).toContainText('YouTube video');
  await expect(content(page).locator('iframe')).toHaveCount(0);
  await tool(page, 'YouTube or Vimeo video').click();
  await expect(embed.getByLabel(/link/)).toHaveValue('');
  await embed.getByLabel(/link/).fill('vimeo.com/76979871');
  await page.keyboard.press('Enter');
  await expect(content(page).locator('.fa-rich-embed[data-provider=vimeo]')).toBeVisible();
  await save(page);
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(source(page)).toHaveValue(/<div data-embed="youtube" data-id="dQw4w9WgXcQ\?start=42"><iframe src="https:\/\/www\.youtube-nocookie\.com\/embed\/dQw4w9WgXcQ\?start=42"[^>]* sandbox="[^"]+"><\/iframe><\/div>/);
  await expect(source(page)).toHaveValue(/<div data-embed="vimeo" data-id="76979871"><iframe src="https:\/\/player\.vimeo\.com\/video\/76979871\?dnt=1"/);
  await expect(content(page).locator('.fa-rich-embed')).toHaveCount(2);
});

test('tabs never show a scroll bar; icons are crisp; the footer counts words', async ({ page }) => {
  await open(page);
  const tabs = body(page).getByRole('tablist');
  expect(await tabs.evaluate(element => element.scrollHeight - element.clientHeight)).toBeLessThanOrEqual(0);
  expect(await tabs.evaluate(element => getComputedStyle(element).overflowY)).toBe('hidden');
  const icon = tool(page, 'Bold').locator('svg');
  expect(await icon.evaluate(svg => { const box = svg.getBoundingClientRect(); return [box.width, box.height]; })).toEqual([20, 20]);
  const footer = body(page).locator('.fa-rich-words');
  await expect(footer).toHaveText('Words: 2');
  await toEnd(page);
  await page.keyboard.type(' again');
  await expect(footer).toHaveText('Words: 3');
  await tabs.getByRole('tab', { name: /^ku/i }).click();
  await expect(footer).toHaveText('Words: 0');
  await expect(content(page, 'ku').locator('p.is-editor-empty')).toHaveAttribute('data-placeholder', 'Start writing…');
});

test('the editor survives validation errors on other fields, and an empty body is reported in the editor', async ({ page }) => {
  await open(page);
  await toEnd(page);
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
  await toEnd(page, field.locator('.fa-rich-panel[data-lang=en] .fa-rich-content'));
  await page.keyboard.type('!');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await page.goto('/en/admin/articles/edit/1');
  await expect(field.locator('textarea[name="body[en]"]')).toHaveValue('<p>Hello <strong>world!</strong></p>');
});

test('Kurdish interface: toolbar, tabs and dialogs read right to left', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page, 'ku');
  await expect(body(page).getByRole('toolbar')).toHaveAttribute('aria-label', /ڕێکخستن/);
  await tool(page, 'بەستەر').click();
  await expect(richDialog(page, 'link').getByLabel('ناونیشانی بەستەر')).toBeFocused();
  await expect(richDialog(page, 'link').getByRole('heading')).toHaveText('دانانی بەستەر');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
