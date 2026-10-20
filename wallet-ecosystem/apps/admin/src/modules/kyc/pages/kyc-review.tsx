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
import { Empty } from '@/components/ui/Empty';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { KycSubmission, PaginatedResponse } from '@/lib/api/admin-app-client';

type StatusFilter = 'all' | 'pending_review' | 'under_review' | 'escalated' | 'approved' | 'rejected';
type TierFilter = 'all' | '1' | '2' | '3';
type SortField = 'submitted_at' | 'risk_score';
type SortDir = 'asc' | 'desc';

function KycStatusBadge({ status }: { status: string }) {
  const variant =
    status === 'approved'      ? 'success'
    : status === 'rejected'    ? 'error'
    : status === 'escalated'   ? 'error'
    : status === 'under_review'? 'warning'
    : status === 'pending_review' ? 'warning'
    : 'neutral';
  const label = status.replace(/_/g, ' ').toUpperCase();
  return <Badge label={label} variant={variant} />;
}

function RiskBadge({ score }: { score: number | null | undefined }) {
  if (score == null) return <span className="text-sm text-text-secondary">—</span>;
  const variant = score >= 70 ? 'error' : score >= 30 ? 'warning' : 'success';
  return <Badge label={String(score)} variant={variant} />;
}

export function KycReviewPage() {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [items, setItems]         = useState<PaginatedResponse<KycSubmission> | null>(null);
  const [loading, setLoading]     = useState(false);
  const [error, setError]         = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('pending_review');
  const [tierFilter, setTierFilter]     = useState<TierFilter>('all');
  const [dateFrom, setDateFrom]   = useState('');
  const [dateTo, setDateTo]       = useState('');
  const [sortField, setSortField] = useState<SortField>('submitted_at');
  const [sortDir, setSortDir]     = useState<SortDir>('desc');
  const [page, setPage]           = useState(1);

  const fetchItems = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const params: Record<string, string> = {
        page: String(page),
        per_page: '25',
        sort_by: sortField,
        sort_dir: sortDir,
      };
      if (statusFilter !== 'all') params.status = statusFilter;
      if (tierFilter !== 'all')   params.tier    = tierFilter;
      if (dateFrom)               params.date_from = dateFrom;
      if (dateTo)                 params.date_to   = dateTo;
      const res = await client.listKyc(params);
      const payload = res.data as unknown;
      if (Array.isArray(payload)) {
        setItems({
          data: payload as KycSubmission[],
          meta: (res.meta as unknown as PaginatedResponse<KycSubmission>['meta'] | null) ?? {
            current_page: page,
            per_page: 25,
            total: payload.length,
            last_page: 1,
          },
        });
      } else {
        setItems(payload as PaginatedResponse<KycSubmission>);
      }
    } catch {
      setError('Failed to load KYC queue. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, statusFilter, tierFilter, dateFrom, dateTo, sortField, sortDir, page]);

  useEffect(() => { void fetchItems(); }, [fetchItems]);

  const toggleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDir(d => d === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDir('desc');
    }
    setPage(1);
  };

  return (
    <PageContainer>
      <PageHeader title="KYC Review Queue" />

      {/* Filters */}
      <Card className="mb-6 p-4">
        <div className="flex flex-wrap gap-3 items-end">
          {/* Status */}
          <div>
            <label htmlFor="kyc-status-filter" className="block text-sm font-medium text-text-secondary mb-1">
              Status
            </label>
            <select
              id="kyc-status-filter"
              value={statusFilter}
              onChange={e => { setStatusFilter(e.target.value as StatusFilter); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2"
            >
              <option value="all">All</option>
              <option value="pending_review">Pending</option>
              <option value="under_review">Under Review</option>
              <option value="escalated">Escalated</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>

          {/* Tier */}
          <div>
            <label htmlFor="kyc-tier-filter" className="block text-sm font-medium text-text-secondary mb-1">
              Tier
            </label>
            <select
              id="kyc-tier-filter"
              value={tierFilter}
              disabled
              onChange={e => { setTierFilter(e.target.value as TierFilter); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2"
            >
              <option value="all">All</option>
              <option value="1">Tier 1</option>
              <option value="2">Tier 2</option>
              <option value="3">Tier 3</option>
            </select>
            <p className="mt-1 text-xs text-text-secondary">Tier is not recorded on KYC cases.</p>
          </div>

          {/* Date from */}
          <div>
            <label htmlFor="kyc-date-from" className="block text-sm font-medium text-text-secondary mb-1">
              From
            </label>
            <input
              id="kyc-date-from"
              type="date"
              value={dateFrom}
              onChange={e => { setDateFrom(e.target.value); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2"
            />
          </div>

          {/* Date to */}
          <div>
            <label htmlFor="kyc-date-to" className="block text-sm font-medium text-text-secondary mb-1">
              To
            </label>
            <input
              id="kyc-date-to"
              type="date"
              value={dateTo}
              onChange={e => { setDateTo(e.target.value); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2"
            />
          </div>

          <Button
            variant="secondary"
            onClick={() => void fetchItems()}
            aria-label="Apply filters"
          >
            Apply
          </Button>
        </div>
      </Card>

      {loading && <Loading label="Loading KYC queue…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchItems()} />}
      {!loading && !error && items?.data.length === 0 && (
        <Empty title="No KYC submissions" description="No submissions match the current filter." />
      )}

      {!loading && !error && items && items.data.length > 0 && (
        <>
          <div className="overflow-x-auto rounded border border-ok-border">
            <table className="min-w-full" aria-label="KYC submissions">
              <thead className="bg-ok-surface">
                <tr>
                  <th className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                    User Name
                  </th>
                  <th className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                    Phone
                  </th>
                  <th className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                    KYC Type
                  </th>
                  <th className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                    Tier
                  </th>
                  <th className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                    <button
                      className="inline-flex items-center gap-1 hover:text-text-primary"
                      disabled
                      aria-label={`Sort by risk score ${sortField === 'risk_score' ? (sortDir === 'asc' ? 'descending' : 'ascending') : 'descending'}`}
                    >
                      Risk Score
                      {sortField === 'risk_score' && (sortDir === 'asc' ? ' ↑' : ' ↓')}
                    </button>
                  </th>
                  <th className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                    Status
                  </th>
                  <th className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                    <button
                      className="inline-flex items-center gap-1 hover:text-text-primary"
                      onClick={() => toggleSort('submitted_at')}
                      aria-label={`Sort by submission date ${sortField === 'submitted_at' ? (sortDir === 'asc' ? 'descending' : 'ascending') : 'descending'}`}
                    >
                      Submitted
                      {sortField === 'submitted_at' && (sortDir === 'asc' ? ' ↑' : ' ↓')}
                    </button>
                  </th>
                  <th className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                {items.data.map(item => (
                  <tr key={item.id} className="border-b border-ok-border hover:bg-ok-hover-bg transition-colors">
                    <td className="py-3 px-4 text-sm text-text-primary">{item.user_name}</td>
                    <td className="py-3 px-4 text-sm text-text-secondary">{item.user_phone_number ?? '—'}</td>
                    <td className="py-3 px-4 text-sm text-text-secondary capitalize">
                      {(item.kyc_type ?? item.document_type).replace(/_/g, ' ')}
                    </td>
                    <td className="py-3 px-4 text-sm text-text-secondary">{item.tier_applied == null ? 'Not recorded' : `Tier ${item.tier_applied}`}</td>
                    <td className="py-3 px-4"><RiskBadge score={item.risk_score} /></td>
                    <td className="py-3 px-4"><KycStatusBadge status={item.status} /></td>
                    <td className="py-3 px-4 text-sm text-text-secondary">
                      {item.submitted_at ? new Date(item.submitted_at).toLocaleDateString() : 'Not submitted'}
                    </td>
                    <td className="py-3 px-4">
                      <Button
                        variant="secondary"
                        onClick={() => router.push(`/kyc/${item.id}`)}
                        aria-label={`View KYC submission for ${item.user_name}`}
                      >
                        View
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          <div className="mt-4 flex items-center justify-between text-sm text-text-secondary">
            <span>
              Page {items.meta.current_page} of {items.meta.last_page} · {items.meta.total} total
            </span>
            <div className="flex gap-2">
              <Button
                variant="secondary"
                onClick={() => setPage(p => Math.max(1, p - 1))}
                disabled={page <= 1}
                aria-label="Previous page"
              >
                Previous
              </Button>
              <Button
                variant="secondary"
                onClick={() => setPage(p => p + 1)}
                disabled={page >= items.meta.last_page}
                aria-label="Next page"
              >
                Next
              </Button>
            </div>
          </div>
        </>
      )}
    </PageContainer>
  );
}
