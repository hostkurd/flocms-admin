import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: 'tests/Browser', workers: 1,
  // On CI, failures also appear as annotations on the pull request.
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  use: { baseURL: 'http://127.0.0.1:8083', headless: true,
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {} },
  webServer: { command: 'FLO_ADMIN_TESTING=1 php -S 127.0.0.1:8083 tests/Browser/router.php', url: 'http://127.0.0.1:8083/en/admin/dashboard', reuseExistingServer: false },
});
