'use client';
import { useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { SupportApi, field, message, type Case } from '../api';
export function CaseDetailPage({ id }: {
    id: string;
}) {
    const { authenticatedClient: client, hasPermission } = useAuth(), allowed = hasPermission('support.investigate'), api = useMemo(() => client ? new SupportApi(client) : null, [client]);
    const [row, setRow] = useState<Case | null>(null), [error, setError] = useState(''), [notice, setNotice] = useState(''), [busy, setBusy] = useState(false), [reload, setReload] = useState(0);
    const [note, setNote] = useState(''), [related, setRelated] = useState(''), [assignee, setAssignee] = useState(''), [reason, setReason] = useState('complex_investigation'), [resolution, setResolution] = useState(''), [file, setFile] = useState<File | null>(null);
    const [amount, setAmount] = useState(''), [refundReason, setRefundReason] = useState(''), [challenge, setChallenge] = useState(''), [methods, setMethods] = useState<string[]>([]), [method, setMethod] = useState('totp'), [credential, setCredential] = useState(''), [confirm, setConfirm] = useState(false);
    const [requestKey, setRequestKey] = useState('');
    const codeRef = useRef<HTMLInputElement>(null);
    useEffect(() => { if (challenge !== '')
        codeRef.current?.focus(); }, [challenge]);
    useEffect(() => { let active = true; if (api && allowed) {
        setError('');
        api.detail(id).then(r => { if (active)
            setRow(r); }).catch(e => { if (active)
            setError(message(e)); });
    } return () => { active = false; }; }, [api, allowed, id, reload]);
    useEffect(() => { setChallenge(''); setCredential(''); setRequestKey(''); }, [amount, refundReason, confirm, id]);
    async function action(fn: () => Promise<Case>, success: string) { setBusy(true); setError(''); setNotice(''); try {
        setRow(await fn());
        setNotice(success);
        return true;
    }
    catch (e) {
        setError(message(e));
        return false;
    }
    finally {
        setBusy(false);
    } }
    async function startRefund() { if (!api || !Number.isSafeInteger(Number(amount)) || Number(amount) <= 0 || !refundReason.trim()) {
        setError('Enter a positive amount in minor units and a refund reason.');
        return;
    } setBusy(true); setError(''); try {
        const c = await api.challenge(id);
        const valid = c.methods.filter(m => m === 'totp' || m === 'pin');
        if (!c.step_up_required || !c.step_up_token || valid.length === 0)
            throw Error('An enrolled authenticator or PIN is required.');
        setChallenge(c.step_up_token);
        setMethods(valid);
        setMethod(valid[0] ?? 'totp');
        setRequestKey(crypto.randomUUID());
        setNotice('Review the amount and reason, then verify to confirm the refund.');
    }
    catch (e) {
        setError(message(e));
    }
    finally {
        setBusy(false);
    } }
    async function finishRefund() { if (!api || !challenge || !credential.trim())
        return; const current = api; await action(async () => { const proof = await current.verify(challenge, method, credential); setCredential(''); setChallenge(''); return current.refund(id, Number(amount), refundReason, proof, requestKey, confirm); }, 'Refund submitted. Review the case status for completion or second approval.'); }
    async function download(entry: string, name: string) { if (!api)
        return; setBusy(true); setError(''); try {
        const blob = await api.download(id, entry);
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    catch (e) {
        setError(message(e));
    }
    finally {
        setBusy(false);
    } }
    if (!allowed)
        return <ErrorState message="You do not have permission to view support cases."/>;
    if (!row)
        return <PageContainer>{error ? <ErrorState message={error} onRetry={() => setReload(n => n + 1)}/> : <Loading label="Loading case"/>}</PageContainer>;
    const terminal = row.status === 'Resolved';
    return <PageContainer><Link className="underline" href="/support/cases">Back to support queue</Link><PageHeader title={row.number} description={row.type + ' case · ' + row.status}/>
 {error && <ErrorState message={error}/>} {notice && <p role="status" aria-label="Case action status">{notice}</p>}
 <div className="grid gap-4 lg:grid-cols-2">
 <Card><h2 className="mb-3 font-semibold">Case overview</h2><p className="whitespace-pre-wrap break-words">{row.description}</p><dl className="mt-4 grid gap-2 text-sm"><div><dt>Customer</dt><dd className="break-all"><Link className="underline" href={'/users/' + row.customer_id}>View customer {row.customer_id}</Link></dd></div><div><dt>Priority</dt><dd>{row.priority}</dd></div><div><dt>Assigned</dt><dd className="break-all">{row.assigned_id ?? 'Unassigned'}</dd></div><div><dt>Created</dt><dd>{new Date(row.created_at).toLocaleString()}</dd></div>{row.deadline !== null && <div><dt>Escalation deadline (24 hours)</dt><dd className={row.overdue ? 'text-error-primary font-semibold' : ''}>{new Date(row.deadline).toLocaleString()} {row.overdue ? 'Overdue' : ''}</dd></div>}</dl>
 <h3 className="mt-4 font-semibold">Related transactions</h3>{(row.transaction_ids?.length ?? 0) > 0 ? row.transaction_ids?.map(t => <p key={t} className="break-all"><Link className="underline" href={'/transactions/' + t}>{t}</Link></p>) : <p>No linked transaction.</p>}
 </Card>
 <Card><h2 className="mb-3 font-semibold">Evidence files</h2>{(row.evidence?.length ?? 0) > 0 ? row.evidence?.map(e => <p key={e.id}><button className="underline break-all text-left" disabled={busy} onClick={() => void download(e.id, e.filename)}>{e.filename}</button></p>) : <p>No attachments.</p>}
 {!terminal && <form className="mt-4 space-y-3" onSubmit={e => { e.preventDefault(); if (api && file)
        void action(() => api.attach(id, file), 'Attachment saved privately.'); }}><label className="block text-sm">Attach investigation evidence<input aria-label="Attach investigation evidence" className={field} type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={e => setFile(e.target.files?.[0] ?? null)}/></label><p className="text-sm text-text-secondary">JPEG, PNG, WebP or PDF; up to 10 MB. Files remain private.</p><Button disabled={busy || !file} type="submit">Attach file</Button></form>}
 </Card>
 <Card><h2 className="mb-3 font-semibold">Conversation timeline</h2>{(row.timeline?.length ?? 0) > 0 ? <ol className="space-y-4">{row.timeline?.map(e => <li key={e.id} className="border-l-2 border-border-primary pl-3"><p className="font-medium">{e.kind === 'note' ? (e.payload['internal'] === true ? 'Internal note' : 'Public note') : e.kind}</p><p className="text-xs text-text-secondary break-all">{new Date(e.created_at).toLocaleString()} · {e.actor_id}</p>{typeof e.payload['content'] === 'string' && <p className="whitespace-pre-wrap break-words">{e.payload['content']}</p>}{typeof e.payload['related_case_id'] === 'string' && <Link className="underline break-all" href={'/support/cases/' + e.payload['related_case_id']}>Related case {e.payload['related_case_id']}</Link>}{typeof e.payload['reason'] === 'string' && <p>{e.payload['reason']}</p>}</li>)}</ol> : <p>No conversation yet.</p>}
 {!terminal && <form className="mt-4 space-y-3" onSubmit={e => { e.preventDefault(); if (api)
        void action(() => api.update(id, { action: 'note', content: note }), 'Internal note added.').then(saved => { if(saved) setNote(''); }); }}><label className="block text-sm">Internal note<textarea aria-label="Internal note" className={field} required maxLength={10000} value={note} onChange={e => setNote(e.target.value)}/></label><Button disabled={busy || !note.trim()} type="submit">Add internal note</Button></form>}
 </Card>
 {!terminal && <Card><h2 className="mb-3 font-semibold">Investigation actions</h2><div className="space-y-4">
 {['open', 'raised', 'evidence_submitted'].includes(row.raw_status) && <Button disabled={busy} onClick={() => api && void action(() => api.update(id, { action: 'investigate' }), 'Investigation started.')}>Start investigation</Button>}
 <form className="space-y-2" onSubmit={e => { e.preventDefault(); if (api)
            void action(() => api.update(id, { action: 'link', related_case_id: related }), 'Related case linked.'); }}><label className="block text-sm">Related case ID<input aria-label="Related case ID" className={field} required value={related} onChange={e => setRelated(e.target.value)}/></label><Button variant="secondary" disabled={busy || !related} type="submit">Link case</Button></form>
 {(hasPermission('support.assign') || hasPermission('dispute.escalate')) && <form className="space-y-2" onSubmit={e => { e.preventDefault(); if (api)
            void action(() => api.update(id, { action: 'assign', assignee_id: assignee }), 'Assignment saved.'); }}><label className="block text-sm">Support agent ID<input aria-label="Support agent ID" className={field} required value={assignee} onChange={e => setAssignee(e.target.value)}/></label>{hasPermission('support.assign') && <Button variant="secondary" disabled={busy || !assignee} type="submit">Assign case</Button>}</form>}
 {hasPermission('dispute.escalate') && row.status !== 'Escalated' && <form className="space-y-2" onSubmit={e => { e.preventDefault(); if (api)
            void action(() => api.update(id, { action: 'escalate', assignee_id: assignee, reason }), 'Escalated. Senior agent notification queued; 24-hour SLA started.'); }}><label className="block text-sm">Escalation reason<select aria-label="Escalation reason" className={field} value={reason} onChange={e => setReason(e.target.value)}>{['complex_investigation', 'financial_review', 'security_review', 'customer_appeal', 'other'].map(r => <option key={r} value={r}>{r.replaceAll('_', ' ')}</option>)}</select></label><p className="text-sm">Enter an eligible senior in Support agent ID above. The senior must have support assignment and investigation permissions.</p><Button disabled={busy || !assignee} type="submit">Escalate case</Button></form>}
 {row.type === 'Support' && row.raw_status === 'in_progress' && hasPermission('support.resolve') && <form className="space-y-2" onSubmit={e => { e.preventDefault(); if (api)
            void action(() => api.resolve(id, resolution), 'Case resolved.'); }}><label className="block text-sm">Resolution note<textarea aria-label="Resolution note" className={field} required value={resolution} onChange={e => setResolution(e.target.value)}/></label><Button disabled={busy || !resolution.trim()} type="submit">Resolve case</Button></form>}
 </div></Card>}
 {row.type === 'Dispute' && !terminal && hasPermission('dispute.refund') && <Card><h2 className="mb-3 font-semibold">Dispute refund</h2>{row.raw_status !== 'approved' ? <p>Refund is available after the existing dispute approval workflow completes.</p> : <div className="space-y-3"><p className="text-sm">Refunds require identity verification and create a compensating transaction. A partial refund requires justification in the reason.</p>{row.refund_pending_confirmation === true && <p className="font-semibold">Pending independent confirmation: {row.refund_amount_minor} minor units. Reason: {row.refund_reason}</p>}
 <label className="block text-sm">Amount (minor units)<input aria-label="Amount (minor units)" className={field} type="number" min={1} step={1} required value={amount} onChange={e => setAmount(e.target.value)}/></label>
 <label className="block text-sm">Refund reason<textarea aria-label="Refund reason" className={field} required value={refundReason} onChange={e => setRefundReason(e.target.value)}/></label>
 {row.refund_pending_confirmation === true && <label className="flex gap-2"><input type="checkbox" checked={confirm} onChange={e => setConfirm(e.target.checked)}/>Confirm another administrator&apos;s pending refund</label>}
 {!challenge ? <Button disabled={busy || !amount || !refundReason.trim()} onClick={() => void startRefund()}>Initiate Refund</Button> : <form className="space-y-3" onSubmit={e => { e.preventDefault(); void finishRefund(); }}><p className="font-semibold">Confirm refund: {amount} minor units</p><label className="block text-sm">Verification method<select aria-label="Verification method" className={field} value={method} onChange={e => setMethod(e.target.value)}>{methods.map(m => <option key={m} value={m}>{m === 'totp' ? 'Authenticator' : 'Transaction PIN'}</option>)}</select></label><label className="block text-sm">Verification code<input ref={codeRef} aria-label="Verification code" className={field} type="password" autoComplete="one-time-code" required value={credential} onChange={e => setCredential(e.target.value)}/></label><Button disabled={busy || !credential} type="submit">Verify and confirm refund</Button><Button variant="secondary" disabled={busy} onClick={() => { setChallenge(''); setCredential(''); }}>Cancel</Button></form>}
 </div>}</Card>}
 </div></PageContainer>;
}
