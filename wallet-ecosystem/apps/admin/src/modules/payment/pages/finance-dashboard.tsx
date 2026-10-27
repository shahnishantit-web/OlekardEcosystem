'use client';

import { useCallback, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import type { PaymentIntentSummary, HistoryParams } from '@/lib/api/payment';
import { useDashboardSummary } from '../hooks/useDashboardSummary';
import { FinanceKpiTile } from '../components/FinanceKpiTile';
import { StaleDataBanner } from '../components/StaleDataBanner';
import { IntentStatusBadge } from '../components/IntentStatusBadge';

export function FinanceDashboardPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const { summary, isLoading, error, fetchedAt, refetch } = useDashboardSummary();
  const [queryingId, setQueryingId] = useState<string | null>(null);
  const [queryToast, setQueryToast] = useState<string | null>(null);

  const canInvestigate = hasPermission('payment.intents.admin');

  const handleQueryStatus = useCallback(async (id: string) => {
    if (!authenticatedClient) return;
    setQueryingId(id);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      await api.queryIntentStatus(id);
      setQueryToast('Status query dispatched.');
      setTimeout(() => setQueryToast(null), 3000);
    } catch {
      setQueryToast('Failed to dispatch status query.');
      setTimeout(() => setQueryToast(null), 4000);
    } finally {
      setQueryingId(null);
    }
  }, [authenticatedClient]);

  if (!hasPermission('payment.history.read.platform')) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to view the finance dashboard.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Finance Dashboard" />

      {queryToast && (
        <div role="status" aria-live="polite" className="mb-4 rounded-lg border border-ok-info-border bg-ok-info-bg px-4 py-2.5 text-sm text-ok-info-fg">
          {queryToast}
        </div>
      )}

      {fetchedAt && (
        <div className="mb-4">
          <StaleDataBanner fetchedAt={fetchedAt} onRefresh={refetch} />
        </div>
      )}

      {isLoading && <LoadingState label="Loading finance summary…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={refetch} />}

      {!isLoading && !error && summary && (
        <div className="space-y-6">
          {/* Volume KPIs by currency */}
          <section aria-labelledby="volume-heading">
            <h2 id="volume-heading" className="mb-3 text-sm font-semibold text-text-secondary uppercase tracking-wide">
              Today&apos;s Volume
            </h2>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {summary.volume_by_currency.length === 0 ? (
                <p className="col-span-4 text-sm text-text-secondary">No volume data for today.</p>
              ) : (
                summary.volume_by_currency.map((vol) => (
                  <FinanceKpiTile
                    key={vol.currency_code}
                    label={`Volume (${vol.currency_code})`}
                    value={vol.total_minor / 100}
                    currency={vol.currency_code}
                  />
                ))
              )}
            </div>
          </section>

          {/* Attempt counts by status */}
          <section aria-labelledby="status-heading">
            <h2 id="status-heading" className="mb-3 text-sm font-semibold text-text-secondary uppercase tracking-wide">
              Intent Counts
            </h2>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
              {Object.entries(summary.attempt_counts_by_status).map(([status, count]) => (
                <FinanceKpiTile key={status} label={status} value={count} />
              ))}
            </div>
          </section>

          {/* Fulfilment backlog */}
          <section aria-labelledby="backlog-heading">
            <h2 id="backlog-heading" className="mb-3 text-sm font-semibold text-text-secondary uppercase tracking-wide">
              Fulfilment Backlog
            </h2>
            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FinanceKpiTile
                label="Captured — Pending Fulfilment"
                value={summary.fulfilment_backlog_count}
                trend={summary.fulfilment_backlog_count > 0 ? 'down' : 'neutral'}
              />
            </div>
            <BacklogTable
              canQueryStatus={canInvestigate}
              queryingId={queryingId}
              onQueryStatus={handleQueryStatus}
            />
          </section>
        </div>
      )}

      {!isLoading && !error && !summary && (
        <Card>
          <p className="py-8 text-center text-sm text-text-secondary">No summary data available.</p>
        </Card>
      )}
    </PageContainer>
  );
}

interface BacklogTableProps {
  canQueryStatus: boolean;
  queryingId: string | null;
  onQueryStatus: (id: string) => void;
}

function BacklogTable({ canQueryStatus, queryingId, onQueryStatus }: BacklogTableProps) {
  const { authenticatedClient } = useAuth();
  const [intents, setIntents] = useState<PaymentIntentSummary[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      const params: HistoryParams = { has_pending_fulfilment: true, per_page: 20 };
      const res = await api.getPaymentHistory(params);
      const page = res.data as unknown as { data: PaymentIntentSummary[] };
      setIntents(page.data ?? []);
    } catch {
      setError('Failed to load backlog.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  // Load on mount
  useState(() => { void load(); });

  if (loading) return <LoadingState label="Loading backlog…" size="sm" />;
  if (error) return <ErrorState message={error} onRetry={load} />;
  if (intents.length === 0) {
    return <p className="text-sm text-text-secondary">No backlog items — all captured intents have been dispatched for fulfilment.</p>;
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-ok-card-border">
      <table className="min-w-full divide-y divide-ok-border text-sm">
        <thead className="bg-neutral-50">
          <tr>
            <th scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">Intent ID</th>
            <th scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">Status</th>
            <th scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">Amount</th>
            <th scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">Created</th>
            {canQueryStatus && <th scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">Actions</th>}
          </tr>
        </thead>
        <tbody className="divide-y divide-ok-border bg-white">
          {intents.map((intent) => (
            <tr key={intent.id}>
              <td className="px-4 py-3 font-mono text-xs">
                <Link href={`/payments/finance/intents/${intent.id}`} className="text-blue-600 hover:underline">
                  {intent.id.slice(0, 8)}…
                </Link>
              </td>
              <td className="px-4 py-3">
                <IntentStatusBadge status={intent.status} />
              </td>
              <td className="px-4 py-3 tabular-nums">
                {(intent.amount_minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })} {intent.currency_code}
              </td>
              <td className="px-4 py-3 text-text-secondary">
                {new Date(intent.created_at).toLocaleString()}
              </td>
              {canQueryStatus && (
                <td className="px-4 py-3">
                  <button
                    type="button"
                    disabled={queryingId === intent.id}
                    onClick={() => onQueryStatus(intent.id)}
                    className="rounded border border-ok-border px-2 py-1 text-xs font-medium text-text-primary hover:bg-ok-hover-bg disabled:opacity-50"
                  >
                    {queryingId === intent.id ? 'Dispatching…' : 'Query Status'}
                  </button>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
