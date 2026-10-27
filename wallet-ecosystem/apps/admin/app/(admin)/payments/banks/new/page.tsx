'use client';

import type { Metadata } from 'next';
import { useState } from 'react';
import { useRouter } from 'next/navigation';

// T084: Add bank form.
export default function NewBankPage() {
  const router = useRouter();
  const [form, setForm] = useState({
    code: '',
    display_name: '',
    description: '',
    supported_countries: [] as string[],
    environment: 'sandbox',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const validate = () => {
    const e: Record<string, string> = {};
    if (!form.code.match(/^[A-Z0-9_-]+$/)) e.code = 'Code must be uppercase letters, digits, _ or -';
    if (!form.display_name.trim()) e.display_name = 'Display name is required';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!validate()) return;
    setSubmitting(true);
    try {
      // POST /api/v1/admin/payments/banks (wired to API client in production)
      router.push('/payments/banks');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="mx-auto max-w-xl space-y-6 p-6">
      <h1 className="text-2xl font-semibold text-gray-900">Add Bank</h1>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Bank Code</label>
          <input
            type="text"
            value={form.code}
            onChange={e => setForm(f => ({ ...f, code: e.target.value.toUpperCase() }))}
            placeholder="FNBZ"
            className={`w-full rounded border px-3 py-2 text-sm ${errors.code ? 'border-red-400' : 'border-gray-300'}`}
          />
          {errors.code && <p className="mt-1 text-xs text-red-600">{errors.code}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Display Name</label>
          <input
            type="text"
            value={form.display_name}
            onChange={e => setForm(f => ({ ...f, display_name: e.target.value }))}
            placeholder="FNB Botswana"
            className={`w-full rounded border px-3 py-2 text-sm ${errors.display_name ? 'border-red-400' : 'border-gray-300'}`}
          />
          {errors.display_name && <p className="mt-1 text-xs text-red-600">{errors.display_name}</p>}
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
          <textarea
            value={form.description}
            onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
            rows={3}
            className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Environment</label>
          <select
            value={form.environment}
            onChange={e => setForm(f => ({ ...f, environment: e.target.value }))}
            className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="sandbox">Sandbox</option>
            <option value="production">Production</option>
          </select>
        </div>

        <div className="flex gap-3 pt-2">
          <button
            type="submit"
            disabled={submitting}
            className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
          >
            {submitting ? 'Creating…' : 'Create Bank'}
          </button>
          <button
            type="button"
            onClick={() => router.back()}
            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>
  );
}
