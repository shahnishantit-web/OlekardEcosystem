'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Table } from '@/components/ui/Table';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import { getAdminAccountStatus } from '@/src/modules/profile/api/profile';
import type { AdminAccountStatusData, AdminStatusHistoryEntry } from '@/src/modules/profile/api/profile';
import { ReactivateDialog } from '@/components/profile/ReactivateDialog';
import { CloseAccountDialog } from '@/components/profile/CloseAccountDialog';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  active: 'success',
  suspended: 'error',
  pending_closure: 'warning',
  closed: 'neutral',
};

interface AccountStatusPageProps {
  userId: string;
  canReactivate?: boolean;
  canClose?: boolean;
}

export function AccountStatusPage({
  userId,
  canReactivate = true,
  canClose = true,
}: AccountStatusPageProps) {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const headingId = useId();

  const [statusData, setStatusData] = useState<AdminAccountStatusData | null>(null);
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const [reactivateOpen, setReactivateOpen] = useState(false);
  const [cancelClosureOpen, setCancelClosureOpen] = useState(false);
  const [closeOpen, setCloseOpen] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  const fetchStatus = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setFetchError(null);
    try {
      const data = await getAdminAccountStatus(authenticatedClient, userId);
      setStatusData(data);
    } catch (err) {
      if (isApiError(err) && (err as ApiError).error.code === 'FORBIDDEN') {
        setForbidden(true);
      } else {
        setFetchError('Failed to load account status. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, userId]);

  useEffect(() => {
    void fetchStatus();
  }, [fetchStatus]);

  function handleReactivateSuccess(newStatus: string) {
    if (statusData) setStatusData({ ...statusData, account_status: newStatus });
    setToastMessage('Account reactivated.');
  }

  function handleCancelClosureSuccess(newStatus: string) {
    if (statusData) setStatusData({ ...statusData, account_status: newStatus });
    setToastMessage('Account closure cancelled.');
  }

  function handleCloseSuccess(newStatus: string) {
    if (statusData) setStatusData({ ...statusData, account_status: newStatus });
    setToastMessage('Account permanently closed.');
  }

  const historyColumns = [
    {
      key: 'from_status',
      header: 'From',
      render: (row: AdminStatusHistoryEntry) => (
        <span className="text-sm text-text-secondary">{row.from_status ?? '—'}</span>
      ),
    },
    {
      key: 'to_status',
      header: 'To',
      render: (row: AdminStatusHistoryEntry) => (
        <Badge
          label={row.to_status.replace(/_/g, ' ').toUpperCase()}
          variant={STATUS_VARIANT[row.to_status] ?? 'neutral'}
        />
      ),
    },
    {
      key: 'actor',
      header: 'Actor',
      render: (row: AdminStatusHistoryEntry) => (
        <span className="text-sm text-text-secondary">
          {row.actor_type}
          {row.actor_id ? ` (${row.actor_id})` : ''}
        </span>
      ),
    },
    {
      key: 'reason',
      header: 'Reason',
      render: (row: AdminStatusHistoryEntry) => (
        <span className="text-sm text-text-secondary">{row.reason ?? '—'}</span>
      ),
    },
    {
      key: 'created_at',
      header: 'Date',
      render: (row: AdminStatusHistoryEntry) => (
        <time dateTime={row.created_at} className="text-sm text-text-secondary">
          {new Date(row.created_at).toLocaleString()}
        </time>
      ),
    },
  ];

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to view account status."
      />
    );
  }

  if (forbidden) {
    return (
      <ErrorState
        title="Access denied"
        message="You do not have permission to view this account status."
      />
    );
  }

  const currentStatus = statusData?.account_status ?? '';

  return (
    <main aria-labelledby={headingId} className="px-6 py-8 max-w-4xl mx-auto">
      <div className="mb-6 flex items-center justify-between gap-4 flex-wrap">
        <h1 id={headingId} className="text-xl font-semibold text-text-primary">
          Account Status
        </h1>

        <div className="flex gap-2">
          {canReactivate && currentStatus === 'suspended' && (
            <Button variant="primary" onClick={() => setReactivateOpen(true)}>
              Reactivate
            </Button>
          )}
          {canReactivate && currentStatus === 'pending_closure' && (
            <Button variant="secondary" onClick={() => setCancelClosureOpen(true)}>
              Cancel Closure
            </Button>
          )}
          {canClose && currentStatus === 'pending_closure' && (
            <Button variant="danger" onClick={() => setCloseOpen(true)}>
              Close Account
            </Button>
          )}
        </div>
      </div>

      {loading && <Loading label="Loading account status…" />}

      {!loading && fetchError && (
        <ErrorState message={fetchError} onRetry={() => void fetchStatus()} />
      )}

      {!loading && !fetchError && statusData && (
        <div className="space-y-6">
          <section aria-label="Current status">
            <div className="flex items-center gap-3">
              <span className="text-sm font-medium text-text-secondary">Current status:</span>
              <Badge
                label={currentStatus.replace(/_/g, ' ').toUpperCase()}
                variant={STATUS_VARIANT[currentStatus] ?? 'neutral'}
              />
            </div>
          </section>

          <section aria-label="Status history">
            <h2 className="text-base font-medium text-text-primary mb-3">Status History</h2>

            {statusData.history.length === 0 ? (
              <Empty title="No history" description="No status transitions recorded yet." />
            ) : (
              <Table
                caption="Account status transition history"
                columns={historyColumns}
                data={statusData.history}
                getRowKey={(row) => `${row.to_status}-${row.created_at}`}
              />
            )}
          </section>
        </div>
      )}

      <ReactivateDialog
        open={reactivateOpen}
        userId={userId}
        mode="reactivate"
        onClose={() => setReactivateOpen(false)}
        onSuccess={handleReactivateSuccess}
        hasPermission={canReactivate}
      />

      <ReactivateDialog
        open={cancelClosureOpen}
        userId={userId}
        mode="cancel_closure"
        onClose={() => setCancelClosureOpen(false)}
        onSuccess={handleCancelClosureSuccess}
        hasPermission={canReactivate}
      />

      <CloseAccountDialog
        open={closeOpen}
        userId={userId}
        onClose={() => setCloseOpen(false)}
        onSuccess={handleCloseSuccess}
        hasPermission={canClose}
      />

      {toastMessage && (
        <Toast message={toastMessage} onDismiss={() => setToastMessage(null)} />
      )}
    </main>
  );
}
