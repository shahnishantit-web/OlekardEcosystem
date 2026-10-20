'use client';

import { Dialog } from '@/components/ui/Dialog';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AdminTransactionDetail, TransactionLedgerEntry, PendingAction } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

// ---------------------------------------------------------------------------
// Status badge
// ---------------------------------------------------------------------------
function TxStatusBadge({ status }: { status: string }) {
  const variant =
    status === 'completed' ? 'success' :
    status === 'failed'    ? 'error'   :
    status === 'reversed'  ? 'warning' : 'neutral';
  return <Badge label={status.toUpperCase()} variant={variant} />;
}

// ---------------------------------------------------------------------------
// Ledger entry row
// ---------------------------------------------------------------------------
function LedgerRow({ entry }: { entry: TransactionLedgerEntry }) {
  const amountStr = (entry.amount_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 });
  return (
    <tr className="border-b border-ok-border hover:bg-ok-hover-bg transition-colors">
      <td className="py-2 px-4 text-xs text-text-secondary">{new Date(entry.posted_at).toLocaleString()}</td>
      <td className="py-2 px-4 text-sm font-mono text-text-secondary">{entry.ledger_account}</td>
      <td className={`py-2 px-4 text-sm tabular-nums font-medium ${entry.direction === 'credit' ? 'text-green-700' : 'text-red-700'}`}>
        {entry.direction === 'credit' ? '+' : '−'}{amountStr}
      </td>
      <td className="py-2 px-4 text-sm capitalize text-text-secondary">
        <Badge label={entry.direction.toUpperCase()} variant={entry.direction === 'credit' ? 'success' : 'error'} />
      </td>
    </tr>
  );
}

// ---------------------------------------------------------------------------
// Reversal dialog
// ---------------------------------------------------------------------------
interface ReversalDialog {
  open: boolean;
  reason: string;
}

