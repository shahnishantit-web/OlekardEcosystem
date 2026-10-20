'use client';
import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Table } from '@/components/ui/Table';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { SecurityApi, type RiskAlert, errorMessage, displayDate } from '../api';
export function SecurityDashboardPage() {
    const { authenticatedClient, hasPermission } = useAuth();
    const allowed = hasPermission('security.alerts.read');
    const api = useMemo(() => authenticatedClient ? new SecurityApi(authenticatedClient) : null, [authenticatedClient]);
    const [rows, setRows] = useState<RiskAlert[]>([]), [page, setPage] = useState(1), [last, setLast] = useState(1);
    const [severity, setSeverity] = useState(''), [status, setStatus] = useState('open'), [loading, setLoading] = useState(true), [error, setError] = useState('');
    const fetchRows = useCallback(async (signal?: AbortSignal) => {
        if (!allowed || !api)
            return;
        setLoading(true);
        setError('');
        try {
            const res = await api.alerts(new URLSearchParams({ severity, status, page: String(page) }));
            if (signal?.aborted !== true) {
                setRows(res.data);
                setLast(res.meta.last_page);
            }
        }
        catch (e) {
            if (signal?.aborted !== true)
                setError(errorMessage(e));
        }
        finally {
            if (signal?.aborted !== true)
                setLoading(false);
        }
    }, [api, allowed, severity, status, page]);
    useEffect(() => { const c = new AbortController(); void fetchRows(c.signal); return () => c.abort(); }, [fetchRows]);
    if (!allowed)
        return <PageContainer><PageHeader title="Risk alert queue"/><p role="alert">Access denied</p></PageContainer>;
    return <PageContainer><PageHeader title="Risk alert queue"/><p className="mb-6 text-text-secondary">Triage fraud and AML alerts, review evidence, and record investigation decisions.</p>
    {hasPermission('security.findings.read') && <Link className="text-primary underline" href="/security/findings">Security findings</Link>}
    <Card><div className="flex flex-wrap gap-4 mb-6">
    <label>Severity<select className="block rounded border p-2" aria-label="Alert severity" value={severity} onChange={e => { setSeverity(e.target.value); setPage(1); }}><option value="">All severities</option>{['critical', 'high', 'medium', 'low'].map(s => <option key={s} value={s}>{s[0]?.toUpperCase()}{s.slice(1)}</option>)}</select></label>
    <label>Status<select className="block rounded border p-2" aria-label="Alert status" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}><option value="open">Open</option><option value="in_review">In Review</option><option value="resolved">Resolved</option></select></label></div>
        {loading ? <Loading label="Loading risk alerts"/> : error ? <ErrorState message={error} onRetry={() => void fetchRows()}/> : <>
        <Table aria-label="Risk alerts" data={rows} getRowKey={a => a.id} emptyMessage="No risk alerts matching your filters." columns={[
                { key: 'severity', header: 'Severity', render: a => <Badge variant={['high', 'critical'].includes(a.severity) ? 'error' : 'warning'}>{a.severity.toUpperCase()}</Badge> },
                { key: 'alert_type', header: 'Type', render: a => a.alert_type.replaceAll('_', ' ') },
                { key: 'title', header: 'Alert', render: a => <Link className="underline text-primary" href={'/security/alerts/' + a.id}>{a.title}</Link> },
                { key: 'subject_id', header: 'Entity', render: a => <span>{a.subject_type}: {a.subject_id}</span> },
                { key: 'created_at', header: 'Created', render: a => displayDate(a.created_at) },
                { key: 'assigned_to', header: 'Assigned To', render: a => a.assigned_to ?? 'Unassigned' }
            ]}/><div className="flex items-center justify-end gap-4 mt-4" aria-label="Pagination">
        <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</Button><span aria-live="polite">Page {page} of {last}</span><Button variant="secondary" disabled={page >= last} onClick={() => setPage(p => p + 1)}>Next</Button></div></>}
    </Card></PageContainer>;
}
