const base = 'http://127.0.0.1:8000';
const login = await fetch(`${base}/v1/auth/login`, {
  method: 'POST',
  headers: { 'content-type': 'application/json', accept: 'application/json' },
  body: JSON.stringify({ identifier: 'event.creator@olekard.local', credential: 'LocalEventCreator2026!', credential_type: 'password', device_fingerprint: 'admin-web' }),
});
const loginBody = await login.json();
if (!login.ok || !loginBody.data?.access_token) throw new Error(`Creator login failed: ${loginBody.error?.code ?? login.status}`);
const token = loginBody.data.access_token;
const results = {};
for (const route of ['/api/v1/community/funeral/readiness', '/api/v1/community/funeral/organisations']) {
  const response = await fetch(`${base}${route}`, { headers: { authorization: `Bearer ${token}`, accept: 'application/json' } });
  const body = await response.json();
  results[route] = { status: response.status, success: body.success, data: body.data };
}
console.log(JSON.stringify({ permissions: JSON.parse(Buffer.from(token.split('.')[1], 'base64url').toString()).permissions.filter((p) => p.startsWith('community.funeral') || p.startsWith('configuration.policy')), routes: results }, null, 2));
await fetch(`${base}/v1/auth/logout`, { method: 'POST', headers: { authorization: `Bearer ${token}`, accept: 'application/json' } });
