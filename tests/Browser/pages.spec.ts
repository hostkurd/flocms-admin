import { test, expect, Page } from '@playwright/test';

/* Pages (0.30): six seeded pages (router.php $seedPages), reset by /__test/pages. */
const lang = (page: Page, code: string) => page.locator(`form[data-lang-scope] .fa-lang-switch .fa-seg-btn[data-lang="${code}"]`);
const rows = async (page: Page) => (await (await page.request.post('/__test/pages-state')).json()) as Record<string, string | null>[];

/** Runs a change that refreshes the form and waits until the new form is in place. */
async function refreshed(page: Page, change: () => Promise<unknown>) {
  const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/site-pages/'));
  await change();
  await response;
  await expect(page.locator('.fa-panel')).not.toHaveAttribute('aria-busy', 'true');
}

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/pages');
});
test.afterAll(async ({ request }) => { await request.post('/__test/pages-off'); });

test('the list shows the pages in the editors\' order, with their status and address', async ({ page }) => {
  await page.goto('/en/admin/site-pages');
  const titles = page.locator('tbody tr td:first-child');
  await expect(titles.first()).toContainText('Home');
  await expect(titles.first()).toContainText('Home page');
  await expect(page.locator('tbody tr', { hasText: 'Web design' })).toContainText('/en/services/web-design');
  await expect(page.locator('tbody tr', { hasText: 'Careers' })).toContainText('Draft');
  await expect(page.locator('tbody tr', { hasText: 'Winter offer' })).toContainText('Scheduled');

  await page.locator('.fa-list-tab[data-admin-tab-value="draft"]').click();
  await expect(page.locator('tbody tr', { hasText: 'About us' })).toHaveCount(0);
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody tr')).toContainText('Careers');
});

test('the editor works one language at a time; the previews follow it', async ({ page }) => {
  await page.goto('/en/admin/site-pages/edit/2');
  await expect(lang(page, 'en')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[name="title[en]"]')).toHaveValue('About us');
  await expect(page.locator('[data-page-urls]')).toHaveValue(/\/en\/about-us$/);

  await page.getByRole('tab', { name: 'SEO' }).click();
  const search = page.locator('.fa-seo-search');
  await expect(search.locator('[data-seo-title]')).toHaveText('About us');
  await page.locator('[name="meta_title[en]"]').fill('About our studio');
  await expect(search.locator('[data-seo-title]')).toHaveText('About our studio');
  await expect(search.locator('[data-seo-desc]')).toHaveText('Who we are and how we work.');

  await lang(page, 'ar').click();
  await expect(page.locator('[name="title[en]"]')).toBeHidden();
  await expect(search.locator('[data-seo-title]')).toHaveText('من نحن');
  await expect(search.locator('[data-seo-url]')).toContainText('من-نحن');
  await expect(page.locator('[data-page-urls]')).toHaveValue(/\/ar\/من-نحن$/);
});

test('Preview opens the unsaved page in a new tab and saves nothing', async ({ page, context }) => {
  await page.goto('/en/admin/site-pages/edit/2');
  const text = page.locator('[data-field="body"] [contenteditable="true"]').first();
  await text.click();
  await page.keyboard.press('End');
  await page.keyboard.type(' Not saved yet.');
  const [tab] = await Promise.all([context.waitForEvent('page'), page.getByRole('button', { name: 'Preview' }).click()]);
  await tab.waitForLoadState();
  await expect(tab).toHaveURL(/\/flo-preview\/[0-9a-f]{32}$/);
  await expect(tab.locator('main')).toContainText('Not saved yet.');
  expect((await rows(page))[1].body).not.toContain('Not saved yet');
  await tab.close();
});

test('saving makes the address from the title and keeps a future time scheduled', async ({ page }) => {
  await page.goto('/en/admin/site-pages/create');
  await page.locator('[name="title[en]"]').fill('Our team');
  // The type switches the content field in place (0.32.1); the status refreshes the form (the panel is inert meanwhile).
  await page.locator('[data-field="type"] input[value="rich_text"]').check({ force: true });
  await expect(page.locator('[data-field="body"]')).toBeVisible();
  const text = page.locator('[data-field="body"] [contenteditable="true"]').first();
  await text.click();
  await page.keyboard.type('Meet the people.');
  await refreshed(page, () => page.locator('[name="status"]').selectOption('published'));
  await page.locator('[name="publish_at"]').fill('2099-05-01T09:00');
  await page.getByRole('button', { name: 'Save and close' }).click();
  await expect(page).toHaveURL(/\/admin\/site-pages(\?|$)/);
  const made = (await rows(page)).find(row => (row.title || '').includes('Our team'));
  expect(JSON.parse(made?.slug || '{}').en).toBe('our-team');
  expect(made?.status).toBe('scheduled');
});

test('switching the content type keeps what was typed, on a new page too (0.32.1)', async ({ page }) => {
  await page.goto('/en/admin/site-pages/create');
  await page.locator('[name="title[en]"]').fill('Kept');
  const editor = page.locator('dialog[data-builder-edit-dialog][open]');
  await page.locator('[data-field="blocks"] [data-builder-list="en"] .fa-builder-add-main > summary').click();
  await page.locator('dialog[data-builder-add-dialog][open] [data-builder-choice="heading"]').click();
  await editor.locator('[name$="[f][text]"]').fill('Kept heading');
  await editor.getByRole('button', { name: 'Done' }).click();
  await expect(editor).toHaveCount(0);
  // No reload: the fields are switched in place
  let reloaded = false;
  page.on('request', request => { if (request.method() === 'POST') reloaded = true; });
  const pick = (type: string) => page.locator(`[data-field="type"] input[value="${type}"]`).evaluate(el => (el as HTMLInputElement).click());
  await pick('rich_text');
  await expect(page.locator('[data-field="blocks"]')).toBeHidden();
  await expect(page.locator('[data-field="body"]')).toBeVisible();
  await page.locator('[data-field="body"] [contenteditable="true"]').first().click();
  await page.keyboard.type('Some text');
  await pick('builder');
  await expect(page.locator('[data-field="blocks"] [data-builder-list="en"] [data-builder-title]')).toHaveText(['Kept heading']);
  await pick('rich_text');
  await expect(page.locator('[data-field="body"] [contenteditable="true"]').first()).toContainText('Some text');
  expect(reloaded).toBe(false);
  await pick('builder');
  await page.getByRole('button', { name: 'Save and close' }).click();
  await expect(page).toHaveURL(/\/admin\/site-pages(\?|$)/);
  const made = (await rows(page)).find(row => (row.title || '').includes('Kept'));
  expect(made?.type).toBe('builder');
  expect(JSON.parse(made?.blocks || '{}').en[0].data.text).toBe('Kept heading');
});

test('a taken address is refused', async ({ page }) => {
  await page.goto('/en/admin/site-pages/create');
  await page.locator('[name="title[en]"]').fill('About again');
  await page.locator('[name="slug[en]"]').fill('about-us');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('[data-field="slug"]')).toContainText('already has this address');
});

