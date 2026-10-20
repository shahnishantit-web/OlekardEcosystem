'use client';

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

export default function QueueMonitoringPage() {
  const { authenticatedClient } = useAuth();

  const { data, isLoading, isError } = useQuery<{ data: MetricPoint[] }>({
    queryKey: ['ops-queue-metrics'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!
        .get<MetricPoint[]>('/v1/admin/ops/metrics?service_name=queue.default&granularity=raw')
        .then((r) => ({ data: Array.isArray(r.data) ? r.data : [] })),
    refetchInterval: 15_000,
  });

  return (
    <PageContainer>
      <PageHeader title="Queue Monitoring" description="Queue depth, throughput, and failed message counts." />

      {isLoading && <p className="text-sm text-text-secondary">Loading…</p>}
      {isError   && <p className="text-sm text-ok-error-fg">Failed to load queue metrics.</p>}

      {data && (
        <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
          <table className="min-w-full text-sm" aria-label="Queue metrics">
            <thead>
              <tr className="bg-neutral-50 border-b border-ok-border">
                {['Recorded At', 'Metric', 'Value'].map((h) => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.data.length === 0 && (
                <tr>
                  <td colSpan={3} className="px-4 py-6 text-center text-text-secondary">No data.</td>
                </tr>
              )}
              {data.data.map((pt) => (
                <tr key={pt.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                  <td className="px-4 py-3 text-xs text-text-secondary">{pt.recorded_at}</td>
                  <td className="px-4 py-3 font-mono text-sm text-text-primary">{pt.metric_name}</td>
                  <td className="px-4 py-3 text-sm text-text-primary">{pt.value}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </PageContainer>
  );
}
