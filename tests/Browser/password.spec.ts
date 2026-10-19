import { test, expect, type Page } from '@playwright/test';

// Forgot password (0.26): request form, same answer, the emailed link, the new password, RTL and phones.
async function lastLink(page: Page): Promise<{ path: string | null; mails: number }> {
  return (await page.request.post('/__test/reset-link')).json();
}

test.beforeEach(async ({ page }) => { await page.request.post('/__test/password'); });

test('the login page links to the request form', async ({ page }) => {
  await page.goto('/en/admin/users/login');
  const link = page.getByRole('link', { name: 'Forgot password?' });
  await expect(link).toBeVisible();
  await link.click();
  await expect(page).toHaveURL(/\/en\/admin\/password\/forgot$/);
  await expect(page.getByRole('heading', { name: 'Reset your password' })).toBeVisible();
  await expect(page.getByLabel('Email address')).toBeFocused();
});

test('a request is answered the same way, and the link sets a new password', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', message => { if (/Content Security Policy|Refused to/.test(message.text())) violations.push(message.text()); });

  await page.goto('/en/admin/password/forgot');
  await page.getByLabel('Email address').fill('nobody@example.test');
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
  await expect(page.locator('.fa-login-text strong')).toHaveText('nobody@example.test');
  expect((await lastLink(page)).mails).toBe(0);

  await page.getByRole('link', { name: 'Use a different email' }).click();
  await page.getByLabel('Email address').fill('sara@example.test');
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
  const { path } = await lastLink(page);
  expect(path).toMatch(/^\/en\/admin\/password\/reset\/[A-Za-z0-9_-]{43}$/);

  await page.goto(path!);
  await expect(page.getByRole('heading', { name: 'Choose a new password' })).toBeVisible();
  await expect(page.getByText('sara@example.test')).toBeVisible();
  await page.getByLabel('New password', { exact: true }).fill('correct-horse');
  await page.getByLabel('Confirm new password').fill('correct-hors');
  await page.getByRole('button', { name: 'Save new password' }).click();
  await expect(page.getByText('The two passwords don’t match.')).toBeVisible();
  await expect(page.getByLabel('Confirm new password')).toHaveAttribute('aria-invalid', 'true');

  await page.getByLabel('New password', { exact: true }).fill('correct-horse');
  await page.getByRole('button', { name: 'Show' }).first().click();
  await expect(page.getByLabel('New password', { exact: true })).toHaveAttribute('type', 'text');
  await page.getByLabel('Confirm new password').fill('correct-horse');
  await page.getByRole('button', { name: 'Save new password' }).click();
  await expect(page).toHaveURL(/\/en\/admin\/users\/login$/);
  await expect(page.getByRole('status')).toHaveText('Your password has been changed. You can sign in now.');

  await page.goto(path!);
  await expect(page.getByRole('heading', { name: 'This link no longer works' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Request a new link' })).toHaveAttribute('href', '/en/admin/password/forgot');
  expect(violations).toEqual([]);
});

for (const [lang, width] of [['ar', 390], ['ku', 390], ['en', 390]] as const) test(`${lang} pages fit a ${width}px phone`, async ({ page }) => {
  await page.setViewportSize({ width, height: 844 });
  await page.goto(`/${lang}/admin/password/forgot`);
  await expect(page.locator('html')).toHaveAttribute('dir', lang === 'en' ? 'ltr' : 'rtl');
  await expect(page.locator('[name=email]')).toHaveAttribute('dir', 'ltr');
  await expect(page.locator('.fa-login-languages [aria-current=page]')).toHaveAttribute('lang', lang);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  await page.locator('[name=email]').fill('sara@example.test');
  await page.locator('.fa-login-submit').click();
  await page.goto((await lastLink(page)).path!.replace('/en/', `/${lang}/`));
  await expect(page.locator('[name=password]')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
});

test('the pages work without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  try {
    const page = await context.newPage();
    await page.request.post('http://127.0.0.1:8083/__test/password');
    await page.goto('http://127.0.0.1:8083/en/admin/password/forgot');
    await page.getByLabel('Email address').fill('not-an-email');
    await page.getByRole('button', { name: 'Send reset link' }).click();
    await expect(page.getByText('Enter a valid email address.')).toBeVisible();
    await page.getByLabel('Email address').fill('sara@example.test');
    await page.getByRole('button', { name: 'Send reset link' }).click();
    await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
    const path = (await (await page.request.post('http://127.0.0.1:8083/__test/reset-link')).json()).path;
    await page.goto('http://127.0.0.1:8083' + path);
    await expect(page.locator('[data-admin-password-toggle]').first()).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Save new password' })).toBeEnabled();
  } finally { await context.close(); }
});
