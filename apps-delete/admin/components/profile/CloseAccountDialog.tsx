'use client';

import { useState } from 'react';
import { Dialog } from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { useAuth } from '@/lib/auth/context';
import { closeAccount } from '@/src/modules/profile/api/profile';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

interface ObligationBlockedError {
  code: 'CLOSURE_BLOCKED_BY_OBLIGATIONS';
  message: string;
  details?: { blocked_by?: string[] };
}

interface CloseAccountDialogProps {
  open: boolean;
  userId: string;
  onClose: () => void;
  onSuccess: (newStatus: string) => void;
  hasPermission?: boolean;
}

export function CloseAccountDialog({
  open,
  userId,
  onClose,
  onSuccess,
  hasPermission = true,
}: CloseAccountDialogProps) {
  const { authenticatedClient } = useAuth();
  const [reason, setReason] = useState('');
  const [step, setStep] = useState<'initial' | 'confirm'>('initial');
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [obligations, setObligations] = useState<string[]>([]);

  function handleClose() {
    setReason('');
    setStep('initial');
    setErrorMessage(null);
    setObligations([]);
    onClose();
  }

  function handleAdvanceToConfirm() {
    setStep('confirm');
  }

  async function handleConfirmClose() {
    if (!authenticatedClient) return;
    setLoading(true);
    setErrorMessage(null);
    setObligations([]);
    try {
      const result = await closeAccount(authenticatedClient, userId, reason.trim() || undefined);
      handleClose();
      onSuccess(result.account_status);
    } catch (err) {
      if (isApiError(err)) {
        const apiErr = err as ApiError;
        if (apiErr.error.code === 'CLOSURE_BLOCKED_BY_OBLIGATIONS') {
          const blockedErr = err as { error: ObligationBlockedError };
          const blockedBy = blockedErr.error.details?.blocked_by ?? [];
          setObligations(blockedBy);
          setErrorMessage(apiErr.error.message);
        } else {
          setErrorMessage(apiErr.error.message);
        }
      } else {
        setErrorMessage('Failed to close account. Please try again.');
      }
      setStep('initial');
    } finally {
      setLoading(false);
    }
  }

  if (!hasPermission) return null;

  return (
    <Dialog open={open} onClose={handleClose} title="Permanently Close Account">
      <div className="space-y-4">
        {step === 'initial' ? (
          <>
            <p className="text-sm text-text-primary font-medium text-error">
              Warning: This action is permanent and cannot be undone.
            </p>
            <p className="text-sm text-text-secondary">
              Permanently closing this account will prevent any future access. The account must be
              in &quot;pending closure&quot; status before it can be closed.
            </p>

            <Input
              label="Reason (optional)"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Enter reason…"
            />

            {errorMessage && (
              <div role="alert" className="text-sm text-error space-y-1">
                <p>{errorMessage}</p>
                {obligations.length > 0 && (
                  <ul className="list-disc list-inside">
                    {obligations.map((ob) => (
                      <li key={ob}>{ob}</li>
                    ))}
                  </ul>
                )}
              </div>
            )}

            <div className="flex justify-end gap-3 pt-2">
              <Button variant="secondary" onClick={handleClose}>
                Cancel
              </Button>
              <Button variant="danger" onClick={handleAdvanceToConfirm}>
                Close Account
              </Button>
            </div>
          </>
        ) : (
          <>
            <p className="text-sm text-text-primary">
              Are you absolutely sure? This will permanently close the account and cannot be
              reversed.
            </p>

            <div className="flex justify-end gap-3 pt-2">
              <Button variant="secondary" onClick={() => setStep('initial')} disabled={loading}>
                Back
              </Button>
              <Button
                variant="danger"
                onClick={() => { void handleConfirmClose(); }}
                disabled={loading}
                aria-busy={loading}
              >
                Yes, Permanently Close
              </Button>
            </div>
          </>
        )}
      </div>
    </Dialog>
  );
}
