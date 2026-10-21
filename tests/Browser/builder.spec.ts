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
  // One block per dialog (0.32), and one height whatever the tab
  await expect(editor(page).locator('[data-builder-dialog-nav]')).toHaveCount(0);
  const height = (await editor(page).boundingBox())!.height;
  expect(height).toBeGreaterThanOrEqual(560);
  expect(height).toBeLessThanOrEqual(760);
  await editor(page).getByRole('tab', { name: 'Slides' }).click();
  await expect(editor(page).locator('[data-repeater-item]')).toHaveCount(2);
  expect((await editor(page).boundingBox())!.height).toBe(height);
  await editor(page).locator('[data-repeater-add]').click();
  await expect(editor(page).locator('[data-repeater-item]')).toHaveCount(3);
  expect((await editor(page).boundingBox())!.height).toBe(height);
  await editor(page).getByRole('tab', { name: 'Content' }).click();
  expect((await editor(page).boundingBox())!.height).toBe(height);
  // Done: the fields go back into their block
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

test('a page with more fields than max_input_vars is saved whole, as one value (0.32)', async ({ page }) => {
  // 76 blocks in three languages: about 1,400 form fields, while the test server keeps PHP's max_input_vars = 1000.
  await page.request.post('/__test/landings', { form: { many: '23' } });
  await page.goto('/en/admin/landings/edit/1');
  await expect(top(page)).toHaveCount(26);
  const fields = await page.evaluate(() => [...new FormData(document.querySelector('[data-field="content"]')!.closest('form') as HTMLFormElement).keys()].length);
  expect(fields).toBeLessThan(40);
  const named = await page.locator('[data-field="content"] [name^="content["]').count();
  expect(named).toBeGreaterThan(1000);
  await edit(page, 'bmen23');
  await editor(page).locator('[name$="[f][text]"]').fill('The last one, changed');
  await editor(page).getByRole('button', { name: 'Done' }).click();
  const sent = page.waitForRequest(request => request.method() === 'POST' && request.url().includes('/landings/edit/1'));
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  const body = (await sent).postData() || '';
  expect(body).not.toContain('content%5Ben%5D');
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  const content = await stored(page);
  expect(content.en).toHaveLength(26);
  expect(content.ar).toHaveLength(23);
  expect(content.ku).toHaveLength(23);
  expect(content.en[25].data.text).toBe('The last one, changed');
  expect(content.ku[22].data.text).toBe('KU heading 23');
});

test('columns sit side by side as wide as their layout, and stack when the structure is narrow (0.32)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/en/admin/landings/edit/1');
  const cols = row(page, 'bcols1').locator(':scope > [data-builder-cols] > [data-builder-col]');
  await expect(cols).toHaveCount(2);
  await expect(cols.locator('[data-builder-col-width]')).toHaveText(['50%', '50%']);
  let first = (await cols.nth(0).boundingBox())!;
  let second = (await cols.nth(1).boundingBox())!;
  expect(Math.abs(first.y - second.y)).toBeLessThan(2);
  expect(second.x).toBeGreaterThan(first.x + first.width - 1);
  // 67 / 33: the first column is twice as wide
  await row(page, 'bcols1').locator(':scope > .fa-builder-row .fa-builder-row-main').dblclick();
  await editor(page).locator('input[value="67-33"]').check({ force: true });
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await expect(cols.locator('[data-builder-col-width]')).toHaveText(['67%', '33%']);
  first = (await cols.nth(0).boundingBox())!;
  second = (await cols.nth(1).boundingBox())!;
  expect(first.width / second.width).toBeGreaterThan(1.8);
  // Thirds: a third column appears, empty, named for screen readers
  await row(page, 'bcols1').locator(':scope > .fa-builder-row .fa-builder-row-main').dblclick();
  await editor(page).locator('input[value="33-33-33"]').check({ force: true });
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await expect(cols).toHaveCount(3);
  await expect(cols.nth(2).locator('[data-builder-add] > summary')).toHaveAttribute('aria-label', 'Add to column 3');
  // A phone: the columns stack, each with its name and width
  await page.setViewportSize({ width: 390, height: 844 });
  first = (await cols.nth(0).boundingBox())!;
  second = (await cols.nth(1).boundingBox())!;
  expect(second.y).toBeGreaterThan(first.y + first.height - 1);
  await expect(cols.nth(1).locator('.fa-builder-col-label')).toBeVisible();
});