// ---------------------------------------------------------------------------
// Detail field helper
// ---------------------------------------------------------------------------
function DetailField({
  label,
  value,
  mono = false,
}: {
  label: string;
  value: React.ReactNode;
  mono?: boolean | undefined;
}) {
  return (
    <div>
      <dt className="text-xs font-medium text-text-secondary uppercase tracking-wide">{label}</dt>
      <dd className={`mt-0.5 text-sm text-text-primary ${mono ? 'font-mono' : ''}`}>{value ?? '—'}</dd>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------
export function TransactionDetailPage({ transactionId }: { transactionId: string }) {
  const { authenticatedClient } = useAuth();

  const [tx, setTx]                 = useState<AdminTransactionDetail | null>(null);
  const [txLoading, setTxLoading]   = useState(false);
  const [txError, setTxError]       = useState<string | null>(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [dialog, setDialog]         = useState<ReversalDialog>({ open: false, reason: '' });

  const fetchTx = useCallback(async () => {
    if (!authenticatedClient) return;
    setTxLoading(true);
    setTxError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getTransaction(transactionId);
      setTx(res.data as unknown as AdminTransactionDetail);
    } catch {
      setTxError('Failed to load transaction. Please try again.');
    } finally {
      setTxLoading(false);
    }
  }, [authenticatedClient, transactionId]);

  useEffect(() => { void fetchTx(); }, [fetchTx]);

  const openReversal = () => {
    setSuccessMsg(null);
    setActionError(null);
    setDialog({ open: true, reason: '' });
  };

  const closeDialog = () => setDialog(d => ({ ...d, open: false, reason: '' }));

  const handleConfirmReversal = async () => {
    if (!authenticatedClient || !dialog.reason.trim()) return;
    setActionLoading(true);
    setActionError(null);
    try {
      const client  = new AdminAppClient(authenticatedClient);
      const txId    = tx?.transaction_id ?? transactionId;
      const idemKey = generateIdempotencyKey(`tx-reverse-${txId}`);
      const res = await client.reverseTransaction(txId, dialog.reason.trim(), idemKey);
      const result = res.data as unknown as PendingAction;
      setSuccessMsg(`Reversal submitted for checker review. Approval ID: ${result.approval_id}`);
      closeDialog();
      void fetchTx();
    } catch (err) {
      const code = (err as { code?: string })?.code;
      if (code === 'ADMINAPP_015' || code === 'ADMINAPP_TXN_002') {
        setActionError('This transaction type is not eligible for reversal.');
      } else if (code === 'ADMINAPP_TXN_001') {
        setActionError('Transaction not found.');
      } else {
        setActionError('Failed to submit reversal. Please try again.');
      }
    } finally {
      setActionLoading(false);
    }
  };

  if (txLoading) return <PageContainer><Loading label="Loading transaction…" /></PageContainer>;
  if (txError)   return <PageContainer><ErrorState message={txError} onRetry={() => void fetchTx()} /></PageContainer>;
  if (!tx)       return null;

  const txId         = tx.transaction_id ?? tx.id;
  const ref          = tx.reference_number ?? txId;
  const currency     = tx.currency_code ?? tx.currency;
  const amountStr    = (tx.amount_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 });
  const initiatorId  = tx.initiator_user_id ?? null;
  const initiatorName = tx.initiator_name ?? '—';
  const canReverse   = tx.reversal_eligible && tx.status !== 'reversed' && tx.status !== 'failed';

  return (
    <PageContainer>
      <PageHeader
        title={ref}
        breadcrumbs={[
          { label: 'Transactions', href: '/admin-portal/transactions' },
          { label: ref },
        ]}
      />

      {successMsg && (
        <div role="status" className="mb-4 rounded bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
          {successMsg}
        </div>
      )}
      {actionError && (
        <div role="alert" className="mb-4 rounded bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
          {actionError}
        </div>
      )}

      {/* Summary card */}
      <Card className="mb-6 p-6">
        <div className="flex flex-wrap gap-6 items-start justify-between">
          <dl className="grid grid-cols-2 gap-x-12 gap-y-3">
            <DetailField label="Reference"    value={ref} mono />
            <DetailField label="Status"       value={<TxStatusBadge status={tx.status} />} />
            <DetailField label="Type"         value={tx.type.replace(/_/g, ' ')} />
            <DetailField label="Currency"     value={currency} mono />
            <DetailField label="Amount"       value={`${currency} ${amountStr}`} />
            {tx.fee_minor_units != null && (
              <DetailField label="Fee" value={`${currency} ${(tx.fee_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`} />
            )}
            <DetailField
              label="Initiator"
              value={
                initiatorId ? (
                  <Link href={`/admin-portal/users/${initiatorId}`} className="text-ok-primary underline hover:text-ok-primary-hover">
                    {initiatorName}
                  </Link>
                ) : (
                  <span>{initiatorName}</span>
                )
              }
            />
            {tx.source_wallet_id && (
              <DetailField
                label="Source Wallet"
                value={
                  <Link href={`/admin-portal/wallets/${tx.source_wallet_id}`} className="text-ok-primary underline hover:text-ok-primary-hover font-mono">
                    {tx.source_wallet_id.substring(0, 8)}…
                  </Link>
                }
              />
            )}
            {tx.destination_wallet_id && (
              <DetailField
                label="Destination Wallet"
                value={
                  <Link href={`/admin-portal/wallets/${tx.destination_wallet_id}`} className="text-ok-primary underline hover:text-ok-primary-hover font-mono">
                    {tx.destination_wallet_id.substring(0, 8)}…
                  </Link>
                }
              />
            )}
            {tx.reversal_id && (
              <DetailField
                label="Reversal Tx"
                value={
                  <Link href={`/admin-portal/transactions/${tx.reversal_id}`} className="text-ok-primary underline hover:text-ok-primary-hover font-mono">
                    {tx.reversal_id.substring(0, 8)}…
                  </Link>
                }
              />
            )}
            {tx.correlation_id && <DetailField label="Correlation ID" value={tx.correlation_id} mono />}
            <DetailField label="Created" value={new Date(tx.created_at).toLocaleString()} />
            {tx.completed_at && <DetailField label="Completed" value={new Date(tx.completed_at).toLocaleString()} />}
            {tx.failed_at && <DetailField label="Failed At" value={new Date(tx.failed_at).toLocaleString()} />}
          </dl>

          {canReverse && (
            <Button variant="danger" onClick={openReversal}>
              Reverse Transaction
            </Button>
          )}
          {!canReverse && tx.status === 'reversed' && (
            <Badge label="REVERSED" variant="warning" />
          )}
        </div>
      </Card>

      {/* Failed transaction info */}
      {tx.status === 'failed' && (tx.failure_reason ?? tx.retry_count != null) && (
        <Card className="mb-6 p-4 border-red-200 bg-red-50">
          <h2 className="text-sm font-semibold text-red-800 mb-2">Failure Details</h2>
          <dl className="grid grid-cols-2 gap-3">
            {tx.failure_reason && (
              <DetailField label="Failure Reason" value={tx.failure_reason} />
            )}
            {tx.retry_count != null && (
              <DetailField label="Retry Count" value={String(tx.retry_count)} />
            )}
          </dl>
        </Card>
      )}

      {/* Fraud assessment */}
      {tx.fraud_assessment && (
        <Card className="mb-6 p-4">
          <h2 className="text-base font-semibold text-text-primary mb-3">Fraud Assessment</h2>
          <dl className="grid grid-cols-3 gap-3">
            <DetailField label="Risk Score" value={String(tx.fraud_assessment.risk_score)} />
            <DetailField
              label="Flags"
              value={tx.fraud_assessment.flags.length > 0 ? tx.fraud_assessment.flags.join(', ') : 'None'}
            />
            <DetailField label="Assessed At" value={new Date(tx.fraud_assessment.assessed_at).toLocaleString()} />
          </dl>
        </Card>
      )}

      {/* Ledger entries */}
      <section aria-label="Ledger entries">
        <h2 className="text-base font-semibold text-text-primary mb-3">Ledger Entries</h2>
        {tx.ledger_entries && tx.ledger_entries.length > 0 ? (
          <div className="overflow-x-auto rounded border border-ok-border">
            <table className="min-w-full" aria-label="Transaction ledger entries">
              <thead className="bg-ok-surface">
                <tr>
                  {['Date', 'Account', 'Amount', 'Direction'].map(h => (
                    <th key={h} className="py-2 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {tx.ledger_entries.map(entry => (
                  <LedgerRow key={entry.entry_id} entry={entry} />
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-text-secondary py-4">No ledger entries for this transaction.</p>
        )}
      </section>

      {/* Provider response */}
      {tx.provider_response && Object.keys(tx.provider_response).length > 0 && (
        <Card className="mt-6 p-4">
          <h2 className="text-base font-semibold text-text-primary mb-3">Provider Response</h2>
          <pre className="text-xs text-text-secondary bg-ok-surface p-3 rounded overflow-x-auto">
            {JSON.stringify(tx.provider_response, null, 2)}
          </pre>
        </Card>
      )}

      {/* Reversal dialog */}
      {dialog.open && (
        <Dialog open showCloseButton={false} onClose={closeDialog} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="reversal-dialog-title">
            <h2 id="reversal-dialog-title" className="text-lg font-semibold text-text-primary mb-4">
              Reverse Transaction
            </h2>
            <p className="text-sm text-text-secondary mb-4">
              A reversal posts compensating double-entry ledger entries and requires a second approver.
              This action cannot be undone once approved.
            </p>
            <div className="mb-4">
              <label htmlFor="reversal-reason" className="block text-sm font-medium text-text-secondary mb-1">
                Reason <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="reversal-reason"
                value={dialog.reason}
                onChange={e => setDialog(d => ({ ...d, reason: e.target.value }))}
                rows={3}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="Enter reason for reversal…"
              />
            </div>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={closeDialog} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                variant="danger"
                onClick={() => void handleConfirmReversal()}
                disabled={!dialog.reason.trim() || actionLoading}
                loading={actionLoading}
                aria-label="Confirm transaction reversal"
              >
                Reverse
              </Button>
            </div>
          </Dialog>
      )}
    </PageContainer>
  );
}
