import { test, expect, Page } from '@playwright/test';

/* Relation fields (fixture: tests/Fixtures/Relations): country → city (dependent), agent (80 rows: server search), features (chips, pivot). */
const search = (page: Page, name: string) => page.locator(`#f-${name}-search`);

async function pick(page: Page, name: string, typed: string, option: string) {
  await search(page, name).click();
  await search(page, name).fill(typed);
  await page.locator(`#f-${name}-listbox [role="option"]`, { hasText: option }).first().click();
}

test('dependent city, server search and chips save together', async ({ page }) => {
  await page.goto('/en/admin/homes/edit/1');
  await expect(search(page, 'city_id')).toHaveValue('Erbil');
  await expect(page.locator('.fa-relation-chip')).toHaveText(['Garden×', 'Pool×']);

  // A new country clears a city of the old one and offers its own cities
  await pick(page, 'country_id', 'gre', 'Greece');
  await expect(search(page, 'city_id')).toHaveValue('');
  await search(page, 'city_id').click();
  await expect(page.locator('#f-city_id-listbox [role="option"]')).toHaveText(['Athens', 'Thessaloniki']);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await expect(search(page, 'city_id')).toHaveValue('Thessaloniki');

  // 80 agents: typing asks the server
  const lookup = page.waitForRequest(request => request.url().includes('/lookup/agent_id') && request.url().includes('q=rebin'));
  await search(page, 'agent_id').fill('rebin agent 7');
  await lookup;
  await page.locator('#f-agent_id-listbox [role="option"]', { hasText: 'Rebin Agent 7' }).click();

  // Chips: remove Pool with its ×, add Sea view
  await page.getByRole('button', { name: 'Remove Pool' }).click();
  await pick(page, 'features', 'sea', 'Sea view');
  await expect(page.locator('.fa-relation-chip')).toHaveText(['Garden×', 'Sea view×']);
  await expect(page.locator('[data-admin-record-state]')).toHaveText('Unsaved changes');

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText('Homes saved.').or(page.getByText(/saved/))).toBeVisible();
  await page.reload();
  await expect(search(page, 'country_id')).toHaveValue('Greece');
  await expect(search(page, 'city_id')).toHaveValue('Thessaloniki');
  await expect(search(page, 'agent_id')).toHaveValue('Rebin Agent 7');
  await expect(page.locator('.fa-relation-chip')).toHaveText(['Garden×', 'Sea view×']);
});

test('keyboard: arrows, Escape and Backspace; at most three features', async ({ page }) => {
  await page.goto('/en/admin/homes/edit/1');
  await search(page, 'features').click();
  await expect(page.locator('#f-features-search')).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  await expect(page.locator('#f-features-search')).toHaveAttribute('aria-expanded', 'false');
  await page.keyboard.press('Backspace');
  await expect(page.locator('.fa-relation-chip')).toHaveText(['Garden×']);
  await pick(page, 'features', 'park', 'Parking');
  await pick(page, 'features', 'bal', 'Balcony');
  await expect(page.locator('#f-features-search')).toHaveAttribute('placeholder', 'At most 3');
  await search(page, 'features').click();
  await expect(page.locator('#f-features-listbox')).toContainText('At most 3');
});

test('right to left: Kurdish titles and keyboard choice', async ({ page }) => {
  await page.goto('/ku/admin/homes/edit/1');
  await expect(search(page, 'city_id')).toHaveValue('هەولێر');
  await search(page, 'city_id').click();
  // Sorted by their Kurdish names: سلێمانی comes first
  await expect(page.locator('#f-city_id-listbox [role="option"]')).toHaveText(['سلێمانی', 'هەولێر']);
  await page.keyboard.press('Home');
  await page.keyboard.press('Enter');
  await expect(search(page, 'city_id')).toHaveValue('سلێمانی');
  const list = page.locator('#f-features-listbox');
  await search(page, 'features').click();
  const box = await list.boundingBox();
  const field = await page.locator('#f-features-search').locator('xpath=ancestor::div[contains(@class,"fa-relation-chips")]').boundingBox();
  expect(Math.abs(box!.x + box!.width - (field!.x + field!.width))).toBeLessThan(2);
});

test('right to left: Latin titles stay on the right, like other fields', async ({ page }) => {
  await page.goto('/ku/admin/homes/edit/1');
  await search(page, 'agent_id').fill('rebin agent 7');
  const option = page.locator('#f-agent_id-listbox [role="option"]', { hasText: 'Rebin Agent 7' });
  // The option's text ends at the option's right edge (minus padding), not at its left
  const gap = await option.evaluate(element => {
    const range = document.createRange();
    range.selectNodeContents(element.firstChild!);
    return element.getBoundingClientRect().right - range.getBoundingClientRect().right;
  });
  expect(gap).toBeLessThan(14);
  await option.click();
  await expect(search(page, 'agent_id')).toHaveValue('Rebin Agent 7');
  await expect(search(page, 'agent_id')).toHaveCSS('text-align', 'right');
});

