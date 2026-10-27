'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import { EnvironmentBadge } from '@/src/modules/payment/components/EnvironmentBadge';
import { AccountLifecycleStatusBadge } from '@/src/modules/payment/components/AccountLifecycleStatusBadge';
import type { PaymentProviderAccountDetail, PaymentProvider } from '@/lib/api/payment';

export default function ProviderDetailPage() {
  const { providerId } = useParams<{ providerId: string }>();
  const { authenticatedClient, hasPermission } = useAuth();
  const [provider, setProvider] = useState<PaymentProvider | null>(null);
  const [accounts, setAccounts] = useState<PaymentProviderAccountDetail[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authenticatedClient || !providerId) return;
    const client = new PaymentApiClient(authenticatedClient);
    setLoading(true);
    Promise.all([
      client.getProvider(providerId),
      client.listProviderAccounts(providerId),
    ]).then(([provRes, accRes]) => {
      setProvider((provRes.data as { data: PaymentProvider }).data);
      setAccounts((accRes.data as { data: PaymentProviderAccountDetail[] }).data);
    }).catch(() => setError('Failed to load data')).finally(() => setLoading(false));
  }, [authenticatedClient, providerId]);

  if (!hasPermission('payment.providers.read')) {
    return <div className="p-6 text-destructive">You don&apos;t have access to the Provider Registry.</div>;
  }
  if (loading) return <div className="p-6">Loading...</div>;
  if (error) return <div className="p-6 text-destructive">{error}</div>;
  if (!provider) return <div className="p-6">Provider not found.</div>;

  return (
    <div className="p-6">
      <div className="flex items-center gap-3 mb-6">
        <h1 className="text-2xl font-semibold">{provider.display_name}</h1>
        <EnvironmentBadge environment={provider.environment} />
      </div>
      <div className="text-sm text-muted-foreground mb-6">Code: {provider.code} · Adapter: {provider.adapter_identifier}</div>

      <h2 className="text-lg font-semibold mb-3">Accounts</h2>
      {accounts.length === 0 ? (
        <p className="text-muted-foreground">No accounts configured.</p>
      ) : (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b">
              <th className="text-left py-2 pr-4">Account ID</th>
              <th className="text-left py-2 pr-4">Status</th>
              <th className="text-left py-2 pr-4">Config Version</th>
            </tr>
          </thead>
          <tbody>
            {accounts.map((acc) => (
              <tr key={acc.id} className="border-b hover:bg-muted/50">
                <td className="py-2 pr-4">
                  <Link href={`/payments/providers/${providerId}/accounts/${acc.id}`} className="underline font-mono text-xs">
                    {acc.id}
                  </Link>
                </td>
                <td className="py-2 pr-4">
                  <AccountLifecycleStatusBadge status={acc.account_lifecycle_status} />
                </td>
                <td className="py-2 pr-4">{acc.config_version}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
