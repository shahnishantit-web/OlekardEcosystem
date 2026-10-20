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
import { IntegrationApi, errorMessage, dateText, type Delivery, type Page } from '../api';
import { IntegrationNav, Pagination, field } from '../shared';
export function WebhookMonitorPage() {
    const { authenticatedClient: client, hasPermission } = useAuth(), allowed = hasPermission('integrations.manage'), api = useMemo(() => client ? new IntegrationApi(client) : null, [client]);
    const [data, setData] = useState<Page<Delivery> | null>(null), [page, setPage] = useState(1), [status, setStatus] = useState(''), [event, setEvent] = useState(''), [error, setError] = useState(''), [notice, setNotice] = useState(''), [reload, setReload] = useState(0), [busy, setBusy] = useState(false);
    useEffect(() => { let active = true; if (api && allowed) {
        setData(null);
        const q = new URLSearchParams({ page: String(page) });
        if (status)
            q.set('status', status);
        if (event)
            q.set('event_type', event);
        api.deliveries(q).then(r => { if (active)
            setData(r); }).catch(e => { if (active)
            setError(errorMessage(e)); });
    } return () => { active = false; }; }, [api, allowed, page, status, event, reload]);
    async function retry(id: string) { if (!api)
        return; setBusy(true); setError(''); try {
        await api.retry(id);
        setNotice('Callback retry queued.');
        setReload(n => n + 1);
    }
    catch (e) {
        setError(errorMessage(e));
    }
    finally {
        setBusy(false);
    } }
    if (!allowed)
        return <PageContainer><p role="alert">Access denied</p></PageContainer>;
    return <PageContainer><PageHeader title="Webhook deliveries" description="Incoming provider callback processing. Delivered means processed by the platform."/><IntegrationNav />
 <div className="grid sm:grid-cols-2 gap-4 mb-5"><label>Status<select aria-label="Webhook status" className={field} value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}><option value="">All statuses</option><option value="delivered">Delivered</option><option value="failed">Failed</option><option value="retrying">Retrying</option></select></label><label>Event type<input aria-label="Event type" className={field} value={event} maxLength={100} onChange={e => { setEvent(e.target.value); setPage(1); }}/></label></div>
 {error && <ErrorState message={error} onRetry={() => { setError(''); setReload(n => n + 1); }}/>}<p role="status" aria-label="Webhook action status">{notice}</p>
 {!data ? <Loading label="Loading webhook deliveries"/> : <>{data.data.length === 0 ? <p>No callbacks match these filters.</p> : <Table data={data.data} getRowKey={d => d.id} columns={[{ key: 'event', header: 'Event' }, { key: 'endpoint', header: 'Endpoint' }, { key: 'status', header: 'Status', render: d => <Badge>{d.status}</Badge> }, { key: 'attempts', header: 'Attempts' }, { key: 'last_attempt_at', header: 'Last Attempt', render: d => d.last_attempt_at !== null ? dateText(d.last_attempt_at) : 'Not attempted' }, { key: 'retry', header: 'Action', render: d => d.status === 'failed' && <Button variant="secondary" disabled={busy} onClick={() => retry(d.id)}>Retry</Button> }]}/>}<Pagination page={page} lastPage={data.meta.last_page} onChange={setPage}/></>}
 </PageContainer>;
}
