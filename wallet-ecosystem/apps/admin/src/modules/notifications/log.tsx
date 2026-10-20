'use client';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Table } from '@/components/ui/Table';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { NotificationsApi, errorMessage, type Delivery, type Page } from './api';
import { NotificationNav, field } from './shared';
export function NotificationLogPage() {
    const { authenticatedClient: client, hasPermission } = useAuth(), api = useMemo(() => client ? new NotificationsApi(client) : null, [client]);
    const read = hasPermission('notifications.log.read'), retry = hasPermission('notifications.retry');
    const [filters, setFilters] = useState({ channel: '', status: '', from: '', to: '' }), [page, setPage] = useState(1), [data, setData] = useState<Page<Delivery> | null>(null), [error, setError] = useState(''), [notice, setNotice] = useState(''), [reload, setReload] = useState(0), [busy, setBusy] = useState<string | null>(null);
    const invalid = !!(filters.from && filters.to && filters.from > filters.to);
    useEffect(() => { let active = true; if (api && read && !invalid) {
        setData(null);
        setError('');
        const q = new URLSearchParams({ page: String(page) });
        Object.entries(filters).forEach(([k, v]) => { if (v)
            q.set(k, v); });
        api.log(q).then(r => { if (active)
            setData(r); }).catch(e => { if (active)
            setError(errorMessage(e)); });
    } return () => { active = false; }; }, [api, read, filters, page, reload, invalid]);
    async function retryDelivery(id: string) { if (!api)
        return; setBusy(id); setError(''); try {
        await api.retry(id);
        setNotice('Retry queued.');
        setReload(n => n + 1);
    }
    catch (e) {
        setError(errorMessage(e));
    }
    finally {
        setBusy(null);
    } }
    if (!read)
        return <PageContainer><p role="alert">Access denied</p></PageContainer>;
    return <PageContainer><PageHeader title="Notification delivery log" description="Inspect channel outcomes and retry failed deliveries."/><NotificationNav />
 <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 mb-5">
 <label>Channel<select aria-label="Channel" className={field} value={filters.channel} onChange={e => { setFilters({ ...filters, channel: e.target.value }); setPage(1); }}><option value="">All channels</option>{['email', 'sms', 'push', 'in_app'].map(v => <option key={v}>{v}</option>)}</select></label>
 <label>Status<select aria-label="Status" className={field} value={filters.status} onChange={e => { setFilters({ ...filters, status: e.target.value }); setPage(1); }}><option value="">All statuses</option>{['sent', 'failed', 'bounced', 'queued', 'delivered', 'permanently_failed'].map(v => <option key={v} value={v}>{v.replaceAll('_', ' ')}</option>)}</select></label>
 {(['from', 'to'] as const).map(k => <label key={k}>{k === 'from' ? 'From date (UTC)' : 'To date (UTC)'}<input aria-label={k === 'from' ? 'From date (UTC)' : 'To date (UTC)'} className={field} type="date" value={filters[k]} onChange={e => { setFilters({ ...filters, [k]: e.target.value }); setPage(1); }}/></label>)}</div>
 {invalid && <p role="alert">To date must be on or after From date.</p>}{error && <ErrorState message={error} onRetry={() => setReload(n => n + 1)}/>}<p role="status" aria-label="Delivery action status">{notice}</p>
 {!invalid && (!data ? <Loading label="Loading deliveries"/> : <>{data.data.length === 0 ? <p className="py-8 text-center text-text-secondary">No deliveries match these filters.</p> : <Table data={data.data} getRowKey={r => r.id} emptyMessage="No deliveries match these filters." columns={[
                { key: 'recipient', header: 'Recipient', render: r => <span className="block min-w-[14rem] break-all">{r.recipient}</span> }, { key: 'channel', header: 'Channel' }, { key: 'event', header: 'Event' },
                { key: 'status', header: 'Status', render: r => <Badge>{r.status.replaceAll('_', ' ')}</Badge> }, { key: 'timestamp', header: 'Timestamp', render: r => new Date(r.timestamp).toLocaleString() },
                { key: 'attempt_count', header: 'Attempts' }, { key: 'retry', header: 'Action', render: r => retry && ['failed', 'permanently_failed'].includes(r.status) ? <Button variant="secondary" disabled={busy !== null} onClick={() => retryDelivery(r.id)}>Retry</Button> : null }
            ]}/>}<div className="flex gap-3 items-center mt-4"><Button variant="secondary" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</Button><span>Page {page} of {data.meta.last_page}</span><Button variant="secondary" disabled={page >= data.meta.last_page} onClick={() => setPage(p => p + 1)}>Next</Button></div></>)}</PageContainer>;
}
