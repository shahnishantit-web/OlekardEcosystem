'use client';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Table } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { SecurityApi, type AlertDetail, errorMessage, displayDate, closed, statusLabel } from '../api';
export function AlertDetailPage({ id }: {
    id: string;
}) {
    const { authenticatedClient, hasPermission, user } = useAuth();
    const allowed = hasPermission('security.alerts.read'), manage = hasPermission('risk.manage_alerts'), freeze = hasPermission('risk.freeze_account');
    const api = useMemo(() => authenticatedClient ? new SecurityApi(authenticatedClient) : null, [authenticatedClient]);
    const [alert, setAlert] = useState<AlertDetail | null>(null), [error, setError] = useState(''), [busy, setBusy] = useState(false), [message, setMessage] = useState('');
    const [note, setNote] = useState(''), [related, setRelated] = useState(''), [reason, setReason] = useState(''), [outcome, setOutcome] = useState('false_positive');
    const [dialog, setDialog] = useState<'freeze' | 'resolve' | 'escalate' | null>(null);
    const load = useCallback(async (signal?: AbortSignal) => { if (!api || !allowed)
        return; try {
        const a = await api.detail(id);
        if (signal?.aborted !== true)
            setAlert(a);
    }
    catch (e) {
        if (signal?.aborted !== true)
            setError(errorMessage(e));
    } }, [api, id, allowed]);
    useEffect(() => { const c = new AbortController(); void load(c.signal); return () => c.abort(); }, [load]);
    async function act(action: string, body: object = {}) {
        if (!api || !alert || busy)
            return;
        setBusy(true);
        setError('');
        setMessage('');
        try {
            await api.action(id, action, { version: alert.version, ...body });
            await load();
            setMessage('Investigation updated.');
            setDialog(null);
            if (action === 'notes')
                setNote('');
            if (action === 'links')
                setRelated('');
            setReason('');
        }
        catch (e) {
            setError(errorMessage(e));
        }
        finally {
            setBusy(false);
        }
    }
    if (!allowed)
        return <PageContainer><p role="alert">Access denied</p></PageContainer>;
    if (!alert)
        return <PageContainer>{error ? <ErrorState message={error} onRetry={() => { setError(''); void load(); }}/> : <Loading label="Loading investigation"/>}</PageContainer>;
    const terminal = closed(alert.status);
    return <PageContainer><Link className="underline text-primary" href="/security/dashboard">Back to risk queue</Link><PageHeader title={alert.title}/>
    <div className="flex flex-wrap items-center gap-3 mb-4"><Badge variant={['critical', 'high'].includes(alert.severity) ? 'error' : 'warning'}>{alert.severity.toUpperCase()}</Badge><Badge>{statusLabel(alert.status)}</Badge><span className="break-all">{alert.subject_type}: {alert.subject_id}</span></div>
    {message && <p role="status" className="mb-4 text-success">{message}</p>}{error && !dialog && <p role="alert" className="mb-4 text-error">{error}</p>}
    <Card><h2 className="text-lg font-semibold mb-3">Alert trigger details</h2><p>{alert.trigger.description}</p><p className="mt-3 text-text-secondary">Created {displayDate(alert.created_at)} · Assigned to {alert.assigned_to ?? 'Unassigned'}</p>
    <div className="flex flex-wrap gap-3 mt-5">
    {manage && <><Button disabled={busy || terminal || alert.assigned_to === user?.id} onClick={() => void act('assign')}>Assign to me</Button><Button variant="secondary" disabled={busy || terminal} onClick={() => { setError(''); setReason(''); setDialog('resolve'); }}>Resolve alert</Button><Button variant="secondary" disabled={busy || terminal || alert.status === 'escalated'} onClick={() => { setError(''); setDialog('escalate'); }}>Escalate</Button></>}
    {alert.active_hold ? <p role="status">Account is Frozen · {displayDate(alert.active_hold.placed_at)}</p> : freeze && ['customer', 'agent', 'wallet', 'user'].includes(alert.subject_type) && <Button variant="danger" disabled={busy || terminal || alert.freeze_requests.some(r => r.status === 'pending')} onClick={() => { setError(''); setReason(''); setDialog('freeze'); }}>Freeze Account</Button>}
    </div></Card>
    {alert.freeze_requests.filter(r => r.status === 'pending').map(r => <Card key={r.id}><h2 className="text-lg font-semibold">Pending freeze approval</h2><p>{r.reason}</p><p>Requested by {r.maker_id}. A separate authorized administrator must approve.</p>{freeze && r.maker_id !== user?.id && <Button variant="danger" disabled={busy || terminal} onClick={() => void act('freeze-requests/' + r.id + '/approve')}>Approve freeze</Button>}</Card>)}
    <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
    <Card><h2 className="text-lg font-semibold mb-3">Affected transactions</h2><Table data={alert.affected_transactions} getRowKey={r => r.reference} emptyMessage="No linked transactions." columns={[{ key: 'reference', header: 'Reference', render: r => <Link className="underline" href={'/transactions?search=' + encodeURIComponent(r.reference)}>{r.reference}</Link> }, { key: 'detected_at', header: 'Detected', render: r => displayDate(r.detected_at) }]}/></Card>
    <Card><h2 className="text-lg font-semibold mb-3">Risk score timeline</h2><Table data={alert.risk_timeline} getRowKey={r => r.id} emptyMessage="No risk scores recorded." columns={[{ key: 'score', header: 'Score', render: r => r.score }, { key: 'risk_level', header: 'Risk level', render: r => r.risk_level }, { key: 'evaluated_at', header: 'Evaluated', render: r => displayDate(r.evaluated_at) }]}/></Card></div>
    <Card><h2 className="text-lg font-semibold mb-3">Entity history</h2><Table data={alert.entity_history} getRowKey={r => r.id} emptyMessage="No entity history recorded." columns={[{ key: 'event_type', header: 'Event', render: r => r.event_type }, { key: 'severity', header: 'Severity', render: r => r.severity }, { key: 'detected_at', header: 'Detected', render: r => displayDate(r.detected_at) }]}/></Card>
    <Card><h2 className="text-lg font-semibold mb-3">Investigation notes</h2>{alert.notes.length === 0 ? <p>No investigation notes yet.</p> : <ol className="space-y-3">{alert.notes.map(n => <li key={n.id}><p className="whitespace-pre-wrap">{n.note}</p><small>{n.actor_id} · {displayDate(n.created_at)}</small></li>)}</ol>}
    {manage && !terminal && <div className="mt-4"><label htmlFor="investigation-note" className="block">Investigation note</label><textarea id="investigation-note" className="border rounded p-3 w-full" maxLength={4000} rows={3} value={note} onChange={e => setNote(e.target.value)}/><Button disabled={busy || !note.trim()} onClick={() => void act('notes', { note })}>Add note</Button></div>}</Card>
    <Card><h2 className="text-lg font-semibold mb-3">Related alerts</h2>{alert.related_alerts.length ? <ul>{alert.related_alerts.map(r => <li key={r.id}><Link className="underline" href={'/security/alerts/' + r.id}>{r.title}</Link></li>)}</ul> : <p>No related alerts linked.</p>}
    {manage && !terminal && <div className="mt-4 flex flex-wrap items-end gap-3"><label htmlFor="related-alert">Related alert ID<input id="related-alert" className="block border rounded p-2" value={related} onChange={e => setRelated(e.target.value)}/></label><Button disabled={busy || !related.trim()} onClick={() => void act('links', { related_alert_id: related.trim() })}>Link alert</Button></div>}</Card>
    {alert.decisions.length > 0 && <Card><h2 className="text-lg font-semibold">Decisions</h2>{alert.decisions.map(d => <p key={d.id}>{statusLabel(d.decision)}: {d.rationale} · {displayDate(d.decided_at)}</p>)}</Card>}
        {dialog && <InvestigationDialog title={dialog === 'freeze' ? 'Freeze account for investigation' : dialog === 'resolve' ? 'Resolve risk alert' : 'Escalate risk alert'} onClose={() => { if (!busy)
            setDialog(null); }}>
        {dialog === 'freeze' && <><p>Request a freeze of this account. A different authorized administrator must approve before the hold takes effect.</p><label htmlFor="freeze-reason" className="block mt-4">Freeze reason</label><textarea id="freeze-reason" className="border rounded p-3 w-full" value={reason} maxLength={2000} onChange={e => setReason(e.target.value)}/></>}
        {dialog === 'resolve' && <><label htmlFor="outcome">Outcome</label><select id="outcome" className="block border p-2" value={outcome} onChange={e => setOutcome(e.target.value)}><option value="false_positive">False positive</option><option value="confirmed_fraud">Confirmed fraud</option></select><label htmlFor="resolution-reason">Resolution reason</label><textarea id="resolution-reason" className="block border rounded p-3 w-full" value={reason} maxLength={4000} onChange={e => setReason(e.target.value)}/></>}
        {dialog === 'escalate' && <p>Escalate this alert for further investigation?</p>}
        {error && <p role="alert" className="text-error mt-3">{error}</p>}
        <div className="flex gap-3 mt-4"><Button variant="secondary" disabled={busy} onClick={() => setDialog(null)}>Cancel</Button><Button variant={dialog === 'freeze' ? 'danger' : 'primary'} disabled={busy || (dialog !== 'escalate' && reason.trim().length < 10)} onClick={() => void act(dialog === 'freeze' ? 'freeze-requests' : dialog, dialog === 'freeze' ? { reason } : dialog === 'resolve' ? { outcome, rationale: reason } : {})}>{dialog === 'freeze' ? 'Request freeze' : dialog === 'resolve' ? 'Confirm resolution' : 'Confirm escalation'}</Button></div>
        </InvestigationDialog>}
    </PageContainer>;
}
function InvestigationDialog({ title, onClose, children }: {
    title: string;
    onClose: () => void;
    children: ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;
    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        const panel = ref.current;
        panel?.focus();
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape')
                closeRef.current();
            if (event.key !== 'Tab')
                return;
            const items = panel?.querySelectorAll<HTMLElement>('button:not(:disabled),textarea,select,input,a[href]');
            if (items === undefined || items.length === 0)
                return;
            const first = items[0], last = items[items.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
                event.preventDefault();
                last?.focus();
            }
            else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        };
        panel?.addEventListener('keydown', onKey);
        return () => { panel?.removeEventListener('keydown', onKey); previous?.focus(); };
    }, []);
    return <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
    <div ref={ref} role="dialog" aria-modal="true" aria-label={title} tabIndex={-1} className="bg-ok-card-bg text-text-primary rounded-xl shadow-xl p-6 w-full max-w-xl max-h-[90vh] overflow-auto">
      <h2 className="text-xl font-semibold mb-4">{title}</h2>{children}
    </div>
  </div>;
}
