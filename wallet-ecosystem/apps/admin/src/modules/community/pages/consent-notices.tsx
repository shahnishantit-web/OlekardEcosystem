'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth/context';

type ConsentPurpose = 'operational' | 'marketing' | 'sponsor_sharing' | 'data_processing';

interface ConsentNotice {
  id: string;
  purpose: ConsentPurpose;
  version: string;
  title: string;
  body_markdown: string;
  locale: string;
  effective_from: string;
  effective_until: string | null;
  is_active: boolean;
  created_at: string;
}



const PURPOSE_LABELS: Record<ConsentPurpose, string> = {
  operational: 'Operational',
  marketing: 'Marketing',
  sponsor_sharing: 'Sponsor Sharing',
  data_processing: 'Data Processing',
};

const PURPOSE_OPTIONS: ConsentPurpose[] = [
  'operational',
  'marketing',
  'sponsor_sharing',
  'data_processing',
];

export function ConsentNoticesPage() {
  const { authenticatedClient: client } = useAuth();
  const [purposeFilter, setPurposeFilter] = useState<ConsentPurpose | 'all'>('all');
  const [localeFilter, setLocaleFilter] = useState('');
  const [loading, setLoading] = useState(false);
  const [notices, setNotices] = useState<ConsentNotice[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const [form, setForm] = useState({
    purpose: 'operational' as ConsentPurpose,
    version: '',
    title: '',
    body_markdown: '',
    locale: 'en',
    effective_from: '',
    effective_until: '',
  });

  async function loadNotices() {
    if (!client) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (purposeFilter !== 'all') params.set('purpose', purposeFilter);
      if (localeFilter) params.set('locale', localeFilter);
      const resp = await client.get(`/v1/community/admin/consent-notices?${params}`);
      const data = resp.data as { data: ConsentNotice[] };
      setNotices(data.data ?? []);
    } catch {
      setError('Failed to load consent notices.');
    } finally {
      setLoading(false);
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!client) return;
    setSubmitting(true);
    setError(null);
    try {
      await client.post('/v1/community/admin/consent-notices', {
        ...form,
        effective_until: form.effective_until || null,
      });
      setShowForm(false);
      setForm({
        purpose: 'operational',
        version: '',
        title: '',
        body_markdown: '',
        locale: 'en',
        effective_from: '',
        effective_until: '',
      });
      await loadNotices();
    } catch {
      setError('Failed to create consent notice.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="p-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Consent Notices</h1>
          <p className="text-sm text-gray-500 mt-1">
            Versioned consent notices per purpose and locale
          </p>
        </div>
        <button
          type="button"
          onClick={() => setShowForm((v) => !v)}
          className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
          aria-expanded={showForm}
          aria-controls="new-notice-form"
        >
          {showForm ? 'Cancel' : 'New Notice'}
        </button>
      </div>

      {/* New notice form */}
      {showForm && (
        <form
          id="new-notice-form"
          onSubmit={handleSubmit}
          className="mb-8 rounded-lg border border-blue-200 bg-blue-50 p-6"
          aria-label="Create new consent notice"
        >
          <h2 className="text-lg font-semibold text-gray-800 mb-4">New Consent Notice</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="notice-purpose" className="block text-xs font-medium text-gray-700 mb-1">
                Purpose <span aria-hidden="true">*</span>
              </label>
              <select
                id="notice-purpose"
                required
                value={form.purpose}
                onChange={(e) => setForm((f) => ({ ...f, purpose: e.target.value as ConsentPurpose }))}
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                {PURPOSE_OPTIONS.map((p) => (
                  <option key={p} value={p}>{PURPOSE_LABELS[p]}</option>
                ))}
              </select>
            </div>

            <div>
              <label htmlFor="notice-version" className="block text-xs font-medium text-gray-700 mb-1">
                Version <span aria-hidden="true">*</span>
              </label>
              <input
                id="notice-version"
                type="text"
                required
                placeholder="e.g. 2.0.0"
                value={form.version}
                onChange={(e) => setForm((f) => ({ ...f, version: e.target.value }))}
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label htmlFor="notice-locale" className="block text-xs font-medium text-gray-700 mb-1">
                Locale <span aria-hidden="true">*</span>
              </label>
              <input
                id="notice-locale"
                type="text"
                required
                placeholder="en"
                value={form.locale}
                onChange={(e) => setForm((f) => ({ ...f, locale: e.target.value }))}
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label htmlFor="notice-title" className="block text-xs font-medium text-gray-700 mb-1">
                Title <span aria-hidden="true">*</span>
              </label>
              <input
                id="notice-title"
                type="text"
                required
                value={form.title}
                onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label htmlFor="notice-effective-from" className="block text-xs font-medium text-gray-700 mb-1">
                Effective From <span aria-hidden="true">*</span>
              </label>
              <input
                id="notice-effective-from"
                type="datetime-local"
                required
                value={form.effective_from}
                onChange={(e) => setForm((f) => ({ ...f, effective_from: e.target.value }))}
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label htmlFor="notice-effective-until" className="block text-xs font-medium text-gray-700 mb-1">
                Effective Until (optional)
              </label>
              <input
                id="notice-effective-until"
                type="datetime-local"
                value={form.effective_until}
                onChange={(e) => setForm((f) => ({ ...f, effective_until: e.target.value }))}
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div className="sm:col-span-2">
              <label htmlFor="notice-body" className="block text-xs font-medium text-gray-700 mb-1">
                Body (Markdown) <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="notice-body"
                required
                rows={6}
                value={form.body_markdown}
                onChange={(e) => setForm((f) => ({ ...f, body_markdown: e.target.value }))}
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="# Consent Notice&#10;&#10;By using this service..."
              />
            </div>
          </div>

          <div className="mt-4 flex justify-end gap-3">
            <button
              type="button"
              onClick={() => setShowForm(false)}
              className="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={submitting}
              className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              {submitting ? 'Saving…' : 'Save Notice'}
            </button>
          </div>
        </form>
      )}

      {/* Filters */}
      <section aria-label="Filter consent notices" className="flex flex-wrap gap-4 mb-6">
        <div>
          <label htmlFor="purpose-filter" className="block text-xs font-medium text-gray-700 mb-1">
            Purpose
          </label>
          <select
            id="purpose-filter"
            value={purposeFilter}
            onChange={(e) => setPurposeFilter(e.target.value as ConsentPurpose | 'all')}
            className="rounded border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="all">All purposes</option>
            {PURPOSE_OPTIONS.map((p) => (
              <option key={p} value={p}>{PURPOSE_LABELS[p]}</option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="locale-filter" className="block text-xs font-medium text-gray-700 mb-1">
            Locale
          </label>
          <input
            id="locale-filter"
            type="text"
            placeholder="e.g. en"
            value={localeFilter}
            onChange={(e) => setLocaleFilter(e.target.value)}
            className="rounded border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div className="flex items-end">
          <button
            type="button"
            onClick={loadNotices}
            disabled={loading || !client}
            className="rounded bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
            aria-label="Apply filters and load consent notices"
          >
            {loading ? 'Loading…' : 'Load Notices'}
          </button>
        </div>
      </section>

      {error && (
        <div role="alert" className="mb-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {/* Table */}
      <div className="overflow-x-auto rounded-lg border border-gray-200">
        <table className="min-w-full divide-y divide-gray-200 text-sm">
          <thead className="bg-gray-50">
            <tr>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Purpose</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Version</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Locale</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Title</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Effective From</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Until</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Active</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 bg-white">
            {notices.length === 0 && !loading && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-gray-400">
                  No consent notices found. Apply filters and click Load Notices.
                </td>
              </tr>
            )}
            {notices.map((n) => (
              <tr key={n.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-900">{PURPOSE_LABELS[n.purpose]}</td>
                <td className="px-4 py-3 text-gray-600 font-mono">{n.version}</td>
                <td className="px-4 py-3 text-gray-600 uppercase">{n.locale}</td>
                <td className="px-4 py-3 text-gray-700">{n.title}</td>
                <td className="px-4 py-3 text-gray-500">
                  {new Date(n.effective_from).toLocaleDateString()}
                </td>
                <td className="px-4 py-3 text-gray-500">
                  {n.effective_until ? new Date(n.effective_until).toLocaleDateString() : '—'}
                </td>
                <td className="px-4 py-3">
                  <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                      n.is_active
                        ? 'bg-green-100 text-green-800'
                        : 'bg-gray-100 text-gray-600'
                    }`}
                    aria-label={n.is_active ? 'Active' : 'Inactive'}
                  >
                    {n.is_active ? 'Active' : 'Inactive'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </main>
  );
}

export default ConsentNoticesPage;
