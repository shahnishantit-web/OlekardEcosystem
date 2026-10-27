'use client';

import { useState } from 'react';
import type { AccountLifecycleStatus, LifecycleAction } from '@/lib/api/payment';

const VALID_TRANSITIONS: Record<AccountLifecycleStatus, LifecycleAction[]> = {
  draft: ['activate'],
  pending_approval: ['activate'],
  active: ['suspend', 'retire'],
  suspended: ['restore', 'retire'],
  retired: [],
  rejected: ['activate'],
};

interface Props {
  status: AccountLifecycleStatus;
  canManage: boolean;
  onTransition: (action: LifecycleAction, reason: string) => Promise<void>;
}

export function ProviderLifecycleControls({ status, canManage, onTransition }: Props) {
  const [activeAction, setActiveAction] = useState<LifecycleAction | null>(null);
  const [reason, setReason] = useState('');
  const [retireConfirm, setRetireConfirm] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const validActions = VALID_TRANSITIONS[status] ?? [];
  if (!canManage || validActions.length === 0) return null;

  const handleSubmit = async () => {
    if (!activeAction || !reason.trim()) return;
    if (activeAction === 'retire' && retireConfirm !== 'RETIRE') return;
    setLoading(true);
    setError(null);
    try {
      await onTransition(activeAction, reason);
      setActiveAction(null);
      setReason('');
      setRetireConfirm('');
    } catch {
      setError('Transition failed. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="border rounded p-4 mt-4">
      <h3 className="text-sm font-semibold mb-3">Lifecycle Actions</h3>
      <div className="flex gap-2 flex-wrap mb-3">
        {validActions.map((action) => (
          <button
            key={action}
            onClick={() => { setActiveAction(action); setReason(''); setRetireConfirm(''); }}
            className="text-sm border rounded px-3 py-1 capitalize"
            aria-label={`${action} account`}
          >
            {action}
          </button>
        ))}
      </div>
      {activeAction && (
        <div className="space-y-2">
          <label htmlFor="lifecycle-reason" className="block text-sm font-medium">
            Reason (required)
          </label>
          <textarea
            id="lifecycle-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={2000}
            rows={2}
            className="border rounded w-full px-2 py-1.5 text-sm"
            aria-label="Reason for lifecycle transition"
          />
          {activeAction === 'retire' && (
            <div>
              <label htmlFor="retire-confirm" className="block text-sm font-medium text-destructive">
                This is irreversible. Type RETIRE to confirm.
              </label>
              <input
                id="retire-confirm"
                type="text"
                value={retireConfirm}
                onChange={(e) => setRetireConfirm(e.target.value)}
                className="border rounded w-full px-2 py-1.5 text-sm"
                aria-label="Type RETIRE to confirm retirement"
              />
            </div>
          )}
          {error && <p className="text-destructive text-sm">{error}</p>}
          <button
            onClick={() => void handleSubmit()}
            disabled={loading || !reason.trim() || (activeAction === 'retire' && retireConfirm !== 'RETIRE')}
            className="text-sm bg-primary text-primary-foreground px-3 py-1.5 rounded disabled:opacity-50"
            aria-label={`Confirm ${activeAction}`}
          >
            {loading ? 'Processing...' : `Confirm ${activeAction}`}
          </button>
        </div>
      )}
    </div>
  );
}
