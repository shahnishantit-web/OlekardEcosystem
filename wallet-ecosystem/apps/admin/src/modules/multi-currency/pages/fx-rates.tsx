'use client';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Table } from '@/components/ui/Table';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
interface Rate {
    id: string;
    from_currency_code: string;
    to_currency_code: string;
    rate: string;
    spread: string;
    source: 'manual' | 'provider';
    provider: string | null;
    status: string;
    last_updated: string;
    effective_from: string;
    created_by: string;
}
interface History {
    chart: Rate[];
    history: Rate[];
    pagination: {
        page: number;
        last_page: number;
        total: number;
    };
}
export function spreadPercent(s: string): string { const [whole = '0', fraction = ''] = s.split('.'); const digits = (whole + fraction.padEnd(5, '0')).replace(/^0+(?=\d)/, ''); return (BigInt(digits) / 1000n).toString() + '.' + (BigInt(digits) % 1000n).toString().padStart(3, '0'); }
export function spreadFraction(s: string): string { if (!/^(?:0|[1-9][0-9]?)(?:\.[0-9]{1,3})?$/.test(s))
    throw Error('Spread must be from 0 to below 100%, with at most 3 decimals.'); const [w = '0', f = ''] = s.split('.'); return '0.' + w.padStart(2, '0') + f.padEnd(3, '0'); }
