'use client';

import Link from 'next/link';
import { Suspense, useCallback, useEffect, useState } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient, type SettlementRecord } from '@/lib/api/admin-app-client';

function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'error' | 'info' {
  switch (status) {
    case 'executed':         return 'success';
    case 'approved':         return 'info';
    case 'pending_approval': return 'warning';
    case 'rejected':
    case 'failed':           return 'error';
    default:                 return 'neutral';
  }
}

function formatAmount(minorUnits: number, currency: string): string {
  return `${currency} ${(minorUnits / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

function SettlementListInner() {
  const { authenticatedClient } = useAuth();
  const searchParams = useSearchParams();
  const router = useRouter();

  const statusFilter = searchParams.get('status') ?? '';
  const fromDate = searchParams.get('from_date') ?? '';
  const toDate = searchParams.get('to_date') ?? '';

  const [settlements, setSettlements] = useState<SettlementRecord[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchSettlements = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string> = { per_page: '25' };
      if (statusFilter) params.status = statusFilter;
      if (fromDate) params.from_date = fromDate;
      if (toDate) params.to_date = toDate;
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listSettlement(params);
      setSettlements(Array.isArray(res.data) ? res.data : []);
      setTotal(res.meta.total);
    } catch {
      setError('Failed to load settlements.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, statusFilter, fromDate, toDate]);

  useEffect(() => { void fetchSettlements(); }, [fetchSettlements]);

  function applyFilter(key: string, value: string) {
    const params = new URLSearchParams(searchParams.toString());
    if (value) params.set(key, value); else params.delete(key);
    router.push(`?${params.toString()}`);
  }

  return (
    <PageContainer>
      <PageHeader
        title="Settlement History"
        actions={
          <Button variant="secondary" onClick={() => void fetchSettlements()} aria-label="Refresh settlement history">
            Refresh
          </Button>
        }
      />

      <div className="flex gap-3 mb-4 flex-wrap">
        <select
          aria-label="Filter by settlement status"
          value={statusFilter}
          onChange={(e) => applyFilter('status', e.target.value)}
          className="text-sm border border-border-primary rounded-md px-3 py-1.5"
        >
          <option value="">All statuses</option>
          <option value="pending_approval">Pending Approval</option>
          <option value="approved">Approved</option>
          <option value="executed">Executed</option>
          <option value="rejected">Rejected</option>
          <option value="failed">Failed</option>
        </select>
        <input
          type="date"
          aria-label="From settlement date"
          value={fromDate}
          onChange={(e) => applyFilter('from_date', e.target.value)}
          className="text-sm border border-border-primary rounded-md px-3 py-1.5"
        />
        <input
          type="date"
          aria-label="To settlement date"
          value={toDate}
          onChange={(e) => applyFilter('to_date', e.target.value)}
          className="text-sm border border-border-primary rounded-md px-3 py-1.5"
        />
      </div>

      {loading && <Loading label="Loading settlements…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchSettlements()} />}

      {!loading && !error && (
        <Card>
          <p className="text-sm text-text-secondary mb-3">{total} settlement{total !== 1 ? 's' : ''} found</p>
          <div className="overflow-x-auto">
            <table className="min-w-full" aria-label="Settlement history table">
              <thead>
                <tr className="border-b border-ok-border border-t border-ok-border bg-neutral-50">
                  {['Date', 'Currency', 'Total Amount', 'Net Position', 'Provider', 'Reference', 'Status', ''].map(h => (
                    <th key={h} scope="col" className="px-4 py-3 text-left font-medium text-text-secondary text-sm">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {settlements.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-8 text-center text-text-secondary text-sm">
                      No settlements found.
                    </td>
                  </tr>
                ) : (
                  settlements.map((s) => (
                    <tr key={s.settlement_id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                      <td className="px-4 py-3 text-sm text-text-primary">{s.settlement_date}</td>
                      <td className="px-4 py-3 text-sm text-text-primary">{s.currency_code}</td>
                      <td className="px-4 py-3 text-sm tabular-nums">
                        {formatAmount(s.total_amount_minor_units, s.currency_code)}
                      </td>
                      <td className="px-4 py-3 text-sm tabular-nums">
                        {formatAmount(s.net_position_minor_units, s.currency_code)}
                      </td>
                      <td className="px-4 py-3 text-sm text-text-primary">{s.provider ?? '—'}</td>
                      <td className="px-4 py-3 text-sm font-mono text-xs text-text-primary">{s.reference ?? '—'}</td>
                      <td className="px-4 py-3">
                        <Badge
                          label={s.status.replace(/_/g, ' ').toUpperCase()}
                          variant={statusVariant(s.status)}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <Link
                          href={`/reconciliation/settlements/${s.settlement_id}`}
                          className="text-sm text-accent hover:underline"
                          aria-label={`View settlement ${s.settlement_id}`}
                        >
                          View
                        </Link>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </PageContainer>
  );
}

export function SettlementListPage() {
  return (
    <Suspense fallback={<Loading label="Loading…" />}>
      <SettlementListInner />
    </Suspense>
  );
}
