'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/lib/auth/context';

interface CreateFeeRulePayload {
  transaction_type: string;
  fee_type: 'fixed' | 'percentage';
  rate?: number;
  amount_minor?: number;
  currency: string;
  subject_type: 'global' | 'customer' | 'agent';
  subject_id?: string;
  valid_from: string;
  valid_to?: string;
  min_fee_minor?: number;
  max_fee_minor?: number;
}

const TRANSACTION_TYPES = [
  'transfer', 'funding', 'withdrawal', 'bill_payment',
  'qr_payment', 'cash_in', 'cash_out', 'settlement',
];

export function FeeRuleCreatePage() {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [feeType, setFeeType] = useState<'fixed' | 'percentage'>('percentage');
  const [subjectType, setSubjectType] = useState<'global' | 'customer' | 'agent'>('global');
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [conflictError, setConflictError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!authenticatedClient) return;

    const form = new FormData(e.currentTarget);
    const payload: CreateFeeRulePayload = {
      transaction_type: form.get('transaction_type') as string,
      fee_type:         feeType,
      currency:         (form.get('currency') as string) || 'BWP',
      subject_type:     subjectType,
      valid_from:       form.get('valid_from') as string,
    };

    if (feeType === 'percentage') {
      payload.rate = Number(form.get('rate'));
      const min = form.get('min_fee_minor') as string;
      const max = form.get('max_fee_minor') as string;
      if (min) payload.min_fee_minor = Number(min);
      if (max) payload.max_fee_minor = Number(max);
    } else {
      payload.amount_minor = Number(form.get('amount_minor'));
    }

    if (subjectType !== 'global') {
      payload.subject_id = form.get('subject_id') as string;
    }

    const validTo = form.get('valid_to') as string;
    if (validTo) payload.valid_to = validTo;

    setSubmitting(true);
    setErrors({});
    setConflictError(null);
    try {
      const res = await authenticatedClient.post<{ data: { id: string } }>('/v1/fees/rules', payload, {
        idempotencyKey: crypto.randomUUID(),
      });
      const createdId = res.data.data.id;
      router.push(`/fees/rules/${createdId}`);
    } catch (err: unknown) {
      const code = (err as { error?: { code?: string } })?.error?.code;
      if (code === 'FEE_RULE_CONFLICT') {
        setConflictError(
          'A conflicting active fee rule already exists for this transaction type, currency, and subject scope.'
        );
      } else {
        const apiErrors = (err as { response?: { data?: { errors?: Record<string, string[]> } } })
          ?.response?.data?.errors ?? {};
        const flat: Record<string, string> = {};
        for (const [field, msgs] of Object.entries(apiErrors)) {
          flat[field] = Array.isArray(msgs) ? (msgs[0] ?? '') : String(msgs);
        }
        setErrors(flat);
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <PageContainer>
        <PageHeader title="Create Fee Rule" />

      {conflictError && (
        <div
          role="alert"
          className="mb-4 flex items-start gap-3 rounded border border-ok-error-border bg-ok-error-bg px-4 py-3"
        >
          <svg
            aria-hidden="true"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="mt-0.5 shrink-0 text-ok-error-fg"
          >
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
          <p className="text-sm text-ok-error-fg">{conflictError}</p>
        </div>
      )}

      <Card>
        <form onSubmit={(e) => void handleSubmit(e)} className="space-y-5 p-2" noValidate>
          <div>
            <label htmlFor="transaction_type" className="block text-sm font-medium text-text-primary mb-1">
              Transaction Type
            </label>
            <select
              id="transaction_type"
              name="transaction_type"
              required
              className="w-full rounded border border-border px-3 py-2 text-sm bg-surface"
            >
              {TRANSACTION_TYPES.map((t) => (
                <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
              ))}
            </select>
            {errors.transaction_type && (
              <p className="mt-1 text-sm text-error">{errors.transaction_type}</p>
            )}
          </div>

          <fieldset>
            <legend className="text-sm font-medium text-text-primary mb-2">Fee Type</legend>
            <div className="flex gap-4">
              {(['percentage', 'fixed'] as const).map((t) => (
                <label key={t} className="flex items-center gap-2 text-sm cursor-pointer">
                  <input
                    type="radio"
                    name="fee_type"
                    value={t}
                    checked={feeType === t}
                    onChange={() => setFeeType(t)}
                  />
                  {t.charAt(0).toUpperCase() + t.slice(1)}
                </label>
              ))}
            </div>
          </fieldset>

          {feeType === 'percentage' && (
            <>
              <div>
                <label htmlFor="rate" className="block text-sm font-medium text-text-primary mb-1">
                  Rate (%)
                </label>
                <input
                  id="rate"
                  name="rate"
                  type="number"
                  step="0.01"
                  min="0.01"
                  max="100"
                  required
                  className="w-full rounded border border-border px-3 py-2 text-sm bg-surface"
                  placeholder="0.50"
                />
                {errors.rate && <p className="mt-1 text-sm text-error">{errors.rate}</p>}
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="min_fee_minor" className="block text-sm font-medium text-text-primary mb-1">
                    Min Fee (minor units)
                  </label>
                  <input
                    id="min_fee_minor"
                    name="min_fee_minor"
                    type="number"
                    min="1"
                    className="w-full rounded border border-border px-3 py-2 text-sm bg-surface"
                    placeholder="100"
                  />
                  {errors.min_fee_minor && <p className="mt-1 text-sm text-error">{errors.min_fee_minor}</p>}
                </div>
                <div>
                  <label htmlFor="max_fee_minor" className="block text-sm font-medium text-text-primary mb-1">
                    Max Fee (minor units)
                  </label>
                  <input
                    id="max_fee_minor"
                    name="max_fee_minor"
                    type="number"
                    min="1"
                    className="w-full rounded border border-border px-3 py-2 text-sm bg-surface"
                    placeholder="5000"
                  />
                  {errors.max_fee_minor && <p className="mt-1 text-sm text-error">{errors.max_fee_minor}</p>}
                </div>
              </div>
            </>
          )}

          {feeType === 'fixed' && (
            <div>
              <label htmlFor="amount_minor" className="block text-sm font-medium text-text-primary mb-1">
                Amount (minor units)
              </label>
              <input
                id="amount_minor"
                name="amount_minor"
                type="number"
                min="1"
                required
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface"
                placeholder="150"
              />
              {errors.amount_minor && <p className="mt-1 text-sm text-error">{errors.amount_minor}</p>}
            </div>
          )}

          <div>
            <label htmlFor="currency" className="block text-sm font-medium text-text-primary mb-1">
              Currency
            </label>
            <input
              id="currency"
              name="currency"
              type="text"
              defaultValue="BWP"
              maxLength={3}
              className="w-full rounded border border-border px-3 py-2 text-sm bg-surface"
            />
          </div>

          <fieldset>
            <legend className="text-sm font-medium text-text-primary mb-2">Subject Type</legend>
            <div className="flex gap-4">
              {(['global', 'customer', 'agent'] as const).map((s) => (
                <label key={s} className="flex items-center gap-2 text-sm cursor-pointer">
                  <input
                    type="radio"
                    name="subject_type"
                    value={s}
                    checked={subjectType === s}
                    onChange={() => setSubjectType(s)}
                  />
                  {s.charAt(0).toUpperCase() + s.slice(1)}
                </label>
              ))}
            </div>
          </fieldset>

          {subjectType !== 'global' && (
            <div>
              <label htmlFor="subject_id" className="block text-sm font-medium text-text-primary mb-1">
                {subjectType === 'agent' ? 'Agent ID' : 'Customer ID'} (UUID)
              </label>
              <input
                id="subject_id"
                name="subject_id"
                type="text"
                required
                pattern="[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface font-mono"
                placeholder="00000000-0000-0000-0000-000000000000"
              />
              {errors.subject_id && <p className="mt-1 text-sm text-error">{errors.subject_id}</p>}
            </div>
          )}

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label htmlFor="valid_from" className="block text-sm font-medium text-text-primary mb-1">
                Valid From
              </label>
              <input
                id="valid_from"
                name="valid_from"
                type="datetime-local"
                required
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface"
              />
              {errors.valid_from && <p className="mt-1 text-sm text-error">{errors.valid_from}</p>}
            </div>
            <div>
              <label htmlFor="valid_to" className="block text-sm font-medium text-text-primary mb-1">
                Valid To (optional)
              </label>
              <input
                id="valid_to"
                name="valid_to"
                type="datetime-local"
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface"
              />
              {errors.valid_to && <p className="mt-1 text-sm text-error">{errors.valid_to}</p>}
            </div>
          </div>

          <div className="flex gap-3 pt-2">
            <Button type="submit" variant="primary" disabled={submitting}>
              {submitting ? 'Creating…' : 'Create Rule'}
            </Button>
            <Button
              type="button"
              variant="secondary"
              onClick={() => router.push('/fees/rules')}
            >
              Cancel
            </Button>
          </div>
        </form>
      </Card>
    </PageContainer>
  );
}