test('containers fold and stay folded after a reload; Collapse all folds them all (0.32)', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  const fold = (id: string) => row(page, id).locator(':scope > .fa-builder-row [data-builder-action="fold"]');
  await expect(fold('bsect1')).toHaveAttribute('aria-expanded', 'true');
  await expect(fold('bhead1')).toHaveCount(0);
  await fold('bsect1').click();
  await expect(row(page, 'bsect1')).toHaveClass(/is-folded/);
  await expect(row(page, 'bhead1')).toBeHidden();
  await expect(fold('bsect1')).toHaveAttribute('aria-expanded', 'false');
  await expect(fold('bsect1')).toHaveAttribute('aria-label', 'Unfold Services');
  await expect(row(page, 'bsect1').locator(':scope > .fa-builder-row [data-builder-inside]')).toHaveText('4 inside');
  await page.reload();
  await expect(row(page, 'bsect1')).toHaveClass(/is-folded/);
  await expect(row(page, 'bhead1')).toBeHidden();
  // Collapse all / Expand all
  const all = list(page).locator('[data-builder-action="collapse-all"]');
  await fold('bsect1').click();
  await expect(all).toHaveText('Collapse all');
  await all.click();
  await expect(row(page, 'bsect1')).toHaveClass(/is-folded/);
  await expect(row(page, 'bcols1')).toHaveClass(/is-folded/);
  await expect(all).toHaveText('Expand all');
  await all.click();
  await expect(row(page, 'bhead1')).toBeVisible();
  await expect(row(page, 'btext1')).toBeVisible();
  // A block added into a folded container opens it
  await fold('bcols1').click();
  await row(page, 'bsect1').locator(':scope > .fa-builder-add > summary').click();
  await adder(page).locator('[data-builder-choice="heading"]').click();
  await expect(editor(page)).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(row(page, 'bcols1')).toHaveClass(/is-folded/);
  await expect(row(page, 'bsect1')).not.toHaveClass(/is-folded/);
});

/** Drag a block by its handle to a point; `hold` keeps the button down at the end (to look at the drop line). */
async function dragTo(page: Page, id: string, to: { x: number; y: number }, hold = false) {
  const handle = row(page, id).locator(':scope > .fa-builder-row [data-builder-handle]');
  const box = (await handle.boundingBox())!;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2 + 8, box.y + box.height / 2 + 8, { steps: 2 });
  await page.mouse.move(to.x, to.y, { steps: 10 });
  if (!hold) await page.mouse.up();
}
const box = async (page: Page, id: string) => (await row(page, id).locator(':scope > .fa-builder-row').boundingBox())!;
const columnOf = (page: Page, id: string) => row(page, id).evaluate(node => (node.parentElement as HTMLElement).dataset.column);

