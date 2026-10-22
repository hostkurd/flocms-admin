import { test, expect, Page } from '@playwright/test';

/* CheckboxList, TagsInput and TimePicker (0.37): fixtures tests/Fixtures/Choices (houses), reset by /__test/houses. */
const features = (page: Page) => page.locator('[data-field="features"]');
const keywords = (page: Page) => page.locator('[data-field="keywords"]');
const chips = (page: Page) => keywords(page).locator('.fa-tag-chip > span');
const state = async (page: Page) => (await (await page.request.post('/__test/houses-state')).json()) as Array<Record<string, string | null>>;

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/houses');
});

test('a checkbox list counts, filters and stops at its maximum', async ({ page }) => {
  await page.goto('/en/admin/houses/edit/1');
  const field = features(page);
  await expect(field.locator('[data-checks-count]')).toHaveText('4 of 9 chosen');
  // Select all would pass the maximum of 5: only Clear is offered
  await expect(field.locator('[data-checks-all]')).toHaveCount(0);
  await expect(field.locator('[data-checks-none]')).toBeVisible();

  await field.getByLabel('Lift').check();
  await expect(field.locator('[data-checks-count]')).toHaveText('5 of 9 chosen');
  await expect(field.getByLabel('Balcony')).toBeDisabled();
  await field.getByLabel('Pool').uncheck();
  await expect(field.getByLabel('Balcony')).toBeEnabled();

  await field.getByRole('searchbox').fill('fire');
  await expect(field.locator('.fa-checks-grid > .fa-check:visible')).toHaveText(['Fireplace']);
  await field.getByRole('searchbox').press('Enter');
  await expect(page).toHaveURL(/edit\/1$/);
  await field.getByRole('searchbox').fill('zzz');
  await expect(field.locator('[data-checks-empty]')).toBeVisible();
  await field.getByRole('searchbox').fill('');

  await field.locator('[data-checks-none]').click();
  await expect(field.locator('[data-checks-count]')).toHaveText('0 of 9 chosen');
  await field.getByLabel('Garden').check();
  await field.getByLabel('Sea view').check();
  await expect(page.locator('[data-admin-record-state]')).toHaveText('Unsaved changes');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('House saved.')).toBeVisible();
  expect((await state(page))[0].features).toBe('["sea_view","garden"]');
});

test('tags: suggestions, Enter and comma, Backspace twice, and the maximum', async ({ page }) => {
  await page.goto('/en/admin/houses/edit/1');
  await expect(chips(page)).toHaveText(['villa', 'sea view', 'garden']);
  const input = keywords(page).locator('.fa-tags-input');
  await expect(input).toHaveAttribute('role', 'combobox');
  await page.getByLabel('Keywords').click();
  await expect(input).toBeFocused();

  // A suggestion with Enter
  await input.pressSequentially('par');
  const options = keywords(page).locator('.fa-tags-option');
  await expect(options).toHaveText(['parking', 'park nearby', /Add “par”/]);
  await page.keyboard.press('Enter');
  await expect(chips(page)).toHaveText(['villa', 'sea view', 'garden', 'parking']);
  // Full at 4: nothing more is added
  await expect(input).toHaveAttribute('placeholder', 'Up to 4 tags.');
  await input.pressSequentially('pool,');
  await expect(chips(page)).toHaveCount(4);
  await input.fill('');

  // Backspace in the empty box goes to the last tag, Backspace again removes it
  await page.keyboard.press('Backspace');
  await expect(keywords(page).locator('.fa-tag-chip').last()).toBeFocused();
  await page.keyboard.press('Backspace');
  await expect(chips(page)).toHaveText(['villa', 'sea view', 'garden']);
  // The arrow keys move between tags
  await page.keyboard.press('ArrowLeft');
  await expect(keywords(page).locator('.fa-tag-chip').nth(1)).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(input).toBeFocused();

  // A comma adds the typed word; a duplicate (any capitals) is ignored
  await input.pressSequentially('Quiet,');
  await expect(chips(page)).toHaveText(['villa', 'sea view', 'garden', 'Quiet']);
  await keywords(page).getByRole('button', { name: 'Remove villa' }).click();
  await input.pressSequentially('GARDEN');
  await page.keyboard.press('Enter');
  await expect(chips(page)).toHaveText(['sea view', 'garden', 'Quiet']);

  await expect(keywords(page).locator('input[data-tags-value]')).toHaveValue('sea view, garden, Quiet');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('House saved.')).toBeVisible();
  expect((await state(page))[0].keywords).toBe('["sea view","garden","Quiet"]');
  await expect(chips(page)).toHaveText(['sea view', 'garden', 'Quiet']);
});

test('a refresh of the page\'s scripts keeps the tags box and what is being typed', async ({ page }) => {
  await page.goto('/en/admin/houses/edit/2');
  const input = keywords(page).locator('.fa-tags-input');
  await input.pressSequentially('half typ');
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('flo-reactive:updated')));
  await expect(keywords(page).locator('.fa-tags-box')).toHaveCount(1);
  await expect(input).toBeFocused();
  await expect(input).toHaveValue('half typ');
  await input.press('Enter');
  await expect(chips(page)).toHaveText(['apartment', 'Sea View', 'half typ']);
});

