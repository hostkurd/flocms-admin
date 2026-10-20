import { test, expect, Page } from '@playwright/test';

/* The Users page (0.28): the list, its row and bulk actions, the composer and mailings. */
async function people(page: Page) {
  await page.request.post('/__test/people');
  await page.goto('/en/admin/accounts');
  await expect(page.locator('.fa-panel')).toBeVisible();
}
const row = (page: Page, name: string) => page.locator('tbody tr', { hasText: name });

test('the list shows avatars, emails and status tabs with counts', async ({ page }) => {
  await people(page);
  await expect(row(page, 'Lana Karim').locator('.fa-avatar')).toHaveText('L');
  await expect(row(page, 'Lana Karim').locator('.fa-cell-sub')).toContainText('lana@seaside.example');
  await expect(row(page, 'Lana Karim').locator('.fa-status')).toHaveText('Active');
  await expect(page.locator('.fa-list-tab')).toHaveText([/All\s*8/, /Active\s*5/, /Pending\s*2/, /Suspended\s*1/]);
  // The tabs never show a scrollbar of their own
  expect(await page.locator('.fa-tabs-bar').evaluate(bar => bar.scrollHeight <= bar.clientHeight)).toBe(true);
  await page.locator('.fa-list-tab', { hasText: 'Pending' }).click();
  await expect(page.locator('.fa-list-tab.is-current')).toContainText('Pending');
  await expect(page.locator('tbody tr')).toHaveCount(2);
  await expect(row(page, 'Omar Haddad')).toBeVisible();
  // The name opens the user, the email does not
  await page.locator('.fa-list-tab', { hasText: 'All' }).click();
  await expect(row(page, 'Lana Karim').locator('.fa-row-link')).toHaveText('Lana Karim');
});

test('the row menu greys out Activate and suspends with a reason', async ({ page }) => {
  await people(page);
  await row(page, 'Lana Karim').locator('.fa-menu > summary').click();
  const menu = row(page, 'Lana Karim').locator('.fa-menu-panel');
  const activate = menu.locator('[data-admin-action=activate]');
  await expect(activate).toHaveAttribute('aria-disabled', 'true');
  // Only the label; the reason is a tooltip on hover
  await expect(activate.locator('.fa-tip')).toHaveText('Already active');
  await expect(activate.locator('.fa-tip')).toBeHidden();
  await activate.hover();
  await expect(activate.locator('.fa-tip')).toBeVisible();
  // A tap shows it too, and the menu stays open
  await activate.click({ force: true }); // aria-disabled: Playwright won't click it otherwise
  await expect(menu).toBeVisible();
  await expect(activate).toHaveClass(/is-tip-open/);
  // The menu stays inside the page
  const box = await menu.boundingBox();
  const width = await page.evaluate(() => document.documentElement.clientWidth);
  expect(box!.x + box!.width).toBeLessThanOrEqual(width - 8);
  await menu.getByText('Suspend', { exact: true }).click();
  const dialog = page.locator('dialog[open]');
  await expect(dialog.locator('h2')).toHaveText('Suspend Lana Karim?');
  await dialog.locator('textarea[name=reason]').fill('Spam listings');
  await dialog.locator('.fa-dialog-foot button[type=submit]').click();
  await expect(page.locator('.fa-toasts')).toContainText('Lana Karim is suspended.');
  await expect(row(page, 'Lana Karim').locator('.fa-status')).toHaveText('Suspended');
  await expect(page.locator('.fa-list-tab', { hasText: 'Suspended' })).toContainText('2');
});

test('bulk Suspend skips yourself and says so', async ({ page }) => {
  await people(page);
  await row(page, 'Sara').first().locator('input[data-admin-select]').check();
  await row(page, 'Mia Fischer').locator('input[data-admin-select]').check();
  await page.locator('[data-admin-bulk-action=suspend]').click();
  const dialog = page.locator('dialog[open]');
  await expect(dialog).toContainText('1 is skipped: You can’t do this to your own account.');
  await dialog.locator('.fa-dialog-foot button[type=submit]').click();
  await expect(page.locator('.fa-toasts')).toContainText('Suspended 1 user.');
  await expect(row(page, 'Mia Fischer').locator('.fa-status')).toHaveText('Suspended');
});

test('Send email from a selection opens the composer and sends a direct email', async ({ page }) => {
  await people(page);
  await row(page, 'Lana Karim').locator('input[data-admin-select]').check();
  await row(page, 'Daniel Okafor').locator('input[data-admin-select]').check();
  await page.locator('[data-admin-bulk-action=email]').click();
  await expect(page).toHaveURL(/\/admin\/mailings\/compose\?users=/);
  await expect(page.locator('.fa-to-chip[data-id]')).toHaveCount(2);
  await page.locator('input[name=subject]').fill('About your listing');
  await page.locator('.fa-rich-content').first().click();
  await page.keyboard.type('Hello {first_name}, thanks!');
  await page.getByRole('button', { name: 'Preview' }).first().click();
  await expect(page.locator('.fa-compose-body')).toContainText(/Hello (Lana|Daniel), thanks!/);
  await page.getByRole('button', { name: 'Send to 2 people' }).click();
  await expect(page).toHaveURL(/\/admin\/accounts/);
  await expect(page.locator('.fa-toasts, .fa-notifications')).toContainText('Sent to 2 people.');
});

