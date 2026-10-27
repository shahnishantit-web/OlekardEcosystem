'use client';

import { useState } from 'react';
import { SecretReferenceField } from '@/app/(admin)/payments/components/SecretReferenceField';
import type { PaymentProviderAccountDetail, UpdateAccountPayload } from '@/lib/api/payment';

interface Props {
  account: PaymentProviderAccountDetail;
  adapterOptions?: string[];
  readOnly?: boolean;
  onSubmit: (payload: UpdateAccountPayload) => void;
  submitting?: boolean;
}

export function ProviderAccountForm({ account, adapterOptions = [], readOnly = false, onSubmit, submitting = false }: Props) {
  const [maxAmount, setMaxAmount] = useState(String(account.max_amount_minor));
  const [minAmount, setMinAmount] = useState(String(account.min_amount_minor));
  const [dailyLimit, setDailyLimit] = useState(String(account.daily_limit_minor ?? ''));
  const [displayLabel, setDisplayLabel] = useState(account.display_label ?? '');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const payload: Parameters<typeof onSubmit>[0] = {
      max_amount_minor: parseInt(maxAmount, 10),
      min_amount_minor: parseInt(minAmount, 10),
      daily_limit_minor: dailyLimit ? parseInt(dailyLimit, 10) : null,
      config_version: account.config_version,
    };
    if (displayLabel) payload.display_label = displayLabel;
    onSubmit(payload);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <label htmlFor="max_amount_minor" className="block text-sm font-medium mb-1">Max Amount (minor units)</label>
        <input
          id="max_amount_minor"
          name="max_amount_minor"
          type="number"
          value={maxAmount}
          onChange={(e) => setMaxAmount(e.target.value)}
          disabled={readOnly}
          className="border rounded px-3 py-1.5 w-full text-sm"
          aria-label="Maximum amount in minor units"
        />
      </div>
      <div>
        <label htmlFor="min_amount_minor" className="block text-sm font-medium mb-1">Min Amount (minor units)</label>
        <input
          id="min_amount_minor"
          name="min_amount_minor"
          type="number"
          value={minAmount}
          onChange={(e) => setMinAmount(e.target.value)}
          disabled={readOnly}
          className="border rounded px-3 py-1.5 w-full text-sm"
          aria-label="Minimum amount in minor units"
        />
      </div>
      <div>
        <label htmlFor="daily_limit_minor" className="block text-sm font-medium mb-1">Daily Limit (minor units)</label>
        <input
          id="daily_limit_minor"
          name="daily_limit_minor"
          type="number"
          value={dailyLimit}
          onChange={(e) => setDailyLimit(e.target.value)}
          disabled={readOnly}
          className="border rounded px-3 py-1.5 w-full text-sm"
          aria-label="Daily limit in minor units"
        />
      </div>
      <div>
        <label htmlFor="display_label" className="block text-sm font-medium mb-1">Display Label</label>
        <input
          id="display_label"
          name="display_label"
          type="text"
          value={displayLabel}
          onChange={(e) => setDisplayLabel(e.target.value)}
          disabled={readOnly}
          className="border rounded px-3 py-1.5 w-full text-sm"
          aria-label="Display label"
        />
      </div>
      <div>
        <SecretReferenceField
          label="Callback Secret Reference"
          value={account.callback_secret_reference}
        />
        {!readOnly && account.callback_secret_reference && (
          <button type="button" className="mt-1 text-xs underline" aria-label="Change credential reference">
            Change reference
          </button>
        )}
      </div>
      {/* callback_url intentionally not rendered */}
      {adapterOptions.length > 0 && (
        <div>
          <label htmlFor="adapter_identifier" className="block text-sm font-medium mb-1">Adapter</label>
          <select
            id="adapter_identifier"
            name="adapter_identifier"
            disabled={readOnly}
            className="border rounded px-3 py-1.5 w-full text-sm"
            aria-label="Adapter identifier"
          >
            {adapterOptions.map((opt) => (
              <option key={opt} value={opt}>{opt}</option>
            ))}
          </select>
        </div>
      )}
      <input type="hidden" name="config_version" value={account.config_version} />
      {!readOnly && (
        <button
          type="submit"
          disabled={submitting}
          className="bg-primary text-primary-foreground px-4 py-2 rounded text-sm disabled:opacity-50"
          aria-label="Save account configuration"
        >
          {submitting ? 'Saving...' : 'Save Changes'}
        </button>
      )}
    </form>
  );
}
