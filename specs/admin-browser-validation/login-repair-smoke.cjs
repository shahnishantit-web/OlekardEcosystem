const { chromium } = require('../../wallet-ecosystem/apps/admin/node_modules/@playwright/test');

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    const failures = [];
    page.on('requestfailed', request => {
      if (request.url().includes('/v1/auth/login')) failures.push(request.failure()?.errorText);
    });
    await page.goto('http://localhost:3000/login');
    await page.locator('#email-input').fill('admin@olekard.local');
    await page.locator('#password-input').fill('Admin123!');
    const loginResponse = page.waitForResponse(response => response.url().endsWith('/v1/auth/login')
      && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    const response = await loginResponse;
    if (response.status() !== 200) throw new Error(`Admin login HTTP ${response.status()}`);
    if (response.headers()['access-control-allow-origin'] !== 'http://localhost:3000') {
      throw new Error('Admin login response is missing the allowed browser origin');
    }
    await page.waitForURL(url => !url.pathname.startsWith('/login'), { timeout: 30000 });
    if (failures.length) throw new Error(failures.join(', '));
    console.log(JSON.stringify({ login: 'passed', cors: 'passed', destination: new URL(page.url()).pathname }));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
