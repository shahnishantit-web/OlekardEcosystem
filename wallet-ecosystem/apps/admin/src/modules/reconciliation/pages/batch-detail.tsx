'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { AdminAppClient, type ReconciliationBatch } from '@/lib/api/admin-app-client';

function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'error' | 'info' {
  switch (status) {
    case 'completed': return 'success';
    case 'matched':   return 'info';
    case 'running':
    case 'processing': return 'warning';
    case 'failed':    return 'error';
    default:          return 'neutral';
  }
}

function formatAmount(minorUnits: number, currency: string): string {
  return `${currency} ${(minorUnits / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

interface Props {
  batchId: string;
}

type BatchDetail = ReconciliationBatch & { unmatched_transaction_ids?: string[] | null };

export function ReconciliationBatchDetailPage({ batchId }: Props) {
  const { authenticatedClient } = useAuth();
  const router = useRouter();
  const headingId = useId();
  const [batch, setBatch] = useState<BatchDetail | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchBatch = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getReconciliationBatch(batchId);
      setBatch(res.data);
    } catch {
      setError('Failed to load batch details.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, batchId]);

  useEffect(() => { void fetchBatch(); }, [fetchBatch]);

  const matchedPct = batch
    ? batch.total_transactions > 0
      ? Math.round((batch.matched_count / batch.total_transactions) * 100)
      : 0
    : 0;

  return (
    <PageContainer>
      <PageHeader
        title="Reconciliation Batch"
        actions={
          <Button
            variant="secondary"
            onClick={() => router.push('/reconciliation/batches')}
            aria-label="Back to reconciliation batches"
          >
            ← Back
          </Button>
        }
      />

      {loading && <Loading label="Loading batch…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchBatch()} />}

      {!loading && !error && batch && (
        <>
          <Card>
            <dl
              id={headingId}
              className="grid grid-cols-2 gap-4 text-sm"
              aria-label="Batch details"
            >
              <div>
                <dt className="text-text-secondary">Batch ID</dt>
                <dd className="font-mono text-xs mt-1">{batch.batch_id}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Status</dt>
                <dd className="mt-1">
                  <Badge label={batch.status.replace(/_/g, ' ').toUpperCase()} variant={statusVariant(batch.status)} />
                </dd>
              </div>
              <div>
                <dt className="text-text-secondary">Period</dt>
                <dd className="mt-1">{batch.period_start} → {batch.period_end}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Currency</dt>
                <dd className="mt-1">{batch.currency_code}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Total Transactions</dt>
                <dd className="mt-1 font-semibold">{batch.total_transactions}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Match Rate</dt>
                <dd className="mt-1 font-semibold">{matchedPct}%</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Matched</dt>
                <dd className="mt-1 text-green-600 font-semibold">{batch.matched_count}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Unmatched</dt>
                <dd className="mt-1 text-amber-600 font-semibold">{batch.unmatched_count}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Discrepancy</dt>
                <dd className="mt-1 text-red-600 font-semibold">
                  {formatAmount(batch.discrepancy_amount_minor_units, batch.currency_code)}
                </dd>
              </div>
              <div>
                <dt className="text-text-secondary">Run At</dt>
                <dd className="mt-1">{batch.run_at ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Completed At</dt>
                <dd className="mt-1">{batch.completed_at ?? '—'}</dd>
              </div>
            </dl>
          </Card>

          {batch.unmatched_transaction_ids && batch.unmatched_transaction_ids.length > 0 && (
            <Card className="mt-4">
              <h2 className="text-sm font-semibold text-text-primary mb-3">
                Unmatched Transactions ({batch.unmatched_transaction_ids.length})
              </h2>
              <p className="text-xs text-text-secondary mb-2">
                Exception resolution (mark as resolved, escalate, write-off) requires a separate
                compliance workflow and is not available in this view.
              </p>
              <ul
                className="space-y-1 max-h-64 overflow-y-auto"
                aria-label="Unmatched transaction IDs"
              >
                {batch.unmatched_transaction_ids.map((txId) => (
                  <li key={txId} className="font-mono text-xs text-text-secondary">
                    {txId}
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </>
      )}
    </PageContainer>
  );
}
