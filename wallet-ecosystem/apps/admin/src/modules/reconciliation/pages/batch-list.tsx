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
import { AdminAppClient, type ReconciliationBatch } from '@/lib/api/admin-app-client';

function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'error' | 'info' {
  switch (status) {
    case 'completed': return 'success';
    case 'matched':   return 'info';
    case 'processing':
    case 'running':   return 'warning';
    case 'failed':    return 'error';
    default:          return 'neutral';
  }
}

function formatAmount(minorUnits: number, currency: string): string {
  return `${currency} ${(minorUnits / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

function ReconciliationBatchListInner() {
  const { authenticatedClient } = useAuth();
  const searchParams = useSearchParams();
  const router = useRouter();

  const statusFilter = searchParams.get('status') ?? '';
  const fromDate = searchParams.get('from_date') ?? '';
  const toDate = searchParams.get('to_date') ?? '';

  const [batches, setBatches] = useState<ReconciliationBatch[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchBatches = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string> = { per_page: '25' };
      if (statusFilter) params.status = statusFilter;
      if (fromDate) params.from_date = fromDate;
      if (toDate) params.to_date = toDate;
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listReconciliation(params);
      setBatches(Array.isArray(res.data) ? res.data : []);
      setTotal(res.meta.total);
    } catch {
      setError('Failed to load reconciliation batches.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, statusFilter, fromDate, toDate]);

  useEffect(() => { void fetchBatches(); }, [fetchBatches]);

  function applyFilter(key: string, value: string) {
    const params = new URLSearchParams(searchParams.toString());
    if (value) params.set(key, value); else params.delete(key);
    router.push(`?${params.toString()}`);
  }

  return (
    <PageContainer>
      <PageHeader
        title="Reconciliation Batches"
        actions={
          <Button variant="secondary" onClick={() => void fetchBatches()} aria-label="Refresh reconciliation batches">
            Refresh
          </Button>
        }
      />

      <div className="flex gap-3 mb-4 flex-wrap">
        <select
          aria-label="Filter by status"
          value={statusFilter}
          onChange={(e) => applyFilter('status', e.target.value)}
          className="text-sm border border-border-primary rounded-md px-3 py-1.5"
        >
          <option value="">All statuses</option>
          <option value="running">Running</option>
          <option value="completed">Completed</option>
          <option value="failed">Failed</option>
        </select>
        <input
          type="date"
          aria-label="From date"
          value={fromDate}
          onChange={(e) => applyFilter('from_date', e.target.value)}
          className="text-sm border border-border-primary rounded-md px-3 py-1.5"
        />
        <input
          type="date"
          aria-label="To date"
          value={toDate}
          onChange={(e) => applyFilter('to_date', e.target.value)}
          className="text-sm border border-border-primary rounded-md px-3 py-1.5"
        />
      </div>

      {loading && <Loading label="Loading batches…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchBatches()} />}

      {!loading && !error && (
        <Card>
          <p className="text-sm text-text-secondary mb-3">{total} batch{total !== 1 ? 'es' : ''} found</p>
          <div className="overflow-x-auto">
            <table className="min-w-full" aria-label="Reconciliation batches table">
              <thead>
                <tr className="border-b border-ok-border border-t border-ok-border bg-neutral-50">
                  {['Period', 'Currency', 'Total', 'Matched', 'Unmatched', 'Discrepancy', 'Status', ''].map(h => (
                    <th key={h} scope="col" className="px-4 py-3 text-left font-medium text-text-secondary text-sm">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {batches.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-8 text-center text-text-secondary text-sm">
                      No reconciliation batches found.
                    </td>
                  </tr>
                ) : (
                  batches.map((b) => (
                    <tr key={b.batch_id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                      <td className="px-4 py-3 text-sm text-text-primary">
                        {b.period_start} → {b.period_end}
                      </td>
                      <td className="px-4 py-3 text-sm text-text-primary">{b.currency_code}</td>
                      <td className="px-4 py-3 text-sm tabular-nums">{b.total_transactions}</td>
                      <td className="px-4 py-3 text-sm tabular-nums text-green-600">{b.matched_count}</td>
                      <td className="px-4 py-3 text-sm tabular-nums text-amber-600">{b.unmatched_count}</td>
                      <td className="px-4 py-3 text-sm tabular-nums">
                        {formatAmount(b.discrepancy_amount_minor_units, b.currency_code)}
                      </td>
                      <td className="px-4 py-3">
                        <Badge
                          label={b.status.replace(/_/g, ' ').toUpperCase()}
                          variant={statusVariant(b.status)}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <Link
                          href={`/reconciliation/batches/${b.batch_id}`}
                          className="text-sm text-accent hover:underline"
                          aria-label={`View reconciliation batch ${b.batch_id}`}
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

export function ReconciliationBatchListPage() {
  return (
    <Suspense fallback={<Loading label="Loading…" />}>
      <ReconciliationBatchListInner />
    </Suspense>
  );
}
