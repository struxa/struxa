import { defineConfig, devices } from '@playwright/test';

const baseURL = (process.env.STRUXA_E2E_BASE_URL ?? process.env.STRUXA_SMOKE_BASE_URL ?? 'http://127.0.0.1:8080').replace(
  /\/$/,
  '',
);

export default defineConfig({
  testDir: 'e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
