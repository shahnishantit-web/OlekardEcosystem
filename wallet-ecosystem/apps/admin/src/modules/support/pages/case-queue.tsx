'use client';
import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Table } from '@/components/ui/Table';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { SupportApi, field, message, type Case, type CasePage } from '../api';
export function CaseQueuePage() {
    const { authenticatedClient: client, hasPermission } = useAuth(), allowed = hasPermission('support.investigate');
    const api = useMemo(() => client ? new SupportApi(client) : null, [client]);
    const [rows, setRows] = useState<CasePage | null>(null), [filters, setFilters] = useState<Record<string, string>>({}), [page, setPage] = useState(1), [error, setError] = useState(''), [reload, setReload] = useState(0);
    useEffect(() => { let active = true; if (api && allowed) {
        setError('');
        setRows(null);
        api.queue(filters, page).then(r => { if (active)
            setRows(r); }).catch(e => { if (active)
            setError(message(e)); });
    } return () => { active = false; }; }, [api, allowed, filters, page, reload]);
    function filter(k: string, v: string) { setFilters(f => ({ ...f, [k]: v })); setPage(1); }
    if (!allowed)
        return <ErrorState message="You do not have permission to view support cases."/>;
    return <PageContainer><PageHeader title="Support & Disputes" description="Triage cases, investigate evidence and track escalations."/>
 <Card><div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
 {([['type', 'Type', ['Support', 'Dispute']], ['status', 'Status', ['Open', 'In Progress', 'Escalated', 'Resolved']], ['priority', 'Priority', ['low', 'medium', 'high', 'critical']]] as const).map(([key, label, options]) => <label key={key} className="text-sm">{label}<select aria-label={label} className={field} value={filters[key] ?? ''} onChange={e => filter(key, e.target.value)}><option value="">All</option>{options.map(o => <option key={o}>{o}</option>)}</select></label>)}
 <label className="text-sm">From date<input aria-label="From date" className={field} type="date" value={filters['date_from'] ?? ''} onChange={e => filter('date_from', e.target.value)}/></label>
 <label className="text-sm">To date<input aria-label="To date" className={field} type="date" min={filters['date_from']} value={filters['date_to'] ?? ''} onChange={e => filter('date_to', e.target.value)}/></label>
 </div></Card>
 {error && <ErrorState message={error} onRetry={() => setReload(n => n + 1)}/>}
 {!rows && !error && <Loading label="Loading support cases"/>}
 {rows && <Card><h2 className="mb-3 font-semibold">{rows.total} cases</h2>{rows.items.length === 0 ? <p>No cases match these filters.</p> : <Table<Case> data={rows.items} getRowKey={r => r.id} columns={[
                    { key: 'number', header: 'Case ID', render: r => <Link className="text-brand-primary underline" href={'/support/cases/' + r.id}>{r.number}</Link> },
                    { key: 'customer', header: 'Customer', render: r => <Link className="underline" href={'/users/' + r.customer_id}>{r.customer_id}</Link> },
                    { key: 'type', header: 'Type' }, { key: 'priority', header: 'Priority' },
                    { key: 'status', header: 'Status', render: r => <span>{r.status}{r.overdue && <strong className="block text-error-primary">Overdue</strong>}</span> },
                    { key: 'created_at', header: 'Created', render: r => new Date(r.created_at).toLocaleString() },
                    { key: 'assigned_id', header: 'Assigned', render: r => r.assigned_id ?? 'Unassigned' }
                ]}/>}
 <div className="mt-4 flex items-center justify-between gap-2"><Button variant="secondary" disabled={page <= 1} onClick={() => setPage(n => n - 1)}>Previous</Button><span>Page {rows.page} of {rows.last_page}</span><Button variant="secondary" disabled={page >= rows.last_page} onClick={() => setPage(n => n + 1)}>Next</Button></div></Card>}
 </PageContainer>;
}
