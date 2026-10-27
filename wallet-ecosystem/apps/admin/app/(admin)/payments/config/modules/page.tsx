'use client';

import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { useModuleConfig } from '@/src/modules/payment/hooks/useModuleConfig';
import { ModuleAvailabilityForm } from '@/src/modules/payment/components/ModuleAvailabilityForm';
import type { UpdateModuleConfigPayload } from '@/lib/api/payment';

export default function ModulesPage() {
  const { hasPermission } = useAuth();
  const { modules, loading, error, updating, updateResult, updateModules } = useModuleConfig();
  const canManage = hasPermission('payment.providers.manage');

  if (!hasPermission('payment.providers.read')) {
    return <div className="p-6 text-destructive">Access denied.</div>;
  }

  const handleSubmit = (payload: UpdateModuleConfigPayload) => {
    void updateModules(payload);
  };

  return (
    <div className="p-6 max-w-2xl">
      <h1 className="text-2xl font-semibold mb-6">Payment Module Availability</h1>
      {loading && <p>Loading modules...</p>}
      {error && <p className="text-destructive">{error}</p>}
      {!loading && !error && (
        <ModuleAvailabilityForm
          modules={modules}
          readOnly={!canManage}
          onSubmit={handleSubmit}
          submitting={updating}
          approvalPending={updateResult?.type === 'requires_approval'}
        />
      )}
      {updateResult?.type === 'requires_approval' && (
        <div className="mt-4 bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-800">
          Module changes submitted for approval.{' '}
          <Link href="/payments/providers/approvals" className="underline">View approval queue →</Link>
        </div>
      )}
    </div>
  );
}
