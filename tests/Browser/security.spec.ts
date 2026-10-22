import { test, expect, type Page } from '@playwright/test';

// Login security (0.35): setting up two-factor sign-in on the profile, the sign-in step,
// backup codes, a remembered device, a role that requires it, email links, RTL and phones.
type State = { two_factor: { user_id: number; failures: number }[]; devices: number; session: Record<string, unknown>; link: string | null; verified: number };

async function state(page: Page): Promise<State> {
  return (await page.request.post('/__test/security-state')).json();
}

async function codeFor(page: Page, key: string, offset = 0): Promise<string> {
  return (await (await page.request.post('/__test/totp', { form: { key, offset: String(offset) } })).json()).code;
}

/** Profile → Security: Set up, scan, first code. Returns the key and the backup codes. */
async function setUp(page: Page): Promise<{ key: string; codes: string[] }> {
  await page.goto('/en/admin/profile/security');
  await page.getByLabel('Current password').fill('secret-pass-1');
  await page.getByRole('button', { name: 'Set up' }).click();
  await expect(page.getByRole('heading', { name: 'Set up two-factor sign-in' })).toBeVisible();
  await expect(page.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible();
  const key = (await page.locator('.fa-two-factor-key code').textContent()) ?? '';
  expect(key).toMatch(/^[A-Z2-7]{4}( [A-Z2-7]{4})+$/);
  await page.getByLabel('Code from the app').fill(await codeFor(page, key));
  await page.getByRole('button', { name: 'Turn on' }).click();
  await expect(page.getByRole('heading', { name: 'Two-factor sign-in is on' })).toBeVisible();
  const codes = await page.locator('.fa-backup-codes li').allTextContents();
  expect(codes).toHaveLength(10);
  await page.getByRole('button', { name: "I've saved them" }).click();
  await expect(page.getByText('On since')).toBeVisible();
  return { key, codes };
}

test.beforeEach(async ({ page }) => { await page.request.post('/__test/security', { form: { verified: '1' } }); });

test('two-factor sign-in is set up on the profile and asked for at the next sign-in', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', message => { if (/Content Security Policy|Refused to/.test(message.text())) violations.push(page.url() + ' ' + message.text().slice(0, 60)); });
  const { key } = await setUp(page);
  await expect(page.getByText('10 of 10 left')).toBeVisible();

  await page.request.post('/__test/signin');
  await page.goto('/en/admin/dashboard');
  await expect(page).toHaveURL(/\/en\/admin\/two-factor$/);
  await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
  await expect(page.getByLabel('Authentication code')).toBeFocused();
  await expect(page.getByLabel('Authentication code')).toHaveAttribute('autocomplete', 'one-time-code');

  await page.getByLabel('Authentication code').fill('000000');
  await page.getByRole('button', { name: 'Verify and continue' }).click();
  await expect(page.getByText("That code doesn't match or was already used. 4 tries left.")).toBeVisible();
  await expect(page.getByLabel('Authentication code')).toHaveAttribute('aria-invalid', 'true');

  await page.getByLabel('Authentication code').fill(await codeFor(page, key, 1));
  await page.getByRole('button', { name: 'Verify and continue' }).click();
  await expect(page).toHaveURL(/\/en\/admin(\/dashboard)?\/?$/);
  expect((await state(page)).session.isloggedin).toBe(true);
  expect(violations).toEqual([]);
});

test('a backup code signs in once, and a remembered device skips the code', async ({ page }) => {
  const { key, codes } = await setUp(page);

  await page.request.post('/__test/signin');
  await page.goto('/en/admin/dashboard');
  await page.getByRole('link', { name: 'Use a backup code instead' }).click();
  await expect(page.getByRole('heading', { name: 'Use a backup code' })).toBeVisible();
  await page.getByLabel('Backup code', { exact: true }).fill(codes[0]);
  await page.getByRole('button', { name: 'Verify and continue' }).click();
  await expect(page).not.toHaveURL(/two-factor/);
  await page.goto('/en/admin/profile/security');
  await expect(page.getByText('9 of 10 left')).toBeVisible();

  await page.request.post('/__test/signin');
  await page.goto('/en/admin/dashboard');
  await page.getByLabel('Authentication code').fill(await codeFor(page, key, 1));
  await page.getByLabel('Remember this device for 30 days').check();
  await page.getByRole('button', { name: 'Verify and continue' }).click();
  await expect(page).not.toHaveURL(/two-factor/);
  expect((await state(page)).devices).toBe(1);

  await page.request.post('/__test/signin');
  await page.goto('/en/admin/dashboard');
  await expect(page).not.toHaveURL(/two-factor/);
  await page.goto('/en/admin/profile/security');
  await expect(page.locator('.fa-security-devices')).toContainText('This device');
  await page.locator('.fa-security-devices').getByRole('button', { name: /^Forget / }).click();
  await expect(page.getByText('No remembered devices.')).toBeVisible();
});

