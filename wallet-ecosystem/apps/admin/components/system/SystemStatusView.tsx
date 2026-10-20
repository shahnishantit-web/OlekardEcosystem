'use client';
import { ErrorPage } from '@/components/ui/ErrorPage';
import { STATUS_COPY, type SystemFailure } from '@/lib/system/http-status';
export type StatusCode = keyof typeof STATUS_COPY;
export function SystemStatusView({ code, embedded = false, detail, estimatedReturn }: {
    code: StatusCode;
    embedded?: boolean;
    detail?: SystemFailure;
    estimatedReturn?: string;
}) {
    const copy = STATUS_COPY[code];
    const extra = detail?.retryAfterSeconds !== undefined ? ' Wait at least ' + detail.retryAfterSeconds + ' seconds.' : '';
    return <ErrorPage {...(/^\d+$/.test(code) ? { code } : {})} title={copy.title} message={copy.message + extra} variant={code === '401' ? 'warning' : 'info'} embedded={embedded} {...(detail?.correlationId !== undefined ? { correlationId: detail.correlationId } : {})} primaryAction={code === '401' ? { label: copy.action, href: '/login' } : { label: copy.action, onClick: () => window.location.reload() }} secondaryAction={code === '401' ? { label: 'Return to home', href: '/' } : { label: 'Return to sign in', href: '/login' }}>
 {code === 'maintenance' && <><p className="text-sm mb-6">Estimated return: {estimatedReturn ?? 'Not yet available. Please check again later.'}</p><details className="text-left text-sm mb-6"><summary>Superadmin maintenance access</summary><form action="/api/system/maintenance-bypass" method="post" className="mt-4 space-y-3"><label className="block">Maintenance bypass token<input name="token" type="password" required maxLength={2048} autoComplete="off" className="mt-2 block w-full rounded border p-2"/></label><button type="submit" className="rounded bg-ok-primary text-white px-4 py-2">Continue with bypass token</button><p>This skips maintenance only. Sign-in and MFA still apply.</p></form></details></>}
 </ErrorPage>;
}
