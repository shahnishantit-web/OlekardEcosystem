'use client';

import { useState } from 'react';
import type { SandboxTestResult } from '@/lib/api/payments/banks';

// T089: Certification evidence list, upload form, sandbox test trigger.
export default function EvidencePage({
  params,
}: {
  params: { id: string; accountId: string };
}) {
  const [testResult, setTestResult] = useState<SandboxTestResult | null>(null);
  const [testing, setTesting] = useState(false);

  const handleSandboxTest = async () => {
    setTesting(true);
    try {
      // POST /api/v1/admin/payments/banks/{id}/accounts/{accountId}/sandbox-test
      // Mock result
      setTestResult({ result: 'pass', message: 'Endpoint resolved.', duration_ms: 142, evidence_id: 'mock-ev-id' });
    } finally {
      setTesting(false);
    }
  };

  return (
    <div className="space-y-6 p-6">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">Certification Evidence</h1>
        <p className="text-sm text-gray-500 mt-1">
          Bank: {params.id} · Account: {params.accountId}
        </p>
      </div>

      {/* Evidence gate indicator */}
      <div className="rounded-lg border border-gray-200 bg-white p-4 space-y-2">
        <p className="text-sm font-medium text-gray-900">Required Evidence</p>
        <ul className="space-y-1">
          {['documentation', 'merchant_ownership'].map(type => (
            <li key={type} className="flex items-center gap-2 text-sm text-gray-600">
              <span className="inline-block h-2 w-2 rounded-full bg-gray-300" />
              {type.replace(/_/g, ' ')} — not yet submitted
            </li>
          ))}
        </ul>
      </div>

      {/* Sandbox test */}
      <div className="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
        <p className="text-sm font-medium text-gray-900">Sandbox Connectivity Test</p>
        <p className="text-xs text-gray-500">
          Runs a status-query probe only — never initiates a real payment (SC-007).
        </p>
        <button
          type="button"
          onClick={handleSandboxTest}
          disabled={testing}
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
        >
          {testing ? 'Testing…' : 'Run Sandbox Test'}
        </button>
        {testResult && (
          <div className={`rounded border px-3 py-2 text-sm ${testResult.result === 'pass' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'}`}>
            {testResult.result.toUpperCase()}: {testResult.message} ({testResult.duration_ms} ms)
          </div>
        )}
      </div>

      {/* Evidence upload */}
      <div className="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
        <p className="text-sm font-medium text-gray-900">Upload Evidence</p>
        <p className="text-sm text-gray-500">Evidence upload form requires API connection.</p>
      </div>
    </div>
  );
}