test('a role that requires it sets it up at sign-in, with the backup codes', async ({ page }) => {
  await page.request.post('/__test/security-require', { form: { required: '1' } });
  await page.request.post('/__test/signin');
  await page.goto('/en/admin/dashboard');
  await expect(page.getByRole('heading', { name: 'Set up two-factor sign-in' })).toBeVisible();
  await expect(page.getByText('Your role, Super Admin, requires it')).toBeVisible();
  const key = (await page.locator('.fa-two-factor-key code').textContent()) ?? '';
  await page.getByLabel('Code from the app').fill(await codeFor(page, key));
  await page.getByRole('button', { name: 'Turn on and continue' }).click();
  await expect(page.getByRole('heading', { name: 'Save your backup codes' })).toBeVisible();
  await expect(page.locator('.fa-backup-codes li')).toHaveCount(10);
  await expect(page.getByRole('button', { name: 'Copy the backup codes' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Download .txt' })).toHaveAttribute('download', 'backup-codes.txt');
  await page.getByRole('button', { name: "I've saved them, continue" }).click();
  await expect(page).not.toHaveURL(/two-factor/);
  await page.goto('/en/admin/profile/security');
  await expect(page.getByText("Your role requires two-factor sign-in, so it can't be turned off.")).toBeVisible();
  await expect(page.getByRole('button', { name: 'Turn off' })).toHaveCount(0);
});

test('an unconfirmed address shows the banner, and the emailed link confirms it', async ({ page }) => {
  await page.request.post('/__test/security', { form: { verified: '0' } });
  await page.goto('/en/admin/dashboard');
  const banner = page.locator('.fa-security-banner');
  await expect(banner).toContainText('Confirm your email address.');
  await banner.getByRole('button', { name: 'Send link' }).click();
  await expect(page.getByText('We sent you a link.')).toBeVisible();
  const { link } = await state(page);
  expect(link).toMatch(/^\/en\/admin\/verify\/link\/[A-Za-z0-9_-]{43}$/);
  await page.goto(link!);
  await expect(page.getByRole('heading', { name: 'Confirm your email address' })).toBeVisible();
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Email address confirmed' })).toBeVisible();
  expect((await state(page)).verified).toBe(1);
  await page.goto('/en/admin/dashboard');
  await expect(page.locator('.fa-security-banner')).toHaveCount(0);
});

test('the sign-in step in Arabic is right to left, and fits a phone', async ({ page }) => {
  const { key } = await setUp(page);
  await page.request.post('/__test/signin');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/ar/admin/dashboard');
  await expect(page).toHaveURL(/\/ar\/admin\/two-factor$/);
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('.fa-login-code')).toHaveAttribute('dir', 'ltr');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  expect(overflow).toBe(false);
  await page.locator('.fa-login-code').fill(await codeFor(page, key, 1));
  await page.locator('.fa-login-submit').click();
  await expect(page).not.toHaveURL(/two-factor/);
});

test('turning it off asks for the password or a code', async ({ page }) => {
  await setUp(page);
  await page.goto('/en/admin/profile/security');
  await page.locator('summary', { hasText: 'Turn off' }).click();
  await page.locator('#fa-security-disable').fill('wrong');
  await page.locator('.fa-security-confirm-body').getByRole('button', { name: 'Turn off' }).click();
  await expect(page.getByText("That password or code isn't right.")).toBeVisible();
  await page.locator('#fa-security-disable').fill('secret-pass-1');
  await page.locator('.fa-security-confirm-body').getByRole('button', { name: 'Turn off' }).click();
  await expect(page.getByText('Two-factor sign-in is off.')).toBeVisible();
  expect((await state(page)).two_factor).toHaveLength(0);
});
