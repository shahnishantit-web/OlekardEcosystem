'use client';
import { useAuth } from '@/lib/auth/context';
import { AdminShell } from '@/components/layout/AdminShell';
import { SystemStatusView, type StatusCode } from './SystemStatusView';
export function StatusRoute({ code }: {
    code: StatusCode;
}) {
    const { phase } = useAuth();
    if ((code === '429' || code === '503') && phase === 'authenticated')
        return <AdminShell><SystemStatusView code={code} embedded/></AdminShell>;
    return <main><SystemStatusView code={code}/></main>;
}
