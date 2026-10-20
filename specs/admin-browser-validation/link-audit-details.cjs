const fs = require('fs');
const path = require('path');
const api = process.env.ADMIN_AUDIT_API || 'http://127.0.0.1:8000';
const readJson = name => {
  const bytes = fs.readFileSync(path.join(__dirname, name));
  return JSON.parse((bytes[0] === 255 && bytes[1] === 254 ? bytes.toString('utf16le') : bytes.toString('utf8')).replace(/^\uFEFF/, ''));
};
const samples = readJson('current-inventory.json').samples;
const links = [], checks = [];
function rows(data) {
  if (Array.isArray(data)) return data;
  if (!data || typeof data !== 'object') return [];
  for (const key of ['data', 'items', 'results', 'keys', 'requests', 'runs']) {
    const found = rows(data[key]);
    if (found.length) return found;
  }
  return [];
}
(async () => {
  const response = await fetch(api + '/v1/auth/login', { method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ identifier: 'admin@olekard.local', credential: 'Admin123!',
      credential_type: 'password', device_fingerprint: 'admin-web' }) });
  const login = await response.json();
  if (!login.data?.access_token) throw new Error(`Login HTTP ${response.status}`);
  const get = async uri => {
    try {
      const result = await fetch(api + uri, { headers: { Accept: 'application/json',
        Authorization: 'Bearer ' + login.data.access_token }, signal: AbortSignal.timeout(20000) });
      const body = await result.json().catch(() => ({}));
      checks.push({ uri, status: result.status, code: body.error?.code });
      return result.ok ? body.data : null;
    } catch (error) { checks.push({ uri, error: error.message.split('\n')[0] }); return null; }
  };
  for (const [sample, apiPath, uiPath] of [
    ['agent', '/v1/admin-app/agents/', '/agents/'],
    ['kyc', '/v1/admin-app/kyc/', '/kyc/'],
    ['batch', '/v1/reconciliation/batches/', '/reconciliation/batches/'],
    ['settlement', '/v1/reconciliation/settlements/', '/reconciliation/settlements/'],
    ['alert', '/v1/admin/security/alerts/', '/security/alerts/'],
    ['finding', '/v1/security/findings/', '/security/findings/'],
    ['case', '/v1/admin/support/cases/', '/support/cases/'],
  ]) {
    if (samples[sample] && await get(apiPath + samples[sample])) links.push({ href: uiPath + samples[sample], source: 'verified existing API record' });
  }
  for (const [uri, build] of [
    ['/v1/community/admin/organisations', row => ['/community/organisations/' + row.id, '/community/staff/' + row.id]],
    ['/v1/community/admin/orders', row => ['/community/orders/' + row.id]],
    ['/v1/community/admin/campaigns', row => ['/community/campaigns/' + row.id]],
    ['/api/v1/admin/payment/providers', row => ['/payment/providers/' + row.id, '/payment/providers/' + row.id + '/edit']],
    ['/v1/admin/configuration/change-requests', row => ['/configuration/approvals/' + row.id]],
    ['/v1/admin/configuration/keys', row => row.domain && row.config_key ? ['/configuration/' + encodeURIComponent(row.domain) + '/' + encodeURIComponent(row.config_key)] : []],
    ['/v1/qa/runs', row => ['/qa/runs/' + row.id]],
    ['/v1/reports/jobs', row => ['/reports/' + row.id]],
  ]) {
    for (const row of rows(await get(uri))) {
      if (typeof row.id !== 'string' && typeof row.key !== 'string' && typeof row.config_key !== 'string') continue;
      for (const href of build(row)) links.push({ href, source: uri });
    }
  }
  fs.writeFileSync(path.join(__dirname, 'link-audit-extra-links.json'), JSON.stringify({ checks, links }, null, 2));
  console.log(JSON.stringify({ checks: checks.length, extraLinks: links.length,
    unavailable: checks.filter(check => check.status !== 200) }, null, 2));
})().catch(error => { console.error(error.message.split('\n')[0]); process.exitCode = 1; });
