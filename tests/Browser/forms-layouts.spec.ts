import { test, expect, Page } from '@playwright/test';
const editor = (page: Page) => page.locator('dialog.fa-editor');
async function open(page: Page, language = 'en') {
  await page.goto(`/${language}/admin/properties`);
  await page.evaluate(() => { (window as any).formsMarker = 'kept'; });
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(editor(page)).toBeVisible();
  await expect(editor(page).getByRole('tab', { name: 'Details', exact: true })).toHaveAttribute('aria-selected', 'true');
}
async function tab(page: Page, name: string) { await editor(page).getByRole('tab', { name, exact: true }).click(); }
async function idle(page: Page) { await expect(page.locator('[data-rx-component]')).toHaveAttribute('aria-busy', 'false'); }

test('sections and tabs keep fields reachable by keyboard without document reloads', async ({ page }) => {
  await open(page);
  await expect(editor(page).getByRole('heading', { name: 'Property details' })).toBeVisible();
  const details = editor(page).getByRole('tab', { name: 'Details', exact: true });
  await details.focus(); await page.keyboard.press('ArrowRight');
  await expect(editor(page).getByRole('tab', { name: 'Location', exact: true })).toBeFocused();
  await expect(editor(page).locator('[name=country]')).toBeVisible();
  await page.keyboard.press('End');
  await expect(editor(page).getByRole('tab', { name: 'Publishing', exact: true })).toBeFocused();
  await page.keyboard.press('Home'); await expect(details).toBeFocused();
  expect(await page.evaluate(() => (window as any).formsMarker)).toBe('kept');
});

test('live country changes clear invalid cities while retaining unrelated drafts and current tab', async ({ page }) => {
  await open(page);
  await editor(page).locator('[name="title[en]"]').fill('Unsaved villa');
  await tab(page, 'Location');
  await editor(page).locator('[name=country]').selectOption('gr');
  await expect(editor(page).locator('[name=city] option[value=athens]')).toHaveCount(1);
  await idle(page);
  await expect(editor(page).locator('[name=city]')).toHaveValue('');
  await expect(editor(page).getByRole('tab', { name: 'Location', exact: true })).toHaveAttribute('aria-selected', 'true');
  await editor(page).locator('[name=city]').selectOption('athens');
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await expect(page.locator('tbody')).toContainText('Unsaved villa');
  await page.locator('#fa-admin-resource-edit-1').click();
  await tab(page, 'Location'); await expect(editor(page).locator('[name=city]')).toHaveValue('athens');
  expect(await page.evaluate(() => (window as any).formsMarker)).toBe('kept');
});

test('conditional fields switch without saving and validation opens the affected tab', async ({ page }) => {
  await open(page); await tab(page, 'Pricing');
  await expect(editor(page).locator('[name=price]')).toBeVisible();
  await editor(page).locator('[name=listing_type]').selectOption('rent');
  await expect(editor(page).locator('[name=lease_months]')).toBeVisible(); await idle(page);
  await expect(editor(page).locator('[name=price]')).toHaveCount(0);
  await tab(page, 'Details');
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page).getByRole('tab', { name: 'Pricing', exact: true })).toHaveAttribute('aria-selected', 'true');
  await expect(editor(page).locator('[name=lease_months]')).toBeFocused();
  await editor(page).locator('[name=lease_months]').fill('12');
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
});

