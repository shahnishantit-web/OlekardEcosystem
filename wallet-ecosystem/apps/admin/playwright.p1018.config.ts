import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
    testDir: './e2e', testMatch: 'p1018-security.spec.ts', timeout: 60000, workers: 1,
    reporter: [['list'], ['json', { outputFile: '../../../specs/042-fraud-risk-aml-dashboard/browser-results.json' }]],
    use: { baseURL: 'http://localhost:3018', ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 }, screenshot: 'only-on-failure' },
    webServer: { command: 'node node_modules/next/dist/bin/next dev -p 3018', url: 'http://localhost:3018/login', timeout: 120000, reuseExistingServer: false, env: { NEXT_PUBLIC_API_URL: 'http://localhost:3018/api' } },
});
