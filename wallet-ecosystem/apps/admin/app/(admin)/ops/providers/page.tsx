'use client';

import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface ProviderStatus {
  id: string;
  provider_type: string;
  status: 'up' | 'degraded' | 'down';
  consecutive_failures: number;
  last_checked_at: string | null;
  degraded_since: string | null;
  down_since: string | null;
}

const statusClass: Record<string, string> = {
  up:       'bg-ok-success-bg text-ok-success-fg',
  degraded: 'bg-ok-warning-bg text-ok-warning-fg',
  down:     'bg-ok-error-bg text-ok-error-fg',
};

export default function ProvidersPage() {
  const { authenticatedClient } = useAuth();

  const { data, isLoading, error } = useQuery<ProviderStatus[]>({
    queryKey: ['ops', 'providers'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!.get<ProviderStatus[]>('/v1/admin/ops/providers').then((r) => r.data),
    refetchInterval: 30_000,
  });

  if (isLoading) return <div className="p-6 text-sm text-text-secondary">Loading provider statuses…</div>;
  if (error)     return <div className="p-6 text-sm text-ok-error-fg">Failed to load provider statuses.</div>;

  return (
    <PageContainer>
      <PageHeader title="Provider Health" />

      <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
        <table className="min-w-full text-sm" aria-label="Provider health">
          <thead>
            <tr className="bg-neutral-50 border-b border-ok-border">
              {['Provider', 'Status', 'Failures', 'Last Checked', 'Degraded Since', 'Down Since'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {(data ?? []).map((row) => (
              <tr key={row.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                <td className="px-4 py-3 font-mono text-text-primary">{row.provider_type}</td>
                <td className="px-4 py-3">
                  <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${statusClass[row.status] ?? 'bg-neutral-100 text-neutral-600'}`}>
                    {row.status}
                  </span>
                </td>
                <td className="px-4 py-3 text-text-primary">{row.consecutive_failures}</td>
                <td className="px-4 py-3 text-text-secondary">{row.last_checked_at ? new Date(row.last_checked_at).toLocaleString() : '—'}</td>
                <td className="px-4 py-3 text-text-secondary">{row.degraded_since ? new Date(row.degraded_since).toLocaleString() : '—'}</td>
                <td className="px-4 py-3 text-text-secondary">{row.down_since ? new Date(row.down_since).toLocaleString() : '—'}</td>
              </tr>
            ))}
            {(data ?? []).length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-text-secondary">No provider status data yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </PageContainer>
  );
}
