'use client';

import { useState } from 'react';
import { BankStatusBadge } from '../../../../components/BankStatusBadge';
import { SecretReferenceField } from '../../../../components/SecretReferenceField';
import { LifecyclePanel } from '../../../../components/LifecyclePanel';
import type { AccountLifecycleStatus } from '@/lib/api/payments/banks';

// T087: Account detail page with lifecycle controls, credential fields, version history.
export default function AccountDetailPage({
  params,
}: {
  params: { id: string; accountId: string };
}) {
  const [approvalConflict, setApprovalConflict] = useState(false);
  // Placeholder status — real value comes from API
  const currentStatus: AccountLifecycleStatus = 'draft';

  const handleTransition = async (action: string, reason?: string) => {
    // POST /api/v1/admin/payments/banks/{id}/accounts/{accountId}/lifecycle
    // Handle 409 approval_conflict
    console.info('Lifecycle transition:', action, reason);
    setApprovalConflict(action === 'approve'); // mock conflict for demo
  };

  return (
    <div className="space-y-6 p-6">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">Account Detail</h1>
        <p className="text-sm text-gray-500 mt-1">
          Bank: {params.id} · Account: {params.accountId}
        </p>
      </div>

      {/* Lifecycle controls */}
      <section className="rounded-lg border border-gray-200 bg-white p-6 space-y-4">
        <h2 className="text-base font-medium text-gray-900">Lifecycle</h2>
        <LifecyclePanel
          status={currentStatus}
          bankId={params.id}
          accountId={params.accountId}
          onTransition={handleTransition}
          approvalConflict={approvalConflict}
        />
      </section>

      {/* Credential reference fields */}
      <section className="rounded-lg border border-gray-200 bg-white p-6 space-y-4">
        <h2 className="text-base font-medium text-gray-900">Credential References</h2>
        <SecretReferenceField
          label="Callback Secret Reference"
          value={{ key: 'payments/fnbz/sandbox/callback_secret', masked: '****' }}
        />
        <div className="flex gap-2 pt-2">
          <a
            href={`/payments/banks/${params.id}/accounts/${params.accountId}/rotate-credential`}
            className="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
          >
            Rotate Credential
          </a>
        </div>
      </section>

      {/* Configuration version history */}
      <section className="rounded-lg border border-gray-200 bg-white p-6 space-y-3">
        <h2 className="text-base font-medium text-gray-900">Configuration Version History</h2>
        <p className="text-sm text-gray-500">Version history loads from API at runtime.</p>
      </section>

      {/* Quick links */}
      <div className="flex flex-wrap gap-3">
        <a
          href={`/payments/banks/${params.id}/accounts/${params.accountId}/operations`}
          className="rounded-md bg-white border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          Configure Operations
        </a>
        <a
          href={`/payments/banks/${params.id}/accounts/${params.accountId}/evidence`}
          className="rounded-md bg-white border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          Evidence & Sandbox Test
        </a>
      </div>
    </div>
  );
}
