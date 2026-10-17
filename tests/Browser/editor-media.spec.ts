import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/* 0.13: resizing and positioning media, captions and links, and paragraph formatting. */
const photo = readFileSync(new URL('./fixtures/photo.png', import.meta.url));
const dialog = (page: Page) => page.locator('dialog.fa-editor');
const body = (page: Page) => dialog(page).locator('[data-field=body]');
const content = (page: Page) => body(page).locator('.fa-rich-panel[data-lang=en] .fa-rich-content');
const source = (page: Page) => body(page).locator('textarea[name="body[en]"]');
const tool = (page: Page, name: string) => body(page).getByRole('toolbar', { name: /formatting/ }).getByRole('button', { name, exact: true });
const bar = (page: Page) => body(page).locator('[data-rich-mediabar]');

async function open(page: Page) {
  await page.goto('/en/admin/articles');
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(body(page).locator('.fa-rich')).toHaveClass(/is-ready/);
}
async function upload(page: Page) {
  await content(page).locator('p').first().click();
  await page.keyboard.press('ControlOrMeta+End');
  const chooser = page.waitForEvent('filechooser');
  await tool(page, 'Image').click();
  await body(page).getByRole('menuitem', { name: /Upload an image/ }).click();
  await (await chooser).setFiles({ name: 'terrace.png', mimeType: 'image/png', buffer: photo });
  await expect(content(page).locator('img')).toHaveCount(1);
}
async function save(page: Page) {
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(dialog(page)).not.toBeVisible();
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(body(page).locator('.fa-rich')).toHaveClass(/is-ready/);
}

test('a selected image gets a toolbar for position and width, and grips that resize it in 5% steps', async ({ page }) => {
  await open(page);
  await upload(page);
  await content(page).locator('p').first().click();
  await expect(bar(page)).toBeHidden();
  await content(page).locator('img').click();
  await expect(bar(page)).toBeVisible();
  await expect(content(page).locator('.fa-media.ProseMirror-selectednode .fa-media-handle')).toHaveCount(6);
  // The bar sits above the image, or over its top edge when the editor's toolbar is in the way.
  await expect.poll(async () => {
    const image = (await content(page).locator('.fa-media').boundingBox())!;
    const options = (await bar(page).boundingBox())!;
    return options.y + options.height <= image.y || (options.y >= image.y && options.y + options.height <= image.y + 80);
  }).toBe(true);

  await bar(page).getByRole('button', { name: '50% wide' }).click();
  await bar(page).getByRole('button', { name: 'Text wraps on the right' }).click();
  await expect(source(page)).toHaveValue(/<img [^>]*class="rt-w-50 rt-wrap-left">$/);
  await expect(bar(page).getByRole('button', { name: '50% wide' })).toHaveAttribute('aria-pressed', 'true');
  // Pressing the same position again takes it off.
  await bar(page).getByRole('button', { name: 'Text wraps on the right' }).click();
  await expect(source(page)).toHaveValue(/<img [^>]*class="rt-w-50">$/);
  await bar(page).getByRole('button', { name: 'Centre', exact: true }).click();

  // Drag the bottom-right grip to the left: smaller, snapped to a 5% step, label while dragging.
  const grip = await content(page).locator('.fa-media-handle.is-se').boundingBox();
  await page.mouse.move(grip!.x + 6, grip!.y + 6);
  await page.mouse.down();
  await page.mouse.move(grip!.x - 60, grip!.y + 6, { steps: 6 });
  await expect(content(page).locator('.fa-media-size')).toBeVisible();
  // While dragging, the frame follows the pointer (not only 5% jumps); the label shows the step it will keep.
  const live = await content(page).locator('.fa-media.is-resizing').evaluate(node => (node as HTMLElement).style.width);
  expect(Number.parseFloat(live) % 5).not.toBe(0);
  await expect(content(page).locator('.fa-media-size')).toHaveText(/\b\d*[05]%$/);
  await page.mouse.up();
  // It glides to the kept step (the class survives the node's redraw).
  expect(await content(page).locator('.fa-media').first().evaluate(node => node.classList.contains('is-settling'))).toBe(true);
  const value = await source(page).inputValue();
  const size = Number(/rt-w-(\d+)/.exec(value)![1]);
  expect(size % 5).toBe(0);
  expect(size).toBeLessThan(50);
  expect(value).toMatch(/class="rt-w-\d+ rt-align-center"/);
  await save(page);
  await expect(source(page)).toHaveValue(new RegExp(`<img [^>]*class="rt-w-${size} rt-align-center" loading="lazy">`));
  await expect(content(page).locator(`.fa-media.rt-w-${size}.rt-align-center`)).toBeVisible();
});

