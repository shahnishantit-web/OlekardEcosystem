'use client';

import { Dialog } from '@/components/ui/Dialog';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AdminUser, AdminWallet, AdminTransaction, AdminRole } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

interface Props {
  userId: string;
}

interface ActionDialog {
  open: boolean;
  action: 'suspend' | 'reactivate';
  reason: string;
}

function statusVariant(status: string): 'success' | 'error' | 'warning' | 'neutral' {
  if (status === 'active')    return 'success';
  if (status === 'suspended') return 'error';
  if (status === 'closed')    return 'neutral';
  return 'neutral';
}

function formatMinorUnits(amount: number, currency: string): string {
  return `${currency} ${(amount / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

export function UserDetailPage({ userId }: Props) {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [user, setUser]             = useState<AdminUser | null>(null);
  const [wallets, setWallets]       = useState<AdminWallet[]>([]);
  const [transactions, setTxns]     = useState<AdminTransaction[]>([]);
  const [loading, setLoading]       = useState(false);
  const [walletsLoading, setWalletsLoading] = useState(false);
  const [txnsLoading, setTxnsLoading]       = useState(false);
  const [error, setError]           = useState<string | null>(null);
  const [actionLoading, setActionLoading]   = useState(false);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [dialog, setDialog]         = useState<ActionDialog>({ open: false, action: 'suspend', reason: '' });
  const [roles, setRoles]           = useState<AdminRole[]>([]);
  const [rolesLoading, setRolesLoading] = useState(false);
  const [roleDialog, setRoleDialog] = useState<{ open: boolean; selectedRoleId: string }>({ open: false, selectedRoleId: '' });
  const [roleActionLoading, setRoleActionLoading] = useState(false);

  const fetchUser = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getUser(userId);
      setUser(res.data);
    } catch {
      setError('Failed to load user.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, userId]);

  const fetchWallets = useCallback(async () => {
    if (!authenticatedClient) return;
    setWalletsLoading(true);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listWallets({ user_id: userId, per_page: '10' });
      setWallets(res.data);
    } catch {
      // Non-fatal — wallet section shows empty gracefully
    } finally {
      setWalletsLoading(false);
    }
  }, [authenticatedClient, userId]);

  const fetchTransactions = useCallback(async () => {
    if (!authenticatedClient) return;
    setTxnsLoading(true);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listTransactions({ user_id: userId, per_page: '10' });
      setTxns(res.data);
    } catch {
      // Non-fatal
    } finally {
      setTxnsLoading(false);
    }
  }, [authenticatedClient, userId]);

  const fetchRoles = useCallback(async () => {
    if (!authenticatedClient) return;
    setRolesLoading(true);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listRoles({ per_page: '50' });
      setRoles(res.data);
    } catch {
      // Non-fatal
    } finally {
      setRolesLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void fetchUser();
    void fetchWallets();
    void fetchTransactions();
    void fetchRoles();
  }, [fetchUser, fetchWallets, fetchTransactions, fetchRoles]);

  const openDialog = (action: 'suspend' | 'reactivate') => {
    setSuccessMsg(null);
    setError(null);
    setDialog({ open: true, action, reason: '' });
  };

  const closeDialog = () => setDialog(d => ({ ...d, open: false, reason: '' }));

  const handleConfirm = async () => {
    if (!authenticatedClient || !dialog.reason.trim()) return;
    setActionLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      await client.updateUserStatus(userId, dialog.action, dialog.reason.trim());
      setSuccessMsg(
        dialog.action === 'suspend'
          ? 'Suspension submitted for checker review.'
          : 'Reactivation submitted for checker review.',
      );
      closeDialog();
      void fetchUser();
    } catch {
      setError(`Failed to submit ${dialog.action}.`);
    } finally {
      setActionLoading(false);
    }
  };

  const handleAssignRole = async () => {
    if (!authenticatedClient || !roleDialog.selectedRoleId) return;
    setRoleActionLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const idemKey = generateIdempotencyKey(`assign-role-${userId}-${roleDialog.selectedRoleId}`);
      await client.assignUserRole(userId, roleDialog.selectedRoleId, idemKey);
      setSuccessMsg('Role assigned successfully. Event ADMIN_ROLE_ASSIGNED fired.');
      setRoleDialog({ open: false, selectedRoleId: '' });
    } catch {
      setError('Failed to assign role.');
    } finally {
      setRoleActionLoading(false);
    }
  };

  return (
    <PageContainer>
      <PageHeader
        title="User Detail"
        actions={
          <Button variant="secondary" onClick={() => router.back()} aria-label="Back to user list">
            ← Back
          </Button>
        }
      />

      {loading && <Loading label="Loading user…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchUser()} />}

      {successMsg && (
        <div role="status" className="mb-4 rounded bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
          {successMsg}
        </div>
      )}

      {!loading && !error && user && (
        <div className="space-y-6">
          {/* Profile + actions */}
          <Card className="p-6">
            <div className="flex items-start justify-between gap-4 flex-wrap mb-6">
              <div className="flex items-center gap-3">
                <div
                  className="w-12 h-12 rounded-full bg-ok-surface flex items-center justify-center text-xl font-semibold text-text-secondary"
                  aria-hidden="true"
                >
                  {user.full_name?.[0] ?? user.email?.[0] ?? '?'}
                </div>
                <div>
                  <h2 className="text-base font-semibold text-text-primary">{user.full_name || user.email}</h2>
                  <p className="text-sm text-text-secondary">{user.email}</p>
                </div>
              </div>
              <Badge
                label={user.status.toUpperCase()}
                variant={statusVariant(user.status)}
                aria-label={`Account status: ${user.status}`}
              />
            </div>

            <dl className="grid grid-cols-2 gap-4">
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Phone</dt>
                <dd className="mt-1 text-sm text-text-primary">{user.phone_number || '—'}</dd>
              </div>
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">KYC Tier</dt>
                <dd className="mt-1 text-sm text-text-primary">Tier {user.kyc_tier}</dd>
              </div>
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Wallets</dt>
                <dd className="mt-1 text-sm text-text-primary">{user.wallet_count}</dd>
              </div>
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Joined</dt>
                <dd className="mt-1 text-sm text-text-primary">{new Date(user.created_at).toLocaleDateString()}</dd>
              </div>
            </dl>

            {/* Action buttons */}
            <div className="flex gap-3 mt-6">
              <span title={user.status === 'suspended' ? 'User is already suspended' : undefined}>
                <Button
                  variant="danger"
                  onClick={() => openDialog('suspend')}
                  disabled={actionLoading || user.status !== 'active'}
                  aria-label="Suspend this user"
                  aria-disabled={user.status !== 'active'}
                >
                  Suspend
                </Button>
              </span>
              {user.status === 'suspended' && (
                <Button
                  variant="primary"
                  onClick={() => openDialog('reactivate')}
                  disabled={actionLoading}
                  aria-label="Reactivate this user"
                >
                  Reactivate
                </Button>
              )}
            </div>
          </Card>

          {/* Wallet balances */}
          <section aria-label="Wallet balances">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-text-primary">Wallets</h3>
              <Link
                href={`/wallets?user_id=${userId}`}
                className="text-xs text-ok-primary hover:underline"
              >
                View all →
              </Link>
            </div>
            {walletsLoading && <Loading label="Loading wallets…" />}
            {!walletsLoading && wallets.length === 0 && (
              <p className="text-sm text-text-secondary">No wallets found.</p>
            )}
            {!walletsLoading && wallets.length > 0 && (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                {wallets.map(w => (
                  <Card key={w.id} as="div" className="p-4">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs font-semibold uppercase text-text-secondary">{w.currency}</span>
                      <Badge
                        label={w.status}
                        variant={w.status === 'active' ? 'success' : w.frozen_at ? 'warning' : 'neutral'}
                      />
                    </div>
                    <p className="text-xl font-bold tabular-nums text-text-primary">
                      {formatMinorUnits(w.balance_minor_units, w.currency)}
                    </p>
                  </Card>
                ))}
              </div>
            )}
          </section>

          {/* Recent transactions */}
          <section aria-label="Recent transactions">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-text-primary">Recent Transactions</h3>
              <Link
                href={`/transactions?user_id=${userId}`}
                className="text-xs text-ok-primary hover:underline"
              >
                View all →
              </Link>
            </div>
            {txnsLoading && <Loading label="Loading transactions…" />}
            {!txnsLoading && transactions.length === 0 && (
              <p className="text-sm text-text-secondary">No transactions found.</p>
            )}
            {!txnsLoading && transactions.length > 0 && (
              <div className="overflow-x-auto rounded border border-ok-border">
                <table className="min-w-full" aria-label="Recent transactions">
                  <thead className="bg-ok-surface">
                    <tr>
                      {['Type', 'Amount', 'Status', 'Date'].map(h => (
                        <th key={h} className="py-2 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {transactions.slice(0, 10).map(tx => (
                      <tr key={tx.id} className="border-b border-ok-border">
                        <td className="py-2 px-4 text-sm text-text-primary capitalize">{tx.type}</td>
                        <td className="py-2 px-4 text-sm tabular-nums text-text-primary">
                          {formatMinorUnits(tx.amount_minor_units, tx.currency)}
                        </td>
                        <td className="py-2 px-4">
                          <Badge
                            label={tx.status}
                            variant={
                              tx.status === 'completed' ? 'success'
                              : tx.status === 'failed'    ? 'error'
                              : 'warning'
                            }
                          />
                        </td>
                        <td className="py-2 px-4 text-sm text-text-secondary">
                          {new Date(tx.created_at).toLocaleDateString()}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          {/* KYC status */}
          <section aria-label="KYC status">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-text-primary">KYC</h3>
              <Link href={`/kyc?user_id=${userId}`} className="text-xs text-ok-primary hover:underline">
                View all →
              </Link>
            </div>
            <Card as="div" className="p-4">
              <dl className="flex gap-6">
                <div>
                  <dt className="text-xs font-semibold uppercase text-text-secondary">Tier</dt>
                  <dd className="mt-1 text-sm text-text-primary">Tier {user.kyc_tier}</dd>
                </div>
                <div>
                  <dt className="text-xs font-semibold uppercase text-text-secondary">Actor type</dt>
                  <dd className="mt-1 text-sm text-text-primary capitalize">{user.actor_type}</dd>
                </div>
              </dl>
            </Card>
          </section>

          {/* Role assignment */}
          <section aria-label="Role assignment">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-text-primary">Roles</h3>
              <Button
                variant="secondary"
                size="sm"
                onClick={() => setRoleDialog({ open: true, selectedRoleId: '' })}
                aria-label="Edit roles for this user"
              >
                Edit Roles
              </Button>
            </div>
            <Card as="div" className="p-4">
              {rolesLoading && <p className="text-sm text-text-secondary">Loading roles…</p>}
              {!rolesLoading && (
                <p className="text-sm text-text-secondary">
                  Use <strong>Edit Roles</strong> to assign a role to this user.
                </p>
              )}
            </Card>
          </section>

          {/* Devices + sessions links */}
          <section aria-label="Devices and sessions">
            <h3 className="text-sm font-semibold text-text-primary mb-3">Devices &amp; Sessions</h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Link
                href={`/devices?user_id=${userId}`}
                className="flex items-center justify-between rounded-lg border border-ok-border px-4 py-3 text-sm text-text-primary hover:bg-ok-hover-bg transition-colors"
                aria-label="View enrolled devices"
              >
                <span>Enrolled Devices</span>
                <span className="text-text-secondary" aria-hidden="true">→</span>
              </Link>
              <Link
                href={`/sessions?user_id=${userId}`}
                className="flex items-center justify-between rounded-lg border border-ok-border px-4 py-3 text-sm text-text-primary hover:bg-ok-hover-bg transition-colors"
                aria-label="View active sessions"
              >
                <span>Active Sessions</span>
                <span className="text-text-secondary" aria-hidden="true">→</span>
              </Link>
            </div>
          </section>
        </div>
      )}

      {/* Assign Role dialog */}
      {roleDialog.open && (
        <Dialog open showCloseButton={false} onClose={() => setRoleDialog({ open: false, selectedRoleId: '' })} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="assign-role-title">
            <h2 id="assign-role-title" className="text-lg font-semibold text-text-primary mb-4">
              Assign Role
            </h2>
            <p className="text-sm text-text-secondary mb-4">
              Select a role to assign to this user. This action fires <code>ADMIN_ROLE_ASSIGNED</code>.
            </p>
            <div className="mb-4">
              <label htmlFor="role-select" className="block text-sm font-medium text-text-secondary mb-1">
                Role <span aria-hidden="true">*</span>
              </label>
              <select
                id="role-select"
                value={roleDialog.selectedRoleId}
                onChange={e => setRoleDialog(d => ({ ...d, selectedRoleId: e.target.value }))}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              >
                <option value="">— Select a role —</option>
                {roles.map(r => (
                  <option key={r.role_id} value={r.role_id}>{r.role_name}</option>
                ))}
              </select>
            </div>
            <div className="flex justify-end gap-3">
              <Button
                variant="secondary"
                onClick={() => setRoleDialog({ open: false, selectedRoleId: '' })}
                disabled={roleActionLoading}
              >
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={() => void handleAssignRole()}
                disabled={!roleDialog.selectedRoleId || roleActionLoading}
                loading={roleActionLoading}
                aria-label="Confirm role assignment"
              >
                Assign Role
              </Button>
            </div>
          </Dialog>
      )}

      {/* Suspend / Reactivate dialog */}
      {dialog.open && (
        <Dialog open showCloseButton={false} onClose={closeDialog} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="confirm-dialog-title">
            <h2 id="confirm-dialog-title" className="text-lg font-semibold text-text-primary mb-4">
              {dialog.action === 'suspend' ? 'Suspend User' : 'Reactivate User'}
            </h2>
            <p className="text-sm text-text-secondary mb-4">
              {dialog.action === 'suspend'
                ? "This action will suspend the user's account and submit for checker approval."
                : "This action will reactivate the user's account and submit for checker approval."}
            </p>
            <div className="mb-4">
              <label htmlFor="action-reason" className="block text-sm font-medium text-text-secondary mb-1">
                Reason <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="action-reason"
                value={dialog.reason}
                onChange={e => setDialog(d => ({ ...d, reason: e.target.value }))}
                rows={3}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="Enter reason…"
              />
            </div>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={closeDialog} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                variant={dialog.action === 'suspend' ? 'danger' : 'primary'}
                onClick={() => void handleConfirm()}
                disabled={!dialog.reason.trim() || actionLoading}
                loading={actionLoading}
                aria-label={dialog.action === 'suspend' ? 'Confirm suspension' : 'Confirm reactivation'}
              >
                {dialog.action === 'suspend' ? 'Suspend' : 'Reactivate'}
              </Button>
            </div>
          </Dialog>
      )}
    </PageContainer>
  );
}
