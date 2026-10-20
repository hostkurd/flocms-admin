import { test, expect, Page } from '@playwright/test';

/* The Builder field (0.29): fixture tests/Fixtures/Builder (landings), reset by /__test/landings. */
const builder = (page: Page) => page.locator('[data-field="content"]');
const list = (page: Page, lang = 'en') => builder(page).locator(`[data-builder-list="${lang}"]`);
const top = (page: Page, lang = 'en') => list(page, lang).locator(':scope > [data-builder-outline] > [data-builder-slot] > [data-builder-node]');
const titlesOf = (scope: ReturnType<Page['locator']>) => scope.locator(':scope > .fa-builder-row [data-builder-title]');
const row = (page: Page, id: string, lang = 'en') => list(page, lang).locator(`[data-builder-node="${id}"]`);
const pane = (page: Page, id: string, lang = 'en') => list(page, lang).locator(`[data-builder-pane="${id}"]`);
const stored = async (page: Page) => { const rows = await (await page.request.post('/__test/landings-state')).json(); return JSON.parse(rows[0].content); };

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/media', { form: { seed: '1' } });
  await page.request.post('/__test/landings');
});
test.afterAll(async ({ request }) => { await request.post('/__test/media-off'); });

test('the structure shows the blocks, and a row opens its block', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Start a project']);
  await expect(row(page, 'bcta1').locator('[data-builder-state]')).toHaveText('Draft');
  await expect(row(page, 'btext1').locator('[data-builder-title]')).toHaveText('Strategy and design.');
  await expect(list(page).locator('[data-builder-none]')).toBeVisible();
  await expect(pane(page, 'bslider1')).toBeHidden();

  await row(page, 'bslider1').locator('[data-builder-select]').first().click();
  await expect(pane(page, 'bslider1')).toBeVisible();
  await expect(pane(page, 'bhead1')).toBeHidden();
  await pane(page, 'bslider1').locator('[name$="[f][name]"]').fill('Main slider');
  await expect(row(page, 'bslider1').locator('[data-builder-title]').first()).toHaveText('Main slider');
  await expect(pane(page, 'bslider1').locator('[data-builder-pane-title]')).toHaveText('Main slider');
  // The slides are on their own tab
  await pane(page, 'bslider1').getByRole('tab', { name: 'Slides' }).click();
  await expect(pane(page, 'bslider1').locator('[data-repeater-item]')).toHaveCount(2);
  await pane(page, 'bslider1').locator('[data-builder-action="close"]').click();
  await expect(pane(page, 'bslider1')).toBeHidden();
});

test('add, move, duplicate and remove blocks, then save them in their places', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  // Add a heading at the end from the picker, with search
  await list(page).locator('.fa-builder-add-main > summary').click();
  await expect(list(page).locator('[data-builder-search]')).toBeFocused();
  await page.keyboard.type('title for');
  await expect(list(page).locator('[data-builder-picker] [data-builder-choice]:visible')).toHaveCount(1);
  await page.keyboard.press('Enter');
  await expect(top(page)).toHaveCount(4);
  const added = top(page).nth(3);
  const id = await added.getAttribute('data-builder-node');
  await expect(pane(page, id!)).toBeVisible();
  await pane(page, id!).locator('[name$="[f][text]"]').fill('Latest news');
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Start a project', 'Latest news']);
  await expect(page.locator('[data-admin-record-state]')).toHaveText('Unsaved changes');

  // Alt + ↑ moves it up
  await added.locator('[data-builder-select]').first().focus();
  await page.keyboard.press('Alt+ArrowUp');
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Latest news', 'Start a project']);

  // A Section is not offered inside Columns; a heading is, in the chosen column
  await row(page, 'bcols1').locator('[data-builder-col="1"] .fa-builder-add-in > summary').click();
  await expect(list(page).locator('[data-builder-picker] [data-builder-choice="section"]')).toBeHidden();
  await list(page).locator('[data-builder-picker] [data-builder-choice="heading"]').click();
  const column = row(page, 'bcols1').locator('[data-builder-col="1"] > [data-builder-slot] > [data-builder-node]');
  await expect(column).toHaveCount(2);
  const inColumn = await column.nth(1).getAttribute('data-builder-node');
  await pane(page, inColumn!).locator('[name$="[f][text]"]').fill('Support');

  // Duplicate the slider (its slides come too), remove the call to action, then Undo
  await row(page, 'bslider1').locator('[data-builder-select]').first().click();
  await pane(page, 'bslider1').locator('[data-builder-action="duplicate"]').click();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Home slider', 'Services', 'Latest news', 'Start a project']);
  await row(page, 'bcta1').locator('[data-builder-select]').first().click();
  await pane(page, 'bcta1').locator('[data-builder-action="remove"]').click();
  await expect(builder(page).locator('[data-builder-undo]')).toContainText('Start a project removed');
  await builder(page).locator('[data-builder-undo-button]').click();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Home slider', 'Services', 'Latest news', 'Start a project']);
  await row(page, 'bcta1').locator('[data-builder-select]').first().click();
  await pane(page, 'bcta1').locator('[data-builder-action="remove"]').click();

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Home slider', 'Services', 'Latest news']);
  const content = await stored(page);
  expect(content.en.map((block: { type: string }) => block.type)).toEqual(['slider', 'slider', 'section', 'heading']);
  expect(content.en[1].data.slides).toHaveLength(2);
  expect(content.en[1].id).not.toBe('bslider1');
  const columns = content.en[2].children[1];
  expect(columns.children.map((block: { data: { text?: string } }) => block.data.text ?? null)).toEqual([null, null, 'Support']);
  expect(columns.children[2].column).toBe(1);
});

