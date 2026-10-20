'use client';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Table } from '@/components/ui/Table';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { NotificationsApi, errorMessage, type Rule } from './api';
import { NotificationNav } from './shared';
export function NotificationRulesPage() {
    const { authenticatedClient: client, hasPermission } = useAuth(), api = useMemo(() => client ? new NotificationsApi(client) : null, [client]);
    const read = hasPermission('notifications.rules.read'), write = hasPermission('notifications.rules.write');
    const [rows, setRows] = useState<Rule[] | null>(null), [reload, setReload] = useState(0), [error, setError] = useState(''), [notice, setNotice] = useState(''), [busy, setBusy] = useState(false);
    useEffect(() => { let active = true; if (api && read)
        api.rules().then(r => { if (active)
            setRows(r); }).catch(e => { if (active)
            setError(errorMessage(e)); }); return () => { active = false; }; }, [api, read, reload]);
    async function toggle(r: Rule) { if (!api)
        return; setBusy(true); setError(''); try {
        await api.updateRule(r);
        setRows(await api.rules());
        setNotice('Rule saved. Applies to new events immediately.');
    }
    catch (e) {
        setError(errorMessage(e));
    }
    finally {
        setBusy(false);
    } }
    if (!read)
        return <PageContainer><p role="alert">Access denied</p></PageContainer>;
    return <PageContainer><PageHeader title="Notification rules" description="Control optional event channels. Financial and security notifications remain mandatory."/><NotificationNav />
 {error && <ErrorState message={error} onRetry={() => { setError(''); setReload(n => n + 1); }}/>}<p role="status" className="mb-3">{notice}</p>
 {!rows ? <Loading label="Loading notification rules"/> : <Table data={rows} getRowKey={r => r.template_id + ':' + r.channel} emptyMessage="No rules available. Rules are derived from existing templates." columns={[
                { key: 'event', header: 'Event type' }, { key: 'channel', header: 'Channel' }, { key: 'policy', header: 'Policy', render: r => r.mandatory ? 'Mandatory - locked' : 'Optional' },
                { key: 'enabled', header: 'State', render: r => <Button role="switch" aria-checked={r.enabled} aria-label={r.event + ' ' + r.channel} variant="secondary" disabled={busy || !write || r.mandatory} onClick={() => toggle(r)}>{r.enabled ? 'Enabled' : 'Disabled'}</Button> }
            ]}/>}</PageContainer>;
}