test('edit adds a caption and a link (a figure); replace keeps them; remove deletes the image', async ({ page }) => {
  await open(page);
  await upload(page);
  await content(page).locator('img').click();
  await bar(page).getByRole('button', { name: '75% wide' }).click();
  await bar(page).getByRole('button', { name: 'Edit image' }).click();
  const edit = body(page).locator('dialog[data-rich-dialog=image]');
  await edit.getByLabel('Description (alt text)').fill('The terrace');
  await edit.getByLabel('Caption').fill('Evening light on the terrace');
  await edit.getByLabel('Link').fill('javascript:alert(1)');
  await edit.getByRole('button', { name: 'Save' }).click();
  await expect(edit.locator('[data-rich-error]')).toContainText('Use a web address');
  await edit.getByLabel('Link').fill('https://fectumhellas.test/villas');
  await edit.getByRole('button', { name: 'Save' }).click();
  await expect(content(page).locator('.fa-media-caption')).toHaveText('Evening light on the terrace');
  await expect(source(page)).toHaveValue(/<figure class="rt-figure rt-w-75"><a href="https:\/\/fectumhellas\.test\/villas"><img [^>]*alt="The terrace"[^>]*><\/a><figcaption>Evening light on the terrace<\/figcaption><\/figure>$/);
  const first = /src="([^"]+)"/.exec(await source(page).inputValue())![1];

  await content(page).locator('img').click();
  const chooser = page.waitForEvent('filechooser');
  await bar(page).getByRole('button', { name: 'Replace image' }).click();
  await (await chooser).setFiles({ name: 'other.png', mimeType: 'image/png', buffer: photo });
  await expect.poll(async () => /src="([^"]+)"/.exec(await source(page).inputValue())![1]).not.toBe(first);
  await expect(source(page)).toHaveValue(/alt="The terrace".*<figcaption>Evening light on the terrace<\/figcaption>/);
  await expect(content(page).locator('img')).toHaveCount(1);
  await save(page);
  await expect(source(page)).toHaveValue(/<figure class="rt-figure rt-w-75"><a href="https:\/\/fectumhellas\.test\/villas"><img [^>]* loading="lazy"><\/a><figcaption>Evening light on the terrace<\/figcaption><\/figure>/);
  await expect(content(page).locator('.fa-media-caption')).toHaveText('Evening light on the terrace');

  await content(page).locator('img').click();
  await bar(page).getByRole('button', { name: 'Remove' }).click();
  await expect(content(page).locator('img')).toHaveCount(0);
  await expect(bar(page)).toBeHidden();
});

test('embeds can be sized and positioned too', async ({ page }) => {
  await open(page);
  await content(page).click();
  await tool(page, 'YouTube or Vimeo video').click();
  const embed = body(page).locator('dialog[data-rich-dialog=embed]');
  await embed.getByLabel(/link/).fill('https://youtu.be/dQw4w9WgXcQ');
  await embed.getByRole('button', { name: 'Insert' }).click();
  await content(page).locator('.fa-rich-player').click();
  await expect(bar(page)).toBeVisible();
  await expect(bar(page).getByRole('button', { name: 'Edit image' })).toBeHidden();
  await bar(page).getByRole('button', { name: '50% wide' }).click();
  await bar(page).getByRole('button', { name: 'Right', exact: true }).click();
  await save(page);
  await expect(source(page)).toHaveValue(/<div data-embed="youtube" data-id="dQw4w9WgXcQ" class="rt-w-50 rt-align-right"><iframe /);
});

