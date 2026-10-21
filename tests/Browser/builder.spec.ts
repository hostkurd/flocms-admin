import { test, expect, Page } from '@playwright/test';

/* The Builder field (0.29): fixture tests/Fixtures/Builder (landings), reset by /__test/landings. */
const builder = (page: Page) => page.locator('[data-field="content"]');
const list = (page: Page, lang = 'en') => builder(page).locator(`[data-builder-list="${lang}"]`);
const top = (page: Page, lang = 'en') => list(page, lang).locator(':scope > [data-builder-outline] > [data-builder-slot] > [data-builder-node]');
const titlesOf = (scope: ReturnType<Page['locator']>) => scope.locator(':scope > .fa-builder-row [data-builder-title]');
const row = (page: Page, id: string, lang = 'en') => list(page, lang).locator(`[data-builder-node="${id}"]`);
const pane = (page: Page, id: string, lang = 'en') => list(page, lang).locator(`[data-builder-pane="${id}"]`);
const editor = (page: Page) => page.locator('dialog[data-builder-edit-dialog]');
const adder = (page: Page) => page.locator('dialog[data-builder-add-dialog]');
const confirm = (page: Page) => page.locator('dialog[data-builder-confirm]');
const tool = (page: Page, id: string, action: string, lang = 'en') => row(page, id, lang).locator(`:scope > .fa-builder-row [data-builder-action="${action}"]`);
async function edit(page: Page, id: string) {
  await row(page, id).locator(':scope > .fa-builder-row').hover();
  await tool(page, id, 'edit').click();
  await expect(editor(page)).toBeVisible();
}
const stored = async (page: Page) => { const rows = await (await page.request.post('/__test/landings-state')).json(); return JSON.parse(rows[0].content); };

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/media', { form: { seed: '1' } });
  await page.request.post('/__test/landings');
});
test.afterAll(async ({ request }) => { await request.post('/__test/media-off'); });

test('the structure shows the blocks; a row shows its settings, Edit opens its fields in a dialog', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Start a project']);
  await expect(row(page, 'bcta1').locator('[data-builder-state]')).toHaveText('Draft');
  await expect(row(page, 'bcta1')).toHaveClass(/is-draft/);
  await expect(row(page, 'bslider1').locator('[data-builder-summary]')).toHaveText('2 items');
  await expect(row(page, 'btext1').locator('[data-builder-title]')).toHaveText('Strategy and design.');
  await expect(list(page).locator('[data-builder-none]')).toBeVisible();
  await expect(pane(page, 'bslider1')).toBeHidden();
  // Icon-only buttons with names
  await expect(tool(page, 'bhead1', 'edit')).toHaveAttribute('aria-label', 'Edit What we do');
  await expect(tool(page, 'bhead1', 'duplicate')).toHaveAttribute('title', 'Duplicate');
  await expect(tool(page, 'bhead1', 'remove')).toHaveAttribute('aria-label', 'Remove block');

  // The panel: the slider's own name, status, dates; no slides there
  await row(page, 'bslider1').locator('[data-builder-select]').first().click();
  await expect(pane(page, 'bslider1')).toBeVisible();
  await expect(pane(page, 'bhead1')).toBeHidden();
  await pane(page, 'bslider1').locator('[name$="[f][name]"]').fill('Main slider');
  await expect(row(page, 'bslider1').locator('[data-builder-title]').first()).toHaveText('Main slider');
  await expect(pane(page, 'bslider1').locator('[data-builder-pane-title]')).toHaveText('Main slider');
  await expect(pane(page, 'bslider1').locator('[name$="[slides][k1][heading]"]')).toBeHidden();

  // Edit content: the slides in the dialog, one under the other
  await pane(page, 'bslider1').locator('.fa-builder-edit').click();
  await expect(editor(page)).toBeVisible();
  await expect(editor(page).locator('[data-builder-dialog-title]')).toHaveText('Main slider');
  await editor(page).getByRole('tab', { name: 'Slides' }).click();
  await expect(editor(page).locator('[data-repeater-item]')).toHaveCount(2);
  await editor(page).locator('[data-repeater-add]').click();
  await expect(editor(page).locator('[data-repeater-item]')).toHaveCount(3);
  // Next block, then Done: the fields go back into their blocks
  await editor(page).locator('[data-builder-dialog-nav="1"]').click();
  await expect(editor(page).locator('[data-builder-dialog-title]')).toHaveText('Services');
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await expect(editor(page)).toBeHidden();
  await expect(row(page, 'bslider1').locator('[data-builder-summary]')).toHaveText('3 items');
  await expect(pane(page, 'bslider1').locator('[data-repeater-item]')).toHaveCount(3);
  await expect(row(page, 'bslider1')).toHaveClass(/is-changed/);
  // A click outside or Escape closes it too
  await edit(page, 'bhead1');
  await page.keyboard.press('Escape');
  await expect(editor(page)).toBeHidden();
  await expect(tool(page, 'bhead1', 'edit')).toBeFocused();
  await edit(page, 'bhead1');
  await page.mouse.click(5, 5);
  await expect(editor(page)).toBeHidden();
});

