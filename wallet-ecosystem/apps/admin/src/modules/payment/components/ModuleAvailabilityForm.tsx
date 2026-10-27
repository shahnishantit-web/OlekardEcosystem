'use client';

import { useState } from 'react';
import type { ModuleConfig, UpdateModuleConfigPayload } from '@/lib/api/payment';

interface Props {
  modules: ModuleConfig[];
  readOnly?: boolean;
  onSubmit: (payload: UpdateModuleConfigPayload) => void;
  submitting?: boolean;
  approvalPending?: boolean;
}

export function ModuleAvailabilityForm({ modules, readOnly = false, onSubmit, submitting = false, approvalPending = false }: Props) {
  const [changes, setChanges] = useState<Record<string, { is_enabled?: boolean; display_order?: number }>>({});

  const handleToggle = (moduleKey: string, is_enabled: boolean) => {
    setChanges((prev) => ({ ...prev, [moduleKey]: { ...prev[moduleKey], is_enabled } }));
  };

  const handleOrderChange = (moduleKey: string, display_order: number) => {
    setChanges((prev) => ({ ...prev, [moduleKey]: { ...prev[moduleKey], display_order } }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const modulesPayload = Object.entries(changes).map(([module_key, change]) => ({ module_key, ...change }));
    if (modulesPayload.length === 0) return;
    onSubmit({ modules: modulesPayload });
  };

  if (modules.length === 0) {
    return <p className="text-muted-foreground text-sm">No modules configured.</p>;
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {approvalPending && (
        <div className="bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-800">
          Module changes submitted for approval.{' '}
          <a href="/payments/providers/approvals" className="underline">View approval queue →</a>
        </div>
      )}
      <table className="w-full text-sm border-collapse">
        <thead>
          <tr className="border-b">
            <th className="text-left py-2 pr-4">Module</th>
            <th className="text-left py-2 pr-4">Enabled</th>
            <th className="text-left py-2 pr-4">Display Order</th>
          </tr>
        </thead>
        <tbody>
          {modules.map((m) => {
            const localChange = changes[m.module_key] ?? {};
            const currentEnabled = localChange.is_enabled ?? m.is_enabled;
            const currentOrder = localChange.display_order ?? m.display_order;
            return (
              <tr key={m.module_key} className="border-b">
                <td className="py-2 pr-4">
                  <span className="font-medium">{m.display_name}</span>
                  <div className="text-xs text-muted-foreground font-mono">{m.module_key}</div>
                </td>
                <td className="py-2 pr-4">
                  <input
                    type="checkbox"
                    checked={currentEnabled}
                    onChange={(e) => handleToggle(m.module_key, e.target.checked)}
                    disabled={readOnly}
                    aria-label={`Enable ${m.display_name}`}
                  />
                </td>
                <td className="py-2 pr-4">
                  <input
                    type="number"
                    value={currentOrder}
                    onChange={(e) => handleOrderChange(m.module_key, parseInt(e.target.value, 10))}
                    disabled={readOnly}
                    className="border rounded px-2 py-1 w-20 text-sm"
                    aria-label={`Display order for ${m.display_name}`}
                  />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      {!readOnly && (
        <button
          type="submit"
          disabled={submitting || Object.keys(changes).length === 0}
          className="bg-primary text-primary-foreground px-4 py-2 rounded text-sm disabled:opacity-50"
          aria-label="Submit module config changes for approval"
        >
          {submitting ? 'Submitting...' : 'Submit for Approval'}
        </button>
      )}
    </form>
  );
}
