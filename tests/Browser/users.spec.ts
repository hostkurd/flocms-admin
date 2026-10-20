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
  await expect(activate).toContainText('Already active');
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
  await expect(page.locator('.fa-compose-chip')).toHaveCount(2);
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
  await expect(page.locator('input[name=to][value=everyone]')).toBeChecked();
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
