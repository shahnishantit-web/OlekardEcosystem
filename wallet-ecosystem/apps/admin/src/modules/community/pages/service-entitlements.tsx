'use client';

import { useCallback, useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';

interface ServiceEntitlement {
  id: string;
  organisation_id: string;
  service_type: 'funeral' | 'events' | 'raffle';
  jurisdiction: string;
  is_enabled: boolean;
  raffle_gate_passed: boolean;
  effective_from: string | null;
  effective_until: string | null;
}

export function ServiceEntitlementsPage() {
  const { authenticatedClient } = useAuth();
  const [entitlements, setEntitlements] = useState<ServiceEntitlement[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [orgFilter, setOrgFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');

  const fetchEntitlements = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (orgFilter) params.set('organisation_id', orgFilter);
      if (typeFilter) params.set('service_type', typeFilter);
      const res = await authenticatedClient.get<{ data: ServiceEntitlement[] }>(
        `/v1/community/admin/service-entitlements?${params.toString()}`
      );
      setEntitlements(res.data.data ?? []);
    } catch {
      setError('Failed to load service entitlements.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, orgFilter, typeFilter]);

  useEffect(() => { void fetchEntitlements(); }, [fetchEntitlements]);

  const handleDisable = async (id: string) => {
    if (!authenticatedClient) return;
    if (!window.confirm('Disable this service entitlement?')) return;
    try {
      await authenticatedClient.post(`/v1/community/admin/service-entitlements/${id}/disable`, {});
      await fetchEntitlements();
    } catch {
      setError('Failed to disable entitlement.');
    }
  };

  return (
    <PageContainer>
      <PageHeader title="Service Entitlements" />

      <div className="mb-4 flex gap-3 flex-wrap">
        <input
          type="text"
          aria-label="Filter by organisation ID"
          placeholder="Organisation ID"
          value={orgFilter}
          onChange={(e) => setOrgFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        />
        <select
          aria-label="Filter by service type"
          value={typeFilter}
          onChange={(e) => setTypeFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All types</option>
          <option value="funeral">Funeral</option>
          <option value="events">Events</option>
          <option value="raffle">Raffle</option>
        </select>
        <Button variant="secondary" onClick={() => void fetchEntitlements()}>
          Search
        </Button>
      </div>

      {loading && <Loading label="Loading entitlements…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchEntitlements()} />}

      {!loading && !error && (
        <Card>
          <Table caption="Service entitlements list">
            <thead>
              <tr className="border-b border-ok-border bg-neutral-50">
                {['Organisation', 'Service', 'Jurisdiction', 'Enabled', 'Raffle Gate', 'Effective', 'Actions'].map((h) => (
                  <th key={h} scope="col" className="px-4 py-3 text-sm font-medium text-text-secondary">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {entitlements.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-text-secondary text-sm">
                    No entitlements found.
                  </td>
                </tr>
              ) : (
                entitlements.map((e) => (
                  <tr key={e.id} className="border-b border-[#f0f1f4]">
                    <td className="px-4 py-3 text-sm text-text-primary font-mono">{e.organisation_id.slice(0, 8)}…</td>
                    <td className="px-4 py-3 text-sm text-text-primary capitalize">{e.service_type}</td>
                    <td className="px-4 py-3 text-sm text-text-primary">{e.jurisdiction}</td>
                    <td className="px-4 py-3">
                      <Badge label={e.is_enabled ? 'ENABLED' : 'DISABLED'} variant={e.is_enabled ? 'success' : 'neutral'} />
                    </td>
                    <td className="px-4 py-3">
                      {e.service_type === 'raffle' ? (
                        <Badge label={e.raffle_gate_passed ? 'PASSED' : 'PENDING'} variant={e.raffle_gate_passed ? 'success' : 'warning'} />
                      ) : (
                        <span className="text-text-secondary text-sm">N/A</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-text-primary">
                      {e.effective_from ? new Date(e.effective_from).toLocaleDateString() : '—'}
                      {e.effective_until ? ` – ${new Date(e.effective_until).toLocaleDateString()}` : ''}
                    </td>
                    <td className="px-4 py-3">
                      {e.is_enabled && (
                        <Button
                          variant="danger"
                          onClick={() => void handleDisable(e.id)}
                          aria-label={`Disable ${e.service_type} for organisation ${e.organisation_id}`}
                        >
                          Disable
                        </Button>
                      )}
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