test('paragraphs can be aligned, indented, turned right to left, and cleared', async ({ page }) => {
  await open(page);
  await content(page).locator('p').first().click();
  const align = tool(page, 'Text alignment');
  await align.click();
  const menu = body(page).getByRole('menu', { name: 'Text alignment' });
  await expect(menu).toBeVisible();
  await expect(menu.getByRole('menuitemradio', { name: 'Align left' })).toHaveAttribute('aria-checked', 'true');
  await menu.getByRole('menuitemradio', { name: 'Centre' }).click();
  await expect(source(page)).toHaveValue('<p class="rt-text-center">Hello <strong>world</strong></p>');
  await expect(align).toHaveClass(/is-active/);
  await align.click();
  await expect(menu.getByRole('menuitemradio', { name: 'Centre' })).toHaveAttribute('aria-checked', 'true');
  await page.keyboard.press('Escape');

  await expect(tool(page, 'Decrease indent')).toBeDisabled();
  await tool(page, 'Increase indent').click();
  await tool(page, 'Increase indent').click();
  await expect(source(page)).toHaveValue('<p class="rt-text-center rt-indent-2">Hello <strong>world</strong></p>');
  await tool(page, 'Decrease indent').click();
  await tool(page, 'Switch text direction').click();
  await expect(tool(page, 'Switch text direction')).toHaveAttribute('aria-pressed', 'true');
  await expect(source(page)).toHaveValue('<p class="rt-text-center rt-indent-1" dir="rtl">Hello <strong>world</strong></p>');

  // Ctrl+\ (or the button) clears marks, alignment, indent and direction.
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.press('ControlOrMeta+\\');
  await expect(source(page)).toHaveValue('<p>Hello world</p>');
  await expect(tool(page, 'Switch text direction')).toHaveAttribute('aria-pressed', 'false');

  // The server keeps exactly these classes and drops anything else.
  await tool(page, 'Text alignment').click();
  await menu.getByRole('menuitemradio', { name: 'Justify' }).click();
  await save(page);
  await expect(source(page)).toHaveValue('<p class="rt-text-justify">Hello world</p>');
});

test('text pasted from Word arrives clean, keeping its alignment', async ({ page }) => {
  await open(page);
  await content(page).locator('p').first().click();
  await page.keyboard.press('ControlOrMeta+a');
  const word = '<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style>p.MsoNormal{margin:0}</style><!--[if gte mso 9]><xml><o:OfficeDocumentSettings/></xml><![endif]--></head>'
    + '<body><p class="MsoTitle" style="text-align:center;mso-line-height-rule:exactly">Villa <b>Thalassa</b><o:p></o:p></p>'
    + '<p class="MsoListParagraph" style="mso-list:l0 level1 lfo1"><span style="mso-list:Ignore">·<span>&nbsp;&nbsp;</span></span>Sea view<o:p></o:p></p>'
    + '<p class="MsoNormal"><o:p>&nbsp;</o:p></p></body></html>';
  await content(page).evaluate((element, html) => {
    const data = new DataTransfer();
    data.setData('text/html', html);
    data.setData('text/plain', 'Villa Thalassa Sea view');
    element.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
  }, word);
  await expect(source(page)).toHaveValue('<p class="rt-text-center">Villa <strong>Thalassa</strong></p><p>Sea view</p>');
});

test('the website stylesheet for rich text is served with the package', async ({ page }) => {
  await page.goto('/en/admin/articles');
  const href = await page.locator('link[href*="/flo-admin/asset/admin-v"]').first().getAttribute('href');
  const version = /admin-v([\d.]+)\.css/.exec(href!)![1];
  const response = await page.request.get(`/en/flo-admin/asset/rich-text-v${version}.css`);
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('text/css');
  const css = await response.text();
  for (const rule of ['.rt-w-50{width:50%}', '.rt-wrap-left{float:left', '.rt-figure figcaption', '.rt-text-center{text-align:center}', 'div[data-embed]{position:relative;aspect-ratio:16/9']) expect(css).toContain(rule);
});

const clip = readFileSync(new URL('./fixtures/clip.mp4', import.meta.url));
const overlap = (a: { x: number; y: number; width: number; height: number }, b: typeof a) =>
  a.x < b.x + b.width - 1 && b.x < a.x + a.width - 1 && a.y < b.y + b.height - 1 && b.y < a.y + a.height - 1;

