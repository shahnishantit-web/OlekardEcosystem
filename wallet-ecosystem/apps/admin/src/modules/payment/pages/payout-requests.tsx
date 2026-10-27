'use client';

import { useState, useCallback } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useAuth } from '@/lib/auth/context';
import { usePayoutRequests } from '../hooks/usePayoutRequests';
import { PayoutStatusBadge } from '../components/PayoutStatusBadge';
import { CurrencyAmount } from '../components/CurrencyAmount';

export function PayoutRequestsPage() {
  const { hasPermission } = useAuth();
  const { payouts, isLoading, error, page, lastPage, setPage, refetch, approveReservation } = usePayoutRequests();
  const [processing, setProcessing] = useState<string | null>(null);
  const [actionError, setActionError] = useState<Record<string, string>>({});

  const canApprove = hasPermission('payment.payout.approve');

  const handleApproveReservation = useCallback(async (id: string) => {
    setProcessing(id);
    setActionError((prev) => ({ ...prev, [id]: '' }));
    try {
      await approveReservation(id);
    } catch {
      setActionError((prev) => ({ ...prev, [id]: 'Failed to approve reservation.' }));
    } finally {
      setProcessing(null);
    }
  }, [approveReservation]);

  if (!hasPermission('payment.payout.view')) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to view payout requests.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Payout Requests" />

      {isLoading && <LoadingState label="Loading payout requests…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={refetch} />}

      {!isLoading && !error && (
        <Card>
          {payouts.length === 0 ? (
            <p className="py-8 text-center text-sm text-text-secondary">No payout requests found.</p>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-ok-border text-sm">
                  <thead className="bg-neutral-50">
                    <tr>
                      {['Reference', 'Purpose', 'Currency', 'Status', 'Organisation', 'Actions'].map((h) => (
                        <th key={h} scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ok-border bg-white">
                    {payouts.map((payout) => {
                      const isReservationPending = payout.status === 'reservation_pending';
                      const isTerminal = ['executing', 'settled'].includes(payout.status);
                      return (
                        <tr key={payout.id}>
                          <td className="px-4 py-3 font-mono text-xs">
                            <Link href={`/payments/finance/payouts/${payout.id}`} className="text-blue-600 hover:underline">
                              {payout.fund_reference_id?.slice(0, 8) ?? payout.id.slice(0, 8)}…
                            </Link>
                          </td>
                          <td className="px-4 py-3 text-text-secondary">{payout.purpose_type}</td>
                          <td className="px-4 py-3 tabular-nums">
                            {payout.computed_payable_minor != null ? (
                              <CurrencyAmount amountMinor={payout.computed_payable_minor} currency={payout.currency_code} />
                            ) : (
                              <span className="text-text-secondary">—</span>
                            )}
                          </td>
                          <td className="px-4 py-3"><PayoutStatusBadge status={payout.status} /></td>
                          <td className="px-4 py-3 text-text-secondary">{payout.organisation_id}</td>
                          <td className="px-4 py-3">
                            {isReservationPending && canApprove ? (
                              <button
                                type="button"
                                disabled={processing === payout.id}
                                onClick={() => void handleApproveReservation(payout.id)}
                                className="rounded border border-ok-border px-3 py-1 text-xs font-medium hover:bg-ok-hover-bg disabled:opacity-50"
                              >
                                {processing === payout.id ? 'Processing…' : 'Approve Reservation'}
                              </button>
                            ) : isTerminal ? (
                              <Badge label="Pending — not yet available" variant="neutral" />
                            ) : (
                              '—'
                            )}
                            {actionError[payout.id] && (
                              <p role="alert" className="mt-1 text-xs text-ok-error-fg">{actionError[payout.id]}</p>
                            )}
                          </td>
                        </tr>
                      );
                    })}
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