test('relation filters narrow the list and show chips', async ({ page }) => {
  await page.goto('/en/admin/homes');
  await page.locator('[id$="-filters-menu"] summary').click();
  const features = page.locator('[id$="filter-features-search"]');
  await features.click();
  await page.locator('[id$="filter-features-listbox"] [role="option"]', { hasText: 'Parking' }).click();
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Apply filters' }).click();
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody')).toContainText('Flat');
  await expect(page.locator('.fa-chip')).toContainText('Parking');
});

test('Reactive updates rebuild the controls: a dialog keeps its features through an error', async ({ page }) => {
  await page.goto('/en/admin/home-dialogs');
  await page.locator('#fa-admin-resource-edit-1').click();
  const dialog = page.locator('dialog.fa-editor[open]');
  await expect(dialog.locator('.fa-relation-chip')).toHaveText(['Garden×', 'Pool×']);
  await dialog.locator('[name="title"]').fill('');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog.getByText('Title is required.')).toBeVisible();
  // The update morphed the dialog: the chips and search box are back, not the plain select
  await expect(dialog.locator('.fa-relation-chip')).toHaveText(['Garden×', 'Pool×']);
  await expect(dialog.locator('select[name="features[]"]')).toBeHidden();
  await dialog.locator('[id$="f-features-search"]').fill('sea');
  await dialog.locator('[id$="f-features-listbox"] [role="option"]', { hasText: 'Sea view' }).click();
  await dialog.locator('[name="title"]').fill('Villa');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.locator('dialog.fa-editor[open]')).toHaveCount(0);
  await expect(page.locator('tbody')).toContainText('Garden, Pool');
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(page.locator('dialog.fa-editor[open] .fa-relation-chip')).toHaveText(['Garden×', 'Pool×', 'Sea view×']);
});

test('in a dialog the list floats over it: no scroll bar, nothing cut off', async ({ page }) => {
  await page.setViewportSize({ width: 1100, height: 560 });
  await page.goto('/en/admin/home-dialogs');
  await page.locator('#fa-admin-resource-edit-1').click();
  const dialog = page.locator('dialog.fa-editor[open]');
  const body = dialog.locator('.fa-dialog-body');
  await expect(body).toBeVisible();
  expect((await body.boundingBox())!.height).toBeGreaterThanOrEqual(149);
  const scrollable = () => body.evaluate(element => element.scrollHeight > element.clientHeight + 1);
  const before = await scrollable();
  await dialog.locator('[id$="f-city_id-search"]').click();
  const list = dialog.locator('[id$="f-city_id-listbox"]');
  await expect(list.locator('[role="option"]').first()).toBeVisible();
  expect(await scrollable()).toBe(before);
  await expect(list).toHaveCSS('position', 'fixed');
  const box = (await list.boundingBox())!;
  const field = (await dialog.locator('[id$="f-city_id-search"]').boundingBox())!;
  expect(box.y + box.height).toBeLessThanOrEqual(560);
  expect(Math.abs(box.x - field.x)).toBeLessThan(2);
  // Every option can be chosen with the mouse
  await list.locator('[role="option"]').last().click();
  await expect(list).toBeHidden();
});

test('a relation filter still works after the list updates', async ({ page }) => {
  await page.goto('/en/admin/homes');
  await page.locator('[id$="-filters-menu"] summary').click();
  await page.locator('[id$="filter-features-search"]').click();
  await page.locator('[id$="filter-features-listbox"] [role="option"]', { hasText: 'Parking' }).click();
  await page.getByRole('button', { name: 'Apply filters' }).click();
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await page.locator('[id$="-filters-menu"] summary').click();
  await expect(page.locator('[id$="filter-features-search"]')).toBeVisible();
  await expect(page.locator('[id$="-filters-menu"] .fa-relation-chip')).toHaveText(['Parking×']);
  await page.getByRole('button', { name: 'Apply filters' }).click();
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('.fa-chip')).toContainText('Parking');
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('native selects edit and save the relations', async ({ page }) => {
    await page.goto('/en/admin/homes/edit/1');
    await expect(page.locator('select[name="city_id"]')).toBeVisible();
    await page.locator('select[name="features[]"]').selectOption(['4', '6']);
    await page.locator('select[name="agent_id"]').selectOption({ label: 'Aram Agent 1' });
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.goto('/en/admin/homes/edit/1');
    const chosen = await page.locator('select[name="features[]"] option:checked').allTextContents();
    expect(chosen.sort()).toEqual(['Fireplace', 'Parking']);
    await expect(page.locator('select[name="agent_id"] option:checked')).toHaveText('Aram Agent 1');
  });
});
