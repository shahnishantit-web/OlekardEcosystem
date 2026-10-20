'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth/context';

interface PartnerConnector {
  id: string;
  name: string;
  adapter_class: string;
  organisation_id: string;
  is_active: boolean;
  is_sandbox: boolean;
  disabled_at: string | null;
  disable_reason: string | null;
  secret_rotated_at: string | null;
}

export function PartnerConnectorListPage() {
  const { authenticatedClient: client } = useAuth();
  const [loading, setLoading]           = useState(false);
  const [connectors, setConnectors]     = useState<PartnerConnector[]>([]);
  const [error, setError]               = useState<string | null>(null);
  const [disableModal, setDisableModal] = useState<PartnerConnector | null>(null);
  const [disableReason, setDisableReason] = useState('');
  const [submitting, setSubmitting]     = useState(false);

  async function loadConnectors() {
    if (!client) return;
    setLoading(true);
    setError(null);
    try {
      const resp = await client.get('/v1/community/admin/partner-connectors');
      const data = resp.data as { data: PartnerConnector[] };
      setConnectors(data.data ?? []);
    } catch {
      setError('Failed to load partner connectors.');
    } finally {
      setLoading(false);
    }
  }

  async function handleDisable(connector: PartnerConnector) {
    if (!client || !disableReason.trim()) return;
    setSubmitting(true);
    try {
      await client.post(`/v1/community/admin/partner-connectors/${connector.id}/disable`, {
        reason: disableReason,
      });
      setDisableModal(null);
      setDisableReason('');
      await loadConnectors();
    } catch {
      setError('Failed to disable connector.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleRotateSecret(connectorId: string) {
    if (!client) return;
    const newPath = prompt('Enter the new vault path for the rotated secret:');
    if (!newPath) return;
    try {
      await client.post(`/v1/community/admin/partner-connectors/${connectorId}/rotate-secret`, {
        new_vault_path: newPath,
      });
      await loadConnectors();
    } catch {
      setError('Failed to rotate secret.');
    }
  }

  return (
    <main className="p-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Partner Connectors</h1>
          <p className="text-sm text-gray-500 mt-1">
            Register and manage payment provider adapters. Secrets are stored by vault path reference only.
          </p>
        </div>
        <button
          type="button"
          onClick={loadConnectors}
          disabled={loading || !client}
          className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
          aria-label="Load partner connectors"
        >
          {loading ? 'Loading…' : 'Load Connectors'}
        </button>
      </div>

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
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Name</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Adapter</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Environment</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Secret Rotated</th>
              <th scope="col" className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 bg-white">
            {connectors.length === 0 && !loading && (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-gray-400">
                  No connectors found. Click Load Connectors.
                </td>
              </tr>
            )}
            {connectors.map((c) => (
              <tr key={c.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-900">{c.name}</td>
                <td className="px-4 py-3 text-gray-500 font-mono text-xs max-w-xs truncate" title={c.adapter_class}>
                  {c.adapter_class.split('\\').pop()}
                </td>
                <td className="px-4 py-3">
                  <span
                    className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                      c.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700'
                    }`}
                    aria-label={`Status: ${c.is_active ? 'Active' : 'Inactive'}`}
                  >
                    {c.is_active ? 'Active' : 'Inactive'}
                  </span>
                </td>
                <td className="px-4 py-3">
                  {c.is_sandbox && (
                    <span
                      className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800"
                      aria-label="Sandbox environment"
                    >
                      Sandbox
                    </span>
                  )}
                  {!c.is_sandbox && (
                    <span
                      className="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800"
                      aria-label="Production environment"
                    >
                      Production
                    </span>
                  )}
                </td>
                <td className="px-4 py-3 text-gray-500 text-xs">
                  {c.secret_rotated_at
                    ? new Date(c.secret_rotated_at).toLocaleDateString()
                    : '—'}
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex items-center justify-end gap-2">
                    <button
                      type="button"
                      onClick={() => handleRotateSecret(c.id)}
                      className="rounded border border-gray-300 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400"
                      aria-label={`Rotate secret for ${c.name}`}
                    >
                      Rotate Secret
                    </button>
                    {c.is_active && (
                      <button
                        type="button"
                        onClick={() => setDisableModal(c)}
                        className="rounded border border-red-300 px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                        aria-label={`Disable connector ${c.name}`}
                      >
                        Disable
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Disable confirmation dialog */}
      {disableModal && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="disable-dialog-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
          <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <h2 id="disable-dialog-title" className="text-lg font-semibold text-gray-900 mb-2">
              Disable Connector
            </h2>
            <p className="text-sm text-gray-600 mb-4">
              Disabling <strong>{disableModal.name}</strong> will prevent new webhook
              processing and payment initiations for this connector.
            </p>
            <div className="mb-4">
              <label htmlFor="disable-reason" className="block text-xs font-medium text-gray-700 mb-1">
                Reason <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="disable-reason"
                rows={3}
                required
                value={disableReason}
                onChange={(e) => setDisableReason(e.target.value)}
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Provide a reason for disabling this connector..."
              />
            </div>
            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => { setDisableModal(null); setDisableReason(''); }}
                disabled={submitting}
                className="rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={() => handleDisable(disableModal)}
                disabled={submitting || !disableReason.trim()}
                className="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                aria-label={`Confirm disable ${disableModal.name}`}
              >
                {submitting ? 'Disabling…' : 'Confirm Disable'}
              </button>
            </div>
          </div>
        </div>
      )}
    </main>
  );
}

export default PartnerConnectorListPage;