test('a pasted list becomes tags; the Arabic comma adds one on a right-to-left page', async ({ page }) => {
  await page.goto('/ar/admin/houses/edit/4');
  const input = keywords(page).locator('.fa-tags-input');
  await input.fill('');
  await input.click();
  await page.keyboard.insertText('جبل، ثلج، ');
  await expect(chips(page)).toHaveText(['lodge', 'جبل', 'ثلج']);
  // Right to left: the right arrow goes back to the tags
  await input.press('ArrowRight');
  await expect(keywords(page).locator('.fa-tag-chip').last()).toBeFocused();
  const box = await keywords(page).locator('.fa-tags-box').boundingBox();
  const first = await keywords(page).locator('.fa-tag-chip').first().boundingBox();
  expect(first!.x + first!.width).toBeGreaterThan(box!.x + box!.width - 20);
});

test('times are checked in the browser and on the server', async ({ page }) => {
  await page.goto('/en/admin/houses/edit/1');
  const until = page.getByLabel('Viewings until');
  await expect(until).toHaveAttribute('type', 'time');
  await expect(until).toHaveAttribute('step', '900');
  await until.fill('21:00');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('[data-field="closes_at"] .fa-error')).toHaveText('Viewings until must be 20:00 or earlier.');
  await until.fill('17:45');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('House saved.')).toBeVisible();
  expect((await state(page))[0].closes_at).toBe('17:45:00');
});

test('a new repeater item gets working tags and checkboxes', async ({ page }) => {
  await page.goto('/en/admin/houses/edit/2');
  const slots = page.locator('[data-field="slots"]');
  await slots.locator('[data-repeater-add]').click();
  const item = slots.locator('[data-repeater-item]').first();
  await item.getByLabel('Tuesday').check();
  await item.locator('.fa-tags-input').pressSequentially('quiet,early,');
  await expect(item.locator('.fa-tag-chip > span')).toHaveText(['quiet', 'early']);
  await item.getByLabel('At', { exact: true }).fill('10:30');
  // A copy starts from the copied value
  await item.locator('[data-repeater-action="duplicate"]').click();
  const copy = slots.locator('[data-repeater-item]').nth(1);
  await expect(copy.locator('.fa-tag-chip > span')).toHaveText(['quiet', 'early']);
  await expect(copy.locator('.fa-tags-box')).toHaveCount(1);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('House saved.')).toBeVisible();
  const saved = JSON.parse(String((await state(page))[1].slots));
  expect(saved.map((slot: Record<string, unknown>) => [slot.days, slot.at, slot.notes])).toEqual([[['tue'], '10:30', ['quiet', 'early']], [['tue'], '10:30', ['quiet', 'early']]]);
});

test('the dialog form sends the fields too', async ({ page }) => {
  await page.goto('/en/admin/house-dialogs');
  await page.locator('#fa-admin-resource-edit-4').click();
  const dialog = page.locator('dialog.fa-editor');
  await dialog.locator('[data-field="features"]').getByLabel('Pool').check();
  await dialog.locator('[data-field="features"]').getByLabel('Fireplace').uncheck();
  await dialog.locator('[data-field="keywords"] .fa-tags-input').pressSequentially('wood,');
  await dialog.getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(dialog).toBeHidden();
  const row = (await state(page))[3];
  expect(row.features).toBe('["pool","sauna"]');
  expect(row.keywords).toBe('["lodge","wood"]');
});

test('list: chips, times and the three filters', async ({ page }) => {
  await page.goto('/en/admin/houses');
  const first = page.locator('tbody tr').filter({ hasText: 'Stone house' });
  await expect(first.locator('.fa-tags-cell').first().locator('.fa-tag')).toHaveText(['Pool', 'Garden', 'Fireplace']);
  await expect(first.locator('.fa-tag-more').first().locator('[aria-hidden]')).toHaveText('+2');
  await expect(first.locator('.fa-tag-more').first()).toHaveAttribute('title', 'Balcony, Furnished');
  await expect(first.locator('time')).toHaveText('11:00');

  await page.locator('details.fa-popover[id$="-filters-menu"] > summary').click();
  await page.locator('[data-admin-filter="features_any.o4"]').check(); // Lift
  await page.locator('[data-admin-filter="features_any.o6"]').check(); // Fireplace
  await page.locator('[data-admin-filter="keywords"]').fill('SEA VIEW');
  await page.locator('[data-admin-filter="opens_at.from"]').fill('09:00');
  await page.locator('[data-admin-filter="opens_at.until"]').fill('10:30');
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody tr')).toContainText('Harbour apartment');
  await expect(page.getByRole('button', { name: /Remove filter Any of: Lift, Fireplace/ })).toBeVisible();
  await page.getByRole('button', { name: /Remove filter Viewings start/ }).click();
  await expect(page.locator('tbody tr')).toHaveCount(2);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('plain checkboxes, one text box and the browser time input save', async ({ page }) => {
    await page.goto('/en/admin/houses/edit/1');
    await expect(features(page).locator('[data-checks-tools]')).toBeHidden();
    await expect(features(page).locator('[data-checks-search-wrap]')).toBeHidden();
    const text = keywords(page).locator('input[name="keywords"]');
    await expect(text).toHaveValue('villa, sea view, garden');
    await features(page).getByLabel('Pool').uncheck();
    await features(page).getByLabel('Parking').uncheck();
    await features(page).getByLabel('Sea view').uncheck();
    await features(page).getByLabel('Garden').uncheck();
    await text.fill('stone, Stone ,  old   port');
    await page.getByLabel('Viewings from').fill('08:15');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('House saved.')).toBeVisible();
    const row = (await state(page))[0];
    expect(row.features).toBeNull();
    expect(row.keywords).toBe('["stone","old port"]');
    expect(row.opens_at).toBe('08:15:00');
  });
});
