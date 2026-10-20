'use client';

import { useState } from 'react';
import { Dialog } from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import { suspendAccount } from '@/src/modules/profile/api/profile';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

interface SuspendDialogProps {
  open: boolean;
  userId: string;
  onClose: () => void;
  onSuccess: (newStatus: string) => void;
  hasPermission?: boolean;
}

export function SuspendDialog({
  open,
  userId,
  onClose,
  onSuccess,
  hasPermission = true,
}: SuspendDialogProps) {
  const { authenticatedClient } = useAuth();
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  function handleClose() {
    setReason('');
    setConfirmed(false);
    setErrorMessage(null);
    onClose();
  }

  async function handleSubmit() {
    if (!authenticatedClient || !reason.trim()) return;
    if (!confirmed) {
      setConfirmed(true);
      return;
    }
    setLoading(true);
    setErrorMessage(null);
    try {
      const result = await suspendAccount(authenticatedClient, userId, reason.trim());
      handleClose();
      onSuccess(result.account_status);
    } catch (err) {
      const msg = isApiError(err)
        ? (err as ApiError).error.message
        : 'Failed to suspend account. Please try again.';
      setErrorMessage(msg);
      setConfirmed(false);
    } finally {
      setLoading(false);
    }
  }

  if (!hasPermission) return null;

  return (
    <>
      <Dialog open={open} onClose={handleClose} title="Suspend Account">
        <div className="space-y-4">
          {confirmed ? (
            <p className="text-sm text-text-primary">
              Are you sure you want to suspend this account? This will prevent the user from
              accessing the platform. Reason: <strong>{reason}</strong>
            </p>
          ) : (
            <>
              <p className="text-sm text-text-secondary">
                Provide a reason for suspending this account. This will be recorded in the
                account history.
              </p>
              <Input
                label="Reason for suspension"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Enter reason…"
              />
            </>
          )}

          {errorMessage && (
            <p role="alert" className="text-sm text-error">
              {errorMessage}
            </p>
          )}

          <div className="flex justify-end gap-3 pt-2">
            <Button variant="secondary" onClick={handleClose} disabled={loading}>
              Cancel
            </Button>
            <Button
              variant="danger"
              onClick={() => { void handleSubmit(); }}
              disabled={!reason.trim() || loading}
              aria-busy={loading}
            >
              {confirmed ? 'Confirm Suspend' : 'Suspend Account'}
            </Button>
          </div>
        </div>
      </Dialog>

      {errorMessage && !open && (
        <Toast message={errorMessage} onDismiss={() => setErrorMessage(null)} />
      )}
    </>
  );
}
