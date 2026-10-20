/** @jest-environment node */
import { webcrypto, createHmac } from 'node:crypto';
import { NextRequest } from 'next/server';
import { middleware } from '@/middleware';
import { verifyMaintenanceToken, signMaintenanceToken } from '@/lib/system/maintenance-token';
import { isUnsupportedBrowser } from '@/lib/system/browser-support';
import { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true });
const base = 'http://localhost:3000', secret = 'test-secret-for-maintenance-at-least-32-characters';
describe('p2001 maintenance security and status integration', () => {
    beforeEach(() => { delete process.env.MAINTENANCE_MODE; delete process.env.MAINTENANCE_BYPASS_SECRET; });
    afterEach(() => jest.restoreAllMocks());
    it('verifies scoped bounded expiring capabilities and rejects tampering', async () => {
        const now = Math.floor(Date.now() / 1000), token = await signMaintenanceToken(secret, now + 300);
        expect(await verifyMaintenanceToken(token, secret)).toEqual({ purpose: 'maintenance', role: 'superadmin', exp: now + 300 });
        expect(await verifyMaintenanceToken(token + 'x', secret)).toBeNull();
        expect(await verifyMaintenanceToken(token, 'wrong')).toBeNull();
        expect(await verifyMaintenanceToken(await signMaintenanceToken(secret, now - 1), secret)).toBeNull();
    });
    it('serves503 during maintenance; valid capability still requires login and MFA', async () => {
        process.env.MAINTENANCE_MODE = 'true';
        process.env.MAINTENANCE_BYPASS_SECRET = secret;
        const denied = await middleware(new NextRequest(base + '/dashboard'));
        expect(denied.status).toBe(503);
        expect(denied.headers.get('x-middleware-rewrite')).toContain('/maintenance');
        const token = await signMaintenanceToken(secret, Math.floor(Date.now() / 1000) + 300);
        const anonymous = await middleware(new NextRequest(base + '/dashboard', { headers: { 'X-Maintenance-Bypass': token } }));
        expect(anonymous.headers.get('location')).toContain('/login');
        const mfa = await middleware(new NextRequest(base + '/dashboard', { headers: { 'X-Maintenance-Bypass': token, Cookie: 'admin_token=test; admin_mfa_setup=required' } }));
        expect(mfa.headers.get('location')).toContain('/mfa-enrollment');
    });
    it('recognizes only named legacy engines and returns script-free HTML', async () => {
        expect(isUnsupportedBrowser('Mozilla Trident/7.0; rv:11.0')).toBe(true);
        expect(isUnsupportedBrowser('Mozilla Edge/18.1')).toBe(true);
        expect(isUnsupportedBrowser('Mozilla Edg/130.0')).toBe(false);
        const r = await middleware(new NextRequest(base + '/dashboard', { headers: { 'user-agent': 'MSIE 10.0' } }));
        const html = await r.text();
        expect(html).toContain('Unsupported browser');
        expect(html).not.toContain('<script');
    });
    it.each([429, 503, 504])('turns HTML HTTP%s into a safe status error without replay', async (status) => {
        const fetcher = jest.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('<h1>secret proxy trace</h1>', { status }));
        const client = new AuthenticatedApiClient('session-token');
        await expect(client.post('/v1/test', { amount: 1 })).rejects.toMatchObject({ status });
        expect(fetcher).toHaveBeenCalledTimes(1);
        await expect(client.getBlob('/v1/export')).rejects.toMatchObject({ status });
    });
    it('rejects wrong-role/purpose, excessive lifetime, missing secret and query bypass', async () => {
        const exp = Math.floor(Date.now() / 1000) + 300;
        const tokenFor = (data: object) => { const payload = Buffer.from(JSON.stringify(data)).toString('base64url'); return payload + '.' + createHmac('sha256', secret).update(payload).digest('base64url'); };
        for (const data of [{ purpose: 'maintenance', role: 'admin', exp }, { purpose: 'login', role: 'superadmin', exp }, { purpose: 'maintenance', role: 'superadmin', exp: exp + 7200 }])
            expect(await verifyMaintenanceToken(tokenFor(data), secret)).toBeNull();
        expect(await verifyMaintenanceToken(tokenFor({ purpose: 'maintenance', role: 'superadmin', exp }), undefined)).toBeNull();
        process.env.MAINTENANCE_MODE = 'true';
        process.env.MAINTENANCE_BYPASS_SECRET = secret;
        const valid = await signMaintenanceToken(secret, exp);
        expect((await middleware(new NextRequest(base + '/dashboard?bypass=' + valid))).status).toBe(503);
        const denied = await middleware(new NextRequest(base + '/security/events', { headers: { 'X-Maintenance-Bypass': valid, Cookie: 'admin_token=invalid' } }));
        expect(denied.headers.get('location')).toContain('/403');
    });
    it('maintains API JSON maintenance response and exact public-route exemptions', async () => {
        process.env.MAINTENANCE_MODE = 'true';
        const response = await middleware(new NextRequest(base + '/api/v1/test'));
        expect(response.status).toBe(503);
        expect((await response.json()).error.code).toBe('MAINTENANCE_MODE');
        delete process.env.MAINTENANCE_MODE;
        expect((await middleware(new NextRequest(base + '/offline-admin-secret'))).headers.get('location')).toContain('/login');
    });
});