test('fewer columns move their blocks into the last one', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await row(page, 'bcols1').locator('[data-builder-select]').first().click();
  await pane(page, 'bcols1').locator('input[value="33-33-33"]').check({ force: true });
  await expect(row(page, 'bcols1').locator('[data-builder-col]')).toHaveCount(3);
  await expect(row(page, 'bcols1').locator('[data-builder-col="2"] .fa-builder-col-label')).toHaveText('Column 3');
  await row(page, 'bcols1').locator('[data-builder-col="2"] .fa-builder-add-in > summary').click();
  await list(page).locator('[data-builder-picker] [data-builder-choice="heading"]').click();
  await expect(row(page, 'bcols1').locator('[data-builder-col="2"] [data-builder-node]')).toHaveCount(1);
  await list(page).locator('[data-builder-pane]:visible [name$="[f][text]"]').fill('Third');
  await row(page, 'bcols1').locator('[data-builder-select]').first().click();
  await pane(page, 'bcols1').locator('input[value="50-50"]').check({ force: true });
  await expect(row(page, 'bcols1').locator('[data-builder-col]')).toHaveCount(2);
  await expect(row(page, 'bcols1').locator('[data-builder-col="1"] [data-builder-node]')).toHaveCount(2);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  const columns = (await stored(page)).en[1].children[1];
  expect(columns.children.map((block: { column?: number }) => block.column ?? 0)).toEqual([0, 1, 1]);
});

test('copy a block, or all of them, to another language', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await row(page, 'bhead1').locator('[data-builder-select]').first().click();
  await pane(page, 'bhead1').locator('[data-builder-action="copy-menu"]').click();
  await pane(page, 'bhead1').locator('[data-builder-copy-to="ar"]').click();
  await builder(page).locator('[data-builder-lang="ar"]').click();
  await expect(titlesOf(top(page, 'ar'))).toHaveText(['What we do']);
  await builder(page).locator('[data-builder-lang="ku"]').click();
  await list(page, 'ku').locator('[data-builder-copy-all]').click();
  await expect(titlesOf(top(page, 'ku'))).toHaveText(['Home slider', 'Services', 'Start a project']);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  const content = await stored(page);
  expect(content.ar).toHaveLength(1);
  expect(content.ar[0].data.text).toBe('What we do');
  expect(content.ku[1].children[1].children).toHaveLength(2);
  expect(content.ku[0].data.slides).toHaveLength(2);
});

test('errors open the block that has them', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await row(page, 'bcta1').locator('[data-builder-select]').first().click();
  await pane(page, 'bcta1').locator('[name$="[f][heading]"]').fill('');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.fa-alert-danger').first()).toContainText('Page content → Call to action: Heading (English)');
  await expect(row(page, 'bcta1')).toHaveClass(/has-error/);
  await expect(pane(page, 'bcta1')).toBeVisible();
  await expect(pane(page, 'bcta1').locator('.fa-error')).toBeVisible();
});

test('the website shows the blocks with their own templates and the slider works', async ({ page }) => {
  await page.goto('/__test/landing-view');
  const slider = page.locator('[data-flo-slider]');
  await expect(slider.locator('.flo-slide.is-current .flo-slide-heading')).toHaveText('Design that works');
  await expect(slider.locator('.flo-slide').nth(1)).toHaveAttribute('aria-hidden', 'true');
  await slider.locator('[data-flo-next]').click();
  await expect(slider.locator('.flo-slide.is-current .flo-slide-heading')).toHaveText('Built to last');
  await expect(slider.locator('[data-flo-dot="1"]')).toHaveAttribute('aria-current', 'true');
  await expect(page.locator('.flo-section h2')).toHaveText('What we do');
  await expect(page.getByText('Start a project')).toHaveCount(0);
  // Only the scripts the page needs
  await expect(page.locator('script')).toHaveCount(1);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('the buttons send the form and the blocks change without saving', async ({ page }) => {
    await page.goto('/en/admin/landings/edit/1');
    await expect(row(page, 'bslider1').locator('[data-builder-handle]')).toBeHidden();
    // Every block's fields are shown; Add opens a list of kinds
    await expect(pane(page, 'bslider1')).toBeVisible();
    await list(page).locator('.fa-builder-add-main > summary').click();
    await list(page).locator('.fa-builder-add-main [data-builder-choice="heading"]').click();
    await expect(top(page)).toHaveCount(4);
    await row(page, 'bcta1').locator('[data-builder-action="up"]').click();
    await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Start a project', 'Services', 'Heading']);
    await pane(page, 'bslider1').locator('[data-builder-action="remove"]').click();
    await expect(titlesOf(top(page))).toHaveText(['Start a project', 'Services', 'Heading']);
    // A slide is added inside the slider's list (and the slider comes back on reload, not saved)
    await page.goto('/en/admin/landings/edit/1');
    await pane(page, 'bslider1').locator('[data-repeater-add]').click();
    await expect(pane(page, 'bslider1').locator('[data-repeater-item]')).toHaveCount(3);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    const content = await stored(page);
    expect(content.en[0].data.slides.length).toBeGreaterThanOrEqual(2);
  });
});
