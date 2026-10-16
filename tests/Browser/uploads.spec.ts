import { test, expect, Page } from '@playwright/test';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6y1EAAAAASUVORK5CYII=', 'base64');
const dialog = (page: Page) => page.locator('dialog.fa-editor');
async function open(page: Page, lang = 'en') {
  await page.goto(`/${lang}/admin/assets`);
  await page.evaluate(() => { (window as any).uploadMarker = 'kept'; });
  await page.locator('#fa-admin-resource-edit-1').click(); await expect(dialog(page)).toBeVisible();
}
async function upload(page: Page, field = 'attachment', name = 'document.txt', buffer = Buffer.from('Property document')) {
  const response = page.waitForResponse(r => r.url().endsWith('/assets/upload'));
  await dialog(page).locator(`[data-upload-field=${field}]`).setInputFiles({ name, mimeType: field === 'cover' ? 'image/png' : 'text/plain', buffer });
  const result = await response; expect(result.status()).toBe(200);
  await expect(dialog(page).locator(`[name=${field}]`)).toHaveValue(/^@upload:/);
  await expect(dialog(page).locator('form[data-admin-draft]')).not.toHaveAttribute('data-upload-busy', 'true');
  return await result.json() as { value: string; url: string };
}

test('Reactive uploads preview, survive validation, save, replace, and remove without document reload', async ({ page }) => {
  await open(page);
  await upload(page, 'cover', 'cover.png', png);
  await expect(dialog(page).locator('.fa-upload-preview')).toBeVisible();
  await expect.poll(() => dialog(page).locator('.fa-upload-preview').evaluate((img: HTMLImageElement) => img.naturalWidth)).toBe(1);
  await upload(page);
  await dialog(page).locator('[name=title]').fill('');
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click();
  await expect(dialog(page).locator('[name=title]')).toHaveAttribute('aria-invalid', 'true');
  await expect(dialog(page).locator('[name=attachment]')).toHaveValue(/^@upload:/);
  await dialog(page).locator('[name=title]').fill('Media saved');
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click(); await expect(dialog(page)).not.toBeVisible();
  await page.locator('#fa-admin-resource-edit-1').click();
  const first = await dialog(page).locator('[name=attachment]').inputValue(); expect(first).toMatch(/^assets\/attachment\//);
  const link = dialog(page).locator('[data-field=attachment] [data-admin-file-link]');
  const storedUrl = (await link.getAttribute('href'))!; const download = await page.request.get(storedUrl); expect(await download.text()).toBe('Property document');
  expect(download.headers()['content-disposition']).toContain('attachment'); expect(download.headers()['x-content-type-options']).toBe('nosniff');
  await upload(page, 'attachment', 'new.txt', Buffer.from('Replacement document'));
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click(); await expect(dialog(page)).not.toBeVisible();
  await page.locator('#fa-admin-resource-edit-1').click(); expect(await dialog(page).locator('[name=attachment]').inputValue()).not.toBe(first);
  expect(await (await page.request.get(storedUrl)).text()).toBe('Replacement document');
  await dialog(page).locator('[data-field=attachment] [data-upload-remove]').check();
  await dialog(page).getByRole('button', { name: 'Save changes', exact: true }).click(); await expect(dialog(page)).not.toBeVisible();
  expect((await page.request.get(storedUrl)).status()).toBe(404);
  expect(await page.evaluate(() => (window as any).uploadMarker)).toBe('kept');
});

test('forged extensions, oversized documents, SVG, and image dimension violations are rejected', async ({ page }) => {
  await open(page);
  for (const [field, name, buffer] of [['attachment', 'fake.pdf', Buffer.from('plain text')], ['attachment', 'huge.txt', Buffer.alloc(129 * 1024, 'x')], ['cover', 'bad.svg', Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"/>')]] as const) {
    const response = page.waitForResponse(r => r.url().endsWith('/assets/upload'));
    await dialog(page).locator(`[data-upload-field=${field}]`).setInputFiles({ name, mimeType: 'application/octet-stream', buffer });
    expect((await response).status()).toBe(422);
    await expect(dialog(page).locator(`[data-upload-field=${field}]`)).toHaveAttribute('aria-invalid', 'true');
    await dialog(page).locator(`[data-upload-field=${field}]`).setInputFiles([]);
  }
});

test('standard multipart forms retain selections through validation and save without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({javaScriptEnabled:false}); const page = await context.newPage();
  try {
    await page.goto('http://127.0.0.1:8083/en/admin/assets/edit/1');
    await page.locator('[data-upload-field=attachment]').setInputFiles({name:'note.txt',mimeType:'text/plain',buffer:Buffer.from('No JS document')});
    await page.locator('[name=title]').fill(''); await page.getByRole('button',{name: 'Save and close',exact:true}).click();
    await expect(page.locator('[name=attachment]')).toHaveValue(/^@upload:/);
    await page.locator('[name=title]').fill('No JS saved');await page.getByRole('button',{name: 'Save and close',exact:true}).click();
    await page.goto('http://127.0.0.1:8083/en/admin/assets/edit/1');
    await expect(page.locator('[name=attachment]')).toHaveValue(/^assets\/attachment\//);
    expect(await (await page.request.get((await page.locator('[data-field=attachment] [data-admin-file-link]').getAttribute('href'))!)).text()).toBe('No JS document');
  } finally { await context.close(); }
});

test('standard async navigation submits multipart file bytes', async ({ page }) => {
  await page.goto('/en/admin/assets/edit/1');await page.evaluate(()=>{(window as any).uploadMarker='kept';});
  await page.locator('[data-upload-field=attachment]').setInputFiles({name:'note.txt',mimeType:'text/plain',buffer:Buffer.from('Async document')});
  await page.getByRole('button',{name: 'Save and close',exact:true}).click();await expect(page).toHaveURL(/\/admin\/assets$/);
  expect(await page.evaluate(()=>(window as any).uploadMarker)).toBe('kept');
  await page.goto('/en/admin/assets/edit/1');await expect(page.locator('[name=attachment]')).toHaveValue(/^assets\/attachment\//);
});

for (const lang of ['ar','ku']) test(`mobile ${lang} uploads fit viewport and retain RTL`, async ({page})=>{
  await page.setViewportSize({width:390,height:844});await open(page,lang);await upload(page,'cover','cover.png',png);
  await expect(page.locator('html')).toHaveAttribute('dir','rtl');expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
});

test('staging and downloads enforce permission revocation', async ({page})=>{
  await open(page);const result=await upload(page);
  await page.request.post('/__test/readonly');expect((await page.request.get(result.url)).status()).toBe(403);
  const response=page.waitForResponse(r=>r.url().endsWith('/assets/upload'));
  await dialog(page).locator('[data-upload-field=attachment]').setInputFiles({name:'other.txt',mimeType:'text/plain',buffer:Buffer.from('Other')});expect((await response).status()).toBe(403);
});

test('staging rejects a rotated CSRF token without saving a file', async ({page})=>{
  await open(page); await page.request.post('/__test/expire');
  const response=page.waitForResponse(r=>r.url().endsWith('/assets/upload'));
  await dialog(page).locator('[data-upload-field=attachment]').setInputFiles({name:'other.txt',mimeType:'text/plain',buffer:Buffer.from('Other')});
  expect((await response).status()).toBe(419);await expect(dialog(page).locator('[name=attachment]')).toHaveValue('');
});

test('staging rejects hidden/disabled fields and stale record operations', async ({page})=>{
  await open(page);
  const fields=await dialog(page).locator('form').evaluate((form:HTMLFormElement)=>({
    _placement:form.dataset.uploadPlacement!,_name:form.dataset.uploadAction!,_id:form.dataset.uploadId!,_operation:form.dataset.uploadOperation!,_token:form.dataset.uploadCsrf!,
    _draft:JSON.stringify({title:'Villa media',kind:'normal'})
  }));
  for(const field of ['internal','extra']) {
    const response=await page.request.post('/en/admin/assets/upload',{multipart:{...fields,_field:field,file:{name:'note.txt',mimeType:'text/plain',buffer:Buffer.from('Note')}}});expect(response.status()).toBe(403);
  }
  await page.request.post('/__test/asset-change',{form:{title:'Changed elsewhere'}});
  const stale=await page.request.post('/en/admin/assets/upload',{multipart:{...fields,_field:'attachment',file:{name:'note.txt',mimeType:'text/plain',buffer:Buffer.from('Note')}}});expect(stale.status()).toBe(409);
});