test('drag and drop: between columns, out of a column, into a Section, a refused place and Escape (0.32)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/en/admin/landings/edit/1');
  // Column 1 → column 2, before "Development and support": a line shows where, the block moves on release
  let target = await box(page, 'btext2');
  await dragTo(page, 'btext1', { x: target.x + 40, y: target.y + 6 }, true);
  await expect(list(page).locator('[data-builder-drop]')).toBeVisible();
  await expect(list(page).locator('[data-builder-drop]')).not.toHaveClass(/is-refused/);
  await expect(row(page, 'btext1')).toHaveClass(/is-drag-source/);
  expect(await columnOf(page, 'btext1')).toBe('0');
  await page.mouse.up();
  expect(await columnOf(page, 'btext1')).toBe('1');
  await expect(titlesOf(row(page, 'bcols1').locator('[data-builder-col="1"] [data-builder-node]'))).toHaveText(['Strategy and design.', 'Development and support.']);
  await expect(list(page).locator('[data-builder-drop]')).toBeHidden();
  // Out of the column, to the top level between the slider and the section
  const slider = await box(page, 'bslider1');
  await dragTo(page, 'btext2', { x: slider.x + 60, y: slider.y + slider.height + 4 });
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Development and support.', 'Services', 'Start a project']);
  // Into the Section, after its heading
  target = await box(page, 'bhead1');
  await dragTo(page, 'bcta1', { x: target.x + 60, y: target.y + target.height - 6 });
  await expect(titlesOf(row(page, 'bsect1').locator(':scope > [data-builder-slot] > [data-builder-node]'))).toHaveText(['What we do', 'Start a project', '2 columns']);
  // Columns out of the Section, to the end of the page
  const card = (await row(page, 'bsect1').boundingBox())!;
  await dragTo(page, 'bcols1', { x: card.x + 60, y: card.y + card.height + 4 });
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Development and support.', 'Services', '2 columns']);
  // A Section may not go inside Columns: a red line, and nothing moves
  const column = (await row(page, 'bcols1').locator('[data-builder-col="0"]').boundingBox())!;
  await dragTo(page, 'bsect1', { x: column.x + 30, y: column.y + column.height - 10 }, true);
  await expect(list(page).locator('[data-builder-drop]')).toHaveClass(/is-refused/);
  await page.mouse.up();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Development and support.', 'Services', '2 columns']);
  // Escape cancels a drag
  target = await box(page, 'bslider1');
  await dragTo(page, 'btext1', { x: target.x + 40, y: target.y + 4 }, true);
  await page.keyboard.press('Escape');
  await page.mouse.up();
  expect(await columnOf(page, 'btext1')).toBe('1');
  await expect(row(page, 'btext1')).not.toHaveClass(/is-drag-source/);

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  const content = await stored(page);
  expect(content.en.map((block: { id: string }) => block.id)).toEqual(['bslider1', 'btext2', 'bsect1', 'bcols1']);
  expect(content.en[2].children.map((block: { id: string }) => block.id)).toEqual(['bhead1', 'bcta1']);
  expect(content.en[3].children[0]).toMatchObject({ id: 'btext1', column: 1 });
});

test('drag and drop right to left, into a folded Section and by touch (0.32)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/ar/admin/landings/edit/1');
  // Arabic: column 1 is on the right; drag its block into column 2, on the left
  const second = (await row(page, 'bcols1').locator('[data-builder-col="1"]').boundingBox())!;
  const first = (await row(page, 'bcols1').locator('[data-builder-col="0"]').boundingBox())!;
  expect(second.x).toBeLessThan(first.x);
  await dragTo(page, 'btext1', { x: second.x + second.width / 2, y: second.y + second.height - 8 });
  expect(await columnOf(page, 'btext1')).toBe('1');
  // Held over a folded Section, it opens
  await row(page, 'bsect1').locator(':scope > .fa-builder-row [data-builder-action="fold"]').click();
  const section = await box(page, 'bsect1');
  await dragTo(page, 'bslider1', { x: section.x + section.width / 2, y: section.y + section.height / 2 }, true);
  await expect(row(page, 'bsect1')).not.toHaveClass(/is-folded/, { timeout: 2000 });
  await page.keyboard.press('Escape');
  await page.mouse.up();
  // Touch: press and hold a row, then move
  await page.goto('/en/admin/landings/edit/1');
  const cta = await box(page, 'bcta1');
  const slider = await box(page, 'bslider1');
  await page.evaluate(async ({ from, to }) => {
    const row = document.elementFromPoint(from.x, from.y)!;
    const init = (x: number, y: number) => ({ bubbles: true, cancelable: true, pointerId: 7, pointerType: 'touch', isPrimary: true, clientX: x, clientY: y, button: 0 });
    row.dispatchEvent(new PointerEvent('pointerdown', init(from.x, from.y)));
    await new Promise(resolve => setTimeout(resolve, 600));
    for (let step = 1; step <= 8; step++) document.dispatchEvent(new PointerEvent('pointermove', init(from.x, from.y + (to.y - from.y) * step / 8)));
    document.dispatchEvent(new PointerEvent('pointerup', init(from.x, to.y)));
  }, { from: { x: cta.x + cta.width / 2, y: cta.y + cta.height / 2 }, to: { x: slider.x + 60, y: slider.y + 6 } });
  await expect(titlesOf(top(page))).toHaveText(['Start a project', 'Home slider', 'Services']);
});

