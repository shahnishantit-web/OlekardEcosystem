'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import type { ChangeRequestDetail } from '@/lib/api/configuration';
import { ConfigurationApiClient } from '@/lib/api/configuration';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

interface Props {
  changeRequestId: string;
}

type StatusVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

function statusVariant(status: string): StatusVariant {
  switch (status) {
    case 'pending_approval': return 'warning';
    case 'approved':         return 'success';
    case 'rejected':         return 'error';
    case 'withdrawn':        return 'neutral';
    case 'failed':           return 'error';
    default:                 return 'neutral';
  }
}

export function ConfigurationApprovalDetailPage({ changeRequestId }: Props) {
  const { authenticatedClient, user, hasPermission } = useAuth();
  const router = useRouter();
  const headingId = useId();

  const canApprove = hasPermission('configuration.approve');

  const [request, setRequest] = useState<ChangeRequestDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionPending, setActionPending] = useState(false);
  const [showRejectDialog, setShowRejectDialog] = useState(false);
  const [rejectReason, setRejectReason] = useState('');

  const fetchRequest = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new ConfigurationApiClient(authenticatedClient);
      const res = await client.getChangeRequest(changeRequestId);
      setRequest(res.data);
    } catch {
      setError('Failed to load change request.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, changeRequestId]);

  useEffect(() => {
    if (canApprove) void fetchRequest();
  }, [fetchRequest, canApprove]);

  const handleApprove = useCallback(async () => {
    if (!authenticatedClient || !request) return;
    setActionPending(true);
    try {
      const client = new ConfigurationApiClient(authenticatedClient);
      await client.approveChangeRequest(changeRequestId, {
        idempotency_key: generateIdempotencyKey(),
      });
      router.push('/configuration/approvals');
    } catch {
      setError('Failed to approve change request.');
    } finally {
      setActionPending(false);
    }
  }, [authenticatedClient, changeRequestId, request, router]);

  const handleReject = useCallback(async () => {
    if (!authenticatedClient || !rejectReason.trim()) return;
    setActionPending(true);
    try {
      const client = new ConfigurationApiClient(authenticatedClient);
      await client.rejectChangeRequest(changeRequestId, {
        reason:          rejectReason,
        idempotency_key: generateIdempotencyKey(),
      });
      router.push('/configuration/approvals');
    } catch {
      setError('Failed to reject change request.');
    } finally {
      setActionPending(false);
      setShowRejectDialog(false);
    }
  }, [authenticatedClient, changeRequestId, rejectReason, router]);

  const handleWithdraw = useCallback(async () => {
    if (!authenticatedClient) return;
    setActionPending(true);
    try {
      const client = new ConfigurationApiClient(authenticatedClient);
      await client.withdrawChangeRequest(changeRequestId, {
        idempotency_key: generateIdempotencyKey(),
      });
      router.push('/configuration/approvals');
    } catch {
      setError('Failed to withdraw change request.');
    } finally {
      setActionPending(false);
    }
  }, [authenticatedClient, changeRequestId, router]);

  if (!canApprove) {
    return (
      <main aria-labelledby={headingId}>
        <h1 id={headingId} className="text-2xl font-semibold">Change Request Detail</h1>
        <Card className="mt-4 p-6">
          <p role="alert" className="text-sm text-error">
            Access denied. You do not have permission to approve configuration changes.
          </p>
        </Card>
      </main>
    );
  }

  if (loading) return <Loading aria-label="Loading change request details" />;
  if (error) return <ErrorState message={error} onRetry={fetchRequest} />;
  if (!request) return null;

  const isPending   = request.status === 'pending_approval';
  const isOwnChange = user?.id === request.maker_id;

  return (
    <main aria-labelledby={headingId}>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 id={headingId} className="text-2xl font-semibold">
            Change Request Detail
          </h1>
          <p className="mt-1 font-mono text-sm text-muted-foreground">{request.config_key}</p>
        </div>
        <Badge variant={statusVariant(request.status)}>{request.status.replace('_', ' ')}</Badge>
      </div>

      {/* Value diff */}
      <div className="mb-6 grid gap-4 md:grid-cols-2">
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Previous Value</h2>
          <pre className="overflow-auto rounded bg-muted p-3 text-xs">
            {request.previous_value != null
              ? JSON.stringify(request.previous_value, null, 2)
              : '(no previous value)'}
          </pre>
        </Card>
        <Card>
          <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Proposed Value</h2>
          <pre className="overflow-auto rounded bg-muted p-3 text-xs">
            {JSON.stringify(request.proposed_value, null, 2)}
          </pre>
        </Card>
      </div>

      {/* Version trail */}
      {request.version_trail && request.version_trail.length > 0 && (
        <Card className="mb-6">
          <h2 className="mb-3 text-sm font-semibold">Version Trail</h2>
          <ol className="space-y-2">
            {request.version_trail.map((v) => (
              <li key={v.id} className="flex items-center gap-3 text-sm">
                <Badge variant="neutral">{v.event_type}</Badge>
                <span className="text-muted-foreground">
                  {new Date(v.occurred_at).toLocaleString()}
                </span>
                {v.notes && <span className="text-muted-foreground">— {v.notes}</span>}
              </li>
            ))}
          </ol>
        </Card>
      )}

      {/* Actions — only shown for pending requests */}
      {isPending && (
        <div>
          {isOwnChange && (
            <p role="alert" className="mb-3 text-sm text-warning">
              You cannot approve your own change request.
            </p>
          )}
          <div className="flex gap-3" role="group" aria-label="Change request actions">
            <Button
              onClick={() => void handleApprove()}
              disabled={actionPending || isOwnChange}
              aria-label="Approve change request"
            >
              Approve
            </Button>
            <Button
              variant="outline"
              onClick={() => setShowRejectDialog(true)}
              disabled={actionPending || isOwnChange}
              aria-label="Reject change request"
            >
              Reject
            </Button>
            <Button
              variant="ghost"
              onClick={() => void handleWithdraw()}
              disabled={actionPending}
              aria-label="Withdraw change request"
            >
              Withdraw
            </Button>
          </div>
        </div>
      )}

      {/* Reject dialog */}
      {showRejectDialog && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="reject-dialog-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
        >
          <Card className="w-full max-w-md p-6">
            <h2 id="reject-dialog-title" className="mb-4 text-lg font-semibold">
              Reject Change Request
            </h2>
            <label htmlFor="reject-reason" className="mb-1 block text-sm font-medium">
              Rejection Reason <span aria-hidden="true">*</span>
            </label>
            <textarea
              id="reject-reason"
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              rows={3}
              className="mb-4 w-full rounded-md border px-3 py-2 text-sm"
              placeholder="Provide a reason for rejection..."
              required
              aria-required="true"
            />
            <div className="flex justify-end gap-3">
              <Button
                variant="ghost"
                onClick={() => setShowRejectDialog(false)}
                disabled={actionPending}
              >
                Cancel
              </Button>
              <Button
                onClick={() => void handleReject()}
                disabled={actionPending || !rejectReason.trim()}
                aria-label="Confirm rejection"
              >
                Confirm Rejection
              </Button>
            </div>
          </Card>
        </div>
      )}
    </main>
  );
}
