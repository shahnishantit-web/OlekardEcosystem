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
import { errorMessage, statusLabel } from '../api';
interface SecurityEvent {
    id: string;
    source: string;
    event_type: string;
    severity: string;
    occurred_at: string;
    actor_id: string | null;
    action: string;
    outcome: string;
    correlation_id: string;
}
interface EventPage {
    items: SecurityEvent[];
    next_cursor: string | null;
}
export function SecurityEventsPage() {
    const { authenticatedClient: client, hasPermission } = useAuth();
    const read = hasPermission('security.events.read');
    const [data, setData] = useState<EventPage>({ items: [], next_cursor: null }), [severity, setSeverity] = useState(''), [type, setType] = useState(''), [cursor, setCursor] = useState<string | null>(null), [back, setBack] = useState<(string | null)[]>([]), [loading, setLoading] = useState(true), [error, setError] = useState(''), [notice, setNotice] = useState(''), [busy, setBusy] = useState(false);
    const query = useCallback(() => { const q = new URLSearchParams(); if (severity !== '')
        q.set('severity', severity); if (type !== '')
        q.set('event_type', type); return q; }, [severity, type]);
    const load = useCallback(async () => { if (!client || !read)
        return; setLoading(true); try {
        const q = query();
        if (cursor !== null)
            q.set('cursor', cursor);
        setData((await client.get<EventPage>('/v1/admin/security/events?' + q)).data);
        setError('');
    }
    catch (e) {
        setError(errorMessage(e));
    }
    finally {
        setLoading(false);
    } }, [client, read, query, cursor]);
    useEffect(() => { void load(); }, [load]);
    async function download() { if (!client)
        return; setBusy(true); try {
        const blob = await client.getBlob('/v1/admin/security/events/export?' + query());
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'security-events.ndjson';
        a.click();
        URL.revokeObjectURL(url);
        setNotice('Security events exported for SIEM ingestion.');
    }
    catch (e) {
        setNotice(errorMessage(e));
    }
    finally {
        setBusy(false);
    } }
    if (!hasPermission('security.events.read'))
        return <PageContainer><PageHeader title="Security Events"/><p role="alert">Access denied</p></PageContainer>;
    return <PageContainer><PageHeader title="Security Events" actions={hasPermission('security.events.export') ? <Button disabled={busy} onClick={() => void download()}>Export SIEM JSONL</Button> : undefined}/><p>Security-classified authentication, privilege, configuration and API credential events. Export uses the selected filters and excludes raw payloads and credentials.</p>{notice !== '' && <p role="status">{notice}</p>}<Card><div className="flex flex-wrap gap-4"><label>Severity<select aria-label="Security event severity" className="block border rounded p-2" value={severity} onChange={e => { setSeverity(e.target.value); setCursor(null); setBack([]); }}><option value="">All severities</option>{['critical', 'high', 'medium', 'low', 'info'].map(s => <option key={s} value={s}>{statusLabel(s)}</option>)}</select></label><label>Event type<select aria-label="Security event type" className="block border rounded p-2" value={type} onChange={e => { setType(e.target.value); setCursor(null); setBack([]); }}><option value="">All security events</option>{['login_failed', 'privilege_change', 'config_change', 'api_key_rotation', 'security'].map(t => <option key={t} value={t}>{statusLabel(t)}</option>)}</select></label></div>
 {loading ? <Loading label="Loading security events"/> : error !== '' ? <ErrorState message={error} onRetry={load}/> : <><div className="overflow-x-auto"><Table data={data.items} getRowKey={e => e.source + ':' + e.id} emptyMessage="No security events match these filters." columns={[{ key: 'time', header: 'Timestamp (UTC)', render: e => e.occurred_at }, { key: 'type', header: 'Event type', render: e => statusLabel(e.event_type) }, { key: 'severity', header: 'Severity', render: e => statusLabel(e.severity) }, { key: 'actor', header: 'Actor', render: e => e.actor_id ?? 'Anonymous / system' }, { key: 'action', header: 'Action', render: e => e.action }, { key: 'outcome', header: 'Outcome', render: e => e.outcome }, { key: 'correlation', header: 'Correlation', render: e => e.correlation_id }]}/></div><div className="flex flex-wrap gap-3"><Button disabled={back.length === 0} onClick={() => { setCursor(back[back.length - 1] ?? null); setBack(back.slice(0, -1)); }}>Previous events</Button><Button disabled={data.next_cursor === null} onClick={() => { setBack([...back, cursor]); setCursor(data.next_cursor); }}>Next events</Button></div></>}
 </Card></PageContainer>;
}
