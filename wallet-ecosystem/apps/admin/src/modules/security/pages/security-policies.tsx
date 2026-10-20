'use client';
import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Table } from '@/components/ui/Table';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { AdminSecurityConfigPage } from '@/src/modules/configuration/pages/security-config';
import { errorMessage } from '../api';
interface Setting {
    key: string;
    label: string;
    type: string;
    value: unknown;
    configured: boolean;
    last_modified_by: string | null;
    last_modified_at: string | null;
    pending: {
        id: string;
        maker_id: string;
        value: unknown;
    } | null;
}
interface PolicyData {
    settings: Setting[];
    roles: {
        slug: string;
        name: string;
    }[];
}
function display(v: unknown, type?: string): string { if (v === null)
    return 'Unconfigured'; if (Array.isArray(v))
    return v.length > 0 ? v.join(', ') : type === 'roles' ? 'Existing MFA requirements' : 'No IP restrictions'; if (typeof v === 'object')
    return Object.entries(v as Record<string, unknown>).map(([k, x]) => k + ': ' + String(x)).join(', ') || 'Existing MFA requirements'; return String(v); }
export function SecurityPoliciesPanel() {
    const { authenticatedClient: client, hasPermission, user } = useAuth();
    const [data, setData] = useState<PolicyData>({ settings: [], roles: [] }), [loading, setLoading] = useState(true), [error, setError] = useState(''), [notice, setNotice] = useState(''), [edit, setEdit] = useState<Setting | null>(null), [draft, setDraft] = useState<unknown>(null), [busy, setBusy] = useState(false);
    const load = useCallback(async () => { if (!client)
        return; setLoading(true); try {
        setData((await client.get<PolicyData>('/v1/admin/platform/security-config')).data);
        setError('');
    }
    catch (e) {
        setError(errorMessage(e));
    }
    finally {
        setLoading(false);
    } }, [client]);
    useEffect(() => { void load(); }, [load]);
    async function save(e: React.FormEvent) { e.preventDefault(); if (!client || edit === null)
        return; setBusy(true); try {
        await client.patch('/v1/admin/platform/security-config', { key: edit.key, value: draft }, { idempotencyKey: crypto.randomUUID() });
        setNotice('Policy submitted. A different administrator must approve it before it takes effect.');
        setEdit(null);
        await load();
    }
    catch (e) {
        setNotice(errorMessage(e));
    }
    finally {
        setBusy(false);
    } }
    async function approve(s: Setting) { if (!client || s.pending === null)
        return; setBusy(true); try {
        await client.post('/v1/admin/platform/security-config/' + s.pending.id + '/approve', {}, { idempotencyKey: crypto.randomUUID() });
        setNotice('Approved policy applied.');
        await load();
    }
    catch (e) {
        setNotice(errorMessage(e));
    }
    finally {
        setBusy(false);
    } }
    if (loading)
        return <Loading label="Loading security policies"/>;
    if (error !== '')
        return <ErrorState message={error} onRetry={load}/>;
    return <><p>Password, session, MFA and administration network changes require approval. Optional MFA preserves enrolled-factor and step-up requirements. Unconfigured limits preserve existing behavior.</p>{notice !== '' && <p role="status">{notice}</p>}<Card><div className="overflow-x-auto"><Table data={data.settings} getRowKey={s => s.key} emptyMessage="No security policies." columns={[
            { key: 'label', header: 'Policy', render: s => s.label }, { key: 'value', header: 'Current value', render: s => display(s.value, s.type) }, { key: 'actor', header: 'Last modified by', render: s => s.last_modified_by ?? 'Existing baseline / unconfigured' }, { key: 'time', header: 'Modified (UTC)', render: s => s.last_modified_at ?? '—' },
            { key: 'pending', header: 'Approval', render: s => s.pending !== null ? <div><p>Proposed: {display(s.pending.value, s.type)}</p><p>Maker: {s.pending.maker_id}</p>{hasPermission('security.config.write') && hasPermission('configuration.approve') && <Button disabled={busy || s.pending.maker_id === user?.id} onClick={() => void approve(s)}>Approve {s.label}</Button>}</div> : hasPermission('security.config.write') ? <Button disabled={busy} onClick={() => { setEdit(s); setDraft(s.value); }}>Edit {s.label}</Button> : null }
        ]}/></div></Card>{edit !== null && <Card className="p-4"><h2>Edit {edit.label}</h2><form onSubmit={e => void save(e)} className="space-y-4">
 {edit.type === 'boolean' ? <label>New value<select aria-label="Policy value" className="block border rounded p-2" value={String(draft)} onChange={e => setDraft(e.target.value === 'true')}><option value="true">Required / enabled</option><option value="false">Optional / disabled</option></select></label> : edit.type === 'roles' ? <fieldset><legend>MFA by role</legend>{data.roles.map(r => <label key={r.slug} className="block">{r.name}<select aria-label={'MFA for ' + r.name} className="block border rounded p-2" value={(draft as Record<string, string>)[r.slug] ?? 'optional'} onChange={e => setDraft({ ...draft as object, [r.slug]: e.target.value })}><option value="optional">Optional (existing requirements retained)</option><option value="required">Required</option></select></label>)}</fieldset> : edit.type === 'cidrs' ? <label>Admin IP addresses or networks, one per line<textarea aria-label="Admin IP allowlist" className="block border rounded p-2 w-full" value={(draft as string[]).join('\n')} onChange={e => setDraft(e.target.value.split('\n').map(x => x.trim()).filter(Boolean))}/></label> : <label>New value{edit.type === 'nullable_integer' && ' (blank = unconfigured)'}<input aria-label="Policy value" type="number" min={edit.key === 'auth.password.min_length' ? 10 : 1} step="1" required={edit.type === 'integer'} className="block border rounded p-2" value={draft === null ? '' : String(draft)} onChange={e => setDraft(e.target.value === '' ? null : Number(e.target.value))}/></label>}
 <div className="flex flex-wrap gap-3"><Button type="submit" disabled={busy}>Submit policy for approval</Button><Button type="button" variant="secondary" disabled={busy} onClick={() => setEdit(null)}>Cancel</Button></div></form></Card>}</>;
}
export function SecurityManagementConfigPage() {
    const { hasPermission } = useAuth();
    return <>{hasPermission('security.config.read') ? <PageContainer><PageHeader title="Platform Security Policies"/><SecurityPoliciesPanel /></PageContainer> : <PageContainer><PageHeader title="Platform Security Policies"/><p role="alert">Access denied</p></PageContainer>}{hasPermission('configuration.policy.view') && <details className="px-8 py-4"><summary>Advanced authentication settings</summary><AdminSecurityConfigPage /></details>}</>;
}
