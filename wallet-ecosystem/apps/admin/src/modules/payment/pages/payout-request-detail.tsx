'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import type { PayoutRequestSummary } from '@/lib/api/payment';
import { PayoutStatusBadge } from '../components/PayoutStatusBadge';
import { CurrencyAmount } from '../components/CurrencyAmount';

interface Props {
  id: string;
}

export function PayoutRequestDetailPage({ id }: Props) {
  const { authenticatedClient, hasPermission } = useAuth();
  const [payout, setPayout] = useState<PayoutRequestSummary | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authenticatedClient) return;
    setIsLoading(true);
    setError(null);
    const client = new PaymentApiClient(authenticatedClient);
    client.getPayoutRequest(id).then((res) => {
      setPayout(res.data as unknown as PayoutRequestSummary);
    }).catch(() => setError('Failed to load payout request.')).finally(() => setIsLoading(false));
  }, [authenticatedClient, id]);

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
      <PageHeader
        title="Payout Request Detail"
        actions={
          <Link href="/payments/finance/payouts" className="text-sm text-blue-600 hover:underline">
            ← Back to list
          </Link>
        }
      />

      {isLoading && <LoadingState label="Loading payout detail…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={() => { setError(null); setIsLoading(true); }} />}

      {!isLoading && !error && payout && (
        <Card semanticLabel="Payout request detail">
          <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
            <div>
              <dt className="text-xs font-medium uppercase text-text-secondary">Status</dt>
              <dd className="mt-1"><PayoutStatusBadge status={payout.status} /></dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase text-text-secondary">Purpose</dt>
              <dd className="mt-1 text-text-primary">{payout.purpose_type}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase text-text-secondary">Amount</dt>
              <dd className="mt-1 tabular-nums">
                {payout.computed_payable_minor != null ? (
                  <CurrencyAmount amountMinor={payout.computed_payable_minor} currency={payout.currency_code} />
                ) : '—'}
              </dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase text-text-secondary">Beneficiary ID</dt>
              <dd className="mt-1 font-mono text-xs text-text-primary">{payout.beneficiary_id ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase text-text-secondary">Fund Reference</dt>
              <dd className="mt-1 font-mono text-xs text-text-primary">{payout.fund_reference_id ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase text-text-secondary">Organisation</dt>
              <dd className="mt-1 text-text-primary">{payout.organisation_id}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase text-text-secondary">Created</dt>
              <dd className="mt-1 text-text-secondary">{new Date(payout.created_at).toLocaleString()}</dd>
            </div>
          </dl>
        </Card>
      )}
    </PageContainer>
  );
}
