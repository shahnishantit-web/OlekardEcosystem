'use client';

import { useState, useCallback } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { useAuth } from '@/lib/auth/context';
import { useRefundApprovalQueue } from '../hooks/useRefundApprovalQueue';
import { RefundStatusBadge } from '../components/RefundStatusBadge';
import { CurrencyAmount } from '../components/CurrencyAmount';

export function RefundApprovalQueuePage() {
  const { hasPermission } = useAuth();
  const { refunds, isLoading, error, refetch, approve, reject } = useRefundApprovalQueue();
  const [actionError, setActionError] = useState<Record<string, string>>({});
  const [rejectModal, setRejectModal] = useState<{ id: string } | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [processing, setProcessing] = useState<string | null>(null);

  const canApprove = hasPermission('payment.refunds.approve');

  const handleApprove = useCallback(async (id: string) => {
    setProcessing(id);
    setActionError((prev) => ({ ...prev, [id]: '' }));
    try {
      await approve(id);
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } })?.response?.status;
      if (status === 403) {
        setActionError((prev) => ({ ...prev, [id]: 'You cannot approve your own refund request.' }));
      } else if (status === 409) {
        setActionError((prev) => ({ ...prev, [id]: 'This refund has already been processed.' }));
      } else {
        setActionError((prev) => ({ ...prev, [id]: 'Failed to approve refund.' }));
      }
    } finally {
      setProcessing(null);
    }
  }, [approve]);

  const handleRejectSubmit = useCallback(async () => {
    if (!rejectModal) return;
    setProcessing(rejectModal.id);
    setActionError((prev) => ({ ...prev, [rejectModal.id]: '' }));
    try {
      await reject(rejectModal.id, rejectReason);
      setRejectModal(null);
      setRejectReason('');
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } })?.response?.status;
      if (status === 409) {
        setActionError((prev) => ({ ...prev, [rejectModal.id]: 'This refund has already been processed.' }));
      } else {
        setActionError((prev) => ({ ...prev, [rejectModal.id]: 'Failed to reject refund.' }));
      }
    } finally {
      setProcessing(null);
    }
  }, [reject, rejectModal, rejectReason]);

  if (!canApprove) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to approve refunds.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Refund Approval Queue" />

      {isLoading && <LoadingState label="Loading refund queue…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={refetch} />}

      {!isLoading && !error && (
        <Card>
          {refunds.length === 0 ? (
            <p className="py-8 text-center text-sm text-text-secondary">No refunds pending approval.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-ok-border text-sm">
                <thead className="bg-neutral-50">
                  <tr>
                    {['Amount', 'Intent', 'Status', 'Requested', 'Actions'].map((h) => (
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
                      <td className="px-4 py-3 font-mono text-xs">
                        <Link href={`/payments/finance/intents/${refund.payment_intent_id}`} className="text-blue-600 hover:underline">
                          {refund.payment_intent_id.slice(0, 8)}…
                        </Link>
                      </td>
                      <td className="px-4 py-3"><RefundStatusBadge status={refund.status} /></td>
                      <td className="px-4 py-3 text-text-secondary">
                        {new Date(refund.requested_at).toLocaleString()}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            disabled={processing === refund.id}
                            onClick={() => void handleApprove(refund.id)}
                            className="rounded border border-ok-border px-3 py-1 text-xs font-medium text-text-primary hover:bg-ok-hover-bg disabled:opacity-50"
                          >
                            Approve
                          </button>
                          <button
                            type="button"
                            disabled={processing === refund.id}
                            onClick={() => { setRejectModal({ id: refund.id }); setRejectReason(''); }}
                            className="rounded border border-ok-error-border px-3 py-1 text-xs font-medium text-ok-error-fg hover:bg-ok-error-bg disabled:opacity-50"
                          >
                            Reject
                          </button>
                        </div>
                        {actionError[refund.id] && (
                          <p role="alert" className="mt-1 text-xs text-ok-error-fg">{actionError[refund.id]}</p>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {/* Reject modal */}
      {rejectModal && (
        <div role="dialog" aria-modal="true" aria-labelledby="reject-dialog-title" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <h2 id="reject-dialog-title" className="mb-4 text-base font-semibold text-text-primary">
              Reject Refund
            </h2>
            <label htmlFor="reject-reason" className="block text-sm font-medium text-text-primary mb-1">
              Reason (required)
            </label>
            <textarea
              id="reject-reason"
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              rows={3}
              className="w-full rounded-md border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Enter rejection reason…"
            />
            <div className="mt-4 flex justify-end gap-3">
              <button
                type="button"
                onClick={() => { setRejectModal(null); setRejectReason(''); }}
                className="rounded border border-ok-border px-4 py-2 text-sm"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={!rejectReason.trim() || processing === rejectModal.id}
                onClick={() => void handleRejectSubmit()}
                className="rounded bg-ok-error-fg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
              >
                Reject
              </button>
            </div>
            {actionError[rejectModal.id] && (
              <p role="alert" className="mt-2 text-xs text-ok-error-fg">{actionError[rejectModal.id]}</p>
            )}
          </div>
        </div>
      )}
    </PageContainer>
  );
}
