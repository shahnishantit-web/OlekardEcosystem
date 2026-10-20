'use client';
import { useCallback, useEffect, useState } from 'react';
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
import { errorMessage, displayDate, statusLabel, type Page } from '../api';
interface Finding {
    id: string;
    cve_id?: string;
    finding_ref?: string;
    severity: string;
    component: string;
    title: string;
    status: string;
    detected_at: string;
}
export function SecurityFindingsPage() {
    const { authenticatedClient: client, hasPermission } = useAuth();
    const read = hasPermission('security.findings.read'), write = hasPermission('security.findings.write');
    const [rows, setRows] = useState<Finding[]>([]), [severity, setSeverity] = useState(() => typeof window === 'undefined' ? '' : new URLSearchParams(window.location.search).get('severity') ?? ''), [status, setStatus] = useState(() => typeof window === 'undefined' ? '' : new URLSearchParams(window.location.search).get('status') ?? ''), [page, setPage] = useState(1), [last, setLast] = useState(1);
    const [loading, setLoading] = useState(true), [error, setError] = useState(''), [actionError, setActionError] = useState(''), [busy, setBusy] = useState(false);
    const [target, setTarget] = useState<string | null>(null), [justification, setJustification] = useState('');
    const load = useCallback(async (signal?: AbortSignal) => {
        if (!read || !client)
            return;
        setLoading(true);
        setError('');
        try {
            const res = await client.get<Finding[]>('/v1/security/findings?' + new URLSearchParams({ severity, status, page: String(page), per_page: '25' })) as unknown as Page<Finding>;
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
    }, [read, client, severity, status, page]);
    useEffect(() => { const c = new AbortController(); void load(c.signal); return () => c.abort(); }, [load]);
    async function update(id: string, next: string, suppression?: string) {
        if (!client || !write || busy)
            return;
        setBusy(true);
        setActionError('');
        try {
            await client.patch('/v1/security/findings/' + id + '/status', { status: next, ...(suppression !== undefined && suppression.length > 0 ? { suppression_justification: suppression } : {}) });
            setTarget(null);
            setJustification('');
            await load();
        }
        catch (e) {
            setActionError(errorMessage(e));
        }
        finally {
            setBusy(false);
        }
    }
    if (!read)
        return <PageContainer><PageHeader title="Security findings"/><p role="alert">Access denied</p></PageContainer>;
    return <PageContainer><PageHeader title="Security findings"/><Link className="underline text-primary" href="/security/overview">Findings overview</Link>
    <Card><div className="flex flex-wrap gap-4 mb-5"><label>Severity<select className="block border rounded p-2" aria-label="Finding severity" value={severity} onChange={e => { setSeverity(e.target.value); setPage(1); }}><option value="">All severities</option>{['critical', 'high', 'medium', 'low', 'info'].map(s => <option key={s} value={s}>{statusLabel(s)}</option>)}</select></label>
    <label>Status<select className="block border rounded p-2" aria-label="Finding status" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}><option value="">All statuses</option>{['open', 'acknowledged', 'in_remediation', 'suppressed', 'resolved', 'verified'].map(s => <option key={s} value={s}>{statusLabel(s)}</option>)}</select></label></div>
    {actionError && <p role="alert">{actionError}</p>}
        {loading ? <Loading label="Loading findings"/> : error ? <ErrorState message={error} onRetry={() => void load()}/> : <>
        <Table data={rows} getRowKey={f => f.id} emptyMessage="No findings matching your filters." aria-label="Security findings" columns={[
                { key: 'cve_id', header: 'CVE ID', render: f => f.cve_id ?? f.finding_ref?.match(/\bCVE-\d{4}-\d{4,}\b/i)?.[0] ?? '—' },
                { key: 'severity', header: 'Severity', render: f => <Badge variant={['critical', 'high'].includes(f.severity) ? 'error' : 'warning'}>{f.severity.toUpperCase()}</Badge> },
                { key: 'component', header: 'Component', render: f => f.component },
                { key: 'title', header: 'Finding', render: f => <Link className="underline" href={'/security/findings/'+f.id}>{f.title}</Link> },
                { key: 'status', header: 'Status', render: f => statusLabel(f.status) },
                { key: 'detected_at', header: 'Discovered', render: f => displayDate(f.detected_at) },
                { key: 'actions', header: 'Actions', render: f => write && f.status !== 'verified' && <div className="flex flex-wrap gap-2">
                    {f.status === 'open' && <Button size="sm" disabled={busy} onClick={() => void update(f.id, 'acknowledged')}>Acknowledge</Button>}
                    {f.status === 'acknowledged' && <Button size="sm" disabled={busy} onClick={() => void update(f.id, 'in_remediation')}>Start remediation</Button>}
                    {f.status !== 'resolved' && <Button size="sm" disabled={busy} onClick={() => void update(f.id, 'resolved')}>Resolve</Button>}
                    {f.status !== 'resolved' && f.status !== 'suppressed' && <Button size="sm" variant="secondary" disabled={busy} onClick={() => setTarget(f.id)}>Suppress</Button>}
                    </div> }
            ]}/><div className="flex items-center justify-end gap-4 mt-4"><Button variant="secondary" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</Button><span aria-live="polite">Page {page} of {last}</span><Button variant="secondary" disabled={page >= last} onClick={() => setPage(p => p + 1)}>Next</Button></div></>}
    </Card>
    {target !== null && <Card><h2 className="text-lg font-semibold">Suppress finding</h2><label htmlFor="suppression">Suppression justification</label><textarea id="suppression" className="block border rounded p-3 w-full" maxLength={1000} value={justification} onChange={e => setJustification(e.target.value)}/><div className="flex gap-3 mt-3"><Button disabled={busy || !justification.trim()} onClick={() => void update(target, 'suppressed', justification)}>Confirm suppression</Button><Button variant="secondary" disabled={busy} onClick={() => setTarget(null)}>Cancel</Button></div></Card>}
    </PageContainer>;
}
