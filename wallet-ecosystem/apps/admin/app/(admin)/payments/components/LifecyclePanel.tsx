'use client';

// T092: Lifecycle action buttons with confirmation modal.
import { useState } from 'react';
import type { AccountLifecycleStatus } from '@/lib/api/payments/banks';
import { BankStatusBadge } from './BankStatusBadge';

const ACTIONS: Record<AccountLifecycleStatus, Array<{ label: string; action: string; variant: 'primary' | 'danger' | 'warning' }>> = {
  draft:           [{ label: 'Submit for Validation', action: 'submit_for_validation', variant: 'primary' }],
  validated:       [{ label: 'Start Sandbox Testing', action: 'initiate_sandbox_testing', variant: 'primary' }],
  sandbox_testing: [{ label: 'Submit Evidence', action: 'submit_evidence', variant: 'primary' }],
  evidence_review: [
    { label: 'Approve', action: 'approve', variant: 'primary' },
    { label: 'Reject', action: 'reject', variant: 'danger' },
    { label: 'Request Changes', action: 'request_changes', variant: 'warning' },
  ],
  approved:        [{ label: 'Activate', action: 'activate', variant: 'primary' }],
  active:          [{ label: 'Suspend', action: 'suspend', variant: 'warning' }],
  suspended:       [
    { label: 'Reactivate', action: 'reactivate', variant: 'primary' },
    { label: 'Retire', action: 'retire', variant: 'danger' },
  ],
  retired:         [],
};

interface Props {
  status: AccountLifecycleStatus;
  bankId: string;
  accountId: string;
  onTransition: (action: string, reason?: string) => Promise<void>;
  approvalConflict?: boolean;
}

export function LifecyclePanel({ status, bankId: _bankId, accountId: _accountId, onTransition, approvalConflict }: Props) {
  const [pending, setPending] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(false);

  const actions = ACTIONS[status] ?? [];

  const needsReason = (action: string) =>
    action === 'reject' || action === 'request_changes' || action === 'retire';

  const handleConfirm = async () => {
    if (!pending) return;
    setLoading(true);
    try {
      await onTransition(pending, needsReason(pending) ? reason : undefined);
      setPending(null);
      setReason('');
    } finally {
      setLoading(false);
    }
  };

  if (actions.length === 0) {
    return (
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div className="flex items-center gap-3">
          <BankStatusBadge status={status} type="account" />
          <p className="text-sm text-gray-500">No further transitions available.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <BankStatusBadge status={status} type="account" />
        {approvalConflict && (
          <p className="text-sm text-red-600">Approval conflict: submitter cannot approve their own submission.</p>
        )}
        {actions.map(({ label, action, variant }) => (
          <button
            key={action}
            type="button"
            onClick={() => setPending(action)}
            className={`rounded-md px-3 py-1.5 text-sm font-medium ${
              variant === 'primary'   ? 'bg-blue-600 text-white hover:bg-blue-700' :
              variant === 'danger'    ? 'bg-red-600 text-white hover:bg-red-700' :
                                        'bg-yellow-500 text-white hover:bg-yellow-600'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {pending && (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <p className="text-sm font-medium text-gray-900 mb-3">
            Confirm: {actions.find(a => a.action === pending)?.label}
          </p>
          {needsReason(pending) && (
            <textarea
              value={reason}
              onChange={e => setReason(e.target.value)}
              placeholder="Reason (required)"
              className="mb-3 w-full rounded border border-gray-300 px-3 py-2 text-sm"
              rows={3}
            />
          )}
          <div className="flex gap-2">
            <button
              type="button"
              onClick={handleConfirm}
              disabled={loading || (needsReason(pending) && reason.trim() === '')}
              className="rounded-md bg-blue-600 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50"
            >
              {loading ? 'Processing…' : 'Confirm'}
            </button>
            <button
              type="button"
              onClick={() => { setPending(null); setReason(''); }}
              className="rounded-md border border-gray-300 px-4 py-1.5 text-sm font-medium text-gray-700"
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
