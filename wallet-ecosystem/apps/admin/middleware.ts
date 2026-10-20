import { isUnsupportedBrowser, unsupportedBrowserHtml } from '@/lib/system/browser-support';
import { verifyMaintenanceToken } from '@/lib/system/maintenance-token';
import { NextRequest, NextResponse } from 'next/server';
import { readTokenCookie } from '@/lib/auth/token-cookies';
const AUTH_ROUTES = /^\/(auth|login|forgot-password|reset-password|verify-email|account-locked|account-disabled|api\/auth)/;
// Permission-slug gates for AdminApp routes.
// The JWT payload contains a `permissions` claim (array of slugs) — decoded here
// without signature verification (the backend enforces the real authorization on
// every API call; this gate is a UX layer that prevents rendering forbidden pages).
const ADMINAPP_PERMISSION_MAP: Record<string, string> = {
    '/platform/security-config': 'security.config.read|configuration.policy.view',
    '/security/events': 'security.events.read',
    '/multi-currency/currencies': 'multi_currency.manage_currencies',
    '/multi-currency/fx-rates': 'multi_currency.configure_rate',
    '/support/cases': 'support.investigate',
    '/integrations': 'integrations.manage',
    '/notifications/templates': 'notifications.templates.read',
    '/notifications/log': 'notifications.log.read',
    '/notifications/rules': 'notifications.rules.read',
    '/kyc': 'adminapp.kyc.review',
    '/agents': 'adminapp.agents.manage',
    '/float': 'adminapp.float.manage',
    '/financial-dashboard': 'adminapp.dashboard.financial',
    '/risk-dashboard': 'adminapp.dashboard.risk',
    '/qa': 'qa.execute',
    '/security/dashboard': 'security.alerts.read',
    '/security/alerts': 'security.alerts.read',
    '/security/overview': 'security.findings.read',
    '/security/findings': 'security.findings.read',
    '/operations/dead-letter': 'operations.dead_letter.manage',
};
function getPermissionsFromToken(token: string | undefined): string[] {
    if (token === undefined || token === '')
        return [];
    try {
        const part = token.split('.')[1];
        if (part === undefined || part === '')
            return [];
        const b64 = part.replace(/-/g, '+').replace(/_/g, '/');
        const payload: unknown = JSON.parse(atob(b64));
        if (payload !== null && typeof payload === 'object' && 'permissions' in payload) {
            const p = (payload as Record<string, unknown>).permissions;
            return Array.isArray(p) ? (p as string[]) : [];
        }
        return [];
    }
    catch {
        return [];
    }
}
export async function middleware(request: NextRequest): Promise<NextResponse> {
    const token = readTokenCookie(request.cookies);
    const pathname = request.nextUrl.pathname;
    const isAuthRoute = AUTH_ROUTES.test(pathname);
    // Legacy engines must never receive hydration scripts.
    if (pathname === '/unsupported-browser' || isUnsupportedBrowser(request.headers.get('user-agent') ?? '')) {
        return new NextResponse(unsupportedBrowserHtml(), { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff', 'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'" } });
    }
    const statusRoutes = ['/401', '/429', '/503', '/504', '/offline', '/maintenance'];
    const bypassEntry = pathname === '/api/system/maintenance-bypass';
    if (process.env.MAINTENANCE_MODE === 'true' && !bypassEntry) {
        const capability = await verifyMaintenanceToken(request.headers.get('X-Maintenance-Bypass') ?? request.cookies.get('maintenance_bypass')?.value, process.env.MAINTENANCE_BYPASS_SECRET);
        if (capability !== null)
            // eslint-disable-next-line no-console -- Server security audit event; excludes credentials and headers.
            console.info(JSON.stringify({ event: 'admin.maintenance.bypass.used', correlation_id: crypto.randomUUID(), actor_type: 'superadmin_capability', expires_at: capability.exp }));
        if (capability === null) {
            if (pathname.startsWith('/api/'))
                return NextResponse.json({ success: false, data: null, error: { code: 'MAINTENANCE_MODE', message: 'The administration service is under maintenance.' } }, { status: 503, headers: { 'Cache-Control': 'no-store' } });
            return NextResponse.rewrite(new URL('/maintenance', request.url), { status: 503, headers: { 'Cache-Control': 'no-store' } });
        }
    }
    // Public status content and capability submission never confer business access.
    if (statusRoutes.includes(pathname) || bypassEntry) {
        if (/^\/(401|429|503|504)$/.test(pathname))
            return NextResponse.rewrite(new URL(pathname, request.url), { status: Number(pathname.slice(1)), headers: { 'Cache-Control': 'no-store' } });
        return NextResponse.next();
    }
    if ((token === undefined || token === '') && !isAuthRoute) {
        const loginUrl = new URL('/login', request.url);
        loginUrl.searchParams.set('callbackUrl', pathname);
        return NextResponse.redirect(loginUrl);
    }
    // MFA enrollment gate: admin logged in but must complete MFA enrollment before accessing the app.
    const mfaSetup = request.cookies.get('admin_mfa_setup')?.value;
    if (token !== undefined && token !== '' && mfaSetup === 'required' && pathname !== '/mfa-enrollment' && !isAuthRoute) {
        return NextResponse.redirect(new URL('/mfa-enrollment', request.url));
    }
    // Check AdminApp route permission gates
    // (pathname already declared above)
    const requiredPermission = Object.entries(ADMINAPP_PERMISSION_MAP).find(([route]) => pathname === route || pathname.startsWith(route + '/'))?.[1];
    if (requiredPermission !== undefined && requiredPermission !== '') {
        const permissions = getPermissionsFromToken(token);
        if (!requiredPermission.split('|').some(permission => permissions.includes(permission)) && !permissions.includes('adminapp.*')) {
            return NextResponse.redirect(new URL('/403', request.url));
        }
    }
    return NextResponse.next();
}
export const config = {
    matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
