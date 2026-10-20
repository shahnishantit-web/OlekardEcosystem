'use client';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Table } from '@/components/ui/Table';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { IntegrationApi, errorMessage, dateText, healthLabel, type Health } from '../api';
import { IntegrationNav } from '../shared';
export function ProviderHealthPage({ providerId }: {
    providerId: string;
}) {
    const { authenticatedClient: client, hasPermission } = useAuth(), allowed = hasPermission('integrations.manage'), api = useMemo(() => client ? new IntegrationApi(client) : null, [client]);
    const [data, setData] = useState<Health | null>(null), [error, setError] = useState(''), [reload, setReload] = useState(0);
    useEffect(() => { let active = true; if (api && allowed)
        api.health(providerId).then(r => { if (active)
            setData(r); }).catch(e => { if (active)
            setError(errorMessage(e)); }); return () => { active = false; }; }, [api, allowed, providerId, reload]);
    if (!allowed)
        return <PageContainer><p role="alert">Access denied</p></PageContainer>;
    return <PageContainer><PageHeader title={data ? data.display_name + ' health' : 'Provider health'} description="Observed uptime over the last 24 UTC hourly buckets."/><IntegrationNav />{error && <ErrorState message={error} onRetry={() => { setError(''); setReload(n => n + 1); }}/>}
 {!data ? <Loading label="Loading provider health"/> : <><Card><div className="flex flex-wrap justify-between gap-3"><p className="font-semibold">{healthLabel(data.health)}</p><p>Last checked: {dateText(data.last_checked_at)}</p></div><p className="text-sm text-text-secondary mt-3">{data.sample_count} probe samples. Missing observations are unknown and are not counted as healthy.</p>
 <svg role="img" aria-label="24-hour observed uptime chart" viewBox="0 0 720 200" className="w-full mt-5"><title>Percentage of healthy checks per UTC hour</title><text x="0" y="30" fontSize="10" fill="currentColor">100%</text><text x="0" y="95" fontSize="10" fill="currentColor">50%</text><text x="0" y="160" fontSize="10" fill="currentColor">0%</text><line x1="45" y1="160" x2="715" y2="160" stroke="currentColor"/>{data.buckets.map((b, i) => <g key={b.hour}><rect x={45 + i * 28} y={b.uptime_percent === null ? 155 : 160 - Math.max(2, b.uptime_percent * 1.3)} width={19} height={b.uptime_percent === null ? 5 : Math.max(2, b.uptime_percent * 1.3)} fill={b.uptime_percent === null ? 'var(--color-text-secondary,#94a3b8)' : b.uptime_percent >= 90 ? 'var(--color-primary,#4860a5)' : '#b45309'}><title>{new Date(b.hour).toISOString().slice(11, 16)} UTC: {b.uptime_percent === null ? 'Unknown' : b.uptime_percent + '% healthy'} ({b.samples} samples)</title></rect>{i % 4 === 0 && <text x={43 + i * 28} y={185} fontSize="12" fill="currentColor">{new Date(b.hour).toISOString().slice(11, 16)}</text>}</g>)}</svg>
 <details><summary className="cursor-pointer underline">View chart data</summary><Table data={data.buckets.map(b => ({ hour: b.hour, samples: b.samples, uptime: b.uptime_percent === null ? 'Unknown' : b.uptime_percent + '%' }))} getRowKey={b => b.hour} columns={[{ key: 'hour', header: 'Hour (UTC)' }, { key: 'samples', header: 'Samples' }, { key: 'uptime', header: 'Observed uptime' }]}/></details>
 </Card><h2 className="text-lg font-semibold mt-6 mb-3">Recent error log</h2><Table data={data.recent_errors} getRowKey={e => e.timestamp + e.status} emptyMessage="No provider errors observed in this window." columns={[{ key: 'timestamp', header: 'Timestamp', render: e => dateText(e.timestamp) }, { key: 'status', header: 'Status' }, { key: 'message', header: 'Error' }]}/></>}
 </PageContainer>;
}
