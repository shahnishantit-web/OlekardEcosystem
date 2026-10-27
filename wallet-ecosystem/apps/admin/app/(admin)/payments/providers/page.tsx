'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { useProviders } from '@/src/modules/payment/hooks/useProviders';
import { EnvironmentBadge } from '@/src/modules/payment/components/EnvironmentBadge';
import { ProviderStatusBadge } from '@/src/modules/payment/components/ProviderStatusBadge';

export default function ProvidersPage() {
  const { hasPermission } = useAuth();
  const [statusFilter, setStatusFilter] = useState('');
  const providerParams: { statusFilter?: string } = {};
  if (statusFilter) providerParams.statusFilter = statusFilter;
  const { providers, loading, error, refetch } = useProviders(providerParams);

  if (!hasPermission('payment.providers.read')) {
    return (
      <div className="p-6">
        <p className="text-destructive">You don&apos;t have access to the Provider Registry.</p>
      </div>
    );
  }

  if (loading) return <div className="p-6">Loading providers...</div>;
  if (error) return (
    <div className="p-6">
      <p className="text-destructive">{error}</p>
      <button onClick={refetch} className="mt-2 underline text-sm">Retry</button>
    </div>
  );

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-2xl font-semibold">Payment Providers</h1>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="border rounded px-2 py-1 text-sm"
          aria-label="Filter by status"
        >
          <option value="">All statuses</option>
          <option value="suspended">Suspended</option>
          <option value="active">Active</option>
        </select>
      </div>
      {providers.length === 0 ? (
        <p className="text-muted-foreground">No providers configured.</p>
      ) : (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b">
              <th className="text-left py-2 pr-4">Name</th>
              <th className="text-left py-2 pr-4">Environment</th>
              <th className="text-left py-2 pr-4">Status</th>
              <th className="text-left py-2 pr-4">Adapter</th>
            </tr>
          </thead>
          <tbody>
            {providers.map((p) => (
              <tr key={p.id} className="border-b hover:bg-muted/50">
                <td className="py-2 pr-4">
                  <Link href={`/payments/providers/${p.id}`} className="underline">
                    {p.display_name}
                  </Link>
                  <div className="text-xs text-muted-foreground">{p.code}</div>
                </td>
                <td className="py-2 pr-4"><EnvironmentBadge environment={p.environment} /></td>
                <td className="py-2 pr-4">
                  <ProviderStatusBadge
                    environment={p.environment}
                    is_enabled={p.is_enabled}
                    is_suspended={p.is_suspended}
                  />
                </td>
                <td className="py-2 pr-4 text-xs">{p.adapter_identifier}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
