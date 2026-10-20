'use client';

import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface BackupJob {
  id: string;
  type: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  storage_path: string | null;
  size_bytes: number | null;
  error_message: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
}

const statusClass: Record<string, string> = {
  pending:   'bg-neutral-100 text-neutral-600',
  running:   'bg-ok-info-bg text-ok-info-fg',
  completed: 'bg-ok-success-bg text-ok-success-fg',
  failed:    'bg-ok-error-bg text-ok-error-fg',
};

function formatBytes(bytes: number | null): string {
  if (bytes === null) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function BackupJobsPage() {
  const { authenticatedClient } = useAuth();

  const { data, isLoading, error } = useQuery<{ data: BackupJob[] }>({
    queryKey: ['ops', 'backup-jobs'],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!.get<{ data: BackupJob[] }>('/v1/admin/ops/backup-jobs').then((r) => r.data),
    refetchInterval: 15_000,
  });

  if (isLoading) return <div className="p-6 text-sm text-text-secondary">Loading backup jobs…</div>;
  if (error)     return <div className="p-6 text-sm text-ok-error-fg">Failed to load backup jobs.</div>;

  const jobs = data?.data ?? [];

  return (
    <PageContainer>
      <PageHeader title="Backup Jobs" />

      <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
        <table className="min-w-full text-sm" aria-label="Backup jobs">
          <thead>
            <tr className="bg-neutral-50 border-b border-ok-border">
              {['Type', 'Status', 'Size', 'Started', 'Completed', 'Error'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {jobs.map((job) => (
              <tr key={job.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                <td className="px-4 py-3 font-mono text-text-primary">{job.type}</td>
                <td className="px-4 py-3">
                  <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${statusClass[job.status] ?? 'bg-neutral-100 text-neutral-600'}`}>
                    {job.status}
                  </span>
                </td>
                <td className="px-4 py-3 text-text-secondary">{formatBytes(job.size_bytes)}</td>
                <td className="px-4 py-3 text-text-secondary">{job.started_at ? new Date(job.started_at).toLocaleString() : '—'}</td>
                <td className="px-4 py-3 text-text-secondary">{job.completed_at ? new Date(job.completed_at).toLocaleString() : '—'}</td>
                <td className="px-4 py-3 text-ok-error-fg max-w-xs truncate">{job.error_message ?? '—'}</td>
              </tr>
            ))}
            {jobs.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-text-secondary">No backup jobs yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </PageContainer>
  );
}
