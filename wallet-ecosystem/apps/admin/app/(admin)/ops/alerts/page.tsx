'use client';

import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface Alert {
  id: string;
  alert_rule_id: string;
  rule?: { name: string; severity: string };
  status: 'firing' | 'resolved';
  condition_summary: string;
  fired_at: string;
  resolved_at: string | null;
}

const statusClass: Record<string, string> = {
  firing:   'bg-ok-error-bg text-ok-error-fg',
  resolved: 'bg-ok-success-bg text-ok-success-fg',
};

export default function AlertsPage() {
  const { authenticatedClient } = useAuth();

  const { data, isLoading, error } = useQuery<{ data: Alert[] }>({
    queryKey: ['ops', 'alerts'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!.get<{ data: Alert[] }>('/v1/admin/ops/alerts').then((r) => r.data),
    refetchInterval: 30_000,
  });

  if (isLoading) return <div className="p-6 text-sm text-text-secondary">Loading alerts…</div>;
  if (error)     return <div className="p-6 text-sm text-ok-error-fg">Failed to load alerts.</div>;

  const alerts = data?.data ?? [];

  return (
    <PageContainer>
      <PageHeader title="Active & Recent Alerts" />

      <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
        <table className="min-w-full text-sm" aria-label="Alerts">
          <thead>
            <tr className="bg-neutral-50 border-b border-ok-border">
              {['Rule', 'Status', 'Condition', 'Fired At', 'Resolved At'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {alerts.map((alert) => (
              <tr key={alert.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                <td className="px-4 py-3 font-medium text-text-primary">{alert.rule?.name ?? alert.alert_rule_id}</td>
                <td className="px-4 py-3">
                  <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${statusClass[alert.status] ?? 'bg-neutral-100 text-neutral-600'}`}>
                    {alert.status}
                  </span>
                </td>
                <td className="px-4 py-3 text-text-secondary max-w-xs truncate">{alert.condition_summary}</td>
                <td className="px-4 py-3 text-text-secondary">{new Date(alert.fired_at).toLocaleString()}</td>
                <td className="px-4 py-3 text-text-secondary">{alert.resolved_at ? new Date(alert.resolved_at).toLocaleString() : '—'}</td>
              </tr>
            ))}
            {alerts.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-text-secondary">No alerts found.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </PageContainer>
  );
}
