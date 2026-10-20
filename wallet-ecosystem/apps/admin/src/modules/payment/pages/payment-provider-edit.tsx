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
import type { PaymentProvider, UpdateProviderPayload } from '@/lib/api/payment';
import { MockBannerAlert } from '../components/MockBannerAlert';
import { CredentialRotationForm } from '../components/CredentialRotationForm';

// T074: Payment provider edit page — high-risk fields show approval-required warning

interface Props {
  providerId: string;
}

export function PaymentProviderEditPage({ providerId }: Props) {
  const { authenticatedClient, hasPermission } = useAuth();
  const router = useRouter();

  const [provider, setProvider] = useState<PaymentProvider | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const canApproveHighRisk = hasPermission('payment.config.approve_high_risk');

  const [form, setForm] = useState<UpdateProviderPayload>({});

  const fetchProvider = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      const res = await api.getProvider(providerId);
      const p = res.data?.data ?? null;
      setProvider(p);
      if (p) {
        setForm({
          display_name: p.display_name,
          is_enabled: p.is_enabled,
          is_default: p.is_default,
          sort_order: p.sort_order,
        });
      }
    } catch {
      setError('Failed to load provider.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, providerId]);

  useEffect(() => { void fetchProvider(); }, [fetchProvider]);

  function set<K extends keyof UpdateProviderPayload>(key: K, value: UpdateProviderPayload[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!authenticatedClient) return;

    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      const res = await api.updateProvider(providerId, form);
      const body = res.data as { requires_approval?: boolean; message?: string } | undefined;

      if (body?.requires_approval) {
        setError(`${body.message ?? 'High-risk fields require approval.'} Please use the approval workflow.`);
        return;
      }

      setSaved(true);
    } catch {
      setError('Failed to save changes.');
    } finally {
      setSaving(false);
    }
  }

  async function handleRotateCredential(newSecretReference: string) {
    if (!authenticatedClient || !provider) return;
    const api = new PaymentApiClient(authenticatedClient);
    // For simplicity use the first account — in a real UI you'd select the account
    await api.rotateCredential(provider.id, 'default', { new_secret_reference: newSecretReference });
  }

  if (loading) return <Loading label="Loading provider…" />;
  if (error && !provider) return <ErrorState message={error} onRetry={() => void fetchProvider()} />;
  if (!provider) return null;

  return (
    <PageContainer>
      <PageHeader
        title={`Edit: ${provider.display_name}`}
        description={provider.code}
      />

      {provider.environment === 'mock' && (
        <div className="mb-4">
          <MockBannerAlert providerCode={provider.code} />
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="text-sm font-semibold text-text-primary mb-4">Provider Settings</h2>
          <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
            <div>
              <label htmlFor="display_name" className="block text-sm font-medium text-text-primary mb-1">
                Display Name
              </label>
              <input
                id="display_name"
                type="text"
                value={form.display_name ?? ''}
                onChange={(e) => set('display_name', e.target.value)}
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                maxLength={128}
                disabled={saving}
              />
            </div>

            <div>
              <label htmlFor="sort_order" className="block text-sm font-medium text-text-primary mb-1">
                Sort Order
              </label>
              <input
                id="sort_order"
                type="number"
                value={form.sort_order ?? 99}
                onChange={(e) => set('sort_order', parseInt(e.target.value, 10))}
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                min={0}
                max={9999}
                disabled={saving}
              />
            </div>

            <div className="space-y-2">
              <label className="flex items-center gap-2 text-sm cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.is_enabled ?? false}
                  onChange={(e) => set('is_enabled', e.target.checked)}
                  disabled={saving}
                />
                Enabled
              </label>
              <label className="flex items-center gap-2 text-sm cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.is_default ?? false}
                  onChange={(e) => set('is_default', e.target.checked)}
                  disabled={saving}
                />
                Default provider
              </label>
            </div>

            {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
            {saved && <p role="status" className="text-sm text-green-700">Changes saved successfully.</p>}

            <div className="flex gap-3">
              <Button type="submit" variant="primary" disabled={saving}>
                {saving ? 'Saving…' : 'Save Changes'}
              </Button>
              <Button
                type="button"
                variant="ghost"
                onClick={() => router.push(`/payment/providers/${provider.id}`)}
                disabled={saving}
              >
                Cancel
              </Button>
            </div>
          </form>
        </Card>

        {canApproveHighRisk && (
          <Card>
            <h2 className="text-sm font-semibold text-text-primary mb-4">Credential Rotation</h2>
            <div className="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 mb-4">
              High-risk operation — requires <strong>payment.config.approve_high_risk</strong> permission.
              Only the secret reference path is stored here. The actual secret value is never shown or stored.
            </div>
            <CredentialRotationForm
              providerId={provider.id}
              accountId="primary"
              onRotate={(ref) => handleRotateCredential(ref)}
            />
          </Card>
        )}
      </div>
    </PageContainer>
  );
}
