import { test, expect, Page } from '@playwright/test';

/* The blocks of 0.34 on the website (landing fixture) and in the editor. */
const setBlocks = async (page: Page, blocks: unknown[]) => page.request.post('/__test/landings-set', { form: { content: JSON.stringify({ en: blocks }) } });
const stored = async (page: Page) => { const rows = await (await page.request.post('/__test/landings-state')).json(); return JSON.parse(rows[0].content); };

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/media', { form: { seed: '1' } });
  await page.request.post('/__test/landings');
});
test.afterAll(async ({ request }) => { await request.post('/__test/media-off'); });

test('the map and a YouTube video load only when clicked', async ({ page }) => {
  await setBlocks(page, [
    { id: 'bcontact', type: 'contact', data: { heading: 'Visit us', address: 'Main Street 1', phone: '+964 750 000 0000', map: 'google' } },
    { id: 'bvideo', type: 'video', data: { url: 'https://www.youtube.com/watch?v=abcdefghijk', caption: 'Our studio' } },
  ]);
  const remote: string[] = [];
  page.on('request', request => { if (/google\.com|youtube/.test(request.url())) remote.push(request.url()); });
  await page.route(/google\.com|youtube/, route => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>embed</p>' }));
  await page.goto('/__test/landing-view');
  await expect(page.locator('.flo-contact-link')).toHaveAttribute('href', 'tel:+9647500000000');
  await expect(page.locator('[data-flo-map] iframe')).toHaveCount(0);
  await expect(page.locator('.flo-video-frame iframe')).toHaveCount(0);
  expect(remote).toEqual([]);
  await page.getByRole('link', { name: 'Show map' }).click();
  await expect(page.locator('.flo-contact-map iframe')).toHaveAttribute('src', 'https://www.google.com/maps?q=Main%20Street%201&z=15&output=embed');
  await page.getByRole('link', { name: 'Play the video: Our studio' }).click();
  await expect(page.locator('.flo-video-frame iframe')).toHaveAttribute('src', 'https://www.youtube-nocookie.com/embed/abcdefghijk?autoplay=1&rel=0');
  await expect(page.locator('.flo-video-frame iframe')).toBeFocused();
});

test('FAQ: one open at a time, and a link opens a question', async ({ page }) => {
  await setBlocks(page, [{ id: 'bfaq', type: 'faq', data: { heading: 'Questions', items: [
    { _key: 'k1', question: 'First?', answer: 'One.' }, { _key: 'k2', question: 'Second?', answer: 'Two.' }, { _key: 'k3', question: 'Third?', answer: 'Three.' },
  ] } }]);
  await page.goto('/__test/landing-view');
  const items = page.locator('details.flo-faq-item');
  await expect(items.nth(0)).toHaveAttribute('open', '');
  await page.getByText('Second?').click();
  await expect(items.nth(1)).toHaveAttribute('open', '');
  await expect(items.nth(0)).not.toHaveAttribute('open', '');
  await page.goto('/__test/landing-view#faq-bfaq-3');
  await expect(items.nth(2)).toHaveAttribute('open', '');
  expect(JSON.parse(await page.locator('script[type="application/ld+json"]').textContent() || '{}')['@type']).toBe('FAQPage');
});

test('Stats count up, and stay still for less motion; testimonials turn as a carousel', async ({ page }) => {
  await setBlocks(page, [
    { id: 'bstats', type: 'stats', data: { items: [{ _key: 'k1', number: '250', suffix: '+', label: 'Projects' }, { _key: 'k2', number: '98.5', label: 'Score' }] } },
    { id: 'bquotes', type: 'testimonials', data: { layout: 'carousel', items: [{ _key: 'k1', quote: 'Great.', name: 'Sara Ahmed', rating: '5' }, { _key: 'k2', quote: 'Fast.', name: 'Karwan Jalal' }] } },
  ]);
  await page.goto('/__test/landing-view');
  await expect(page.locator('[data-flo-count="250"]')).toHaveText('250');
  await expect(page.locator('[data-flo-count="98.5"]')).toHaveText('98.5');
  const slides = page.locator('.flo-testimonials-carousel .flo-slide');
  await expect(slides.nth(0)).toBeVisible();
  await expect(slides.nth(1)).toBeHidden();
  await page.getByRole('button', { name: 'Next' }).click();
  await expect(slides.nth(1)).toBeVisible();
  await expect(page.locator('.flo-stars')).toHaveAttribute('aria-label', 'Rated 5 out of 5');
  // Less motion: the number is shown at once, with no counting
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/__test/landing-view');
  await expect(page.locator('[data-flo-count="250"]')).toHaveText('250');
});

test('the new blocks are added from Add block and saved', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  const builder = page.locator('[data-field="content"]');
  await builder.locator('[data-builder-list="en"] > [data-builder-add-main], [data-builder-list="en"] .fa-builder-add-main > summary').first().click().catch(async () => {
    await builder.getByRole('button', { name: /Add block/ }).first().click();
  });
  const adder = page.locator('dialog[data-builder-add-dialog]');
  await expect(adder).toBeVisible();
  await adder.locator('[data-builder-search]').fill('faq');
  await adder.locator('[data-builder-choice="faq"]').click();
  const editor = page.locator('dialog[data-builder-edit-dialog]');
  await expect(editor).toBeVisible();
  await expect(editor.getByRole('tab')).toHaveText(['Content', 'Questions', 'Display']);
  await editor.getByRole('tab', { name: 'Questions' }).click();
  await editor.getByRole('button', { name: 'Add a question' }).click();
  await editor.locator('[name$="[question]"]').last().fill('Do you work weekends?');
  await editor.locator('[name$="[answer]"]').last().fill('Saturdays, 10:00–14:00.');
  await editor.getByRole('button', { name: 'Done' }).click();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  const faq = (await stored(page)).en.find((block: { type: string }) => block.type === 'faq');
  expect(faq.data.items[0]).toMatchObject({ question: 'Do you work weekends?', answer: 'Saturdays, 10:00–14:00.' });
});

test('a Hero saved with Centre keeps it as bottom-center in the 3 x 3 picker', async ({ page }) => {
  await setBlocks(page, [{ id: 'bhero', type: 'hero', data: { heading: 'Welcome', align: 'center' } }]);
  await page.goto('/en/admin/landings/edit/1');
  await page.locator('[data-builder-list="en"] [data-builder-node="bhero"] > .fa-builder-row .fa-builder-row-main').dblclick();
  const editor = page.locator('dialog[data-builder-edit-dialog]');
  await expect(editor.locator('[data-field="position"] input[value="bottom-center"]')).toBeChecked();
  await page.goto('/__test/landing-view');
  await expect(page.locator('.flo-hero')).toHaveClass(/is-pos-bottom-center/);
});

test('a gallery shows each image\'s title as its caption, also in the larger view', async ({ page }) => {
  await setBlocks(page, [{ id: 'bgallery', type: 'gallery', data: { images: ['1', '2', '3'], captions: '1', lightbox: '1' } }]);
  await page.goto('/__test/landing-view');
  // The garden has no title: no caption
  await expect(page.locator('.flo-gallery figcaption')).toHaveText(['Sea view', 'Hall']);
  await page.locator('[data-flo-gallery-item]').first().click();
  await expect(page.locator('.flo-lightbox-caption')).toHaveText('Sea view');
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('.flo-lightbox-caption')).toBeHidden();
});
