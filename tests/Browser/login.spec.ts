import { test, expect } from '@playwright/test';

test('login theme has native credential fields, CSRF and an accessible password toggle', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', message => { if (/Content Security Policy|Refused to execute/.test(message.text())) violations.push(message.text()); });
  await page.goto('/en/__login-preview');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await expect(page.getByLabel('Email address')).toHaveAttribute('autocomplete', 'username');
  await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('autocomplete', 'current-password');
  await expect(page.locator('input[name="_token"]')).toHaveCount(1);
  await page.getByLabel('Password', { exact: true }).fill('local-test-password');
  await page.getByRole('button', { name: 'Show', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Hide', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'text');
  await page.getByRole('button', { name: 'Hide', exact: true }).click();
  await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'password');
  await expect(page.locator('.fa-login-form')).toHaveAttribute('action', '/en/admin/users/login');
  await expect(page.locator('script[src*="reactive-v"]')).toHaveCount(0);
  expect(violations).toEqual([]);
});

for (const lang of ['ar', 'ku']) test(`mobile ${lang} login is translated and fits the viewport`, async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/${lang}/__login-preview`);
  await expect(page.locator('.fa-login')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('html')).toHaveAttribute('lang', lang);
  await expect(page.locator('[name=email]')).toHaveAttribute('dir', 'ltr');
  await expect(page.locator('.fa-login-form')).toHaveAttribute('action', `/${lang}/admin/users/login`);
  await expect(page.locator('.fa-login-languages [aria-current=page]')).toHaveAttribute('lang', lang);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

test('login form remains usable without JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  try {
    const page = await context.newPage();
    await page.goto('http://127.0.0.1:8083/en/__login-preview');
    await expect(page.getByLabel('Email address')).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeEnabled();
    await expect(page.locator('[data-admin-password-toggle]')).not.toBeVisible();
  } finally { await context.close(); }
});
