'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import type { CreateProviderPayload, ProviderEnvironment } from '@/lib/api/payment';

// T073: Payment provider create page — requires payment.providers.manage

const ENVIRONMENTS: ProviderEnvironment[] = ['sandbox', 'production', 'mock'];

export function PaymentProviderCreatePage() {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [form, setForm] = useState<CreateProviderPayload>({
    code: '',
    display_name: '',
    adapter_identifier: '',
    adapter_version: '1.0.0',
    environment: 'sandbox',
    is_default: false,
    sort_order: 99,
  });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function set<K extends keyof CreateProviderPayload>(key: K, value: CreateProviderPayload[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!authenticatedClient) return;

    setLoading(true);
    setError(null);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      const res = await api.createProvider(form);
      const id = res.data?.data?.id;
      if (id) {
        router.push(`/payment/providers/${id}`);
      } else {
        router.push('/payment/providers');
      }
    } catch {
      setError('Failed to create provider. Please check all fields and try again.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <PageContainer>
      <PageHeader title="Add Payment Provider" />

      <Card>
        <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4 max-w-lg">
          <div>
            <label htmlFor="code" className="block text-sm font-medium text-text-primary mb-1">
              Provider Code <span aria-hidden="true">*</span>
            </label>
            <input
              id="code"
              type="text"
              value={form.code}
              onChange={(e) => set('code', e.target.value)}
              placeholder="e.g. bank_abc"
              className="w-full rounded border border-border px-3 py-2 text-sm font-mono bg-surface text-text-primary"
              required
              maxLength={64}
              disabled={loading}
            />
          </div>

          <div>
            <label htmlFor="display_name" className="block text-sm font-medium text-text-primary mb-1">
              Display Name <span aria-hidden="true">*</span>
            </label>
            <input
              id="display_name"
              type="text"
              value={form.display_name}
              onChange={(e) => set('display_name', e.target.value)}
              placeholder="e.g. ABC Bank"
              className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
              required
              maxLength={128}
              disabled={loading}
            />
          </div>

          <div>
            <label htmlFor="adapter_identifier" className="block text-sm font-medium text-text-primary mb-1">
              Adapter Identifier <span aria-hidden="true">*</span>
            </label>
            <input
              id="adapter_identifier"
              type="text"
              value={form.adapter_identifier}
              onChange={(e) => set('adapter_identifier', e.target.value)}
              placeholder="e.g. mock_bank"
              className="w-full rounded border border-border px-3 py-2 text-sm font-mono bg-surface text-text-primary"
              required
              maxLength={128}
              disabled={loading}
            />
          </div>

          <div>
            <label htmlFor="environment" className="block text-sm font-medium text-text-primary mb-1">
              Environment <span aria-hidden="true">*</span>
            </label>
            <select
              id="environment"
              value={form.environment}
              onChange={(e) => set('environment', e.target.value as ProviderEnvironment)}
              className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
              disabled={loading}
            >
              {ENVIRONMENTS.map((env) => (
                <option key={env} value={env}>{env}</option>
              ))}
            </select>
            {form.environment === 'mock' && (
              <p className="mt-1 text-xs text-red-700 font-medium">
                Mock providers are permanently blocked in production.
              </p>
            )}
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
              disabled={loading}
            />
          </div>

          <label className="flex items-center gap-2 text-sm cursor-pointer">
            <input
              type="checkbox"
              checked={form.is_default ?? false}
              onChange={(e) => set('is_default', e.target.checked)}
              disabled={loading}
            />
            Set as default provider
          </label>

          <p className="text-xs text-text-secondary">
            Provider will be created in <strong>disabled</strong> state. Enable it after completing configuration.
          </p>

          {error && <p role="alert" className="text-sm text-red-700">{error}</p>}

          <div className="flex gap-3">
            <Button type="submit" variant="primary" disabled={loading}>
              {loading ? 'Creating…' : 'Create Provider'}
            </Button>
            <Button
              type="button"
              variant="ghost"
              onClick={() => router.push('/payment/providers')}
              disabled={loading}
            >
              Cancel
            </Button>
          </div>
        </form>
      </Card>
    </PageContainer>
  );
}
