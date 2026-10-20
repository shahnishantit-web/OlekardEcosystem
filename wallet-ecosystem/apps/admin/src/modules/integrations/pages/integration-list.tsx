'use client';
import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Table } from '@/components/ui/Table';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { IntegrationApi, errorMessage, dateText, healthLabel, type Provider, type Page } from '../api';
import { IntegrationNav, Pagination, field } from '../shared';
export function IntegrationListPage() {
    const { authenticatedClient: client, hasPermission } = useAuth(), allowed = hasPermission('integrations.manage'), api = useMemo(() => client ? new IntegrationApi(client) : null, [client]);
    const [type, setType] = useState('');
    const [data, setData] = useState<Page<Provider> | null>(null), [page, setPage] = useState(1), [reload, setReload] = useState(0), [error, setError] = useState(''), [busy, setBusy] = useState(false);
    useEffect(() => { let active = true; if (api && allowed) {
        setData(null);
        api.providers(page, type).then(r => { if (active)
            setData(r); }).catch(e => { if (active)
            setError(errorMessage(e)); });
    } return () => { active = false; }; }, [api, allowed, page, reload, type]);
    async function mutate(p: Provider, action: 'status' | 'default') { if (!api)
        return; setBusy(true); setError(''); try {
        if (action === 'default')
            await api.setDefault(p.id);
        else
            await api.providerStatus(p.id, p.status === 'active' ? 'inactive' : 'active');
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
    return <PageContainer><PageHeader title="Integrations" description="Provider health and partner API access." actions={<Button variant="secondary" onClick={() => setReload(n => n + 1)}>Refresh</Button>}/><IntegrationNav /><label className="block mb-4">Provider type<select aria-label="Provider type" className={field} value={type} onChange={e => { setType(e.target.value); setPage(1); }}><option value="">All types</option>{['otp', 'sms', 'email', 'kyc', 'payment', 'bank', 'mobile_money'].map(t => <option key={t}>{t}</option>)}</select></label>
 {error && <ErrorState message={error} onRetry={() => { setError(''); setReload(n => n + 1); }}/>}
 {!data ? <Loading label="Loading integrations"/> : <><Table data={data.data} getRowKey={p => p.id} emptyMessage="No integrations registered." columns={[
                { key: 'display_name', header: 'Partner' }, { key: 'provider_type', header: 'Type' }, { key: 'status', header: 'Configuration' }, { key: 'health', header: 'Health', render: p => <Badge variant={p.health === 'healthy' ? 'success' : p.health === 'down' ? 'error' : 'warning'}>{healthLabel(p.health)}</Badge> },
                { key: 'last_checked_at', header: 'Last checked', render: p => dateText(p.last_checked_at) },
                { key: 'actions', header: 'Actions', render: p => <div className="flex flex-wrap gap-3"><Link className="underline" href={'/integrations/providers/' + encodeURIComponent(p.id) + '/health'}>View Health</Link><Link className="underline" href={'/integrations/clients?integration_id=' + encodeURIComponent(p.id)}>New API Client</Link>{!p.is_default && <Button variant="secondary" disabled={busy} onClick={() => mutate(p, 'default')}>Set Default</Button>}<Button variant="secondary" disabled={busy} onClick={() => mutate(p, 'status')}>{p.status === 'active' ? 'Deactivate' : 'Activate'}</Button></div> }
            ]}/><Pagination page={page} lastPage={data.meta.last_page} onChange={setPage}/></>}
 </PageContainer>;
}
