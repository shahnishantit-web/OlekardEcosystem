import { NextRequest, NextResponse } from 'next/server';
import { verifyMaintenanceToken } from '@/lib/system/maintenance-token';
export async function POST(request: NextRequest): Promise<NextResponse> {
    const deny = () => new NextResponse('Access denied', { status: 403, headers: { 'Cache-Control': 'no-store' } });
    if (request.headers.get('origin') !== new URL(request.url).origin)
        return deny();
    if (!(request.headers.get('content-type') ?? '').startsWith('application/x-www-form-urlencoded'))
        return deny();
    // Bound actual streamed bytes as Content-Length is optional and untrusted.
    const reader = request.body?.getReader();
    if (reader === undefined)
        return deny();
    let size = 0, text = '';
    const decoder = new TextDecoder();
    try {
        while (true) {
            const part = await reader.read();
            if (part.done)
                break;
            size += part.value.byteLength;
            if (size > 4096) {
                await reader.cancel();
                return deny();
            }
            text += decoder.decode(part.value, { stream: true });
        }
        text += decoder.decode();
    }
    catch {
        return deny();
    }
    const token = new URLSearchParams(text).get('token') ?? undefined;
    const capability = await verifyMaintenanceToken(token, process.env.MAINTENANCE_BYPASS_SECRET);
    // eslint-disable-next-line no-console -- Server security audit event; excludes credentials and headers.
            console.info(JSON.stringify({ event: 'admin.maintenance.bypass.submission', correlation_id: crypto.randomUUID(), outcome: capability === null ? 'denied' : 'granted', actor_type: 'superadmin_capability' }));
    if (capability === null)
        return new NextResponse('Invalid or expired maintenance access token. Return to maintenance and try again.', { status: 403, headers: { 'Cache-Control': 'no-store', 'Content-Type': 'text/plain; charset=utf-8' } });
    const response = NextResponse.redirect(new URL('/dashboard', request.url), 303);
    response.headers.set('Cache-Control', 'no-store');
    response.cookies.set('maintenance_bypass', String(token), { httpOnly: true, sameSite: 'strict', secure: process.env.NODE_ENV === 'production', path: '/', maxAge: Math.max(0, capability.exp - Math.floor(Date.now() / 1000)) });
    return response;
}
