'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { errorMessage, statusLabel } from '../api';
interface Counts {
    total_open: number;
    findings_by_status: Record<string, number>;
    findings_by_severity: Record<string, number>;
}
export function FindingsOverviewPage() {
    const { authenticatedClient: client, hasPermission } = useAuth();
    const read = hasPermission('security.findings.read');
    const [data, setData] = useState<Counts | null>(null), [error, setError] = useState(''), [retry, setRetry] = useState(0);
    useEffect(() => { let active = true; if (read && client)
        client.get<Counts>('/v1/security/dashboard').then(r => { if (active)
            setData(r.data); }).catch(e => { if (active)
            setError(errorMessage(e)); }); return () => { active = false; }; }, [client, read, retry]);
    if (!read)
        return <PageContainer><p role="alert">Access denied</p></PageContainer>;
    return <PageContainer><PageHeader title="Findings overview"/><Link className="underline text-primary" href="/security/findings">All security findings</Link>{error ? <ErrorState message={error} onRetry={() => { setError(''); setRetry(n => n + 1); }}/> : !data ? <Loading label="Loading findings overview"/> : <><h2 className="text-lg font-semibold mt-6">Findings by status</h2><div className="grid grid-cols-2 lg:grid-cols-5 gap-4">{Object.entries(data.findings_by_status).map(([s, n]) => <Card key={s}><Link className="underline" href={'/security/findings?status=' + s}>{statusLabel(s)}</Link><p className="text-3xl font-semibold">{n}</p></Card>)}</div><h2 className="text-lg font-semibold mt-6">Findings by severity</h2><div className="grid grid-cols-2 lg:grid-cols-5 gap-4">{Object.entries(data.findings_by_severity).map(([s, n]) => <Card key={s}><Link className="underline" href={'/security/findings?severity=' + s}>{statusLabel(s)}</Link><p className="text-3xl font-semibold">{n}</p></Card>)}</div></>}</PageContainer>;
}
