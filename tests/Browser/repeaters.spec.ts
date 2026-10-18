import { test, expect, Page } from '@playwright/test';

/* Repeater and key-value fields (0.24): fixtures tests/Fixtures/Repeaters (apartments), reset by /__test/apartments. */
const rooms = (page: Page) => page.locator('[data-field="rooms"]');
const items = (page: Page) => rooms(page).locator('[data-repeater-item]');
const titles = (page: Page) => rooms(page).locator('[data-repeater-title]');

test.beforeEach(async ({ page }) => {
  await page.request.post('/__test/apartments');
});

test('add, edit, move and remove items, then save them in their order', async ({ page }) => {
  await page.goto('/en/admin/apartments/edit/1');
  await expect(titles(page)).toHaveText(['Master bedroom', 'Kitchen', 'Living room']);
  await expect(rooms(page).locator('[data-repeater-count]')).toHaveText('3 of up to 6');
  // The JavaScript tools are shown
  await expect(items(page).first().locator('[data-repeater-handle]')).toBeVisible();
  await expect(items(page).first().locator('[data-repeater-action="up"]')).toBeDisabled();

  await rooms(page).locator('[data-repeater-add]').click();
  await expect(items(page)).toHaveCount(4);
  const study = items(page).nth(3);
  await expect(study.locator('[name$="[name][en]"]')).toBeFocused();
  await study.locator('[name$="[name][en]"]').fill('Study');
  await expect(titles(page).nth(3)).toHaveText('Study');
  await study.locator('[name$="[size]"]').fill('9');
  await expect(rooms(page).locator('[data-repeater-count]')).toHaveText('4 of up to 6');
  await expect(page.locator('[data-admin-record-state]')).toHaveText('Unsaved changes');

  // Move the study up twice, the kitchen down with the keyboard on its handle
  await study.locator('[data-repeater-action="up"]').click();
  await items(page).nth(2).locator('[data-repeater-action="up"]').click();
  await expect(titles(page)).toHaveText(['Master bedroom', 'Study', 'Kitchen', 'Living room']);
  await items(page).nth(2).locator('[data-repeater-handle]').focus();
  await page.keyboard.press('ArrowDown');
  await expect(titles(page)).toHaveText(['Master bedroom', 'Study', 'Living room', 'Kitchen']);

  // Remove with Undo, then remove for good
  await items(page).first().locator('[data-repeater-action="remove"]').click();
  await expect(rooms(page).locator('[data-repeater-undo]')).toContainText('Master bedroom removed');
  await rooms(page).locator('[data-repeater-undo-button]').click();
  await expect(titles(page)).toHaveText(['Master bedroom', 'Study', 'Living room', 'Kitchen']);
  await items(page).first().locator('[data-repeater-action="remove"]').click();
  await expect(titles(page)).toHaveText(['Study', 'Living room', 'Kitchen']);

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Apartment saved.')).toBeVisible();
  await expect(titles(page)).toHaveText(['Study', 'Living room', 'Kitchen']);
  await expect(items(page).first().locator('[name$="[size]"]')).toHaveValue('9');
});

test('duplicate and collapse an item', async ({ page }) => {
  await page.goto('/en/admin/apartments/edit/1');
  await items(page).nth(1).locator('[data-repeater-action="duplicate"]').click();
  await expect(titles(page)).toHaveText(['Master bedroom', 'Kitchen', 'Kitchen', 'Living room']);
  await expect(items(page).nth(2).locator('[name$="[size]"]')).toHaveValue('16');
  await expect(items(page).nth(2).locator('select[name$="[floor]"]')).toHaveValue('0');
  const first = items(page).first();
  await first.locator('[data-repeater-action="toggle"]').click();
  await expect(first.locator('.fa-repeater-body')).toBeHidden();
  await expect(first.locator('[data-repeater-action="toggle"]')).toHaveAttribute('aria-expanded', 'false');
  await expect(first.locator('[data-repeater-summary]')).toContainText('22');
  await first.locator('[data-repeater-action="toggle"]').click();
  await expect(first.locator('.fa-repeater-body')).toBeVisible();
  // The duplicate has the same name: distinct refuses it on save
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Rooms → item 3: Name')).toBeVisible();
  await expect(items(page).nth(2)).toHaveClass(/has-error/);
});

test('drag an item by its handle', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 1600 });
  await page.goto('/en/admin/apartments/edit/1');
  const handle = items(page).nth(2).locator('[data-repeater-handle]');
  const target = items(page).first();
  const from = await handle.boundingBox();
  const to = await target.boundingBox();
  await page.mouse.move(from!.x + from!.width / 2, from!.y + from!.height / 2);
  await page.mouse.down();
  await page.mouse.move(from!.x + from!.width / 2, to!.y + 10, { steps: 12 });
  await page.mouse.up();
  await expect(titles(page)).toHaveText(['Living room', 'Master bedroom', 'Kitchen']);
});

