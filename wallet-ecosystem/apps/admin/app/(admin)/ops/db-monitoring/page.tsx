'use client';

import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface MetricPoint {
  id: string;
  metric_name: string;
  value: number;
  recorded_at: string;
}

interface MetricsResponse {
  data: MetricPoint[];
}

const DB_METRICS = [
  { label: 'Active Connections', key: 'db.active_connections', unit: '' },
  { label: 'Max Connections',    key: 'db.max_connections',    unit: '' },
  { label: 'Storage',            key: 'db.storage_bytes',      unit: 'B' },
  { label: 'Slow Queries',       key: 'db.slow_query_count',   unit: '' },
];

export default function DbMonitoringPage() {
  const { authenticatedClient } = useAuth();

  const { data, isLoading, isError } = useQuery<MetricsResponse>({
    queryKey: ['ops-db-metrics'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!
        .get<MetricsResponse>('/v1/admin/ops/metrics?service_name=database&granularity=raw')
        .then((r) => r.data),
    refetchInterval: 30_000,
  });

  const latest = (name: string): string => {
    const pts = data?.data?.filter((p) => p.metric_name === name) ?? [];
    if (!pts.length) return '—';
    const val = pts.at(-1)?.value;
    return val !== undefined ? String(val) : '—';
  };

  return (
    <PageContainer>
      <PageHeader title="Database Monitoring" description="PostgreSQL connection pool, slow queries, and storage utilization." />

      {isLoading && <p className="text-sm text-text-secondary">Loading…</p>}
      {isError   && <p className="text-sm text-ok-error-fg">Failed to load metrics.</p>}

      {data && (
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {DB_METRICS.map(({ label, key, unit }) => (
            <div
              key={key}
              className="bg-ok-card-bg border border-ok-card-border rounded-[11px] p-5"
              style={{ boxShadow: 'var(--ok-card-shadow)' }}
            >
              <p className="text-xs font-medium text-text-secondary mb-2">{label}</p>
              <p className="text-2xl font-bold text-text-primary tabular-nums">
                {latest(key)}{unit}
              </p>
            </div>
          ))}
        </div>
      )}
    </PageContainer>
  );
}
