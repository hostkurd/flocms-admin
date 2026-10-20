import { test, expect } from '@playwright/test';

/* Row menus with the scrollbars desktop browsers show (Windows and Linux: 15–17 px);
 * headless Chromium hides them unless told not to. */
test.use({ launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined, ignoreDefaultArgs: ['--hide-scrollbars'] } });

test('a row menu opens in its place without moving', async ({ page }) => {
  // A short window: the open menu reaches past the page, which used to bring up a scrollbar and shift it.
  await page.setViewportSize({ width: 1100, height: 620 });
  await page.request.post('/__test/people');
  await page.goto('/en/admin/accounts');
  await page.locator('.fa-list-tab', { hasText: 'Suspended' }).click();
  await expect(page.locator('tbody tr')).toHaveCount(1);
  expect(await page.evaluate(() => document.documentElement.scrollHeight <= document.documentElement.clientHeight)).toBe(true);
  await page.evaluate(() => {
    const seen: string[] = [];
    (window as unknown as { seen: string[] }).seen = seen;
    const start = performance.now();
    const tick = () => {
      const panel = document.querySelector<HTMLElement>('details.fa-menu[open] > .fa-menu-panel');
      if (panel && getComputedStyle(panel).visibility !== 'hidden') {
        const r = panel.getBoundingClientRect();
        const at = `${Math.round(r.left)},${Math.round(r.top)}`;
        if (seen[seen.length - 1] !== at) seen.push(at);
      }
      if (performance.now() - start < 1500) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });
  await page.locator('tbody tr').last().locator('.fa-menu > summary').click();
  await page.waitForTimeout(1200);
  const seen = await page.evaluate(() => (window as unknown as { seen: string[] }).seen);
  expect(seen).toHaveLength(1);
});