test('add, move, duplicate, hide and delete blocks, then save them in their places', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  // Add a heading at the end from the picker dialog, with search: it opens to be filled in
  await list(page).locator('.fa-builder-add-main > summary').click();
  await expect(adder(page)).toBeVisible();
  await expect(adder(page).locator('[data-builder-add-place]')).toHaveText('At the end of the page');
  await expect(adder(page).locator('[data-builder-search]')).toBeFocused();
  await page.keyboard.type('title for');
  await expect(adder(page).locator('[data-builder-choice]:visible')).toHaveCount(1);
  await page.keyboard.press('Enter');
  await expect(adder(page)).toBeHidden();
  await expect(top(page)).toHaveCount(4);
  const added = top(page).nth(3);
  await expect(editor(page)).toBeVisible();
  await editor(page).locator('[name$="[f][text]"]').fill('Latest news');
  await expect(editor(page).locator('[data-builder-dialog-title]')).toHaveText('Latest news');
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Start a project', 'Latest news']);
  await expect(page.locator('[data-admin-record-state]')).toHaveText('Unsaved changes');

  // The close button and a click outside close Add
  await list(page).locator('.fa-builder-add-main > summary').click();
  await adder(page).locator('[data-builder-dialog-close]').click();
  await expect(adder(page)).toBeHidden();

  // Alt + ↑ moves it up
  await added.locator('[data-builder-select]').first().focus();
  await page.keyboard.press('Alt+ArrowUp');
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Latest news', 'Start a project']);

  // A Section is not offered inside Columns; a heading is, in the chosen column
  await row(page, 'bcols1').locator('[data-builder-col="1"] .fa-builder-add-in > summary').click();
  await expect(adder(page).locator('[data-builder-add-place]')).toHaveText('In “2 columns”, column 2');
  await expect(adder(page).locator('[data-builder-choice="section"]')).toBeHidden();
  await adder(page).locator('[data-builder-choice="heading"]').click();
  const column = row(page, 'bcols1').locator('[data-builder-col="1"] > [data-builder-slot] > [data-builder-node]');
  await expect(column).toHaveCount(2);
  await editor(page).locator('[name$="[f][text]"]').fill('Support');
  await page.keyboard.press('Escape');
  // Recently used comes first next time
  await list(page).locator('.fa-builder-add-main > summary').click();
  await expect(adder(page).locator('[data-builder-cat="recent"]')).toBeVisible();
  await page.keyboard.press('Escape');

  // Duplicate the slider from its row (its slides come too)
  await row(page, 'bslider1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'bslider1', 'duplicate').click();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Home slider', 'Services', 'Latest news', 'Start a project']);
  // Hide and show the heading
  await row(page, 'bhead1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'bhead1', 'toggle').click();
  await expect(row(page, 'bhead1').locator('[data-builder-state]')).toHaveText('Draft');
  await expect(tool(page, 'bhead1', 'toggle')).toHaveAttribute('aria-pressed', 'true');
  await expect(tool(page, 'bhead1', 'toggle')).toHaveAttribute('aria-label', 'Show on the website');
  // Delete asks first (Cancel keeps it); then Undo brings it back
  await row(page, 'bcta1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'bcta1', 'remove').click();
  await expect(confirm(page)).toBeVisible();
  await expect(confirm(page).locator('[data-builder-confirm-title]')).toHaveText('Delete “Start a project”?');
  await confirm(page).getByRole('button', { name: 'Cancel' }).click();
  await expect(row(page, 'bcta1')).toHaveCount(1);
  await row(page, 'bcta1').locator('[data-builder-select]').first().focus();
  await page.keyboard.press('Delete');
  await confirm(page).getByRole('button', { name: 'Delete' }).click();
  await expect(builder(page).locator('[data-builder-undo]')).toContainText('Start a project removed');
  await builder(page).locator('[data-builder-undo-button]').click();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Home slider', 'Services', 'Latest news', 'Start a project']);
  await row(page, 'bcta1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'bcta1', 'remove').click();
  await confirm(page).getByRole('button', { name: 'Delete' }).click();

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Home slider', 'Services', 'Latest news']);
  const content = await stored(page);
  expect(content.en.map((block: { type: string }) => block.type)).toEqual(['slider', 'slider', 'section', 'heading']);
  expect(content.en[1].data.slides).toHaveLength(2);
  expect(content.en[1].id).not.toBe('bslider1');
  expect(content.en[2].children[0].settings.status).toBe('draft');
  const columns = content.en[2].children[1];
  expect(columns.children.map((block: { data: { text?: string } }) => block.data.text ?? null)).toEqual([null, null, 'Support']);
  expect(columns.children[2].column).toBe(1);
});

test('a block\'s name and note are for editors only', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await row(page, 'bhead1').locator('[data-builder-select]').first().click();
  await pane(page, 'bhead1').locator('[name$="[f][_label]"]').fill('Intro heading');
  await pane(page, 'bhead1').locator('[name$="[f][_note]"]').fill('Ask Sara before changing');
  await expect(row(page, 'bhead1').locator('[data-builder-title]')).toHaveText('Intro heading');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  await expect(row(page, 'bhead1').locator('[data-builder-title]')).toHaveText('Intro heading');
  const content = await stored(page);
  expect(content.en[1].children[0].settings._label).toBe('Intro heading');
  expect(content.en[1].children[0].settings._note).toBe('Ask Sara before changing');
  await page.goto('/__test/landing-view');
  await expect(page.locator('.flo-section h2')).toHaveText('What we do');
  await expect(page.getByText('Ask Sara')).toHaveCount(0);
  await expect(page.getByText('Intro heading')).toHaveCount(0);
});

