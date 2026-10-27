'use client';

import { useState, useEffect, useCallback } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import type { DisputeCaseSummary } from '@/lib/api/payment';
import { DisputeStatusBadge } from '../components/DisputeStatusBadge';

interface Props {
  id: string;
}

export function DisputeCaseDetailPage({ id }: Props) {
  const { authenticatedClient, hasPermission } = useAuth();
  const [dispute, setDispute] = useState<DisputeCaseSummary | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [holding, setHolding] = useState(false);
  const [holdError, setHoldError] = useState<string | null>(null);

  const canManage = hasPermission('payment.disputes.manage');

  useEffect(() => {
    if (!authenticatedClient) return;
    setIsLoading(true);
    setError(null);
    const client = new PaymentApiClient(authenticatedClient);
    client.getDisputeCase(id).then((res) => {
      setDispute(res.data as unknown as DisputeCaseSummary);
    }).catch(() => setError('Failed to load dispute case.')).finally(() => setIsLoading(false));
  }, [authenticatedClient, id]);

  const handleAuthoriseHold = useCallback(async () => {
    if (!authenticatedClient || !dispute) return;
    setHolding(true);
    setHoldError(null);
    try {
      const key = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
      });
      const client = new PaymentApiClient(authenticatedClient);
      await client.authoriseDisputeHold(id, { hold_reason: 'Finance review authorised' }, key);
      // Reload
      const res = await client.getDisputeCase(id);
      setDispute(res.data as unknown as DisputeCaseSummary);
    } catch {
      setHoldError('Failed to authorise hold.');
    } finally {
      setHolding(false);
    }
  }, [authenticatedClient, dispute, id]);

  if (!hasPermission('payment.disputes.view')) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to view dispute cases.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader
        title="Dispute Case Detail"
        actions={
          <Link href="/payments/finance/disputes" className="text-sm text-blue-600 hover:underline">
            ← Back to list
          </Link>
        }
      />

      {isLoading && <LoadingState label="Loading dispute case…" />}
      {!isLoading && error && <ErrorState message={error} />}

      {!isLoading && !error && dispute && (
        <div className="space-y-4">
          <Card semanticLabel="Dispute case summary">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p className="text-xs font-mono text-text-secondary">ID: {id}</p>
                <div className="mt-1 flex items-center gap-2">
                  <DisputeStatusBadge status={dispute.status} />
                  {dispute.requires_finance_review && (
                    <span className="text-xs font-medium text-ok-warning-fg">Finance review required</span>
                  )}
                </div>
                <p className="mt-2 text-sm text-text-secondary">{dispute.case_type}</p>
              </div>

              {canManage && (
                <div>
                  <button
                    type="button"
                    disabled={holding}
                    onClick={() => void handleAuthoriseHold()}
                    className="rounded-md border border-ok-border bg-white px-4 py-2 text-sm font-medium hover:bg-ok-hover-bg disabled:opacity-50"
                  >
                    {holding ? 'Authorising…' : 'Authorise Hold'}
                  </button>
                  {holdError && <p role="alert" className="mt-1 text-xs text-ok-error-fg">{holdError}</p>}
                </div>
              )}
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
              <div>
                <dt className="text-xs font-medium uppercase text-text-secondary">Linked Intent</dt>
                <dd className="mt-1 font-mono text-xs">
                  {dispute.payment_intent_id ? (
                    <Link href={`/payments/finance/intents/${dispute.payment_intent_id}`} className="text-blue-600 hover:underline">
                      {dispute.payment_intent_id.slice(0, 8)}…
                    </Link>
                  ) : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase text-text-secondary">Support Case</dt>
                <dd className="mt-1">
                  {dispute.linked_support_case_id ? (
                    <Link href={`/support/cases/${dispute.linked_support_case_id}`} className="text-xs text-blue-600 hover:underline">
                      {dispute.linked_support_case_id.slice(0, 8)}…
                    </Link>
                  ) : <span className="text-xs text-text-secondary">case not found</span>}
                </dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase text-text-secondary">Deadline</dt>
                <dd className="mt-1 text-text-secondary">
                  {dispute.deadline_at ? new Date(dispute.deadline_at).toLocaleDateString() : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase text-text-secondary">Created</dt>
                <dd className="mt-1 text-text-secondary">{new Date(dispute.created_at).toLocaleString()}</dd>
              </div>
            </dl>
          </Card>
        </div>
      )}
    </PageContainer>
  );
}
