'use client';

// T093: Form editor for a single API operation config.
import { useState } from 'react';
import type { ApiOperationConfig } from '@/lib/api/payments/banks';

const OPERATION_TYPES = ['initiate', 'query', 'cancel', 'full_refund', 'partial_refund', 'callback', 'reconciliation'] as const;
const HTTP_METHODS    = ['GET', 'POST', 'PUT', 'PATCH'] as const;

interface Props {
  initial?: Partial<ApiOperationConfig>;
  onChange: (value: Partial<ApiOperationConfig>) => void;
}

export function ApiOperationEditor({ initial = {}, onChange }: Props) {
  const [form, setForm] = useState<Partial<ApiOperationConfig>>(initial);
  const [urlError, setUrlError] = useState<string | null>(null);

  const update = (patch: Partial<ApiOperationConfig>) => {
    const next = { ...form, ...patch };
    setForm(next);
    onChange(next);
  };

  const validateUrl = (url: string) => {
    if (!url.startsWith('https://')) {
      setUrlError('URL must use HTTPS');
    } else {
      setUrlError(null);
    }
  };

  return (
    <div className="space-y-4 rounded-lg border border-gray-200 p-4">
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Operation Type</label>
          <select
            value={form.operation_type ?? ''}
            onChange={e => update({ operation_type: e.target.value })}
            className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">Select…</option>
            {OPERATION_TYPES.map(t => (
              <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">HTTP Method</label>
          <select
            value={form.http_method ?? ''}
            onChange={e => update({ http_method: e.target.value as typeof HTTP_METHODS[number] })}
            className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">Select…</option>
            {HTTP_METHODS.map(m => (
              <option key={m} value={m}>{m}</option>
            ))}
          </select>
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Base URL
          {urlError && (
            <span className="ml-2 text-xs text-red-600">{urlError}</span>
          )}
        </label>
        <div className="flex items-center gap-2">
          <input
            type="url"
            value={form.base_url ?? ''}
            onChange={e => { update({ base_url: e.target.value }); validateUrl(e.target.value); }}
            onBlur={e => validateUrl(e.target.value)}
            placeholder="https://api.provider.com"
            className={`flex-1 rounded border px-3 py-2 text-sm ${urlError ? 'border-red-400' : 'border-gray-300'}`}
          />
          {form.base_url?.startsWith('https://') && (
            <span className="text-xs text-green-600 font-medium">✓ HTTPS</span>
          )}
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Endpoint Path</label>
        <input
          type="text"
          value={form.endpoint_path ?? ''}
          onChange={e => update({ endpoint_path: e.target.value })}
          placeholder="/v1/payments/initiate"
          className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
        />
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Timeout (ms) — max 120,000
        </label>
        <input
          type="number"
          min={100}
          max={120000}
          value={form.timeout_ms ?? 30000}
          onChange={e => update({ timeout_ms: parseInt(e.target.value, 10) })}
          className="w-40 rounded border border-gray-300 px-3 py-2 text-sm"
        />
      </div>
    </div>
  );
}
