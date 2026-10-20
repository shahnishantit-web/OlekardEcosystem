const fs = require('fs');
const path = require('path');
const { chromium } = require('../../wallet-ecosystem/apps/admin/node_modules/@playwright/test');
const api = 'http://127.0.0.1:8000';
const origin = 'http://localhost:3000';

(async () => {
  const login = await fetch(api + '/v1/auth/login', {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ identifier: 'admin@olekard.local', credential: 'Admin123!',
      credential_type: 'password', device_fingerprint: 'admin-web' }),
  });
  const token = (await login.json()).data?.access_token;
  if (!token) throw new Error(`Login HTTP ${login.status}`);
  const checks = [];
  async function get(uri) {
    const response = await fetch(api + uri, { headers: {
      Authorization: `Bearer ${token}`, Accept: 'application/json', Origin: origin,
    }, signal: AbortSignal.timeout(30000) });
    const cors = response.headers.get('access-control-allow-origin') === origin;
    checks.push({ uri, status: response.status, cors });
    if (!response.ok) throw new Error(`${uri}: HTTP ${response.status}`);
    return (await response.json()).data;
  }
  const keys = [];
  for (let page = 1, last = 1; page <= last; page++) {
    const result = await get(`/v1/admin/configuration/keys?include_inactive=true&per_page=50&page=${page}`);
    keys.push(...result.data);
    last = result.last_page ?? result.meta?.last_page ?? 1;
  }
  const results = [];
  console.log(`Checking ${keys.length} registered keys`);
  for (const row of keys) {
    const uri = '/v1/admin/configuration/keys/' + encodeURIComponent(row.config_key);
    try {
      const detail = await get(uri);
      const versions = await get(uri + '/versions?per_page=20');
      results.push({ key: row.config_key, domain: row.domain,
        matches: detail.config_key === row.config_key,
        valueUnchanged: JSON.stringify(detail.current_value) === JSON.stringify(row.current_value),
        versions: (versions.data || []).length });
    } catch (error) {
      results.push({ key: row.config_key, error: error.message.split('\n')[0] });
    }
    if (results.length % 25 === 0) console.log(`Checked ${results.length}/${keys.length} keys`);
  }
  const browserChecks = [];
  const report = { generatedAt: new Date().toISOString(), keyCount: keys.length, results, checks, browserChecks };
  const save = () => fs.writeFileSync(path.join(__dirname, 'configuration-key-audit.json'), JSON.stringify(report, null, 2));
  save();
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const page = await browser.newPage();
    await page.goto(origin + '/login');
    await page.locator('#email-input').fill('admin@olekard.local');
    await page.locator('#password-input').fill('Admin123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(url => !url.pathname.startsWith('/login'));
    for (const domain of [...new Set(keys.map(row => row.domain))]) {
      const row = keys.find(row => row.domain === domain);
      const prefix = api + '/v1/admin/configuration/keys/' + encodeURIComponent(row.config_key);
      const detailResponse = page.waitForResponse(r => r.url() === prefix, { timeout: 30000 });
      const historyResponse = page.waitForResponse(r => r.url().startsWith(prefix + '/versions?'), { timeout: 30000 });
      await page.goto(origin + `/configuration/${encodeURIComponent(domain)}/${encodeURIComponent(row.config_key)}`);
      const [detail, history] = await Promise.all([detailResponse, historyResponse]);
      browserChecks.push({ key: row.config_key, detail: detail.status(), history: history.status() });
    }
  } finally { await browser.close(); }
  save();
  const failures = results.filter(row => row.error || !row.matches || !row.valueUnchanged);
  const httpFailures = checks.filter(row => row.status !== 200 || !row.cors);
  console.log(JSON.stringify({ keys: keys.length, requests: checks.length, failures, httpFailures, browserChecks }, null, 2));
  if (failures.length || httpFailures.length || browserChecks.some(row => row.detail !== 200 || row.history !== 200)) process.exitCode = 1;
})().catch(error => { console.error(error.message.split('\n')[0]); process.exitCode = 1; });
