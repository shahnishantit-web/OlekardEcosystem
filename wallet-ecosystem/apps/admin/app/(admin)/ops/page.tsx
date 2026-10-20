'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface HealthStatus {
  status: 'healthy' | 'degraded' | 'unhealthy';
  components: Record<string, { status: string; latency_ms?: number; error?: string }>;
  checked_at: string;
}

interface ProviderStatus {
  provider_type: string;
  status: 'up' | 'degraded' | 'down';
  consecutive_failures: number;
}

const statusColorClass: Record<string, string> = {
  healthy:   'text-ok-success-fg',
  degraded:  'text-ok-warning-fg',
  unhealthy: 'text-ok-error-fg',
  up:        'text-ok-success-fg',
  down:      'text-ok-error-fg',
};

const NAV_ITEMS = [
  { label: 'Logs',            href: '/ops/logs' },
  { label: 'Metrics',         href: '/ops/metrics' },
  { label: 'Providers',       href: '/ops/providers' },
  { label: 'Alert Rules',     href: '/ops/alert-rules' },
  { label: 'Alerts',          href: '/ops/alerts' },
  { label: 'DB Monitoring',   href: '/ops/db-monitoring' },
  { label: 'Queue Monitoring', href: '/ops/queue-monitoring' },
  { label: 'Backup Jobs',     href: '/ops/backup-jobs' },
  { label: 'Deployments',     href: '/ops/deployments' },
];

export default function OpsDashboardPage() {
  const { authenticatedClient } = useAuth();

  const { data: health } = useQuery<HealthStatus>({
    queryKey: ['ops', 'health'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!.get<HealthStatus>('/health').then((r) => r.data),
    refetchInterval: 30_000,
  });

  const { data: providers } = useQuery<ProviderStatus[]>({
    queryKey: ['ops', 'providers-summary'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!.get<ProviderStatus[]>('/v1/admin/ops/providers').then((r) => r.data),
    refetchInterval: 60_000,
  });

  const { data: alerts } = useQuery<{ data: { status: string }[] }>({
    queryKey: ['ops', 'alerts-summary'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!
        .get<{ data: { status: string }[] }>('/v1/admin/ops/alerts')
        .then((r) => r.data),
    refetchInterval: 30_000,
  });

  const firingAlerts  = (alerts?.data ?? []).filter((a) => a.status === 'firing').length;
  const downProviders = (providers ?? []).filter((p) => p.status === 'down').length;

  const summaryCards = [
    {
      title: 'System Health',
      value: health?.status ?? '…',
      href:  '/ops/providers',
      color: statusColorClass[health?.status ?? ''] ?? 'text-text-secondary',
    },
    {
      title: 'Firing Alerts',
      value: String(firingAlerts),
      href:  '/ops/alerts',
      color: firingAlerts > 0 ? 'text-ok-error-fg' : 'text-ok-success-fg',
    },
    {
      title: 'Providers Down',
      value: String(downProviders),
      href:  '/ops/providers',
      color: downProviders > 0 ? 'text-ok-error-fg' : 'text-ok-success-fg',
    },
  ];

  return (
    <PageContainer>
      <PageHeader title="Operations Center" />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        {summaryCards.map((card) => (
          <Link
            key={card.title}
            href={card.href}
            className="block rounded-[11px] border border-ok-card-border bg-ok-card-bg p-5 hover:bg-ok-hover-bg transition-colors"
            style={{ boxShadow: 'var(--ok-card-shadow)' }}
          >
            <p className="text-xs font-medium text-text-secondary mb-2">{card.title}</p>
            <p className={`text-2xl font-bold capitalize ${card.color}`}>{card.value}</p>
          </Link>
        ))}
      </div>

      <h2 className="text-sm font-semibold text-text-secondary uppercase tracking-wider mb-3">
        Quick Navigation
      </h2>
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {NAV_ITEMS.map((item) => (
          <Link
            key={item.href}
            href={item.href}
            className="block rounded-lg border border-ok-border bg-ok-card-bg px-4 py-3 text-sm font-medium text-text-secondary hover:bg-ok-hover-bg hover:text-text-primary transition-colors"
          >
            {item.label}
          </Link>
        ))}
      </div>
    </PageContainer>
  );
}
