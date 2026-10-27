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

interface Campaign {
  id: string;
  organisation_id: string;
  service_type: 'funeral' | 'events' | 'raffle';
  title: string;
  status: string;
  reference: string;
  currency: string;
  contribution_amount_minor: number | null;
  capacity: number | null;
  starts_at: string | null;
  ends_at: string | null;
  created_at: string;
  opened_at: string | null;
}

type BadgeVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

function statusVariant(status: string): BadgeVariant {
  switch (status) {
    case 'active':  return 'success';
    case 'closed':  return 'warning';
    case 'settled': return 'info';
    default:        return 'neutral';
  }
}

function typeIcon(type: string) {
  switch (type) {
    case 'funeral': return '🕊';
    case 'events':  return '🎟';
    case 'raffle':  return '🎰';
    default:        return '📋';
  }
}

export function CampaignListPage({ serviceType }: { serviceType?: string }) {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState<string | null>(null);
  const [typeFilter, setTypeFilter]     = useState(serviceType ?? '');
  const [statusFilter, setStatusFilter] = useState('');

  const fetchCampaigns = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (typeFilter)   params.set('service_type', typeFilter);
      if (statusFilter) params.set('status', statusFilter);
      const res = await authenticatedClient.get<{ data: Campaign[] }>(
        `/v1/community/admin/campaigns?${params.toString()}`
      );
      setCampaigns(res.data.data ?? []);
    } catch {
      setError('Failed to load campaigns.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, typeFilter, statusFilter]);

  useEffect(() => { void fetchCampaigns(); }, [fetchCampaigns]);

  const title = serviceType === 'funeral' ? 'Funeral Collections'
    : serviceType === 'events' ? 'Events & Ticketing'
    : 'Community Campaigns';

  return (
    <PageContainer>
      <PageHeader
        title={title}
        actions={
          <Button variant="primary" onClick={() => router.push('/community/campaigns/new')} disabled={serviceType === 'funeral'}>
            {serviceType === 'funeral' ? 'New Collection' : serviceType === 'events' ? 'Create Event' : 'New Campaign'}
          </Button>
        }
      />

      {serviceType === 'funeral' && (
        <p role="status" className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          Funeral Assistance creation, collection and disbursement are unavailable while P24 verification is in progress.
        </p>
      )}

      <div className="mb-4 flex gap-3 flex-wrap">
        {!serviceType && (
          <select
            aria-label="Filter by service type"
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
            className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
          >
            <option value="">All types</option>
            <option value="funeral">Funeral Assistance</option>
            <option value="events">Events & Ticketing</option>
            <option value="raffle">Raffle</option>
          </select>
        )}
        <select
          aria-label="Filter by status"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All statuses</option>
          <option value="draft">Draft</option>
          <option value="active">Active</option>
          <option value="closed">Closed</option>
          <option value="settled">Settled</option>
        </select>
      </div>

      {loading && <Loading label="Loading campaigns…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchCampaigns()} />}

      {!loading && !error && (
        <Card>
          <Table caption="Community campaigns">
            <thead>
              <tr className="border-b border-ok-border bg-neutral-50">
                {['Type', 'Title', 'Reference', 'Status', 'Opened', 'Actions'].map((h) => (
                  <th key={h} scope="col" className="px-4 py-3 text-sm font-medium text-text-secondary">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {campaigns.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-text-secondary text-sm">
                    No campaigns found.
                  </td>
                </tr>
              ) : (
                campaigns.map((c) => (
                  <tr key={c.id} className="border-b border-[#f0f1f4]">
                    <td className="px-4 py-3 text-sm">
                      <span title={c.service_type}>{typeIcon(c.service_type)}</span>
                      <span className="ml-1 capitalize text-text-secondary">{c.service_type}</span>
                    </td>
                    <td className="px-4 py-3 text-sm font-medium text-text-primary">{c.title}</td>
                    <td className="px-4 py-3 text-xs font-mono text-text-secondary">{c.reference ?? '—'}</td>
                    <td className="px-4 py-3">
                      <Badge label={c.status.toUpperCase()} variant={statusVariant(c.status)} />
                    </td>
                    <td className="px-4 py-3 text-sm text-text-secondary">
                      {c.opened_at ? new Date(c.opened_at).toLocaleDateString() : '—'}
                    </td>
                    <td className="px-4 py-3">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.push(`/community/campaigns/${c.id}`)}
                      >
                        View
                      </Button>
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
