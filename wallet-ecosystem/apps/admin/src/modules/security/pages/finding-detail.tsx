'use client';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { errorMessage, statusLabel } from '../api';
interface FindingDetail {
    id: string;
    title: string;
    component: string;
    severity: string;
    description: string | null;
    cvss_score: string | null;
    remediation_steps: string | null;
    status: string;
    version: number;
    timeline: {
        id: string;
        action: string;
        status: string | null;
        actor_id: string | null;
        occurred_at: string;
    }[];
}
export function FindingDetailPage({ id }: {
    id: string;
}) {
    const { authenticatedClient: client, hasPermission } = useAuth();
    const [row, setRow] = useState<FindingDetail | null>(null), [loading, setLoading] = useState(true), [error, setError] = useState(''), [notice, setNotice] = useState(''), [busy, setBusy] = useState(false), [score, setScore] = useState(''), [steps, setSteps] = useState('');
    const load = useCallback(async () => { if (!client)
        return; setLoading(true); try {
        const r = (await client.get<FindingDetail>('/v1/admin/security/findings/' + encodeURIComponent(id))).data;
        setRow(r);
        setScore(r.cvss_score ?? '');
        setSteps(r.remediation_steps ?? '');
        setError('');
    }
    catch (e) {
        setError(errorMessage(e));
    }
    finally {
        setLoading(false);
    } }, [client, id]);
    useEffect(() => { void load(); }, [load]);
    async function save(status?: string) { if (!client || row === null)
        return; setBusy(true); setNotice(''); try {
        const r = await client.patch<FindingDetail>('/v1/admin/security/findings/' + encodeURIComponent(id), { version: row.version, ...(status !== undefined ? { status } : {}), cvss_score: score === '' ? null : score, remediation_steps: steps === '' ? null : steps }, { idempotencyKey: crypto.randomUUID() });
        setRow(r.data);
        setNotice('Finding updated and audited.');
    }
    catch (e) {
        setNotice(errorMessage(e));
    }
    finally {
        setBusy(false);
    } }
    if (!hasPermission('security.findings.read'))
        return <PageContainer><PageHeader title="Finding investigation"/><p role="alert">Access denied</p></PageContainer>;
    if (loading)
        return <Loading label="Loading finding"/>;
    if (error !== '')
        return <ErrorState message={error} onRetry={load}/>;
    if (row === null)
        return null;
    const write = hasPermission('security.findings.write'), next = row.status === 'resolved' ? 'verified' : ['open', 'acknowledged', 'suppressed'].includes(row.status) ? 'in_remediation' : row.status === 'in_remediation' ? 'resolved' : null;
    return <PageContainer><PageHeader title={row.title}/><Link href="/security/findings">Back to vulnerability findings</Link>{notice !== '' && <p role="status">{notice}</p>}<Card className="p-4 space-y-3"><dl className="grid gap-2 sm:grid-cols-2"><dt>Component</dt><dd>{row.component}</dd><dt>Severity</dt><dd>{statusLabel(row.severity)}</dd><dt>Status</dt><dd>{statusLabel(row.status)}</dd><dt>CVSS score</dt><dd>{row.cvss_score ?? 'Not provided by scanner'}</dd></dl><p>{row.description ?? 'No description provided.'}</p><h2>Remediation steps</h2><p className="whitespace-pre-wrap">{row.remediation_steps ?? 'No remediation steps recorded.'}</p>
 {write && <form className="space-y-4" onSubmit={e => { e.preventDefault(); void save(); }}><label className="block">CVSS score (0–10)<input aria-label="CVSS score" type="number" min="0" max="10" step="0.1" className="block rounded border p-2" value={score} onChange={e => setScore(e.target.value)}/></label><label className="block">Remediation steps<textarea aria-label="Remediation steps" maxLength={20000} className="block rounded border p-2 w-full" value={steps} onChange={e => setSteps(e.target.value)}/></label><div className="flex flex-wrap gap-3"><Button type="submit" disabled={busy}>Save finding details</Button>{next !== null && <Button type="button" disabled={busy} onClick={() => void save(next)}>{next === 'in_remediation' ? 'Start remediation' : next === 'resolved' ? 'Resolve finding' : 'Verify resolution'}</Button>}<Button type="button" variant="secondary" disabled={busy} onClick={() => void load()}>Reload finding</Button></div></form>}
 </Card><Card className="p-4"><h2>Remediation timeline</h2><ol className="space-y-4">{row.timeline.map(t => <li key={t.id}><p>{statusLabel(t.status ?? t.action)}</p><p>{t.occurred_at} · {t.actor_id ?? 'Scanner'}</p></li>)}</ol></Card></PageContainer>;
}
