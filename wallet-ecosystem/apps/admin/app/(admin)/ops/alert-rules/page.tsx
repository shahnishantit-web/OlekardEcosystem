'use client';

import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface AlertRule {
  id: string;
  name: string;
  description: string | null;
  severity: string;
  is_enabled: boolean;
  notification_channels: string[];
  created_at: string;
}

const severityClass: Record<string, string> = {
  info:     'bg-ok-info-bg text-ok-info-fg',
  warning:  'bg-ok-warning-bg text-ok-warning-fg',
  critical: 'bg-ok-error-bg text-ok-error-fg',
};

export default function AlertRulesPage() {
  const { authenticatedClient } = useAuth();

  const { data, isLoading, error } = useQuery<AlertRule[]>({
    queryKey: ['ops', 'alert-rules'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!.get<AlertRule[]>('/v1/admin/ops/alert-rules').then((r) => r.data),
  });

  if (isLoading) return <div className="p-6 text-sm text-text-secondary">Loading alert rules…</div>;
  if (error)     return <div className="p-6 text-sm text-ok-error-fg">Failed to load alert rules.</div>;

  return (
    <PageContainer>
      <PageHeader title="Alert Rules" />

      <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
        <table className="min-w-full text-sm" aria-label="Alert rules">
          <thead>
            <tr className="bg-neutral-50 border-b border-ok-border">
              {['Name', 'Severity', 'Channels', 'Enabled', 'Created'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {(data ?? []).map((rule) => (
              <tr key={rule.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                <td className="px-4 py-3 font-medium text-text-primary">
                  {rule.name}
                  {rule.description && (
                    <p className="text-xs text-text-secondary mt-0.5">{rule.description}</p>
                  )}
                </td>
                <td className="px-4 py-3">
                  <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${severityClass[rule.severity] ?? 'bg-neutral-100 text-neutral-600'}`}>
                    {rule.severity}
                  </span>
                </td>
                <td className="px-4 py-3 text-text-secondary">{rule.notification_channels.join(', ')}</td>
                <td className="px-4 py-3">
                  <span className={rule.is_enabled ? 'text-ok-success-fg' : 'text-text-secondary'}>
                    {rule.is_enabled ? 'Yes' : 'No'}
                  </span>
                </td>
                <td className="px-4 py-3 text-text-secondary">{new Date(rule.created_at).toLocaleDateString()}</td>
              </tr>
            ))}
            {(data ?? []).length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-text-secondary">No alert rules configured.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </PageContainer>
  );
}
