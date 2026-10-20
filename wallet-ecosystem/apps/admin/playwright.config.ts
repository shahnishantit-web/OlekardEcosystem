import { defineConfig, devices } from '@playwright/test';
import * as path from 'path';

export default defineConfig({
  testDir:    './e2e',
  timeout:    30_000,
  retries:    0,
  reporter:   [['list'], ['json', { outputFile: 'e2e/results.json' }]],
  globalSetup: path.join(__dirname, 'playwright', 'global-setup.ts'),
  use: {
    baseURL:       'http://localhost:3000',
    headless:      true,
    screenshot:    'only-on-failure',
    video:         'off',
    // All tests start with the pre-fetched admin session
    storageState:  path.join(__dirname, 'playwright', '.auth', 'admin.json'),
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