test('an image after a wrapped image keeps its own frame, and resizing it changes only it', async ({ page }) => {
  await open(page);
  await upload(page);
  await content(page).locator('.fa-media').nth(0).locator('img').click();
  await bar(page).getByRole('button', { name: '50% wide' }).click();
  await bar(page).getByRole('button', { name: 'Text wraps on the right' }).click();
  await upload(page);
  await expect(content(page).locator('img')).toHaveCount(2);
  const second = content(page).locator('.fa-media').nth(1);
  await second.locator('img').click({ position: { x: 20, y: 20 } });
  await bar(page).getByRole('button', { name: 'Left', exact: true }).click();
  await bar(page).getByRole('button', { name: '25% wide' }).click();
  // The second frame sits beside or under the floated first one, never on top of it.
  const firstBox = (await content(page).locator('.fa-media').nth(0).boundingBox())!;
  const secondBox = (await second.boundingBox())!;
  expect(overlap(firstBox, secondBox)).toBe(false);
  // Its grips are on its own image.
  const image = (await second.locator('img').boundingBox())!;
  const grip = (await second.locator('.fa-media-handle.is-se').boundingBox())!;
  expect(Math.abs(grip.x + grip.width / 2 - (image.x + image.width))).toBeLessThan(6);
  expect(Math.abs(grip.y + grip.height / 2 - (image.y + image.height))).toBeLessThan(6);
  // Dragging its grip resizes it, not the first image.
  await page.mouse.move(grip.x + 6, grip.y + 6);
  await page.mouse.down();
  await page.mouse.move(grip.x + 120, grip.y + 6, { steps: 6 });
  await page.mouse.up();
  const html = await source(page).inputValue();
  const classes = [...html.matchAll(/<img [^>]*class="([^"]+)"/g)].map(match => match[1]);
  expect(classes[0]).toBe('rt-w-50 rt-wrap-left');
  expect(classes[1]).toMatch(/^rt-w-(\d+) rt-align-left$/);
  expect(Number(/rt-w-(\d+)/.exec(classes[1])![1])).toBeGreaterThan(25);
});

test('videos and embeds take the width they are given, in every position', async ({ page }) => {
  await open(page);
  await content(page).locator('p').first().click();
  await page.keyboard.press('ControlOrMeta+End');
  const chooser = page.waitForEvent('filechooser');
  await tool(page, 'Video').click();
  await (await chooser).setFiles({ name: 'tour.mp4', mimeType: 'video/mp4', buffer: clip });
  await expect(content(page).locator('video')).toHaveCount(1);
  const column = async () => content(page).evaluate(element => element.clientWidth - parseFloat(getComputedStyle(element).paddingLeft) - parseFloat(getComputedStyle(element).paddingRight));
  const video = content(page).locator('.fa-media[data-kind=video]');
  await video.click({ position: { x: 10, y: 10 } });
  for (const position of [null, 'Text wraps on the right', 'Text wraps on the left', 'Centre']) {
    if (position) await bar(page).getByRole('button', { name: position, exact: true }).click();
    await bar(page).getByRole('button', { name: '75% wide' }).click();
    await expect.poll(async () => Math.round((await video.locator('video').boundingBox())!.width / await column() * 100)).toBe(75);
    await bar(page).getByRole('button', { name: '25% wide' }).click();
    await expect.poll(async () => Math.round((await video.locator('video').boundingBox())!.width / await column() * 100)).toBe(25);
  }

  await tool(page, 'YouTube or Vimeo video').click();
  await body(page).locator('dialog[data-rich-dialog=embed]').getByLabel(/link/).fill('https://youtu.be/dQw4w9WgXcQ');
  await body(page).locator('dialog[data-rich-dialog=embed]').getByRole('button', { name: 'Insert' }).click();
  const embed = content(page).locator('.fa-media[data-kind=embed]');
  await embed.click();
  await bar(page).getByRole('button', { name: '25% wide' }).click();
  await expect.poll(async () => Math.round((await embed.boundingBox())!.width / await column() * 100)).toBe(25);
  // 16:9 at any width.
  const player = (await embed.locator('.fa-rich-player').boundingBox())!;
  expect(Math.abs(player.width / player.height - 16 / 9)).toBeLessThan(0.05);
  await bar(page).getByRole('button', { name: 'Text wraps on the left' }).click();
  await bar(page).getByRole('button', { name: '25% wide' }).click();
  await expect.poll(async () => Math.round((await embed.boundingBox())!.width / await column() * 100)).toBe(50);
});
