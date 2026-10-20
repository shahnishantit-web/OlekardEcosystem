'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { AdminAppClient, type SettlementRecord } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'error' | 'info' {
  switch (status) {
    case 'executed':         return 'success';
    case 'approved':         return 'info';
    case 'pending_approval': return 'warning';
    case 'rejected':
    case 'failed':           return 'error';
    default:                 return 'neutral';
  }
}

function formatAmount(minorUnits: number, currency: string): string {
  return `${currency} ${(minorUnits / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

interface Props {
  settlementId: string;
}

export function SettlementDetailPage({ settlementId }: Props) {
  const { authenticatedClient } = useAuth();
  const router = useRouter();
  const dialogLabelId = useId();

  const [settlement, setSettlement] = useState<SettlementRecord | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  const [approveDialog, setApproveDialog] = useState(false);
  const [approveLoading, setApproveLoading] = useState(false);

  const fetchSettlement = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getSettlement(settlementId);
      setSettlement(res.data);
    } catch {
      setError('Failed to load settlement details.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, settlementId]);

  useEffect(() => { void fetchSettlement(); }, [fetchSettlement]);

  const handleApprove = async () => {
    if (!authenticatedClient) return;
    setApproveLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const idemKey = generateIdempotencyKey(`approve-settlement-${settlementId}`);
      const res = await client.approveSettlement(settlementId, idemKey);
      setSettlement(res.data);
      setApproveDialog(false);
      setSuccessMsg('Settlement approved successfully. Event SETTLEMENT_APPROVED recorded.');
    } catch {
      setError('Failed to approve settlement. Ensure you are not the maker of this settlement (maker-checker policy).');
      setApproveDialog(false);
    } finally {
      setApproveLoading(false);
    }
  };

  const canApprove = settlement?.status === 'pending_approval';

  return (
    <PageContainer>
      <PageHeader
        title="Settlement Detail"
        actions={
          <Button
            variant="secondary"
            onClick={() => router.push('/reconciliation/settlements')}
            aria-label="Back to settlement history"
          >
            ← Back
          </Button>
        }
      />

      {loading && <Loading label="Loading settlement…" />}
      {!loading && error && (
        <div role="alert" className="mb-4 p-3 bg-error-subtle text-error text-sm rounded-md">
          {error}
        </div>
      )}
      {!loading && successMsg && (
        <div role="status" className="mb-4 p-3 bg-success-subtle text-success text-sm rounded-md">
          {successMsg}
        </div>
      )}
      {!loading && error && !settlement && (
        <ErrorState message={error} onRetry={() => void fetchSettlement()} />
      )}

      {!loading && settlement && (
        <Card>
          <div className="flex items-start justify-between mb-4">
            <dl className="grid grid-cols-2 gap-4 text-sm flex-1" aria-label="Settlement details">
              <div>
                <dt className="text-text-secondary">Settlement ID</dt>
                <dd className="font-mono text-xs mt-1">{settlement.settlement_id}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Status</dt>
                <dd className="mt-1">
                  <Badge
                    label={settlement.status.replace(/_/g, ' ').toUpperCase()}
                    variant={statusVariant(settlement.status)}
                  />
                </dd>
              </div>
              <div>
                <dt className="text-text-secondary">Settlement Date</dt>
                <dd className="mt-1">{settlement.settlement_date}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Currency</dt>
                <dd className="mt-1">{settlement.currency_code}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Total Amount</dt>
                <dd className="mt-1 font-semibold">
                  {formatAmount(settlement.total_amount_minor_units, settlement.currency_code)}
                </dd>
              </div>
              <div>
                <dt className="text-text-secondary">Net Position</dt>
                <dd className="mt-1 font-semibold">
                  {formatAmount(settlement.net_position_minor_units, settlement.currency_code)}
                </dd>
              </div>
              <div>
                <dt className="text-text-secondary">Provider</dt>
                <dd className="mt-1">{settlement.provider ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Reference</dt>
                <dd className="mt-1 font-mono text-xs">{settlement.reference ?? '—'}</dd>
              </div>
            </dl>

            {canApprove && (
              <Button
                variant="primary"
                onClick={() => setApproveDialog(true)}
                aria-label="Approve settlement"
                className="ml-4 shrink-0"
              >
                Approve Settlement
              </Button>
            )}
          </div>
        </Card>
      )}

      {/* Approve confirmation dialog */}
      {approveDialog && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby={dialogLabelId}
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
          <div className="bg-surface-primary rounded-xl shadow-xl p-6 w-full max-w-sm">
            <h2 id={dialogLabelId} className="text-base font-semibold text-text-primary mb-3">
              Approve Settlement
            </h2>
            <p className="text-sm text-text-secondary mb-2">
              Confirm you want to approve settlement{' '}
              <span className="font-mono text-xs">{settlementId}</span>.
            </p>
            <p className="text-xs text-text-tertiary mb-5">
              Maker-checker policy: you must not be the same user who created this settlement.
            </p>
            <div className="flex gap-3 justify-end">
              <Button
                variant="secondary"
                onClick={() => setApproveDialog(false)}
                disabled={approveLoading}
              >
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={() => void handleApprove()}
                disabled={approveLoading}
                aria-label="Confirm settlement approval"
              >
                {approveLoading ? 'Approving…' : 'Confirm Approve'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </PageContainer>
  );
}
