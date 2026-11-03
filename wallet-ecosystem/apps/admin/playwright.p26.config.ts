import { defineConfig } from '@playwright/test';

const adminUrl = process.env.P26_ADMIN_URL ?? 'http://127.0.0.1:3036';

export default defineConfig({
  testDir: './e2e',
  testMatch: 'p26-free-journey.spec.ts',
  timeout: 180_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  outputDir: './test-results/p26-e2e',
  use: {
    actionTimeout: 15_000,
    baseURL: adminUrl,
    channel: 'chrome',
    headless: true,
    viewport: { width: 1440, height: 1000 },
    screenshot: 'off',
    video: 'off',
    trace: 'off',
  },
});
