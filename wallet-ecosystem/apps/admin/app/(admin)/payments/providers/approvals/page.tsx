'use client';

import { useState, useEffect, useCallback } from 'react';
import { useAuth } from '@/lib/auth/context';
import { ApprovalQueueTable } from '@/src/modules/payment/components/ApprovalQueueTable';
import type { ConfigDraft } from '@/lib/api/payment';

export default function ApprovalsPage() {
  const { authenticatedClient, hasPermission, user } = useAuth();
  const [drafts, setDrafts] = useState<ConfigDraft[]>([]);
  const [loading, setLoading] = useState(true);

  const canApprove = hasPermission('payment.config.approve_high_risk');

  const loadDrafts = useCallback(() => {
    if (!authenticatedClient) return;
    // Cross-provider approval queue: backend returns pending drafts across all providers.
    // A real endpoint would be /api/v1/admin/payment/config-changes?status=pending_approval
    // For now we initialise to empty and surface what the backend provides.
    setLoading(false);
    setDrafts([]);
  }, [authenticatedClient]);

  useEffect(() => { loadDrafts(); }, [loadDrafts]);

  if (!hasPermission('payment.providers.read')) {
    return <div className="p-6 text-destructive">Access denied.</div>;
  }

  const handleApprove = async (draftId: string, providerId: string) => {
    if (!authenticatedClient) return;
    await authenticatedClient.post(
      `/api/v1/admin/payment/providers/${providerId}/config-changes/${draftId}/approve`,
      {},
    );
    loadDrafts();
  };

  const handleDeny = async (draftId: string, providerId: string, reason: string) => {
    if (!authenticatedClient) return;
    await authenticatedClient.post(
      `/api/v1/admin/payment/providers/${providerId}/config-changes/${draftId}/reject`,
      { reason },
    );
    loadDrafts();
  };

  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold mb-6">Approval Queue</h1>
      {!canApprove && (
        <p className="text-muted-foreground text-sm mb-4">
          You have read-only access to the approval queue. The approve and deny actions require the <code>payment.config.approve_high_risk</code> permission.
        </p>
      )}
      {loading ? (
        <p>Loading...</p>
      ) : (
        <ApprovalQueueTable
          drafts={drafts}
          currentUserId={user?.id ?? ''}
          onApprove={handleApprove}
          onDeny={handleDeny}
        />
      )}
    </div>
  );
}
