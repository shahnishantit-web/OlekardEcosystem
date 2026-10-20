'use client';

import { useCallback, useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { RetentionPolicy } from '@/lib/api/admin-app-client';

interface FormState {
  data_category: string;
  minimum_retention_days: string;
  post_retention_action: 'archive' | 'purge';
}

interface PendingChange {
  policy: RetentionPolicy;
  newDays: number;
  newAction: 'archive' | 'purge';
}

const emptyForm: FormState = {
  data_category: '',
  minimum_retention_days: '90',
  post_retention_action: 'archive',
};

function isHoldActive(policy: RetentionPolicy): boolean {
  if (!policy.enforcement_hold_until) return false;
  return new Date(policy.enforcement_hold_until) > new Date();
}

export function RetentionPoliciesPage() {
  const { authenticatedClient, hasPermission } = useAuth();

  const canRead   = hasPermission('compliance.read') || hasPermission('compliance.config');
  const canConfig = hasPermission('compliance.config');

  const [policies, setPolicies] = useState<RetentionPolicy[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [submitting, setSubmitting] = useState(false);
  const [pendingChange, setPendingChange] = useState<PendingChange | null>(null);

  const fetchPolicies = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const res = await new AdminAppClient(authenticatedClient).listRetentionPolicies();
      setPolicies(res.data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load retention policies.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    if (canRead) fetchPolicies();
  }, [canRead, fetchPolicies]);

  const handleOpenEdit = (policy: RetentionPolicy) => {
    setEditingId(policy.id);
    setForm({
      data_category:          policy.data_category,
      minimum_retention_days: String(policy.minimum_retention_days),
      post_retention_action:  policy.post_retention_action,
    });
    setShowForm(true);
  };

  const handleRequestSubmit = () => {
    if (!editingId) return;
    const policy = policies.find(p => p.id === editingId);
    if (!policy) return;
    const newDays   = parseInt(form.minimum_retention_days, 10);
    const newAction = form.post_retention_action;
    setPendingChange({ policy, newDays, newAction });
  };

  const handleConfirmChange = useCallback(async () => {
    if (!authenticatedClient || !pendingChange) return;
    setSubmitting(true);
    setError(null);
    try {
      await new AdminAppClient(authenticatedClient).updateRetentionPolicy(pendingChange.policy.id, {
        minimum_retention_days: pendingChange.newDays,
        post_retention_action:  pendingChange.newAction,
      });
      setPendingChange(null);
      setShowForm(false);
      setForm(emptyForm);
      setEditingId(null);
      fetchPolicies();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to save retention policy.');
    } finally {
      setSubmitting(false);
    }
  }, [authenticatedClient, pendingChange, fetchPolicies]);

  const handleCancelConfirm = () => {
    setPendingChange(null);
  };

  if (!canRead) {
    return (
      <PageContainer>
        <PageHeader title="Data Retention Policies" />
        <Card className="p-6">
          <p role="alert" className="text-sm text-error">
            Access denied. You do not have permission to view retention policies.
          </p>
        </Card>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Data Retention Policies" />

      {error && <ErrorState message={error} />}
      {loading && <Loading />}

      {/* Confirmation Dialog */}
      {pendingChange && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label="Confirm retention policy change"
          className="fixed inset-0 bg-black/30 z-50 flex items-center justify-center"
        >
          <Card className="p-6 max-w-md w-full mx-4">
            <h2 className="text-base font-semibold mb-3">Confirm Policy Change</h2>
            <p className="text-sm text-text-secondary mb-4">
              You are about to update <strong>{pendingChange.policy.data_category}</strong>:
            </p>
            <dl className="text-sm space-y-2 mb-6">
              <div className="flex gap-4">
                <dt className="font-medium w-40">Retention Days</dt>
                <dd>{pendingChange.policy.minimum_retention_days} → <strong>{pendingChange.newDays}</strong></dd>
              </div>
              <div className="flex gap-4">
                <dt className="font-medium w-40">Post-Retention Action</dt>
                <dd>{pendingChange.policy.post_retention_action} → <strong>{pendingChange.newAction}</strong></dd>
              </div>
            </dl>
            <p className="text-xs text-text-secondary mb-4">
              This change will be recorded in the audit log and takes effect from the next scheduled cleanup run.
            </p>
            <div className="flex gap-2">
              <Button onClick={handleConfirmChange} disabled={submitting}>
                {submitting ? 'Saving…' : 'Confirm'}
              </Button>
              <Button variant="secondary" onClick={handleCancelConfirm} disabled={submitting}>
                Cancel
              </Button>
            </div>
          </Card>
        </div>
      )}

      {/* Edit Form */}
      {showForm && !pendingChange && (
        <Card className="mb-4 p-4">
          <h2 className="text-lg font-semibold mb-3">Edit Policy</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <label htmlFor="category-input" className="text-sm font-medium block mb-1">Data Category</label>
              <input
                id="category-input"
                type="text"
                value={form.data_category}
                readOnly
                className="border rounded px-3 py-2 text-sm w-full bg-gray-50"
              />
            </div>
            <div>
              <label htmlFor="retention-days-input" className="text-sm font-medium block mb-1">Minimum Retention Days</label>
              <input
                id="retention-days-input"
                type="number"
                min="1"
                value={form.minimum_retention_days}
                onChange={e => setForm(f => ({ ...f, minimum_retention_days: e.target.value }))}
                className="border rounded px-3 py-2 text-sm w-full"
              />
            </div>
            <div>
              <label htmlFor="action-select" className="text-sm font-medium block mb-1">Post-Retention Action</label>
              <select
                id="action-select"
                value={form.post_retention_action}
                onChange={e => setForm(f => ({ ...f, post_retention_action: e.target.value as 'archive' | 'purge' }))}
                className="border rounded px-3 py-2 text-sm w-full"
              >
                <option value="archive">Archive</option>
                <option value="purge">Purge</option>
              </select>
            </div>
          </div>
          <div className="mt-4 flex gap-2">
            <Button
              onClick={handleRequestSubmit}
              disabled={!form.data_category || !form.minimum_retention_days}
            >
              Update Policy
            </Button>
            <Button variant="secondary" onClick={() => { setShowForm(false); setEditingId(null); setForm(emptyForm); }}>
              Cancel
            </Button>
          </div>
        </Card>
      )}

      <Table aria-label="Retention policies">
        <thead>
          <tr>
            <th scope="col">Data Category</th>
            <th scope="col">Min. Retention Days</th>
            <th scope="col">Action</th>
            <th scope="col">Effective From</th>
            <th scope="col">Grace Period Until</th>
            <th scope="col">Last Updated</th>
            {canConfig && <th scope="col">Actions</th>}
          </tr>
        </thead>
        <tbody>
          {policies.map(policy => {
            const held = isHoldActive(policy);
            return (
              <tr key={policy.id}>
                <td className="font-mono text-sm">{policy.data_category}</td>
                <td className="text-center">{policy.minimum_retention_days}</td>
                <td>
                  <Badge variant={policy.post_retention_action === 'purge' ? 'error' : 'warning'}>
                    {policy.post_retention_action}
                  </Badge>
                </td>
                <td>{policy.effective_from}</td>
                <td>
                  {policy.enforcement_hold_until ? (
                    <Badge variant="info">{new Date(policy.enforcement_hold_until).toLocaleDateString()}</Badge>
                  ) : '—'}
                </td>
                <td className="text-sm text-gray-500">{new Date(policy.updated_at).toLocaleDateString()}</td>
                {canConfig && (
                  <td>
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => handleOpenEdit(policy)}
                      disabled={held}
                      title={held ? `Policy in grace period until ${policy.enforcement_hold_until}` : undefined}
                    >
                      Edit
                    </Button>
                  </td>
                )}
              </tr>
            );
          })}
          {policies.length === 0 && !loading && (
            <tr>
              <td colSpan={canConfig ? 7 : 6} className="text-center text-sm text-gray-500 py-6">
                No retention policies configured.
              </td>
            </tr>
          )}
        </tbody>
      </Table>
    </PageContainer>
  );
}
