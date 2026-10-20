'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface MetricPoint {
  id: string;
  metric_name: string;
  value: number;
  unit: string;
  recorded_at: string;
}

const SERVICES      = ['api', 'horizon', 'database', 'queue'];
const GRANULARITIES = ['raw', 'minute', 'hour', 'day'];

export default function OpsMetricsPage() {
  const { authenticatedClient } = useAuth();
  const [serviceName,  setServiceName]  = useState('api');
  const [granularity,  setGranularity]  = useState('minute');

  const { data, isLoading, isError } = useQuery<{ data: MetricPoint[] }>({
    queryKey: ['ops-metrics', serviceName, granularity],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!
        .get<MetricPoint[]>(`/v1/admin/ops/metrics?service_name=${serviceName}&granularity=${granularity}`)
        .then((r) => ({ data: Array.isArray(r.data) ? r.data : [] })),
  });

  const selectCls = 'rounded-lg border border-ok-input-border bg-ok-input-bg px-3 h-[35px] text-sm text-text-primary focus:outline-none focus:border-ok-primary focus:ring-1 focus:ring-ok-focus-ring';

  return (
    <PageContainer>
      <PageHeader title="System Metrics" description="Performance measurements by service and granularity." />

      <div className="flex gap-3 mb-4">
        <select
          value={serviceName}
          onChange={(e) => setServiceName(e.target.value)}
          aria-label="Service"
          className={selectCls}
        >
          {SERVICES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>

        <select
          value={granularity}
          onChange={(e) => setGranularity(e.target.value)}
          aria-label="Granularity"
          className={selectCls}
        >
          {GRANULARITIES.map((g) => <option key={g} value={g}>{g}</option>)}
        </select>
      </div>

      {isLoading && <p className="text-sm text-text-secondary">Loading…</p>}
      {isError   && <p className="text-sm text-ok-error-fg">Failed to load metrics.</p>}

      {data && (
        <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
          <table className="min-w-full text-sm" aria-label="System metrics">
            <thead>
              <tr className="bg-neutral-50 border-b border-ok-border">
                {['Recorded At', 'Metric', 'Value', 'Unit'].map((h) => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.data.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-4 py-6 text-center text-text-secondary">No data points.</td>
                </tr>
              )}
              {data.data.map((pt) => (
                <tr key={pt.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                  <td className="px-4 py-3 text-xs text-text-secondary">{pt.recorded_at}</td>
                  <td className="px-4 py-3 font-mono text-sm text-text-primary">{pt.metric_name}</td>
                  <td className="px-4 py-3 text-sm text-text-primary">{pt.value}</td>
                  <td className="px-4 py-3 text-sm text-text-secondary">{pt.unit}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </PageContainer>
  );
}