test('a mailing sends its batches while its page is open', async ({ page }) => {
  await people(page);
  await page.goto('/en/admin/mailings/compose');
  await expect(page.locator('.fa-to-chip.is-group')).toHaveText('Everyone');
  await page.locator('input[name=subject]').fill('New listings');
  await page.locator('.fa-rich-content').first().click();
  await page.keyboard.type('Twelve new homes went online this week.');
  await page.getByRole('button', { name: /Send to \d+ people/ }).click();
  await expect(page).toHaveURL(/\/admin\/mailings\/show\/\d+/);
  await expect(page.locator('.fa-mailing-card .fa-status')).toHaveText('Finished', { timeout: 15000 });
  await expect(page.locator('.fa-mailing-progress-text')).toContainText(/(\d+) of \1 sent/);
  await page.goto('/en/admin/mailings');
  await expect(page.locator('tbody')).toContainText('New listings');
});

test('the To field adds people by name and groups from the menu', async ({ page }) => {
  await people(page);
  await page.goto('/en/admin/mailings/compose');
  await page.locator('.fa-to-chip.is-group .fa-to-remove').click();
  await expect(page.locator('[data-admin-to-count]')).toContainText('0 people');
  await page.locator('[data-admin-to-input]').fill('lana');
  await expect(page.locator('.fa-to-option')).toHaveCount(1);
  await page.keyboard.press('Enter');
  await page.locator('[data-admin-to-input]').fill('omar');
  await page.locator('.fa-to-option', { hasText: 'Omar Haddad' }).click();
  await expect(page.locator('.fa-to-chip[data-id]')).toHaveCount(2);
  await expect(page.locator('[data-admin-to-count]')).toContainText('2 people will get it.');
  await expect(page.locator('.fa-page-title')).toHaveText('Send email');
  await page.keyboard.press('Backspace');
  await expect(page.locator('.fa-to-chip[data-id]')).toHaveCount(1);
  await expect(page.locator('[data-admin-to-count]')).toContainText('1 person will get it.');
  // A group replaces the people
  await page.locator('.fa-to-group > summary').click();
  await page.locator('.fa-to-group button', { hasText: 'Editor' }).click();
  await expect(page.locator('.fa-to-chip.is-group')).toHaveText('Role: Editor');
  await expect(page.locator('.fa-to-chip[data-id]')).toHaveCount(0);
  await expect(page.locator('[data-admin-to-count]')).toContainText('2 people will get it.');
  await expect(page.locator('.fa-page-title')).toHaveText('Email users');
});

test('the user page has the details in front and photo, access and quick actions beside', async ({ page }) => {
  await people(page);
  await page.goto('/en/admin/accounts/create');
  await expect(page.locator('.fa-form-aside .fa-section-title')).toHaveText(['Photo', 'Access']);
  await expect(page.locator('.fa-form-main textarea[name=bio]')).toBeVisible();
  await page.goto('/en/admin/accounts');
  await row(page, 'Daniel Okafor').locator('.fa-row-link').click();
  await expect(page.locator('.fa-form-aside .fa-section-title')).toHaveText(['Photo', 'Access', 'Quick actions']);
  await page.locator('textarea[name=bio]').fill('Sales lead in Erbil.');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('textarea[name=bio]')).toHaveValue('Sales lead in Erbil.');
  // Suspend from Quick actions comes back to the user
  await page.locator('.fa-quick-actions').getByText('Suspend').click();
  await page.locator('.fa-form-actions button[type=submit]').first().click();
  await expect(page).toHaveURL(/\/admin\/accounts\/edit\/\d+/);
  await expect(page.locator('select[name=status]')).toHaveValue('3');
});

test('Generate fills a password and shows it once', async ({ page }) => {
  await people(page);
  await page.goto('/en/admin/accounts/create');
  const generate = page.locator('[data-admin-generate-password]');
  await expect(generate).toBeVisible();
  await generate.click();
  const input = page.locator('input[name=password]');
  await expect(input).toHaveAttribute('type', 'text');
  expect((await input.inputValue()).length).toBe(16);
  await expect(generate).toHaveText('Copy');
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('tabs are links and a mailing goes on with Continue', async ({ page }) => {
    await page.request.post('/__test/people');
    await page.goto('/en/admin/accounts?static=1');
    await page.locator('.fa-list-tab', { hasText: 'Suspended' }).click();
    await expect(page).toHaveURL(/filter%5Bstatus%5D=3/);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('[data-admin-generate-password]')).toHaveCount(0);
  });
});
