'use client';

import { useState } from 'react';
import { Dialog } from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { useAuth } from '@/lib/auth/context';
import { reactivateAccount, cancelClosureAccount } from '@/src/modules/profile/api/profile';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

type ReactivateMode = 'reactivate' | 'cancel_closure';

interface ReactivateDialogProps {
  open: boolean;
  userId: string;
  mode: ReactivateMode;
  onClose: () => void;
  onSuccess: (newStatus: string) => void;
  hasPermission?: boolean;
}

export function ReactivateDialog({
  open,
  userId,
  mode,
  onClose,
  onSuccess,
  hasPermission = true,
}: ReactivateDialogProps) {
  const { authenticatedClient } = useAuth();
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const title = mode === 'reactivate' ? 'Reactivate Account' : 'Cancel Account Closure';
  const confirmLabel = mode === 'reactivate' ? 'Reactivate' : 'Cancel Closure';
  const description =
    mode === 'reactivate'
      ? 'Reactivating this account will restore access for the user. An optional reason can be recorded.'
      : 'Cancelling closure will restore this account to active status. An optional reason can be recorded.';

  function handleClose() {
    setReason('');
    setErrorMessage(null);
    onClose();
  }

  async function handleConfirm() {
    if (!authenticatedClient) return;
    setLoading(true);
    setErrorMessage(null);
    try {
      const optionalReason = reason.trim() || undefined;
      const result =
        mode === 'reactivate'
          ? await reactivateAccount(authenticatedClient, userId, optionalReason)
          : await cancelClosureAccount(authenticatedClient, userId, optionalReason);
      handleClose();
      onSuccess(result.account_status);
    } catch (err) {
      const msg = isApiError(err)
        ? (err as ApiError).error.message
        : 'Action failed. Please try again.';
      setErrorMessage(msg);
    } finally {
      setLoading(false);
    }
  }

  if (!hasPermission) return null;

  return (
    <Dialog open={open} onClose={handleClose} title={title}>
      <div className="space-y-4">
        <p className="text-sm text-text-secondary">{description}</p>

        <Input
          label="Reason (optional)"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Enter reason…"
        />

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
            variant="primary"
            onClick={() => { void handleConfirm(); }}
            disabled={loading}
            aria-busy={loading}
          >
            {confirmLabel}
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