test('copy a block with Ctrl+C and paste it on another page', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await row(page, 'bslider1').locator('[data-builder-select]').first().focus();
  await page.keyboard.press('Control+c');
  await expect(builder(page).locator('[data-builder-undo]')).toContainText('Block copied');
  // Ctrl+V after a row
  await row(page, 'bcta1').locator('[data-builder-select]').first().focus();
  await page.keyboard.press('Control+v');
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Start a project', 'Home slider']);
  // And Paste in Add, on a fresh page (the copy is kept in the browser)
  await page.request.post('/__test/landings');
  await page.goto('/en/admin/landings/edit/1');
  await list(page, 'en').locator('.fa-builder-add-main > summary').click();
  await expect(adder(page).locator('[data-builder-paste]')).toContainText('Home slider');
  await adder(page).locator('[data-builder-paste]').click();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Start a project', 'Home slider']);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  const content = await stored(page);
  expect(content.en[3].type).toBe('slider');
  expect(content.en[3].data.slides).toHaveLength(2);
  expect(content.en[3].data.slides[0].heading).toBe('Design that works');
  // Not offered where it may not sit (a Section inside Columns)
  await row(page, 'bsect1').locator('[data-builder-select]').first().focus();
  await page.keyboard.press('Control+c');
  await row(page, 'bcols1').locator('[data-builder-col="0"] .fa-builder-add-in > summary').click();
  await expect(adder(page).locator('[data-builder-paste]')).toBeHidden();
});

