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
import type { AdminWallet, WalletLedgerEntry, PaginatedResponse, PendingAction } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

// ---------------------------------------------------------------------------
// Status badge
// ---------------------------------------------------------------------------
function WalletStatusBadge({ wallet }: { wallet: AdminWallet }) {
  if (wallet.frozen_at !== null) return <Badge label="FROZEN" variant="error" />;
  const variant = wallet.status === 'active' ? 'success' : wallet.status === 'closed' ? 'neutral' : 'warning';
  return <Badge label={wallet.status.toUpperCase()} variant={variant} />;
}

// ---------------------------------------------------------------------------
// Freeze / Unfreeze dialog
// ---------------------------------------------------------------------------
interface FreezeDialog {
  open: boolean;
  action: 'freeze' | 'unfreeze';
  reason: string;
}

// ---------------------------------------------------------------------------
// Adjustment dialog
// ---------------------------------------------------------------------------
interface AdjustDialog {
  open: boolean;
  direction: 'credit' | 'debit';
  amount: string;
  reason: string;
  reference: string;
}

// ---------------------------------------------------------------------------
// Ledger entry row
// ---------------------------------------------------------------------------
function LedgerRow({ entry }: { entry: WalletLedgerEntry }) {
  const amountStr = (entry.amount_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 });
  const runningStr = entry.running_balance_minor_units != null
    ? (entry.running_balance_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })
    : '—';

  return (
    <tr className="border-b border-ok-border hover:bg-ok-hover-bg transition-colors">
      <td className="py-2 px-4 text-xs text-text-secondary">
        {new Date(entry.posted_at).toLocaleString()}
      </td>
      <td className="py-2 px-4 text-sm capitalize text-text-secondary">
        {entry.type.replace(/_/g, ' ')}
      </td>
      <td className="py-2 px-4 text-sm text-text-secondary font-mono">
        {entry.reference ?? '—'}
      </td>
      <td className={`py-2 px-4 text-sm tabular-nums font-medium ${entry.direction === 'credit' ? 'text-green-700' : 'text-red-700'}`}>
        {entry.direction === 'credit' ? '+' : '−'}{amountStr}
      </td>
      <td className="py-2 px-4 text-sm tabular-nums text-text-primary">{runningStr}</td>
    </tr>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------
export function WalletDetailPage({ walletId }: { walletId: string }) {
  const { authenticatedClient } = useAuth();

  const [wallet, setWallet]       = useState<AdminWallet | null>(null);
  const [walletLoading, setWalletLoading] = useState(false);
  const [walletError, setWalletError]     = useState<string | null>(null);

  const [ledger, setLedger]       = useState<PaginatedResponse<WalletLedgerEntry> | null>(null);
  const [ledgerLoading, setLedgerLoading] = useState(false);
  const [ledgerPage, setLedgerPage]       = useState(1);

  const [actionLoading, setActionLoading] = useState(false);
  const [successMsg, setSuccessMsg]       = useState<string | null>(null);
  const [actionError, setActionError]     = useState<string | null>(null);

  const [freezeDialog, setFreezeDialog] = useState<FreezeDialog>({
    open: false, action: 'freeze', reason: '',
  });
  const [adjustDialog, setAdjustDialog] = useState<AdjustDialog>({
    open: false, direction: 'credit', amount: '', reason: '', reference: '',
  });

  // --- Fetch wallet ---
  const fetchWallet = useCallback(async () => {
    if (!authenticatedClient) return;
    setWalletLoading(true);
    setWalletError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getWallet(walletId);
      setWallet(res.data as unknown as AdminWallet);
    } catch {
      setWalletError('Failed to load wallet details. Please try again.');
    } finally {
      setWalletLoading(false);
    }
  }, [authenticatedClient, walletId]);

  // --- Fetch ledger ---
  const fetchLedger = useCallback(async () => {
    if (!authenticatedClient) return;
    setLedgerLoading(true);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getWalletLedger(walletId, {
        page: String(ledgerPage),
        per_page: '20',
      });
      setLedger(res.data as unknown as PaginatedResponse<WalletLedgerEntry>);
    } catch {
      // Ledger section shows empty gracefully if endpoint is unavailable
      setLedger(null);
    } finally {
      setLedgerLoading(false);
    }
  }, [authenticatedClient, walletId, ledgerPage]);

  useEffect(() => { void fetchWallet(); }, [fetchWallet]);
  useEffect(() => { void fetchLedger(); }, [fetchLedger]);

  // --- Freeze / Unfreeze ---
  const openFreezeDialog = (action: 'freeze' | 'unfreeze') => {
    setSuccessMsg(null);
    setActionError(null);
    setFreezeDialog({ open: true, action, reason: '' });
  };

  const closeFreezeDialog = () => setFreezeDialog(d => ({ ...d, open: false, reason: '' }));

  const handleFreezeConfirm = async () => {
    if (!authenticatedClient || !freezeDialog.reason.trim()) return;
    setActionLoading(true);
    setActionError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const idemKey = generateIdempotencyKey(`wallet-${freezeDialog.action}-${walletId}`);
      let result: PendingAction;
      if (freezeDialog.action === 'freeze') {
        const res = await client.freezeWallet(walletId, freezeDialog.reason.trim(), idemKey);
        result = res.data as unknown as PendingAction;
      } else {
        const res = await client.unfreezeWallet(walletId, freezeDialog.reason.trim(), idemKey);
        result = res.data as unknown as PendingAction;
      }
      setSuccessMsg(
        `${freezeDialog.action === 'freeze' ? 'Freeze' : 'Unfreeze'} submitted for checker review. ` +
        `Approval ID: ${result.approval_id}`,
      );
      closeFreezeDialog();
      void fetchWallet();
    } catch {
      setActionError(`Failed to submit ${freezeDialog.action}. Please try again.`);
    } finally {
      setActionLoading(false);
    }
  };

  // --- Adjustment ---
  const openAdjustDialog = () => {
    setSuccessMsg(null);
    setActionError(null);
    setAdjustDialog({ open: true, direction: 'credit', amount: '', reason: '', reference: '' });
  };

  const closeAdjustDialog = () => setAdjustDialog(d => ({ ...d, open: false }));

  const adjustAmountValid = (): boolean => {
    const n = parseFloat(adjustDialog.amount);
    return !isNaN(n) && n > 0;
  };

  const handleAdjustConfirm = async () => {
    if (!authenticatedClient || !adjustAmountValid() || !adjustDialog.reason.trim() || !adjustDialog.reference.trim()) return;
    setActionLoading(true);
    setActionError(null);
    try {
      const client  = new AdminAppClient(authenticatedClient);
      const idemKey = generateIdempotencyKey(`wallet-adjustment-${walletId}-${adjustDialog.reference}`);
      const currency = wallet?.currency_code ?? wallet?.currency ?? 'BWP';
      const res = await client.adjustWallet(
        walletId,
        {
          direction: adjustDialog.direction,
          amount_minor_units: Math.round(parseFloat(adjustDialog.amount) * 100),
          currency,
          reason: adjustDialog.reason.trim(),
          reference: adjustDialog.reference.trim(),
        },
        idemKey,
      );
      const result = res.data as unknown as PendingAction;
      setSuccessMsg(
        `Balance adjustment submitted for checker review. Approval ID: ${result.approval_id}`,
      );
      closeAdjustDialog();
      void fetchWallet();
      void fetchLedger();
    } catch {
      setActionError('Failed to submit adjustment. Please try again.');
    } finally {
      setActionLoading(false);
    }
  };

  if (walletLoading) return <PageContainer><Loading label="Loading wallet…" /></PageContainer>;
  if (walletError)   return <PageContainer><ErrorState message={walletError} onRetry={() => void fetchWallet()} /></PageContainer>;
  if (!wallet)       return null;

  const isFrozen  = wallet.frozen_at !== null;
  const canFreeze = !isFrozen && wallet.status === 'active';
  const canAdjust = wallet.status !== 'closed';
  const ownerUserId = wallet.owner_user_id ?? wallet.user_id;
  const ownerName   = wallet.owner_name ?? ownerUserId;
  const currency    = wallet.currency_code ?? wallet.currency;
  const balance     = (wallet.balance_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 });
  const available   = wallet.available_balance_minor_units != null
    ? (wallet.available_balance_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })
    : null;

  return (
    <PageContainer>
      <PageHeader
        title={wallet.wallet_number ?? `Wallet ${wallet.id.substring(0, 8)}…`}
        breadcrumbs={[
          { label: 'Wallets', href: '/wallets' },
          { label: wallet.wallet_number ?? wallet.id.substring(0, 8) },
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
          <div className="grid grid-cols-2 gap-x-12 gap-y-3">
            <DetailField label="Wallet ID"     value={wallet.wallet_number ?? wallet.id} mono />
            <DetailField label="Status"        value={<WalletStatusBadge wallet={wallet} />} />
            <DetailField label="Owner"         value={
              <Link href={`/users/${ownerUserId}`} className="text-ok-primary underline hover:text-ok-primary-hover">
                {ownerName}
              </Link>
            } />
            <DetailField label="Currency"      value={currency} mono />
            <DetailField label="Balance"       value={`${currency} ${balance}`} />
            {available && <DetailField label="Available" value={`${currency} ${available}`} />}
            {wallet.spending_limit_minor_units != null && (
              <DetailField
                label="Spending Limit"
                value={`${currency} ${(wallet.spending_limit_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`}
              />
            )}
            {wallet.frozen_at && (
              <DetailField label="Frozen At" value={new Date(wallet.frozen_at).toLocaleString()} />
            )}
            {wallet.freeze_reason && (
              <DetailField label="Freeze Reason" value={wallet.freeze_reason} />
            )}
            {wallet.created_at && (
              <DetailField label="Created" value={new Date(wallet.created_at).toLocaleDateString()} />
            )}
          </div>

          {/* Action buttons */}
          <div className="flex gap-3 flex-wrap">
            {canFreeze && (
              <Button variant="danger" onClick={() => openFreezeDialog('freeze')}>
                Freeze Wallet
              </Button>
            )}
            {isFrozen && (
              <Button variant="primary" onClick={() => openFreezeDialog('unfreeze')}>
                Unfreeze Wallet
              </Button>
            )}
            {canAdjust && (
              <Button variant="secondary" onClick={openAdjustDialog}>
                Balance Adjustment
              </Button>
            )}
          </div>
        </div>
      </Card>

      {/* Ledger section */}
      <section aria-label="Ledger entries">
        <h2 className="text-base font-semibold text-text-primary mb-3">Ledger Entries</h2>

        {ledgerLoading && <Loading label="Loading ledger…" />}

        {!ledgerLoading && ledger && ledger.data.length > 0 && (
          <>
            <div className="overflow-x-auto rounded border border-ok-border">
              <table className="min-w-full" aria-label="Wallet ledger entries">
                <thead className="bg-ok-surface">
                  <tr>
                    {['Date', 'Type', 'Reference', 'Amount', 'Running Balance'].map(h => (
                      <th key={h} className="py-2 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {ledger.data.map(entry => (
                    <LedgerRow key={entry.entry_id} entry={entry} />
                  ))}
                </tbody>
              </table>
            </div>

            <div className="mt-3 flex items-center justify-between text-sm text-text-secondary">
              <span>
                Page {ledger.meta.current_page} of {ledger.meta.last_page} · {ledger.meta.total} entries
              </span>
              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  onClick={() => setLedgerPage(p => Math.max(1, p - 1))}
                  disabled={ledgerPage <= 1}
                  aria-label="Previous ledger page"
                >
                  Previous
                </Button>
                <Button
                  variant="secondary"
                  onClick={() => setLedgerPage(p => p + 1)}
                  disabled={ledgerPage >= ledger.meta.last_page}
                  aria-label="Next ledger page"
                >
                  Next
                </Button>
              </div>
            </div>
          </>
        )}

        {!ledgerLoading && (!ledger || ledger.data.length === 0) && (
          <p className="text-sm text-text-secondary py-4">No ledger entries found for this wallet.</p>
        )}
      </section>

      {/* Freeze / Unfreeze dialog */}
      {freezeDialog.open && (
        <Dialog open showCloseButton={false} onClose={closeFreezeDialog} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="freeze-dialog-title">
            <h2 id="freeze-dialog-title" className="text-lg font-semibold text-text-primary mb-4">
              {freezeDialog.action === 'freeze' ? 'Freeze Wallet' : 'Unfreeze Wallet'}
            </h2>
            <p className="text-sm text-text-secondary mb-4">
              {freezeDialog.action === 'freeze'
                ? 'Freezing prevents all transactions on this wallet. The action requires a second approver.'
                : 'Unfreezing restores normal wallet operations. The action requires a second approver.'}
            </p>
            <div className="mb-4">
              <label htmlFor="freeze-reason" className="block text-sm font-medium text-text-secondary mb-1">
                Reason <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="freeze-reason"
                value={freezeDialog.reason}
                onChange={e => setFreezeDialog(d => ({ ...d, reason: e.target.value }))}
                rows={3}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="Enter reason…"
              />
            </div>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={closeFreezeDialog} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                variant={freezeDialog.action === 'freeze' ? 'danger' : 'primary'}
                onClick={() => void handleFreezeConfirm()}
                disabled={!freezeDialog.reason.trim() || actionLoading}
                loading={actionLoading}
                aria-label={freezeDialog.action === 'freeze' ? 'Confirm freeze' : 'Confirm unfreeze'}
              >
                {freezeDialog.action === 'freeze' ? 'Freeze' : 'Unfreeze'}
              </Button>
            </div>
          </Dialog>
      )}

      {/* Adjustment dialog */}
      {adjustDialog.open && (
        <Dialog open showCloseButton={false} onClose={closeAdjustDialog} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 p-6" labelledBy="adjust-dialog-title">
            <h2 id="adjust-dialog-title" className="text-lg font-semibold text-text-primary mb-1">
              Balance Adjustment
            </h2>
            <p className="text-sm text-text-secondary mb-4">
              Adjustments are posted through the double-entry ledger and require a second approver before taking effect.
              Direct balance mutation is not permitted.
            </p>

            <div className="grid grid-cols-2 gap-4 mb-4">
              <div>
                <label htmlFor="adjust-direction" className="block text-sm font-medium text-text-secondary mb-1">
                  Direction <span aria-hidden="true">*</span>
                </label>
                <select
                  id="adjust-direction"
                  value={adjustDialog.direction}
                  onChange={e => setAdjustDialog(d => ({ ...d, direction: e.target.value as 'credit' | 'debit' }))}
                  className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                >
                  <option value="credit">Credit (add funds)</option>
                  <option value="debit">Debit (remove funds)</option>
                </select>
              </div>

              <div>
                <label htmlFor="adjust-amount" className="block text-sm font-medium text-text-secondary mb-1">
                  Amount ({currency}) <span aria-hidden="true">*</span>
                </label>
                <input
                  id="adjust-amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={adjustDialog.amount}
                  onChange={e => setAdjustDialog(d => ({ ...d, amount: e.target.value }))}
                  className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                  placeholder="0.00"
                />
              </div>
            </div>

            <div className="mb-4">
              <label htmlFor="adjust-reference" className="block text-sm font-medium text-text-secondary mb-1">
                Reference <span aria-hidden="true">*</span>
              </label>
              <input
                id="adjust-reference"
                type="text"
                value={adjustDialog.reference}
                onChange={e => setAdjustDialog(d => ({ ...d, reference: e.target.value }))}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="e.g. ADJ-2026-001"
              />
            </div>

            <div className="mb-4">
              <label htmlFor="adjust-reason" className="block text-sm font-medium text-text-secondary mb-1">
                Reason <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="adjust-reason"
                value={adjustDialog.reason}
                onChange={e => setAdjustDialog(d => ({ ...d, reason: e.target.value }))}
                rows={3}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="Describe why this adjustment is needed…"
              />
            </div>

            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={closeAdjustDialog} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={() => void handleAdjustConfirm()}
                disabled={!adjustAmountValid() || !adjustDialog.reason.trim() || !adjustDialog.reference.trim() || actionLoading}
                loading={actionLoading}
              >
                Submit for Approval
              </Button>
            </div>
          </Dialog>
      )}
    </PageContainer>
  );
}

// ---------------------------------------------------------------------------
// Small helper component
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
      <dd className={`mt-0.5 text-sm text-text-primary ${mono ? 'font-mono' : ''}`}>{value}</dd>
    </div>
  );
}
