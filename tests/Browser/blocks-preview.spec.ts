import { test, expect } from '@playwright/test';

/* Pages › Blocks preview (0.33): every kind of block in its styles and colours, in the website's page. */
test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/media', { form: { seed: '1' } });
  await page.request.post('/__test/pages');
});
test.afterAll(async ({ request }) => { await request.post('/__test/pages-off'); await request.post('/__test/media-off'); });

test('the blocks preview shows a kind in its styles and colours, labelled with selectors, at three widths', async ({ page }) => {
  await page.goto('/en/admin/site-pages');
  await page.locator('.fa-sidebar, nav').getByRole('link', { name: 'Blocks preview' }).click();
  await expect(page.locator('h1')).toHaveText('Blocks preview');
  await expect(page.locator('.fa-breadcrumbs, nav[aria-label]').filter({ hasText: 'Pages' }).first()).toBeVisible();
  await page.locator('select[name="block"]').selectOption('heading');
  await expect(page).toHaveURL(/block=heading/);
  const frame = page.frameLocator('[data-preview-frame]');
  const labels = frame.locator('[data-flo-preview-label] strong');
  await expect(labels).toHaveText(['Heading · Default', 'Heading · Underlined', 'Heading · With eyebrow', 'Heading · Brand colour', 'Heading · Light', 'Heading · Dark', 'Heading · Muted']);
  await expect(frame.locator('[data-flo-preview-selector]').nth(1)).toHaveText('.flo-block-heading.is-style-underlined');
  await expect(frame.locator('.flo-block-heading.is-style-underlined h2')).toHaveText('What we do');
  await expect(frame.locator('.flo-block-heading.has-bg-dark')).toHaveCSS('color', 'rgb(255, 255, 255)');
  await expect(frame.getByRole('button', { name: 'Copy selector' })).toHaveCount(7);
  // Widths
  await page.getByRole('button', { name: 'Phone' }).click();
  await expect(page.getByRole('button', { name: 'Phone' })).toHaveAttribute('aria-pressed', 'true');
  await expect.poll(async () => (await page.locator('[data-preview-frame]').boundingBox())!.width).toBeLessThan(400);
  // Without styles and colours; in Arabic, right to left
  await page.locator('input[type="checkbox"][name="variants"]').uncheck();
  await expect(page).not.toHaveURL(/variants=1/);
  await expect(frame.locator('[data-flo-preview-label] strong')).toHaveText(['Heading · Default']);
  await page.locator('select[name="lang"]').selectOption('ar');
  await expect(frame.locator('html')).toHaveAttribute('dir', 'rtl');
});

test('the blocks preview needs its permission', async ({ page }) => {
  await page.request.post('/__test/writer');
  const response = await page.goto('/en/admin/blocks-preview');
  expect(response!.status()).toBe(403);
});