function message(e: unknown): string { return (e as {
    error?: {
        message?: string;
    };
})?.error?.message ?? (e instanceof Error ? e.message : 'FX request failed.'); }
function Chart({ rows }: {
    rows: Rate[];
}) {
    if (!rows.length)
        return <p>No rate changes in the last 30 days.</p>;
    const values = rows.map(r => Number(r.rate)), lo = Math.min(...values), hi = Math.max(...values);
    const times = rows.map(r => new Date(r.effective_from).getTime()), start = Math.min(...times), end = Math.max(...times);
    const points = rows.map((r, i) => ({ r, x: 35 + (times[i]! - start) / (end - start || 1) * 630, y: 155 - (values[i]! - lo) / (hi - lo || 1) * 110 }));
    return <figure><div className="overflow-x-auto"><svg role="img" aria-label="Exchange rate changes in the last 30 days; manual overrides are annotated" viewBox="0 0 720 210" className="w-full min-w-[640px] max-w-4xl"><title>Rate history over the last 30 days</title><line x1="35" y1="170" x2="700" y2="170" stroke="currentColor"/><polyline fill="none" stroke="currentColor" strokeWidth="2" points={points.map(p => p.x + ',' + p.y).join(' ')}/>{points.map(p => <g key={p.r.id}><circle cx={p.x} cy={p.y} r={p.r.source === 'manual' ? 5 : 3} fill="currentColor"/><text x={p.x} y={p.y - 12} fontSize="11" textAnchor={p.x < 100 ? "start" : p.x > 600 ? "end" : "middle"}>{p.r.source === 'manual' ? 'Manual: ' : ''}{p.r.rate}</text><title>{p.r.effective_from} {p.r.source} {p.r.rate}</title></g>)}<text x="35" y="195" fontSize="11">{rows[0]?.effective_from.slice(0, 10)}</text><text x="700" y="195" fontSize="11" textAnchor="end">{rows[rows.length - 1]?.effective_from.slice(0, 10)}</text></svg></div><figcaption>Last 30 days, UTC. Scroll the chart horizontally on narrow screens. Larger points identify manual overrides. Exact rates and timestamps appear in the table.</figcaption></figure>;
}
export function FxRatesPage() {
    const { authenticatedClient, hasPermission, user } = useAuth();
    const [rates, setRates] = useState<Rate[]>([]), [loading, setLoading] = useState(true), [error, setError] = useState<string | null>(null), [notice, setNotice] = useState(''), [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ from_currency_code: '', to_currency_code: '', rate: '', spread_percent: '', mode: 'manual' });
    const [history, setHistory] = useState<History | null>(null), [pair, setPair] = useState(''), [historyBusy, setHistoryBusy] = useState(false);
    const load = useCallback(async () => { if (!authenticatedClient)
        return; setLoading(true); try {
        const r = await authenticatedClient.get<Rate[]>('/v1/admin/fx/rates');
        setRates(r.data);
        setError(null);
    }
    catch (e) {
        setError(message(e));
    }
    finally {
        setLoading(false);
    } }, [authenticatedClient]);
    useEffect(() => { void load(); }, [load]);
    async function submit(e: React.FormEvent) { e.preventDefault(); if (!authenticatedClient)
        return; setBusy(true); setNotice(''); try {
        await authenticatedClient.post('/v1/admin/fx/rates', { from_currency_code: form.from_currency_code.toUpperCase(), to_currency_code: form.to_currency_code.toUpperCase(), ...(form.mode === 'manual' ? { rate: form.rate } : {}), spread: spreadFraction(form.spread_percent), mode: form.mode }, { idempotencyKey: crypto.randomUUID(), domainErrorCodes: ['FX_PROVIDER_UNAVAILABLE'] });
        setNotice('Rate submitted for approval. The current rate remains active until a different administrator approves.');
        await load();
    }
    catch (e) {
        setNotice(message(e));
    }
    finally {
        setBusy(false);
    } }
    async function approve(r: Rate) { if (!authenticatedClient)
        return; setBusy(true); try {
        await authenticatedClient.post('/v1/admin/fx/rates/' + r.id + '/activate', {}, { idempotencyKey: crypto.randomUUID() });
        setNotice('Approved rate applied.');
        await load();
    }
    catch (e) {
        setNotice(message(e));
    }
    finally {
        setBusy(false);
    } }
    async function openHistory(p: string, page = 1) { if (!authenticatedClient)
        return; setPair(p); setHistoryBusy(true); try {
        const r = await authenticatedClient.get<History>('/v1/admin/fx/rates/' + p + '/history?page=' + page);
        setHistory(r.data);
    }
    catch (e) {
        setNotice(message(e));
    }
    finally {
        setHistoryBusy(false);
    } }
    if (loading)
        return <Loading />;
    if (error !== null)
        return <ErrorState message={error} onRetry={load}/>;
    const columns = [
        { key: 'pair', header: 'Pair', render: (r: Rate) => <button type="button" className="underline" onClick={() => void openHistory(r.from_currency_code + '-' + r.to_currency_code)}>{r.from_currency_code}/{r.to_currency_code}</button> },
        { key: 'rate', header: 'Rate', render: (r: Rate) => r.rate }, { key: 'spread', header: 'Spread %', render: (r: Rate) => spreadPercent(r.spread) + '%' },
        { key: 'provider', header: 'Provider / Mode', render: (r: Rate) => r.source === 'manual' ? (r.status === 'pending_approval' ? 'Manual (awaiting approval)' : 'Manual (locked)') : r.provider ?? 'Provider unavailable' },
        { key: 'updated', header: 'Last Updated (UTC)', render: (r: Rate) => r.last_updated },
        { key: 'status', header: 'Status', render: (r: Rate) => r.status === 'pending_approval' ? 'Pending approval' : r.status },
        { key: 'approve', header: 'Approval', render: (r: Rate) => r.status === 'pending_approval' && hasPermission('configuration.approve') ? <Button disabled={busy || r.created_by === user?.id} onClick={() => void approve(r)}>Approve rate</Button> : null }
    ];
    const historyColumns = [{ key: 'rate', header: 'Rate', render: (r: Rate) => r.rate }, { key: 'spread', header: 'Spread %', render: (r: Rate) => spreadPercent(r.spread) + '%' }, { key: 'source', header: 'Source', render: (r: Rate) => r.source === 'manual' ? 'Manual override' : r.provider ?? 'Provider' }, { key: 'time', header: 'Timestamp (UTC)', render: (r: Rate) => r.effective_from }];
    return <PageContainer><PageHeader title="FX Rate Management"/><Link href="/multi-currency/currencies">Manage currencies</Link><p>Manual overrides lock the pair. Provider mode resumes only after an authentic provider rate is available and a different administrator approves.</p>{notice && <p role="status">{notice}</p>}
 <Card className="p-4"><h2>Submit rate or revert to provider</h2><form onSubmit={e => void submit(e)} className="grid gap-4 sm:grid-cols-2">
 {(['from_currency_code', 'to_currency_code', 'rate', 'spread_percent'] as const).map(f => <label key={f} className="flex flex-col">{({ from_currency_code: 'From currency', to_currency_code: 'To currency', rate: 'Exchange rate', spread_percent: 'Spread %' })[f]}<input className="rounded border p-2" aria-label={({ from_currency_code: 'From currency', to_currency_code: 'To currency', rate: 'Exchange rate', spread_percent: 'Spread %' })[f]} required={f !== 'rate' || form.mode === 'manual'} disabled={f === 'rate' && form.mode === 'provider'} value={form[f]} onChange={e => setForm({ ...form, [f]: e.target.value })}/></label>)}
 <label className="flex flex-col">Mode<select aria-label="Rate mode" className="rounded border p-2" value={form.mode} onChange={e => setForm({ ...form, mode: e.target.value })}><option value="manual">Manual override</option><option value="provider">Revert to provider</option></select></label><Button type="submit" disabled={busy || !hasPermission('multi_currency.configure_rate')}>Submit for approval</Button>
 </form></Card><Card><div className="overflow-x-auto"><Table columns={columns} data={rates} getRowKey={r => r.id} emptyMessage="No active or pending rates configured."/></div></Card>
 {pair && <Card className="p-4"><h2>{pair.replace('-', '/')} rate history</h2>{historyBusy ? <p role="status">Loading rate history...</p> : history && <><Chart rows={history.chart}/><div className="overflow-x-auto"><Table columns={historyColumns} data={history.history} getRowKey={r => r.id} emptyMessage="No historical rates."/></div><p>Page {history.pagination.page} of {history.pagination.last_page}; {history.pagination.total} rates</p><Button disabled={history.pagination.page <= 1} onClick={() => void openHistory(pair, history.pagination.page - 1)}>Previous history page</Button><Button disabled={history.pagination.page >= history.pagination.last_page} onClick={() => void openHistory(pair, history.pagination.page + 1)}>Next history page</Button></>}</Card>}
 </PageContainer>;
}
