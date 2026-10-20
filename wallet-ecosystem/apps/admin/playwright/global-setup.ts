/**
 * Playwright global setup — runs once before all tests.
 * Fetches a single admin auth token and writes storageState (cookies)
 * so all tests can reuse it without hitting the API rate limiter.
 */

import * as https from 'https';
import * as http  from 'http';
import * as fs    from 'fs';
import * as path  from 'path';

const API_BASE  = 'http://127.0.0.1:8000';
const EMAIL     = 'admin@olekard.local';
const PASSWORD  = 'Admin123!';
const AUTH_FILE = path.join(__dirname, '.auth', 'admin.json');

interface LoginSuccess {
  success: true;
  data: {
    access_token: string;
    session_id:   string;
    expires_in:   number;
  };
}

function postJson(url: string, body: unknown): Promise<unknown> {
  return new Promise((resolve, reject) => {
    const payload  = JSON.stringify(body);
    const parsed   = new URL(url);
    const options  = {
      hostname: parsed.hostname,
      port:     parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
      path:     parsed.pathname + parsed.search,
      method:   'POST',
      headers:  {
        'Content-Type':   'application/json',
        'Content-Length': Buffer.byteLength(payload),
        'Accept':         'application/json',
      },
    };
    const transport = parsed.protocol === 'https:' ? https : http;
    const req = transport.request(options, (res) => {
      let data = '';
      res.on('data', (chunk: string) => { data += chunk; });
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch { reject(new Error(`Non-JSON response (${res.statusCode}): ${data.slice(0, 200)}`)); }
      });
    });
    req.on('error', reject);
    req.write(payload);
    req.end();
  });
}

export default async function globalSetup(): Promise<void> {
  console.log('\n[global-setup] Fetching admin auth token…');

  const response = await postJson(`${API_BASE}/v1/auth/login`, {
    identifier:         EMAIL,
    credential:         PASSWORD,
    credential_type:    'password',
    device_fingerprint: 'admin-web',
  }) as LoginSuccess;

  if (!response.success) {
    throw new Error(`[global-setup] Login failed: ${JSON.stringify(response)}`);
  }

  const { access_token, session_id, expires_in } = response.data;
  const userJson  = encodeURIComponent(JSON.stringify({ id: '', email: EMAIL, name: EMAIL }));
  // Playwright storageState uses Unix timestamp for expires (-1 = session cookie)
  const expiresAt = Math.floor(Date.now() / 1000) + expires_in;

  const storageState = {
    cookies: [
      { name: 'admin_token', value: access_token, domain: 'localhost', path: '/', expires: expiresAt, httpOnly: false, secure: false, sameSite: 'Lax' as const },
      { name: 'admin_user',  value: userJson,      domain: 'localhost', path: '/', expires: expiresAt, httpOnly: false, secure: false, sameSite: 'Lax' as const },
      { name: 'admin_sid',   value: session_id,    domain: 'localhost', path: '/', expires: expiresAt, httpOnly: false, secure: false, sameSite: 'Lax' as const },
    ],
    origins: [],
  };

  fs.writeFileSync(AUTH_FILE, JSON.stringify(storageState, null, 2));
  console.log(`[global-setup] Auth state saved → ${AUTH_FILE} (expires in ${expires_in}s)\n`);
}