test('fewer columns move their blocks into the last one', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await edit(page, 'bcols1');
  await editor(page).locator('input[value="33-33-33"]').check({ force: true });
  await expect(row(page, 'bcols1').locator('[data-builder-col]')).toHaveCount(3);
  await expect(row(page, 'bcols1').locator('[data-builder-col="2"] .fa-builder-col-label')).toHaveText('Column 3');
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await row(page, 'bcols1').locator('[data-builder-col="2"] .fa-builder-add-in > summary').click();
  await adder(page).locator('[data-builder-choice="heading"]').click();
  await expect(row(page, 'bcols1').locator('[data-builder-col="2"] [data-builder-node]')).toHaveCount(1);
  await editor(page).locator('[name$="[f][text]"]').fill('Third');
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await edit(page, 'bcols1');
  await editor(page).locator('input[value="50-50"]').check({ force: true });
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await expect(row(page, 'bcols1').locator('[data-builder-col]')).toHaveCount(2);
  await expect(row(page, 'bcols1').locator('[data-builder-col="1"] [data-builder-node]')).toHaveCount(2);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  const columns = (await stored(page)).en[1].children[1];
  expect(columns.children.map((block: { column?: number }) => block.column ?? 0)).toEqual([0, 1, 1]);
});

test('the keyboard moves blocks into and out of a section, and Enter opens a block', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await row(page, 'bcta1').locator('[data-builder-select]').first().focus();
  await page.keyboard.press('Alt+ArrowRight');
  await expect(row(page, 'bsect1').locator('[data-builder-node="bcta1"]')).toHaveCount(1);
  await expect(pane(page, 'bcta1').locator('[data-builder-parent]')).toHaveValue('bsect1');
  await expect(builder(page).locator('[data-builder-total="en"]')).toHaveValue('7');
  await page.keyboard.press('Alt+ArrowLeft');
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Start a project']);
  await expect(pane(page, 'bcta1').locator('[data-builder-parent]')).toHaveValue('');
  await page.keyboard.press('Enter');
  await expect(editor(page)).toBeVisible();
  await expect(editor(page).locator('[data-builder-dialog-title]')).toHaveText('Start a project');
  await page.keyboard.press('Escape');
  await row(page, 'bhead1').locator('[data-builder-select]').first().focus();
  await page.keyboard.press('Control+d');
  await expect(row(page, 'bsect1').locator('[data-builder-node][data-type="heading"]')).toHaveCount(2);
});

test('copy a block, or all of them, to another language', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  await row(page, 'bhead1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'bhead1', 'copy-menu').click();
  await expect(row(page, 'bhead1').locator('[data-builder-copy-to="en"]')).toBeHidden();
  await row(page, 'bhead1').locator('[data-builder-copy-to="ar"]').click();
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
  await edit(page, 'bcta1');
  await editor(page).locator('[name$="[f][heading]"]').fill('');
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.fa-alert-danger').first()).toContainText('Page content → Call to action: Heading (English)');
  await expect(row(page, 'bcta1')).toHaveClass(/has-error/);
  await expect(editor(page)).toBeVisible();
  await expect(editor(page).locator('.fa-error')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(pane(page, 'bcta1')).toBeVisible();
  await expect(pane(page, 'bcta1').locator('.fa-builder-pane-error')).toBeVisible();
});

test('on a phone the panel and the dialogs fill the screen', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto('/en/admin/landings/edit/1');
  await expect(tool(page, 'bhead1', 'edit')).toBeHidden();
  await row(page, 'bhead1').locator('[data-builder-select]').first().click();
  const panel = await pane(page, 'bhead1').boundingBox();
  expect(panel!.width).toBeGreaterThan(385);
  await pane(page, 'bhead1').locator('.fa-builder-edit').click();
  await expect(editor(page)).toBeVisible();
  const box = await editor(page).boundingBox();
  expect(box!.width).toBeGreaterThan(385);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
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
    await pane(page, 'bslider1').locator('button[type="submit"][data-builder-action="remove"]').click();
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
