'use client';

import { useParams } from 'next/navigation';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import { useProviderAccount } from '@/src/modules/payment/hooks/useProviderAccount';
import { ProviderAccountForm } from '@/src/modules/payment/components/ProviderAccountForm';
import { AccountLifecycleStatusBadge } from '@/src/modules/payment/components/AccountLifecycleStatusBadge';
import { ProviderLifecycleControls } from '@/src/modules/payment/components/ProviderLifecycleControls';
import type { UpdateAccountPayload, LifecycleAction } from '@/lib/api/payment';

export default function AccountDetailPage() {
  const { providerId, accountId } = useParams<{ providerId: string; accountId: string }>();
  const { hasPermission, authenticatedClient } = useAuth();
  const { account, loading, error, updating, updateResult, updateAccount, refetch } = useProviderAccount(providerId, accountId);
  const canManage = hasPermission('payment.providers.manage');

  if (!hasPermission('payment.providers.read')) {
    return <div className="p-6 text-destructive">Access denied.</div>;
  }
  if (loading) return <div className="p-6">Loading account...</div>;
  if (error) return <div className="p-6 text-destructive">{error}</div>;
  if (!account) return <div className="p-6">Account not found.</div>;

  const handleSubmit = (payload: UpdateAccountPayload) => {
    void updateAccount(payload);
  };

  const handleTransition = async (action: LifecycleAction, reason: string) => {
    if (!authenticatedClient) return;
    const client = new PaymentApiClient(authenticatedClient);
    await client.transitionAccountLifecycle(providerId, accountId, { action, reason });
    refetch();
  };

  return (
    <div className="p-6 max-w-2xl">
      <div className="mb-2 text-sm">
        <Link href={`/payments/providers/${providerId}`} className="underline text-muted-foreground">← Provider</Link>
      </div>
      <div className="flex items-center gap-3 mb-6">
        <h1 className="text-xl font-semibold font-mono text-sm">{account.id}</h1>
        <AccountLifecycleStatusBadge status={account.account_lifecycle_status} />
        <span className="text-xs text-muted-foreground">v{account.config_version}</span>
      </div>

      {updateResult?.type === 'saved' && (
        <div className="bg-green-50 border border-green-200 rounded p-3 mb-4 text-sm text-green-800">Changes saved successfully.</div>
      )}
      {updateResult?.type === 'requires_approval' && (
        <div className="bg-amber-50 border border-amber-200 rounded p-3 mb-4 text-sm text-amber-800">
          High-risk changes submitted for approval. Fields: {updateResult.high_risk_fields.join(', ')}.{' '}
          <Link href="/payments/providers/approvals" className="underline">View approval queue →</Link>
        </div>
      )}
      {updateResult?.type === 'conflict' && (
        <div className="bg-red-50 border border-red-200 rounded p-3 mb-4 text-sm text-red-800">
          Config changed since you loaded this page. Please refresh and re-submit.
        </div>
      )}
      {updateResult?.type === 'error' && (
        <div className="bg-red-50 border border-red-200 rounded p-3 mb-4 text-sm text-red-800">
          {updateResult.message}
        </div>
      )}

      <ProviderAccountForm
        account={account}
        readOnly={!canManage}
        onSubmit={handleSubmit}
        submitting={updating}
      />

      <ProviderLifecycleControls
        status={account.account_lifecycle_status}
        canManage={canManage}
        onTransition={handleTransition}
      />

      <div className="mt-6">
        <Link href={`/payments/providers/${providerId}/accounts/${accountId}/history`} className="text-sm underline">
          View config change history →
        </Link>
      </div>
    </div>
  );
}