test('the ⋯ menu repeats the actions and moves a block to any place it may go (0.32)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/en/admin/landings/edit/1');
  const menu = builder(page).locator('[data-builder-menu]');
  await row(page, 'btext1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'btext1', 'more').click();
  await expect(menu).toBeVisible();
  await expect(tool(page, 'btext1', 'more')).toHaveAttribute('aria-expanded', 'true');
  await expect(menu.getByRole('menuitem').first()).toBeFocused();
  await expect(menu.getByRole('menuitem')).toHaveText(['Edit Strategy and design.', 'Duplicate', 'Hide from the website', 'Copy to العربية', 'Copy to کوردی', 'Top level, after “Services”', 'In “Services”', 'Column 2', 'Remove block']);
  // The keyboard: arrows move, Escape closes and goes back to the button
  await page.keyboard.press('ArrowUp');
  await expect(menu.getByRole('menuitem', { name: 'Remove block' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();
  await expect(tool(page, 'btext1', 'more')).toBeFocused();
  // Tab closes it too, back on the button; Move to is a named group
  await page.keyboard.press('Enter');
  await expect(menu.getByRole('group', { name: 'Move to' })).toBeVisible();
  await page.keyboard.press('Tab');
  await expect(menu).toBeHidden();
  await expect(tool(page, 'btext1', 'more')).toBeFocused();
  // Move to: column 2
  await page.keyboard.press('Enter');
  await menu.getByRole('menuitem', { name: 'Column 2' }).click();
  await expect(menu).toBeHidden();
  expect(await columnOf(page, 'btext1')).toBe('1');
  await expect(row(page, 'btext1')).toHaveClass(/is-changed/);
  // Move to: the top level, after the Section
  await row(page, 'btext1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'btext1', 'more').click();
  await menu.getByRole('menuitem', { name: 'Top level, after “Services”' }).click();
  await expect(titlesOf(top(page))).toHaveText(['Home slider', 'Services', 'Strategy and design.', 'Start a project']);
  // The other items do what the row's buttons do
  await row(page, 'bcta1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'bcta1', 'more').click();
  await menu.getByRole('menuitem', { name: 'Show on the website' }).click();
  await expect(row(page, 'bcta1')).not.toHaveClass(/is-draft/);
  await row(page, 'bcta1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'bcta1', 'more').click();
  await menu.getByRole('menuitem', { name: 'Remove block' }).click();
  await expect(confirm(page)).toBeVisible();
  await confirm(page).getByRole('button', { name: 'Cancel' }).click();
  // A click elsewhere closes it
  await row(page, 'bcta1').locator(':scope > .fa-builder-row').hover();
  await tool(page, 'bcta1', 'more').click();
  await page.mouse.click(5, 5);
  await expect(menu).toBeHidden();
});

test('blocks: options shown for one choice, a new slider opens on its slides, a button needs its link (0.32)', async ({ page }) => {
  await page.goto('/en/admin/landings/edit/1');
  // Height (px) and the interval only show when they matter, without reloading the form
  await row(page, 'bslider1').locator(':scope > .fa-builder-row .fa-builder-row-main').dblclick();
  const px = editor(page).locator('[data-field="height_px"]');
  const interval = editor(page).locator('[data-field="interval"]');
  await expect(px).toBeHidden();
  await expect(interval).toBeVisible();
  await editor(page).locator('[data-field="height"] input[value="fixed"]').check({ force: true });
  await expect(px).toBeVisible();
  await expect(editor(page).locator('[data-field="height_phone"]')).toBeVisible();
  await editor(page).locator('[data-field="autoplay"] input[type="checkbox"]').uncheck({ force: true });
  await expect(interval).toBeHidden();
  await editor(page).getByRole('button', { name: 'Done' }).click();
  // A new slider: its Slides tab, with Add slide ready; its row says it still needs some
  await list(page).locator('.fa-builder-add-main > summary').click();
  await adder(page).locator('[data-builder-choice="slider"]').click();
  await expect(editor(page)).toBeVisible();
  await expect(editor(page).getByRole('tab', { name: 'Slides' })).toHaveAttribute('aria-selected', 'true');
  await expect(editor(page).locator('[data-repeater-add]')).toBeFocused();
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await expect(top(page).last().locator(':scope > .fa-builder-row [data-builder-summary]')).toHaveText('Nothing added yet');
  await top(page).last().locator(':scope > .fa-builder-row').hover();
  await top(page).last().locator(':scope > .fa-builder-row [data-builder-action="remove"]').click();
  await confirm(page).getByRole('button', { name: 'Delete' }).click();
  // A call to action's button without its link: the error opens the block
  await row(page, 'bcta1').locator(':scope > .fa-builder-row .fa-builder-row-main').dblclick();
  await editor(page).locator('[name$="[f][link]"]').fill('');
  await editor(page).getByRole('button', { name: 'Done' }).click();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(editor(page)).toBeVisible();
  await expect(editor(page).locator('[data-field="link"]')).toContainText('required');
});

test('a new rich text block has its editor; Show on and the spacing are the dialog\'s Display tab (0.32.1)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/en/admin/landings/edit/1');
  await list(page).locator('.fa-builder-add-main > summary').click();
  await adder(page).locator('[data-builder-choice="rich_text"]').click();
  await expect(editor(page).locator('[contenteditable="true"]')).toHaveCount(1);
  await expect(editor(page).locator('textarea[data-rich-source]')).toBeHidden();
  await editor(page).locator('[contenteditable="true"]').click();
  await page.keyboard.type('Typed in the editor');
  await expect(editor(page).getByRole('tab')).toHaveText(['Content', 'Display']);
  expect((await editor(page).boundingBox())!.height).toBeGreaterThanOrEqual(560);
  await editor(page).getByRole('tab', { name: 'Display' }).click();
  await expect(editor(page).locator('[data-field="devices"]')).toBeVisible();
  await expect(editor(page).locator('[data-field="anchor"]')).toBeVisible();
  await editor(page).locator('[data-field="devices"] input[value="phone"]').check({ force: true });
  await editor(page).getByRole('button', { name: 'Done' }).click();
  const added = top(page).last();
  await expect(added.locator(':scope > .fa-builder-row [data-builder-state]')).toHaveText('Phones only');
  // The panel keeps name, note, status and dates only
  const id = await added.getAttribute('data-builder-node');
  await expect(pane(page, id!).locator('[data-builder-settings] [data-field="devices"]')).toHaveCount(0);
  await expect(pane(page, id!).locator('[data-builder-settings] [data-field="status"]')).toBeVisible();
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Landing page saved.')).toBeVisible();
  const content = await stored(page);
  const last = content.en[content.en.length - 1];
  expect(last.data.body).toContain('Typed in the editor');
  expect(last.settings.devices).toBe('phone');
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
  await expect(top(page).nth(3).locator(':scope > .fa-builder-row [data-builder-select]')).toBeFocused();
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
  // The dialog gives the focus back to the block's Edit button when it has closed: wait for it before moving on.
  await expect(editor(page)).toBeHidden();
  await expect(tool(page, 'bcta1', 'edit')).toBeFocused();
  await row(page, 'bhead1').locator('[data-builder-select]').first().focus();
  await expect(row(page, 'bhead1').locator('[data-builder-select]').first()).toBeFocused();
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