test('large translated descriptions save in the modal and reopen without fallback', async ({ page }) => {
  await open(page);
  const text = 'خانوو '.repeat(2000);
  await editor(page).locator('[name="description[en]"]').fill(text);
  await editor(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(editor(page)).not.toBeVisible();
  await page.locator('#fa-admin-resource-edit-1').click();
  await expect(editor(page).locator('[name="description[en]"]')).toHaveValue(text.trim());
  await expect(editor(page).locator('[data-admin-full-page]')).not.toBeVisible();
});

test('standard forms retain all sections and dependency refresh with JavaScript disabled', async ({ browser }) => {
  const context = await browser.newContext({javaScriptEnabled: false});
  try {
    const page=await context.newPage(); await page.goto('http://127.0.0.1:8083/en/admin/properties/edit/1');
    await expect(page.locator('[name="title[en]"]')).toBeVisible();
    await expect(page.locator('[name=country]')).toBeVisible(); await expect(page.locator('[name=price]')).toBeVisible();
    await page.locator('[name=country]').selectOption('gr');
    await page.getByRole('button', {name:'Update fields',exact:true}).click();
    await expect(page.locator('[name=city] option[value=athens]')).toHaveCount(1);
    await page.locator('[name=city]').selectOption('athens');
    await page.getByRole('button', {name:'Save changes',exact:true}).click();
    await expect(page.locator('tbody')).toContainText('athens');
  } finally { await context.close(); }
});

for(const language of ['ar','ku'])test(`mobile ${language} tab navigation respects RTL and fits the viewport`,async({page})=>{
  await page.setViewportSize({width:390,height:844}); await open(page,language);
  await editor(page).getByRole('tab',{name:'Details',exact:true}).focus(); await page.keyboard.press('ArrowLeft');
  await expect(editor(page).getByRole('tab',{name:'Location',exact:true})).toBeFocused();
  await editor(page).locator('[name=country]').selectOption('gr'); await expect(editor(page).locator('[name=city] option[value=athens]')).toHaveCount(1);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  const box=await editor(page).boundingBox(); expect(box!.width).toBeLessThanOrEqual(390);
});

test('custom and bulk action dialogs reuse dependent fields without reloading', async ({ page }) => {
  await page.goto('/en/admin/properties');
  await page.evaluate(() => { (window as any).formsMarker = 'actions'; });
  await page.locator('#fa-admin-resource-relocate-1').click();
  await editor(page).locator('[name=country]').selectOption('gr');
  await expect(editor(page).locator('[name=city] option[value=athens]')).toHaveCount(1); await idle(page);
  await editor(page).locator('[name=city]').selectOption('athens');
  await editor(page).getByRole('button', {name:'Relocate',exact:true}).click();
  await expect(editor(page)).not.toBeVisible(); await expect(page.locator('tbody')).toContainText('athens');
  await page.locator('[data-admin-select][value="1"]').check(); await idle(page);
  await page.locator('[data-admin-bulk-name]').selectOption('relocate');
  await page.getByRole('button',{name:'Review action',exact:true}).click();
  await editor(page).locator('[name=country]').selectOption('iq');
  await expect(editor(page).locator('[name=city] option[value=erbil]')).toHaveCount(1); await idle(page);
  await editor(page).locator('[name=city]').selectOption('erbil');
  await editor(page).getByRole('button',{name:'Relocate',exact:true}).click();
  await expect(editor(page)).not.toBeVisible(); await expect(page.locator('tbody')).toContainText('erbil');
  expect(await page.evaluate(() => (window as any).formsMarker)).toBe('actions');
});

test('translated validation reveals its tab before opening the language dialog', async ({page}) => {
  await open(page);
  await editor(page).locator('[data-field=title] .fa-lang-badge').click();
  await editor(page).locator('[name="title[ar]"]').fill('x'.repeat(191));
  await editor(page).locator('[data-field=title] [data-dialog-save]').click();
  await tab(page,'Location');
  await editor(page).getByRole('button',{name:'Save changes',exact:true}).click();
  await expect(editor(page).getByRole('tab',{name:'Details',exact:true})).toHaveAttribute('aria-selected','true');
  await expect(editor(page).locator('[data-field=title] .fa-lang-dialog')).toBeVisible();
  await expect(editor(page).locator('[name="title[ar]"]')).toBeFocused();
});

test('standard dependency refresh preserves focus and document identity',async({page})=>{
  await page.goto('/en/admin/properties/edit/1'); await page.evaluate(()=>{(window as any).formsMarker='standard';});
  await page.getByRole('tab',{name:'Location',exact:true}).click();
  await page.locator('[name=country]').focus(); await page.locator('[name=country]').selectOption('gr');
  await expect(page.locator('[name=city] option[value=athens]')).toHaveCount(1);
  await expect(page.locator('[name=country]')).toBeFocused();
  expect(await page.evaluate(()=>(window as any).formsMarker)).toBe('standard');
});
