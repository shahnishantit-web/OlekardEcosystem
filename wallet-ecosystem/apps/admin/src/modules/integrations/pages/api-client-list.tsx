'use client';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Table } from '@/components/ui/Table';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { IntegrationApi, errorMessage, type Client, type Credentials, type Page, type Provider } from '../api';
import { IntegrationNav, Pagination, field } from '../shared';
export function ApiClientListPage({ partnerId, clientId }: {
    partnerId?: string | undefined;
    clientId?: string | undefined;
} = {}) {
    const { authenticatedClient: client, hasPermission } = useAuth(), allowed = hasPermission('integrations.manage'), api = useMemo(() => client ? new IntegrationApi(client) : null, [client]);
    const [rows, setRows] = useState<Page<Client> | null>(null), [partners, setPartners] = useState<Provider[]>([]), [page, setPage] = useState(1), [reload, setReload] = useState(0), [error, setError] = useState(''), [notice, setNotice] = useState(''), [busy, setBusy] = useState(false), [form, setForm] = useState(partnerId !== undefined);
    const [name, setName] = useState(''), [partner, setPartner] = useState(partnerId ?? ''), [permissions, setPermissions] = useState(''), [ips, setIps] = useState(''), [rate, setRate] = useState(60), [credentials, setCredentials] = useState<Credentials | null>(null), [confirm, setConfirm] = useState<{
        id: string;
        name: string;
        action: 'rotate' | 'revoke';
    } | null>(null);
    const nameRef = useRef<HTMLInputElement>(null);
    useEffect(() => { let active = true; if (api && allowed) {
        setRows(null);
        api.clients(page, partnerId, clientId).then(r => { if (active)
            setRows(r); }).catch(e => { if (active)
            setError(errorMessage(e)); });
    } return () => { active = false; }; }, [api, allowed, page, partnerId, clientId, reload]);
    useEffect(() => { let active = true; if (api && allowed)
        api.providers().then(r => { if (active)
            setPartners(r.data); }).catch(e => { if (active)
            setError(errorMessage(e)); }); return () => { active = false; }; }, [api, allowed]);
    useEffect(() => { if (form)
        nameRef.current?.focus(); }, [form]);
    useEffect(() => { const clear = () => { if (document.visibilityState === 'hidden')
        setCredentials(null); }; document.addEventListener('visibilitychange', clear); return () => document.removeEventListener('visibilitychange', clear); }, []);
    const list = (s: string) => s.split(/[,\n]/).map(v => v.trim()).filter(Boolean);
    async function create(e: React.FormEvent) { e.preventDefault(); if (!api)
        return; setBusy(true); setError(''); try {
        setCredentials(await api.create({ name, integration_id: partner, permissions: list(permissions), ip_whitelist: list(ips), rate_limit_per_minute: rate }));
        setForm(false);
        setReload(n => n + 1);
    }
    catch (e) {
        setError(errorMessage(e));
    }
    finally {
        setBusy(false);
    } }
    async function mutate() { if (!api || !confirm)
        return; setBusy(true); setCredentials(null); setError(''); try {
        if (confirm.action === 'rotate')
            setCredentials(await api.rotate(confirm.id));
        else {
            await api.revoke(confirm.id);
            setNotice('Credentials revoked.');
        }
        setConfirm(null);
        setReload(n => n + 1);
    }
    catch (e) {
        setError(errorMessage(e));
    }
    finally {
        setBusy(false);
    } }
    async function toggle(c: Client) { if (!api)
        return; setBusy(true); try {
        await api.setStatus(c.id, c.status === 'active' ? 'inactive' : 'active');
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
    return <PageContainer><PageHeader title={clientId !== undefined ? 'API client details' : 'API clients'} description="Manage partner credentials and source IP access." actions={clientId === undefined && <Button onClick={() => { setForm(true); setCredentials(null); }}>New API Client</Button>}/><IntegrationNav />
 {error && <ErrorState message={error} onRetry={() => { setError(''); setReload(n => n + 1); }}/>}<p role="status" aria-label="Client action status">{notice}</p>
 {credentials && <Card><h2 className="font-semibold">Credentials shown once</h2><p className="text-sm mb-3">Save these credentials now. They will disappear when dismissed or when you leave this page.</p><label className="block mb-3">API key<input aria-label="API key" className={field} value={credentials.api_key} readOnly/></label><label className="block mb-3">Client secret<input aria-label="Client secret" className={field} value={credentials.raw_secret} readOnly autoComplete="off"/></label><Button variant="secondary" onClick={() => setCredentials(null)}>I have saved the credentials</Button></Card>}
 {form && <Card><h2 className="font-semibold mb-3">New API Client</h2><form onSubmit={create} className="grid gap-3">
 <label>Partner<select aria-label="Partner" className={field} required value={partner} onChange={e => setPartner(e.target.value)}><option value="">Select partner</option>{partner && !partners.some(p => p.id === partner) && <option value={partner}>Selected partner</option>}{partners.map(p => <option key={p.id} value={p.id}>{p.display_name}</option>)}</select></label>
 <label>Client name<input ref={nameRef} aria-label="Client name" className={field} required maxLength={255} value={name} onChange={e => setName(e.target.value)}/></label>
 <label>Permissions (comma-separated)<input aria-label="Permissions" className={field} value={permissions} onChange={e => setPermissions(e.target.value)} placeholder="Enter registered permission slugs"/></label>
 <label>IP whitelist (addresses or CIDRs)<textarea aria-label="IP whitelist" className={field} rows={3} value={ips} onChange={e => setIps(e.target.value)}/></label><p className="text-sm text-text-secondary">Separate entries with commas or new lines. An empty list allows any source IP.</p>
 <label>Requests per minute<input aria-label="Requests per minute" className={field} type="number" min={1} max={10000} value={rate} onChange={e => setRate(Number(e.target.value))}/></label>
 <div className="flex gap-2"><Button type="submit" disabled={busy}>Create client</Button><Button type="button" variant="secondary" onClick={() => setForm(false)}>Cancel</Button></div></form></Card>}
 {confirm && <Card><h2 className="font-semibold">{confirm.action === 'rotate' ? 'Rotate' : 'Revoke'} credentials for {confirm.name}?</h2><p className="my-3">{confirm.action === 'rotate' ? 'The current secret will stop working immediately.' : 'Revocation is permanent. All requests using these credentials will be rejected.'}</p><div className="flex gap-2"><Button variant="danger" disabled={busy} onClick={mutate}>Confirm {confirm.action}</Button><Button variant="secondary" onClick={() => setConfirm(null)}>Cancel</Button></div></Card>}
 {!rows ? <Loading label="Loading API clients"/> : <><Table data={rows.data} emptyMessage="No API clients found." getRowKey={c => c.id} columns={[{ key: 'name', header: 'Name' }, { key: 'api_key_prefix', header: 'Key prefix' }, { key: 'status', header: 'Status' }, { key: 'permissions', header: 'Permissions', render: c => c.permissions.join(', ') || 'None' }, { key: 'ip_whitelist', header: 'IP whitelist', render: c => c.ip_whitelist.join(', ') || 'Any source' }, { key: 'rate_limit_per_minute', header: 'Requests/min' }, { key: 'last_used_at', header: 'Last used', render: c => c.last_used_at === null ? 'Never' : new Date(c.last_used_at).toLocaleString() }, { key: 'actions', header: 'Actions', render: c => <div className="flex flex-wrap gap-2">{c.status !== 'revoked' && <><Button variant="secondary" disabled={busy} onClick={() => setConfirm({ id: c.id, name: c.name, action: 'rotate' })}>Rotate</Button><Button variant="danger" disabled={busy} onClick={() => setConfirm({ id: c.id, name: c.name, action: 'revoke' })}>Revoke</Button><Button variant="secondary" disabled={busy} onClick={() => toggle(c)}>{c.status === 'active' ? 'Deactivate' : 'Activate'}</Button></>}</div> }]}/><Pagination page={page} lastPage={rows.meta.last_page} onChange={setPage}/></>}
 </PageContainer>;
}