test('key-value pairs and table rows', async ({ page }) => {
  await page.goto('/en/admin/apartments/edit/1');
  const specs = page.locator('[data-field="specs"]');
  await specs.locator('[data-repeater-add]').click();
  const row = specs.locator('[data-repeater-item]').last();
  await row.locator('[name$="[key]"]').fill('View');
  await row.locator('[name$="[value][en]"]').fill('Sea');
  const hours = page.locator('[data-field="hours"]');
  await expect(hours.locator('[data-repeater-action="up"]')).toBeHidden();
  await hours.locator('[data-repeater-add]').click();
  await hours.locator('[data-repeater-item]').last().locator('select[name$="[day]"]').selectOption('tue');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Apartment saved.')).toBeVisible();
  await expect(specs.locator('[data-repeater-item]')).toHaveCount(3);
  await expect(specs.locator('[data-repeater-item]').last().locator('[name$="[key]"]')).toHaveValue('View');
  await expect(hours.locator('[data-repeater-item]')).toHaveCount(2);
});

test('a dialog with page-level translation: items follow the switch, the list is locked while translating', async ({ page }) => {
  await page.goto('/en/admin/apartment-dialogs');
  await page.locator('#fa-admin-resource-edit-1').click();
  const dialog = page.locator('dialog.fa-editor');
  const list = dialog.locator('[data-field="rooms"]');
  await expect(list.locator('[data-repeater-add]')).toBeVisible();
  await dialog.locator('.fa-seg-btn[data-lang="ar"]').click();
  await expect(list.locator('[data-repeater-add]')).toBeHidden();
  await expect(list.locator('[data-repeater-action="remove"]').first()).toBeHidden();
  const kitchen = list.locator('[data-repeater-item]').nth(1);
  await expect(kitchen.locator('[name$="[name][ar]"]')).toBeVisible();
  await expect(kitchen.locator('[data-ref-line][data-lang="ar"]')).toContainText('Kitchen');
  await kitchen.locator('[name$="[name][ar]"]').fill('مطبخ');
  await dialog.locator('.fa-seg-btn[data-lang="en"]').click();
  await list.locator('[data-repeater-add]').click();
  const added = list.locator('[data-repeater-item]').last();
  await added.locator('[name$="[name][en]"]').fill('Balcony');
  // A new item takes the switch's language too
  await dialog.locator('.fa-seg-btn[data-lang="ar"]').click();
  await expect(added.locator('[name$="[name][ar]"]')).toBeVisible();
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog).toBeHidden();
  await page.locator('#fa-admin-resource-edit-1').click();
  await dialog.locator('.fa-seg-btn[data-lang="en"]').click();
  await expect(dialog.locator('[data-field="rooms"] [data-repeater-title]')).toHaveText(['Master bedroom', 'Kitchen', 'Living room', 'Balcony']);
});

test('the Arabic panel: right to left, translated counts, the handle keys still move items', async ({ page }) => {
  await page.goto('/ar/admin/apartments/edit/1');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(rooms(page).locator('[data-repeater-count]')).toHaveText('3 من 6 كحد أقصى');
  await items(page).first().locator('[data-repeater-handle]').focus();
  await page.keyboard.press('ArrowDown');
  await expect(titles(page)).toHaveText(['Kitchen', 'Master bedroom', 'Living room']);
  await page.locator('[data-field="hours"] [data-repeater-add]').click();
  await expect(page.locator('[data-field="hours"] [data-repeater-item]')).toHaveCount(2);
});

test('on phones items stack and keep their tools', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/en/admin/apartments/edit/1');
  const box = await items(page).first().locator('[name$="[size]"]').boundingBox();
  expect(box!.width).toBeGreaterThan(250);
  await expect(items(page).first().locator('[data-repeater-action="remove"]')).toBeVisible();
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('the buttons send the form and the list changes without saving', async ({ page }) => {
    await page.goto('/en/admin/apartments/edit/1');
    await expect(items(page).first().locator('[data-repeater-handle]')).toBeHidden();
    await rooms(page).locator('[data-repeater-add]').click();
    await expect(items(page)).toHaveCount(4);
    await items(page).nth(3).locator('[name$="[name][en]"]').fill('Study');
    await items(page).nth(3).locator('[data-repeater-action="up"]').click();
    await expect(titles(page)).toHaveText(['Master bedroom', 'Kitchen', 'Study', 'Living room']);
    await items(page).first().locator('[data-repeater-action="remove"]').click();
    await expect(titles(page)).toHaveText(['Kitchen', 'Study', 'Living room']);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(titles(page)).toHaveText(['Kitchen', 'Study', 'Living room']);
  });
});
