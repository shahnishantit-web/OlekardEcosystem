/** @jest-environment node */
import { NextRequest } from 'next/server';
import { middleware } from '@/middleware';
import { readTokenCookie, tokenCookieParts, TOKEN_CHUNK_SIZE } from '@/lib/auth/token-cookies';
import { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

describe('full permission sessions and scoped FX failures', () => {
  afterEach(() => jest.restoreAllMocks());
  const token = 'e30.' + Buffer.from(JSON.stringify({ sub: 'qa', permissions: ['security.events.read'], padding: 'x'.repeat(6500) })).toString('base64url') + '.signature';
  it('restores a large token identically across middleware and server readers', async () => {
    const parts = tokenCookieParts(token);
    expect(parts.every(p => p.value.length <= TOKEN_CHUNK_SIZE)).toBe(true);
    const request = new NextRequest('http://localhost/security/events', { headers: { cookie: parts.map(p => `${p.name}=${p.value}`).join('; ') } });
    expect(readTokenCookie(request.cookies)).toBe(token);
    expect((await middleware(request)).headers.get('location')).toBeNull();
    request.cookies.set('admin_mfa_setup', 'required');
    expect((await middleware(request)).headers.get('location')).toContain('/mfa-enrollment');
  });
  it('fails closed on a missing chunk and preserves permission denial', async () => {
    const parts = tokenCookieParts(token).filter(p => p.name !== 'admin_token.1');
    const request = new NextRequest('http://localhost/security/events', { headers: { cookie: parts.map(p => `${p.name}=${p.value}`).join('; ') } });
    expect(readTokenCookie(request.cookies)).toBeUndefined();
    expect((await middleware(request)).headers.get('location')).toContain('/login');
    request.cookies.set('admin_token', 'e30.' + Buffer.from('{"permissions":[]}').toString('base64url') + '.sig');
    expect((await middleware(request)).headers.get('location')).toContain('/403');
  });
  it('rejects oversized/count-tampered storage and retains small legacy sessions', () => {
    expect(() => tokenCookieParts('x'.repeat(12001))).toThrow('too large');
    expect(readTokenCookie({ get: () => ({ value: 'chunks:99' }) })).toBeUndefined();
    expect(readTokenCookie({ get: () => ({ value: 'legacy-token' }) })).toBe('legacy-token');
  });
  it('preserves the known FX error without replay', async () => {
    const error = { success: false, error: { code: 'FX_PROVIDER_UNAVAILABLE', message: 'FX provider is not configured.' } };
    const fetcher = jest.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify(error), { status: 503 }));
    await expect(new AuthenticatedApiClient('token').post('/v1/admin/fx/rates', { mode: 'provider' }, { domainErrorCodes: ['FX_PROVIDER_UNAVAILABLE'] })).rejects.toEqual(error);
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
  it.each(['<html>private proxy error</html>', JSON.stringify({ error: { code: 'SERVICE_UNAVAILABLE', message: 'private error' } })])('keeps unrecognized 503 responses safe and global', async body => {
    const fetcher = jest.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(body, { status: 503 }));
    await expect(new AuthenticatedApiClient('token').post('/v1/admin/fx/rates', {}, { domainErrorCodes: ['FX_PROVIDER_UNAVAILABLE'] })).rejects.toMatchObject({ status: 503 });
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
});
