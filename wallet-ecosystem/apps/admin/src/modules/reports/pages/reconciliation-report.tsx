'use client';

import { useCallback, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { ReconciliationBatch } from '@/lib/api/admin-app-client';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import {
  deriveReconciliationSummary,
  type ReconciliationSummary,
} from '@/src/modules/reports/api/reports';

export function ReconciliationReportPage() {
  const { authenticatedClient } = useAuth();

  const [dateFrom, setDateFrom]   = useState('');
  const [dateTo, setDateTo]       = useState('');
  const [searched, setSearched]   = useState(false);
  const [dateError, setDateError] = useState<string | null>(null);
  const [batches, setBatches]     = useState<ReconciliationBatch[]>([]);
  const [summary, setSummary]     = useState<ReconciliationSummary | null>(null);
  const [loading, setLoading]     = useState(false);
  const [error, setError]         = useState<string | null>(null);

  const fetchBatches = useCallback(async (params: { date_from: string; date_to: string }) => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listReconciliation({
        date_from: params.date_from,
        date_to:   params.date_to,
      });
      const data = Array.isArray(res.data) ? res.data : [];
      setBatches(data);
      setSummary(deriveReconciliationSummary(data));
    } catch {
      setError('Failed to load reconciliation batches.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  const handleSearch = () => {
    setDateError(null);
    if (!dateFrom || !dateTo) {
      setDateError('Both From and To dates are required.');
      return;
    }
    if (dateTo < dateFrom) {
      setDateError('Period end must be on or after period start.');
      return;
    }
    setSearched(true);
    void fetchBatches({ date_from: dateFrom, date_to: dateTo });
  };

  return (
    <PageContainer>
      <PageHeader title="Reconciliation Report" />

      {/* Date range filter */}
      <Card className="mb-6 p-4">
        <div className="flex flex-wrap gap-3 items-end">
          <div>
            <label htmlFor="recon-from" className="block text-xs font-medium text-text-secondary mb-1">From</label>
            <input
              id="recon-from"
              type="date"
              value={dateFrom}
              onChange={e => setDateFrom(e.target.value)}
              className="rounded border border-ok-border px-2 py-1.5 text-sm"
            />
          </div>
          <div>
            <label htmlFor="recon-to" className="block text-xs font-medium text-text-secondary mb-1">To</label>
            <input
              id="recon-to"
              type="date"
              value={dateTo}
              onChange={e => setDateTo(e.target.value)}
              className="rounded border border-ok-border px-2 py-1.5 text-sm"
            />
          </div>
          <Button variant="primary" onClick={handleSearch} aria-label="Search reconciliation batches">
            Search
          </Button>
        </div>
        {dateError && (
          <p role="alert" className="mt-2 text-sm text-error">{dateError}</p>
        )}
      </Card>

      {loading && <Loading label="Loading reconciliation batches…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => searched && void fetchBatches({ date_from: dateFrom, date_to: dateTo })} />}

      {/* KPI summary cards — shown after first successful search */}
      {!loading && !error && summary !== null && (
        <dl className="grid grid-cols-2 gap-4 mb-6 sm:grid-cols-4">
          <Card className="p-4">
            <dt className="text-xs font-medium text-text-secondary uppercase tracking-wider mb-1">Total Transactions</dt>
            <dd className="text-2xl font-semibold tabular-nums">{summary.total_transactions.toLocaleString()}</dd>
          </Card>
          <Card className="p-4">
            <dt className="text-xs font-medium text-text-secondary uppercase tracking-wider mb-1">Matched %</dt>
            <dd className="text-2xl font-semibold tabular-nums">
              {summary.total_transactions > 0 ? summary.matched_pct.toFixed(1) : '0.0'}%
            </dd>
          </Card>
          <Card className="p-4">
            <dt className="text-xs font-medium text-text-secondary uppercase tracking-wider mb-1">Unmatched Count</dt>
            <dd className={`text-2xl font-semibold tabular-nums ${summary.unmatched_count > 0 ? 'text-error' : ''}`}>
              {summary.unmatched_count.toLocaleString()}
            </dd>
          </Card>
          <Card className="p-4">
            <dt className="text-xs font-medium text-text-secondary uppercase tracking-wider mb-1">Exception Amount</dt>
            <dd className="text-2xl font-semibold tabular-nums">BWP {summary.exception_amount_bwp.toFixed(2)}</dd>
          </Card>
        </dl>
      )}

      {/* Batch table */}
      {!loading && !error && searched && batches.length === 0 && (
        <p className="text-sm text-text-secondary">No reconciliation batches found for the selected period.</p>
      )}

      {!loading && !error && batches.length > 0 && (
        <div className="overflow-x-auto rounded border border-ok-border">
          <table className="min-w-full" aria-label="Reconciliation batches">
            <thead className="bg-ok-surface">
              <tr>
                {['Batch ID', 'Status', 'Matched', 'Unmatched', 'Discrepancy', 'Period', 'Actions'].map(h => (
                  <th key={h} className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {batches.map(batch => (
                <tr key={batch.batch_id} className="border-b border-ok-border hover:bg-ok-hover-bg">
                  <td className="py-3 px-4 text-sm font-mono">{batch.batch_id.substring(0, 8)}…</td>
                  <td className="py-3 px-4">
                    <Badge
                      label={batch.status.toUpperCase()}
                      variant={batch.status === 'completed' ? 'success' : batch.status === 'failed' ? 'error' : 'warning'}
                    />
                  </td>
                  <td className="py-3 px-4 text-sm tabular-nums text-success">{batch.matched_count.toLocaleString()}</td>
                  <td className="py-3 px-4 text-sm tabular-nums">
                    <span className={batch.unmatched_count > 0 ? 'text-error font-medium' : 'text-text-secondary'}>
                      {batch.unmatched_count.toLocaleString()}
                    </span>
                  </td>
                  <td className="py-3 px-4 text-sm tabular-nums">{(batch.discrepancy_amount_minor_units / 100).toLocaleString()}</td>
                  <td className="py-3 px-4 text-xs text-text-secondary">
                    {new Date(batch.period_start).toLocaleDateString()} – {new Date(batch.period_end).toLocaleDateString()}
                  </td>
                  <td className="py-3 px-4">
                    <a
                      href={`/reconciliation/batches/${batch.batch_id}`}
                      className="text-sm text-brand-primary underline hover:no-underline"
                      aria-label={`View batch ${batch.batch_id}`}
                    >
                      Detail
                    </a>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </PageContainer>
  );
}
