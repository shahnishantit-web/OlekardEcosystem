'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';

interface Organisation {
  id: string;
  name: string;
  type: string;
  status: string;
  operating_countries: string[];
  created_at: string;
}

type StatusVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

function statusVariant(status: string): StatusVariant {
  switch (status) {
    case 'approved':          return 'success';
    case 'submitted':         return 'info';
    case 'under_review':      return 'info';
    case 'changes_requested': return 'warning';
    case 'suspended':         return 'error';
    case 'archived':          return 'neutral';
    case 'rejected':          return 'error';
    default:                  return 'neutral';
  }
}

export function OrganisationListPage() {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [organisations, setOrganisations] = useState<Organisation[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');

  const fetchOrganisations = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (statusFilter) params.set('status', statusFilter);
      if (typeFilter) params.set('type', typeFilter);
      const res = await authenticatedClient.get<{ data: Organisation[] }>(
        `/v1/community/admin/organisations?${params.toString()}`
      );
      setOrganisations(res.data.data ?? []);
    } catch {
      setError('Failed to load organisations.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, statusFilter, typeFilter]);

  useEffect(() => { void fetchOrganisations(); }, [fetchOrganisations]);

  const statuses = [
    'draft', 'submitted', 'under_review', 'changes_requested',
    'approved', 'suspended', 'archived', 'rejected',
  ];

  return (
    <PageContainer>
      <PageHeader title="Community Organisations" />

      <div className="mb-4 flex gap-3 flex-wrap">
        <select
          aria-label="Filter by status"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All statuses</option>
          {statuses.map((s) => (
            <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
          ))}
        </select>

        <select
          aria-label="Filter by type"
          value={typeFilter}
          onChange={(e) => setTypeFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All types</option>
          <option value="funeral">Funeral</option>
          <option value="events">Events</option>
          <option value="raffle">Raffle</option>
          <option value="general">General</option>
        </select>

        <Button variant="secondary" onClick={() => void fetchOrganisations()}>
          Refresh
        </Button>
      </div>

      {loading && <Loading label="Loading organisations…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchOrganisations()} />}

      {!loading && !error && (
        <Card>
          <Table caption="Community organisations list">
            <thead>
              <tr className="border-b border-ok-border border-t border-ok-border bg-neutral-50">
                {['Name', 'Type', 'Countries', 'Status', 'Created'].map((h) => (
                  <th key={h} scope="col" className="px-4 py-3 text-sm font-medium text-text-secondary">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {organisations.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-8 text-center text-text-secondary text-sm">
                    No organisations found.
                  </td>
                </tr>
              ) : (
                organisations.map((org) => (
                  <tr
                    key={org.id}
                    className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg cursor-pointer"
                    onClick={() => router.push(`/community/organisations/${org.id}`)}
                    tabIndex={0}
                    role="row"
                    aria-label={`View organisation: ${org.name}`}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') router.push(`/community/organisations/${org.id}`);
                    }}
                  >
                    <td className="px-4 py-3 text-sm font-medium text-text-primary">{org.name}</td>
                    <td className="px-4 py-3 text-sm text-text-primary">{org.type}</td>
                    <td className="px-4 py-3 text-sm text-text-primary">{org.operating_countries.join(', ')}</td>
                    <td className="px-4 py-3">
                      <Badge label={org.status.replace(/_/g, ' ').toUpperCase()} variant={statusVariant(org.status)} />
                    </td>
                    <td className="px-4 py-3 text-sm text-text-primary">
                      {new Date(org.created_at).toLocaleDateString()}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </Table>
        </Card>
      )}
    </PageContainer>
  );
}
