'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import type { PaymentProvider } from '@/lib/api/payment';
import { ProviderStatusBadge } from '../components/ProviderStatusBadge';
import { MockBannerAlert } from '../components/MockBannerAlert';

// T071: Payment providers list page — requires payment.providers.read permission

export function PaymentProvidersPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const router = useRouter();

  const [providers, setProviders] = useState<PaymentProvider[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canManage = hasPermission('payment.providers.manage');

  const fetchProviders = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      const res = await api.listProviders();
      setProviders(res.data?.data ?? []);
    } catch {
      setError('Failed to load payment providers.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => { void fetchProviders(); }, [fetchProviders]);

  const hasMockProviders = providers.some((p) => p.environment === 'mock');

  return (
    <PageContainer>
      <PageHeader
        title="Payment Providers"
        actions={
          canManage ? (
            <Button
              variant="primary"
              onClick={() => router.push('/payment/providers/create')}
            >
              Add Provider
            </Button>
          ) : undefined
        }
      />

      {hasMockProviders && (
        <div className="mb-4">
          <MockBannerAlert />
        </div>
      )}

      {loading && <Loading label="Loading payment providers…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchProviders()} />}

      {!loading && !error && (
        <Card>
          <Table caption="Payment providers list">
            <thead>
              <tr className="border-b border-ok-border border-t border-ok-border bg-neutral-50">
                {['Code', 'Display Name', 'Adapter', 'Environment', 'Status', 'Default', ''].map((h) => (
                  <th key={h} scope="col" className="px-4 py-3 text-sm font-medium text-text-secondary">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {providers.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-text-secondary text-sm">
                    No payment providers configured.
                    {canManage && (
                      <button
                        type="button"
                        className="underline text-ok-primary ml-1"
                        onClick={() => router.push('/payment/providers/create')}
                      >
                        Add your first provider
                      </button>
                    )}
                  </td>
                </tr>
              ) : (
                providers.map((p) => (
                  <tr
                    key={p.id}
                    className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg cursor-pointer"
                    onClick={() => router.push(`/payment/providers/${p.id}`)}
                    tabIndex={0}
                    role="row"
                    aria-label={`View provider: ${p.display_name}`}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') router.push(`/payment/providers/${p.id}`);
                    }}
                  >
                    <td className="px-4 py-3 text-sm font-mono text-text-primary">{p.code}</td>
                    <td className="px-4 py-3 text-sm text-text-primary">{p.display_name}</td>
                    <td className="px-4 py-3 text-sm font-mono text-text-secondary">{p.adapter_identifier}</td>
                    <td className="px-4 py-3 text-sm text-text-primary capitalize">{p.environment}</td>
                    <td className="px-4 py-3">
                      <ProviderStatusBadge
                        environment={p.environment}
                        is_enabled={p.is_enabled}
                        is_suspended={p.is_suspended}
                      />
                    </td>
                    <td className="px-4 py-3 text-sm text-text-primary">
                      {p.is_default ? 'Yes' : '—'}
                    </td>
                    <td className="px-4 py-3">
                      {canManage && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={(e) => {
                            e.stopPropagation();
                            router.push(`/payment/providers/${p.id}/edit`);
                          }}
                          aria-label={`Edit ${p.display_name}`}
                        >
                          Edit
                        </Button>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </Table>
        </Card>
      )}
    </PageContainer>
  );
}
