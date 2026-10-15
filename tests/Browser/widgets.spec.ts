import { test, expect, Page } from '@playwright/test';
async function open(page: Page, lang = 'en', standard = false) {
  await page.goto(`/${lang}/admin/dashboard`);
  await page.request.post('/__test/widgets', { form: { static: standard ? '1' : '0' } });
  await page.reload();
  await page.evaluate(() => { (window as any).widgetMarker = 'persistent'; });
}
const overview = (page: Page) => page.locator('[data-admin-widget]').filter({ has: page.getByRole('heading', { name: 'Content overview', exact: true }) });
const recent = (page: Page) => page.locator('[data-admin-widget]').filter({ has: page.getByRole('heading', { name: 'Recent posts', exact: true }) });

test('dashboard renders statistics, bounded translated records, bar/line charts and accessible data tables', async ({page}) => {
  await open(page);await expect(page.locator('[data-admin-widget]')).toHaveCount(4);
  await expect(overview(page).locator('.fa-stat-value').first()).toHaveText('8');
  await expect(recent(page).locator('tbody tr')).toHaveCount(3);
  await expect(page.locator('svg.fa-widget-chart')).toHaveCount(2);
  await expect(page.locator('.fa-chart-line')).toHaveCount(1);
  await expect(page.locator('svg.fa-widget-chart').first()).toHaveAttribute('role','img');
  await page.locator('.fa-chart-data summary').first().focus();await page.keyboard.press('Enter');
  await expect(page.locator('.fa-chart-data').first().locator('table')).toBeVisible();
  await expect(page.locator('.fa-chart-data').first().locator('tbody')).toContainText('Draft');
  const ids=await page.locator('[id]').evaluateAll(nodes=>nodes.map(n=>n.id));expect(new Set(ids).size).toBe(ids.length);
});

test('manual refresh reads current data without reloading and permission revocation removes all widget data', async ({page})=>{
  await open(page);await page.request.post('/__test/widget-add');await page.locator('#fa-dashboard-refresh').click();
  await expect(overview(page).locator('.fa-stat-value').first()).toHaveText('9');await expect(recent(page)).toContainText('Fresh widget post');
  expect(await page.evaluate(()=>(window as any).widgetMarker)).toBe('persistent');
  await page.request.post('/__test/readonly');await page.locator('#fa-dashboard-refresh').click();
  await expect(page.locator('[data-admin-widget]')).toHaveCount(0);await expect(page.locator('.fa-content')).not.toContainText('Fresh widget post');
});

test('View all links use ordinary resource navigation and back returns to widgets', async ({page})=>{
  await open(page);await recent(page).getByRole('link',{name:'View all records'}).click();await expect(page).toHaveURL(/\/admin\/widget-posts$/);
  await page.goBack();await expect(page.locator('[data-admin-widget]')).toHaveCount(4);expect(await page.evaluate(()=>(window as any).widgetMarker)).toBe('persistent');
});

test('dashboard renders and refreshes with JavaScript disabled', async ({browser})=>{
  const context=await browser.newContext({javaScriptEnabled:false});const page=await context.newPage();
  try {
    await page.goto('http://127.0.0.1:8083/en/admin/dashboard');await page.request.post('/__test/widgets');await page.reload();
    await expect(page.locator('[data-admin-widget]')).toHaveCount(4);await page.request.post('/__test/widget-add');await page.locator('#fa-dashboard-refresh').click();
    await expect(overview(page).locator('.fa-stat-value').first()).toHaveText('9');
  } finally {await context.close();}
});

test('reactive=false keeps dashboard widgets and native refresh', async ({page})=>{
  await open(page,'en',true);await expect(page.locator('[data-rx-component]')).toHaveCount(0);await page.request.post('/__test/widget-add');await page.locator('#fa-dashboard-refresh').click();await expect(overview(page).locator('.fa-stat-value').first()).toHaveText('9');
});

for(const lang of ['ar','ku']) test(`mobile ${lang} widgets, chart tables and long labels fit the viewport`,async({page})=>{
  await page.setViewportSize({width:390,height:844});await open(page,lang);await expect(page.locator('html')).toHaveAttribute('dir','rtl');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await page.locator('.fa-chart-data summary').first().click();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await expect(recent(page)).toContainText(lang==='ar'?'منزل':'خانوو');
});

test('widget charts comply with the strict CSP and contain no inline scripts or styles',async({page})=>{
  const violations:string[]=[];await page.addInitScript(()=>{document.addEventListener('securitypolicyviolation',event=>{(window as any).widgetCsp=(window as any).widgetCsp||[];(window as any).widgetCsp.push(event.violatedDirective);});});
  await open(page);await expect(page.locator('[data-admin-widget] script,[data-admin-widget] [style]')).toHaveCount(0);
  expect(await page.evaluate(()=>(window as any).widgetCsp||[])).toEqual(violations);
});
