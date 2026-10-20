'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import type { PaymentProvider } from '@/lib/api/payment';
import { ProviderStatusBadge } from '../components/ProviderStatusBadge';
import { MockBannerAlert } from '../components/MockBannerAlert';

// T072: Payment provider detail page

interface Props {
  providerId: string;
}

export function PaymentProviderDetailPage({ providerId }: Props) {
  const { authenticatedClient, hasPermission } = useAuth();
  const router = useRouter();

  const [provider, setProvider] = useState<PaymentProvider | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [suspending, setSuspending] = useState(false);

  const canManage = hasPermission('payment.providers.manage');

  const fetchProvider = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      const res = await api.getProvider(providerId);
      setProvider(res.data?.data ?? null);
    } catch {
      setError('Failed to load provider.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, providerId]);

  useEffect(() => { void fetchProvider(); }, [fetchProvider]);

  async function handleSuspend() {
    if (!authenticatedClient || !provider) return;
    if (!confirm(`Suspend provider "${provider.display_name}"? All pending payments will continue but no new intents will be accepted.`)) return;

    setSuspending(true);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      await api.suspendProvider(provider.id);
      void fetchProvider();
    } catch {
      setError('Failed to suspend provider.');
    } finally {
      setSuspending(false);
    }
  }

  if (loading) return <Loading label="Loading provider…" />;
  if (error) return <ErrorState message={error} onRetry={() => void fetchProvider()} />;
  if (!provider) return null;

  return (
    <PageContainer>
      <PageHeader
        title={provider.display_name}
        description={provider.code}
        actions={
          canManage ? (
            <div className="flex gap-2">
              <Button
                variant="secondary"
                onClick={() => router.push(`/payment/providers/${provider.id}/edit`)}
              >
                Edit
              </Button>
              {!provider.is_suspended && (
                <Button
                  variant="danger"
                  onClick={() => void handleSuspend()}
                  disabled={suspending}
                >
                  {suspending ? 'Suspending…' : 'Suspend'}
                </Button>
              )}
            </div>
          ) : undefined
        }
      />

      {provider.environment === 'mock' && (
        <div className="mb-4">
          <MockBannerAlert providerCode={provider.code} />
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Card>
          <h2 className="text-sm font-semibold text-text-primary mb-3">Provider Details</h2>
          <dl className="space-y-2">
            <DetailRow label="Code" value={<code className="font-mono text-xs">{provider.code}</code>} />
            <DetailRow label="Adapter" value={<code className="font-mono text-xs">{provider.adapter_identifier}</code>} />
            <DetailRow label="Version" value={provider.adapter_version ?? '—'} />
            <DetailRow label="Environment" value={<span className="capitalize">{provider.environment}</span>} />
            <DetailRow
              label="Status"
              value={
                <ProviderStatusBadge
                  environment={provider.environment}
                  is_enabled={provider.is_enabled}
                  is_suspended={provider.is_suspended}
                />
              }
            />
            <DetailRow label="Default" value={provider.is_default ? 'Yes' : 'No'} />
            <DetailRow label="Sort Order" value={String(provider.sort_order)} />
          </dl>
        </Card>

        <Card>
          <h2 className="text-sm font-semibold text-text-primary mb-3">Timestamps</h2>
          <dl className="space-y-2">
            <DetailRow
              label="Created"
              value={new Date(provider.created_at).toLocaleString()}
            />
            <DetailRow
              label="Updated"
              value={provider.updated_at ? new Date(provider.updated_at).toLocaleString() : '—'}
            />
          </dl>
        </Card>
      </div>

      <div className="mt-4">
        <Button variant="ghost" onClick={() => router.push('/payment/providers')}>
          ← Back to providers
        </Button>
      </div>
    </PageContainer>
  );
}

function DetailRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between text-sm">
      <dt className="text-text-secondary">{label}</dt>
      <dd className="text-text-primary font-medium text-right">{value}</dd>
    </div>
  );
}
