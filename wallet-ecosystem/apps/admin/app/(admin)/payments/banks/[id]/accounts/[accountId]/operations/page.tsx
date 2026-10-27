'use client';

import { useState } from 'react';
import { ApiOperationEditor } from '../../../../../components/ApiOperationEditor';
import type { ApiOperationConfig } from '@/lib/api/payments/banks';

// T088: API operation configuration editor.
export default function OperationsPage({
  params,
}: {
  params: { id: string; accountId: string };
}) {
  const [operations, setOperations] = useState<Partial<ApiOperationConfig>[]>([]);
  const [draftCreated, setDraftCreated] = useState(false);
  const [saving, setSaving] = useState(false);

  const handleSave = async () => {
    setSaving(true);
    try {
      // PUT /api/v1/admin/payments/banks/{id}/accounts/{accountId}/operations
      // When active account edited → draft_created = true
      setDraftCreated(true); // mock
    } finally {
      setSaving(false);
    }
  };

  const handleValidate = async () => {
    // POST /api/v1/admin/payments/banks/{id}/accounts/{accountId}/operations/validate
    console.info('Validate operations:', operations);
  };

  return (
    <div className="space-y-6 p-6">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">API Operations</h1>
        <p className="text-sm text-gray-500 mt-1">
          Bank: {params.id} · Account: {params.accountId}
        </p>
      </div>

      {draftCreated && (
        <div className="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
          A new draft was created from the active configuration. The active account is unchanged until the draft is approved and activated.
        </div>
      )}

      {operations.map((op, i) => (
        <ApiOperationEditor
          key={i}
          initial={op}
          onChange={updated => {
            const next = [...operations];
            next[i] = updated;
            setOperations(next);
          }}
        />
      ))}

      <button
        type="button"
        onClick={() => setOperations(o => [...o, {}])}
        className="rounded-md border border-dashed border-gray-300 px-4 py-2 text-sm text-gray-600 hover:border-blue-400"
      >
        + Add Operation
      </button>

      <div className="flex gap-3">
        <button
          type="button"
          onClick={handleSave}
          disabled={saving}
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
        >
          {saving ? 'Saving…' : 'Save Operations'}
        </button>
        <button
          type="button"
          onClick={handleValidate}
          className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700"
        >
          Validate (no save)
        </button>
      </div>
    </div>
  );
}
