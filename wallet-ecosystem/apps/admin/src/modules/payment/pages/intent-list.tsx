'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import type { PaymentIntentSummary, HistoryParams } from '@/lib/api/payment';
import { IntentStatusBadge } from '../components/IntentStatusBadge';
import { CurrencyAmount } from '../components/CurrencyAmount';

export function IntentListPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const [intents, setIntents] = useState<PaymentIntentSummary[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  // Filter state
  const [status, setStatus] = useState('');
  const [currency, setCurrency] = useState('');

  const load = useCallback(async () => {
    if (!authenticatedClient) return;
    setIsLoading(true);
    setError(null);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      const params: HistoryParams = { page, per_page: 25 };
      if (status) params.status = status;
      if (currency) params.currency = currency;
      const res = await api.getPaymentHistory(params);
      const paginated = res.data as unknown as { data: PaymentIntentSummary[]; meta: { last_page: number } };
      setIntents(paginated.data ?? []);
      setLastPage(paginated.meta?.last_page ?? 1);
    } catch {
      setError('Failed to load payment history.');
    } finally {
      setIsLoading(false);
    }
  }, [authenticatedClient, page, status, currency]);

  useEffect(() => { void load(); }, [load]);

  if (!hasPermission('payment.history.read.platform')) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to view payment history.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Payment History" />

      {/* Filters */}
      <div className="mb-4 flex flex-wrap gap-3">
        <select
          aria-label="Filter by status"
          value={status}
          onChange={(e) => { setStatus(e.target.value); setPage(1); }}
          className="rounded-md border border-ok-border bg-white px-3 py-1.5 text-sm"
        >
          <option value="">All statuses</option>
          {['created', 'captured', 'fulfilled', 'failed', 'cancelled', 'pending_ledger', 'ledger_failed'].map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>
        <select
          aria-label="Filter by currency"
          value={currency}
          onChange={(e) => { setCurrency(e.target.value); setPage(1); }}
          className="rounded-md border border-ok-border bg-white px-3 py-1.5 text-sm"
        >
          <option value="">All currencies</option>
          {['BWP', 'USD', 'ZAR', 'ZMW'].map((c) => (
            <option key={c} value={c}>{c}</option>
          ))}
        </select>
      </div>

      {isLoading && <LoadingState label="Loading payment history…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={load} />}

      {!isLoading && !error && (
        <Card>
          {intents.length === 0 ? (
            <p className="py-8 text-center text-sm text-text-secondary">No payment intents found.</p>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-ok-border text-sm">
                  <thead className="bg-neutral-50">
                    <tr>
                      {['Intent ID', 'Status', 'Amount', 'Provider', 'Created', 'Pending Fulfilment'].map((h) => (
                        <th key={h} scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">
                          {h}
                        </th>
                      ))}
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
                          <CurrencyAmount amountMinor={intent.amount_minor} currency={intent.currency_code} />
                        </td>
                        <td className="px-4 py-3 text-text-secondary">{intent.provider_code ?? '—'}</td>
                        <td className="px-4 py-3 text-text-secondary">
                          {new Date(intent.created_at).toLocaleString()}
                        </td>
                        <td className="px-4 py-3 text-center">
                          {intent.has_pending_fulfilment ? (
                            <span className="text-xs font-medium text-ok-warning-fg">⚠ Pending</span>
                          ) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              <div className="flex items-center justify-between border-t border-ok-border px-4 py-3">
                <button
                  type="button"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => p - 1)}
                  className="rounded border border-ok-border px-3 py-1.5 text-sm disabled:opacity-40"
                >
                  Previous
                </button>
                <span className="text-sm text-text-secondary">Page {page} of {lastPage}</span>
                <button
                  type="button"
                  disabled={page >= lastPage}
                  onClick={() => setPage((p) => p + 1)}
                  className="rounded border border-ok-border px-3 py-1.5 text-sm disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            </>
          )}
        </Card>
      )}
    </PageContainer>
  );
}
