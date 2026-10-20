import { defineConfig, devices } from '@playwright/test';
export default defineConfig({ testDir: './e2e', testMatch: 'p1020-integrations.spec.ts', timeout: 60000, workers: 1,
    reporter: [['list'], ['json', { outputFile: '../../../specs/044-integration-api-platform-management/browser-results.json' }]],
    use: { baseURL: 'http://localhost:3020', ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 }, screenshot: 'only-on-failure' },
    webServer: { command: 'node node_modules/next/dist/bin/next dev -p 3020', url: 'http://localhost:3020/login', timeout: 120000, reuseExistingServer: false, env: { NEXT_PUBLIC_API_URL: 'http://localhost:3020/api' } } });
