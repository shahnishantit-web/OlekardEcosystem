'use client';

import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface DeploymentVersion {
  id: string;
  version: string;
  git_sha: string | null;
  deployed_by: string | null;
  deployed_at: string;
  is_current: boolean;
}

interface DeploymentRollback {
  id: string;
  from_version: string;
  to_version: string;
  status: 'in_progress' | 'completed' | 'failed';
  initiated_by: string | null;
  reason: string | null;
  completed_at: string | null;
  created_at: string;
}

const rollbackStatusClass: Record<string, string> = {
  in_progress: 'bg-ok-info-bg text-ok-info-fg',
  completed:   'bg-ok-success-bg text-ok-success-fg',
  failed:      'bg-ok-error-bg text-ok-error-fg',
};

export default function DeploymentsPage() {
  const { authenticatedClient } = useAuth();

  const { data: versions, isLoading: versionsLoading } = useQuery<{ data: DeploymentVersion[] }>({
    queryKey: ['ops', 'deployment-versions'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!.get<{ data: DeploymentVersion[] }>('/v1/admin/ops/deployments/versions').then((r) => r.data),
  });

  const { data: rollbacks, isLoading: rollbacksLoading } = useQuery<{ data: DeploymentRollback[] }>({
    queryKey: ['ops', 'deployment-rollbacks'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!.get<{ data: DeploymentRollback[] }>('/v1/admin/ops/deployments/rollbacks').then((r) => r.data),
    refetchInterval: 15_000,
  });

  return (
    <PageContainer>
      <PageHeader title="Deployments" />

      <section className="mb-8">
        <h2 className="text-base font-semibold text-text-primary mb-3">Deployment Versions</h2>
        {versionsLoading ? (
          <p className="text-sm text-text-secondary">Loading versions…</p>
        ) : (
          <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
            <table className="min-w-full text-sm" aria-label="Deployment versions">
              <thead>
                <tr className="bg-neutral-50 border-b border-ok-border">
                  {['Version', 'Git SHA', 'Deployed By', 'Deployed At', 'Current'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {(versions?.data ?? []).map((v) => (
                  <tr key={v.id} className={`border-b border-[#f0f1f4] ${v.is_current ? 'bg-ok-success-bg/30' : 'hover:bg-ok-hover-bg'}`}>
                    <td className="px-4 py-3 font-mono font-medium text-text-primary">{v.version}</td>
                    <td className="px-4 py-3 font-mono text-xs text-text-secondary">{v.git_sha ? v.git_sha.slice(0, 8) : '—'}</td>
                    <td className="px-4 py-3 text-text-secondary">{v.deployed_by ?? '—'}</td>
                    <td className="px-4 py-3 text-text-secondary">{new Date(v.deployed_at).toLocaleString()}</td>
                    <td className="px-4 py-3">
                      {v.is_current ? <span className="text-ok-success-fg font-medium">✓ Current</span> : '—'}
                    </td>
                  </tr>
                ))}
                {(versions?.data ?? []).length === 0 && (
                  <tr><td colSpan={5} className="px-4 py-6 text-center text-text-secondary">No deployment versions recorded.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section>
        <h2 className="text-base font-semibold text-text-primary mb-3">Recent Rollbacks</h2>
        {rollbacksLoading ? (
          <p className="text-sm text-text-secondary">Loading rollbacks…</p>
        ) : (
          <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
            <table className="min-w-full text-sm" aria-label="Deployment rollbacks">
              <thead>
                <tr className="bg-neutral-50 border-b border-ok-border">
                  {['From', 'To', 'Status', 'Initiated By', 'Initiated At'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {(rollbacks?.data ?? []).map((r) => (
                  <tr key={r.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                    <td className="px-4 py-3 font-mono text-text-primary">{r.from_version}</td>
                    <td className="px-4 py-3 font-mono text-text-primary">{r.to_version}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${rollbackStatusClass[r.status] ?? 'bg-neutral-100 text-neutral-600'}`}>
                        {r.status.replace('_', ' ')}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-text-secondary">{r.initiated_by ?? '—'}</td>
                    <td className="px-4 py-3 text-text-secondary">{new Date(r.created_at).toLocaleString()}</td>
                  </tr>
                ))}
                {(rollbacks?.data ?? []).length === 0 && (
                  <tr><td colSpan={5} className="px-4 py-6 text-center text-text-secondary">No rollbacks recorded.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </PageContainer>
  );
}
