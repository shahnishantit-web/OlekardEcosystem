/**
 * @jest-environment node
 *
 * US-P1-002-004 — Admin is forced to MFA enrollment gate (middleware layer).
 *
 * Verifies that middleware.ts:
 *  1. Redirects authenticated sessions with admin_mfa_setup=required to /mfa-enrollment.
 *  2. Does NOT redirect /mfa-enrollment itself (would cause an infinite loop).
 *  3. Does NOT redirect auth routes (/login, /forgot-password, etc.).
 *  4. Does NOT apply the MFA gate when the cookie is absent.
 *  5. Redirects unauthenticated requests to /login with callbackUrl.
 */
import { NextRequest } from 'next/server';
import { middleware }  from '@/middleware';

const BASE = 'http://localhost:3000';

function req(pathname: string, cookies: Record<string, string> = {}): NextRequest {
  const cookieHeader = Object.entries(cookies)
    .map(([k, v]) => `${k}=${v}`)
    .join('; ');
  return new NextRequest(new URL(pathname, BASE), {
    headers: cookieHeader ? { Cookie: cookieHeader } : {},
  });
}

const WITH_TOKEN: Record<string, string> = {
  admin_token: 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyLTAwMSIsInBlcm1pc3Npb25zIjpbXX0.sig',
};

describe('middleware — MFA enrollment gate (US-P1-002-004)', () => {
  it('redirects authenticated + admin_mfa_setup=required to /mfa-enrollment', async () => {
    const res = await middleware(req('/dashboard', {
      ...WITH_TOKEN,
      admin_mfa_setup: 'required',
    }));
    expect(res.status).toBe(307);
    expect(res.headers.get('location')).toContain('/mfa-enrollment');
  });

  it('redirects /users route to /mfa-enrollment when gate cookie is present', async () => {
    const res = await middleware(req('/users', {
      ...WITH_TOKEN,
      admin_mfa_setup: 'required',
    }));
    expect(res.status).toBe(307);
    expect(res.headers.get('location')).toContain('/mfa-enrollment');
  });

  it('allows /mfa-enrollment itself to pass through (prevents infinite redirect loop)', async () => {
    const res = await middleware(req('/mfa-enrollment', {
      ...WITH_TOKEN,
      admin_mfa_setup: 'required',
    }));
    // Must NOT redirect back to /mfa-enrollment
    const location = res.headers.get('location') ?? '';
    expect(location).not.toMatch(/\/mfa-enrollment/);
  });

  it('allows auth routes through despite admin_mfa_setup=required', async () => {
    for (const authPath of ['/login', '/forgot-password', '/reset-password', '/account-locked']) {
      const res = await middleware(req(authPath, {
        ...WITH_TOKEN,
        admin_mfa_setup: 'required',
      }));
      const location = res.headers.get('location') ?? '';
      expect(location).not.toMatch(/\/mfa-enrollment/);
    }
  });

  it('does not apply MFA gate when admin_mfa_setup cookie is absent', async () => {
    const res = await middleware(req('/dashboard', WITH_TOKEN));
    const location = res.headers.get('location') ?? '';
    expect(location).not.toMatch(/\/mfa-enrollment/);
  });

  it('does not apply MFA gate when admin_mfa_setup cookie has a different value', async () => {
    const res = await middleware(req('/dashboard', {
      ...WITH_TOKEN,
      admin_mfa_setup: 'complete', // any value other than 'required'
    }));
    const location = res.headers.get('location') ?? '';
    expect(location).not.toMatch(/\/mfa-enrollment/);
  });

  it('redirects unauthenticated requests to /login with callbackUrl', async () => {
    const res = await middleware(req('/dashboard'));
    expect(res.status).toBe(307);
    const location = res.headers.get('location') ?? '';
    expect(location).toContain('/login');
    expect(location).toContain('callbackUrl');
  });
});