test('the code editor numbers lines, indents with Tab and formats', async ({ page }) => {
  await page.goto('/en/admin/site-pages/edit/4');
  const code = page.locator('[data-field="html"] [data-code-input]').first();
  await expect(page.locator('[data-field="html"] .fa-code-lines').first()).toContainText('4');
  await code.fill('<section><p>One</p></section>');
  await page.locator('[data-field="html"]').getByRole('button', { name: 'Format' }).first().click();
  await expect(code).toHaveValue('<section>\n  <p>One</p>\n</section>');
  await code.focus();
  await page.keyboard.press('Control+Home');
  await page.keyboard.press('Tab');
  await expect(code).toHaveValue(/^ {2}<section>/);
  // Escape, then Tab leaves the editor
  await page.keyboard.press('Escape');
  await page.keyboard.press('Tab');
  await expect(code).not.toBeFocused();
});

test('the website: home page, menu, nested addresses, other languages and 404s', async ({ page }) => {
  await page.goto('/en/');
  await expect(page.locator('h1, h2').first()).toContainText('Design that works');
  await expect(page).toHaveTitle('Your Company');
  const menu = page.locator('header.site-head');
  await expect(menu.getByRole('link')).toHaveText(['About us', 'Services']);
  await expect(menu.getByRole('link', { name: 'Careers' })).toHaveCount(0);

  await page.goto('/en/services/web-design');
  await expect(page.locator('section.custom h2')).toHaveText('Web design');
  expect((await page.request.get('/en/careers')).status()).toBe(404);
  expect((await page.request.get('/en/winter-offer')).status()).toBe(404);

  await page.goto('/ar/' + encodeURIComponent('من-نحن'));
  await expect(page.locator('main')).toContainText('نحن استوديو صغير يبني المواقع.');
  await expect(page.locator('link[rel="alternate"][hreflang="en"]')).toHaveAttribute('href', /\/en\/about-us$/);

  const sitemap = await (await page.request.get('/sitemap.xml')).text();
  expect(sitemap).toContain('/en/services/web-design</loc>');
  expect(sitemap).not.toContain('careers');
});

test('Make home page moves the home page', async ({ page }) => {
  await page.goto('/en/admin/site-pages');
  const button = page.locator('[data-admin-action="make_home"][data-admin-record="2"]');
  if (!(await button.isVisible())) await page.locator('#fa-admin-resource-menu-2 > summary').click();
  await button.click();
  await page.getByRole('dialog').getByRole('button', { name: 'Set as home page' }).click();
  await expect(page.locator('[data-admin-notifications] [data-admin-notification]')).toContainText('About us');
  const all = await rows(page);
  expect(all.filter(row => String(row.is_home) === '1').map(row => row.id)).toEqual([2]);
});
