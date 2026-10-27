'use client';

import Link from 'next/link';
import { useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { useAuth } from '@/lib/auth/context';
import { useRefundManagement } from '../hooks/useRefundManagement';
import { RefundStatusBadge } from '../components/RefundStatusBadge';
import { CurrencyAmount } from '../components/CurrencyAmount';
import type { RefundStatus } from '@/lib/api/payment';

export function RefundManagementPage() {
  const { hasPermission } = useAuth();
  const [statusFilter, setStatusFilter] = useState<RefundStatus | ''>('');

  const { refunds, isLoading, error, page, lastPage, setPage, refetch } = useRefundManagement(
    statusFilter ? { status: statusFilter } : {},
  );

  if (!hasPermission('payment.refunds.view')) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to view refunds.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Refund Management" />

      <div className="mb-4">
        <select
          aria-label="Filter by status"
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value as RefundStatus | ''); setPage(1); }}
          className="rounded-md border border-ok-border bg-white px-3 py-1.5 text-sm"
        >
          <option value="">All statuses</option>
          {(['pending_approval', 'approved', 'processing', 'completed', 'rejected', 'failed'] as RefundStatus[]).map((s) => (
            <option key={s} value={s}>{s.replace('_', ' ')}</option>
          ))}
        </select>
      </div>

      {isLoading && <LoadingState label="Loading refunds…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={refetch} />}

      {!isLoading && !error && (
        <Card>
          {refunds.length === 0 ? (
            <p className="py-8 text-center text-sm text-text-secondary">No refunds found.</p>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-ok-border text-sm">
                  <thead className="bg-neutral-50">
                    <tr>
                      {['Amount', 'Currency', 'Status', 'Intent', 'Requested'].map((h) => (
                        <th key={h} scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ok-border bg-white">
                    {refunds.map((refund) => (
                      <tr key={refund.id}>
                        <td className="px-4 py-3 tabular-nums">
                          <CurrencyAmount amountMinor={refund.amount_minor} currency={refund.currency_code} />
                        </td>
                        <td className="px-4 py-3 text-text-secondary">{refund.currency_code}</td>
                        <td className="px-4 py-3"><RefundStatusBadge status={refund.status} /></td>
                        <td className="px-4 py-3 font-mono text-xs">
                          <Link href={`/payments/finance/intents/${refund.payment_intent_id}`} className="text-blue-600 hover:underline">
                            {refund.payment_intent_id.slice(0, 8)}…
                          </Link>
                        </td>
                        <td className="px-4 py-3 text-text-secondary">
                          {new Date(refund.requested_at).toLocaleString()}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="flex items-center justify-between border-t border-ok-border px-4 py-3">
                <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded border border-ok-border px-3 py-1.5 text-sm disabled:opacity-40">Previous</button>
                <span className="text-sm text-text-secondary">Page {page} of {lastPage}</span>
                <button type="button" disabled={page >= lastPage} onClick={() => setPage(page + 1)} className="rounded border border-ok-border px-3 py-1.5 text-sm disabled:opacity-40">Next</button>
              </div>
            </>
          )}
        </Card>
      )}
    </PageContainer>
  );
}
