'use client';

import { useState } from 'react';
import { ConfigDiffPreview } from './ConfigDiffPreview';
import type { ConfigDraft } from '@/lib/api/payment';

interface Props {
  drafts: ConfigDraft[];
  currentUserId: string;
  onApprove: (draftId: string, providerId: string, notes?: string) => Promise<void>;
  onDeny: (draftId: string, providerId: string, reason: string) => Promise<void>;
}

export function ApprovalQueueTable({ drafts, currentUserId, onApprove, onDeny }: Props) {
  const [expandedDraftId, setExpandedDraftId] = useState<string | null>(null);
  const [denyingDraftId, setDenyingDraftId] = useState<string | null>(null);
  const [denyReason, setDenyReason] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);

  if (drafts.length === 0) {
    return <p className="text-muted-foreground text-sm">No changes awaiting approval.</p>;
  }

  const handleApprove = async (draft: ConfigDraft) => {
    setActionError(null);
    try {
      await onApprove(draft.id, draft.provider_id ?? '', undefined);
    } catch {
      setActionError('Approval failed. Please try again.');
    }
  };

  const handleDeny = async (draft: ConfigDraft) => {
    if (!denyReason.trim()) return;
    setActionError(null);
    try {
      await onDeny(draft.id, draft.provider_id ?? '', denyReason);
      setDenyingDraftId(null);
      setDenyReason('');
    } catch {
      setActionError('Denial failed. Please try again.');
    }
  };

  return (
    <div className="space-y-3">
      {actionError && <p className="text-destructive text-sm">{actionError}</p>}
      {drafts.map((draft) => {
        const isSelf = draft.drafted_by_actor_id === currentUserId;
        const isExpanded = expandedDraftId === draft.id;
        const isDenying = denyingDraftId === draft.id;

        return (
          <div key={draft.id} className="border rounded p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium">{draft.change_type} — {draft.status}</p>
                <p className="text-xs text-muted-foreground">
                  Submitted by {draft.drafted_by_actor_id} · {new Date(draft.created_at).toLocaleDateString()}
                </p>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setExpandedDraftId(isExpanded ? null : draft.id)}
                  className="text-xs underline"
                  aria-label={isExpanded ? 'Collapse diff' : 'Expand diff'}
                >
                  {isExpanded ? 'Hide diff' : 'View diff'}
                </button>
                <button
                  onClick={() => void handleApprove(draft)}
                  disabled={isSelf}
                  title={isSelf ? 'Self-approval not permitted' : 'Approve'}
                  className="text-xs bg-green-600 text-white px-2 py-1 rounded disabled:opacity-50"
                  aria-label="Approve this change"
                >
                  Approve
                </button>
                <button
                  onClick={() => setDenyingDraftId(isDenying ? null : draft.id)}
                  className="text-xs bg-red-600 text-white px-2 py-1 rounded"
                  aria-label="Deny this change"
                >
                  Deny
                </button>
              </div>
            </div>
            {isExpanded && (
              <div className="mt-3 border-t pt-3">
                <ConfigDiffPreview diff={
                  Object.fromEntries(
                    Object.entries(draft.change_payload as Record<string, unknown>).map(([k, v]) => [k, { current: null, proposed: v }])
                  )
                } />
              </div>
            )}
            {isDenying && (
              <div className="mt-3 border-t pt-3 space-y-2">
                <label htmlFor={`deny-reason-${draft.id}`} className="block text-sm font-medium">Denial reason (required)</label>
                <textarea
                  id={`deny-reason-${draft.id}`}
                  value={denyReason}
                  onChange={(e) => setDenyReason(e.target.value)}
                  className="border rounded w-full px-2 py-1.5 text-sm"
                  rows={2}
                  aria-label="Reason for denying this change"
                />
                <button
                  onClick={() => void handleDeny(draft)}
                  disabled={!denyReason.trim()}
                  className="text-xs bg-red-600 text-white px-2 py-1 rounded disabled:opacity-50"
                  aria-label="Submit denial"
                >
                  Submit denial
                </button>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
