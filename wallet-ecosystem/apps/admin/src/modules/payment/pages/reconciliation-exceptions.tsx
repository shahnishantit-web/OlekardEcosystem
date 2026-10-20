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
import { PaymentApiClient } from '@/lib/api/payment';
import type { ReconciliationException, ReconciliationExceptionStatus, ReconciliationExceptionType } from '@/lib/api/payment';

// T075: Reconciliation exceptions list — requires payment.reconciliation.view
// NO mark-as-paid action exposed. Status updates and notes only.

type BadgeVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

function statusVariant(status: ReconciliationExceptionStatus): BadgeVariant {
  switch (status) {
    case 'open':          return 'error';
    case 'investigating': return 'warning';
    case 'escalated':     return 'info';
    case 'resolved':      return 'success';
    default:              return 'neutral';
  }
}

function typeLabel(type: ReconciliationExceptionType): string {
  return type.replace(/_/g, ' ');
}

const STATUS_OPTIONS: ReconciliationExceptionStatus[] = ['open', 'investigating', 'escalated', 'resolved'];

export function ReconciliationExceptionsPage() {
  const { authenticatedClient, hasPermission } = useAuth();

  const [exceptions, setExceptions] = useState<ReconciliationException[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<ReconciliationExceptionStatus | ''>('');

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editNotes, setEditNotes] = useState('');
  const [editStatus, setEditStatus] = useState<ReconciliationExceptionStatus | ''>('');
  const [saving, setSaving] = useState(false);

  const canManage = hasPermission('payment.reconciliation.manage');

  const fetchExceptions = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      const res = await api.listReconciliationExceptions(
        statusFilter ? { status: statusFilter } : {},
      );
      setExceptions(res.data?.data ?? []);
    } catch {
      setError('Failed to load reconciliation exceptions.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, statusFilter]);

  useEffect(() => { void fetchExceptions(); }, [fetchExceptions]);

  function startEdit(ex: ReconciliationException) {
    setEditingId(ex.id);
    setEditNotes(ex.notes ?? '');
    setEditStatus(ex.status);
  }

  async function saveEdit(id: string) {
    if (!authenticatedClient) return;
    setSaving(true);
    try {
      const api = new PaymentApiClient(authenticatedClient);
      await api.updateExceptionStatus(id, {
        ...(editStatus ? { status: editStatus } : {}),
        ...(editNotes ? { notes: editNotes } : {}),
      });
      setEditingId(null);
      void fetchExceptions();
    } catch {
      setError('Failed to save changes.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <PageContainer>
      <PageHeader title="Reconciliation Exceptions" />

      <div className="mb-4 flex gap-3 flex-wrap">
        <select
          aria-label="Filter by status"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as ReconciliationExceptionStatus | '')}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>
      </div>

      {loading && <Loading label="Loading exceptions…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchExceptions()} />}

      {!loading && !error && (
        <Card>
          <Table caption="Reconciliation exceptions">
            <thead>
              <tr className="border-b border-ok-border border-t border-ok-border bg-neutral-50">
                {['Type', 'Provider', 'Amount', 'Status', 'Created', 'Notes', canManage ? 'Actions' : ''].filter(Boolean).map((h) => (
                  <th key={h} scope="col" className="px-4 py-3 text-sm font-medium text-text-secondary">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {exceptions.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-text-secondary text-sm">
                    No reconciliation exceptions found.
                  </td>
                </tr>
              ) : (
                exceptions.map((ex) => (
                  <tr key={ex.id} className="border-b border-[#f0f1f4]">
                    <td className="px-4 py-3 text-sm text-text-primary capitalize">{typeLabel(ex.type)}</td>
                    <td className="px-4 py-3 text-sm font-mono text-text-secondary">{ex.provider_code ?? '—'}</td>
                    <td className="px-4 py-3 text-sm text-text-primary">
                      {ex.amount_minor !== null ? ex.amount_minor.toLocaleString() : '—'}
                    </td>
                    <td className="px-4 py-3">
                      {editingId === ex.id ? (
                        <select
                          value={editStatus}
                          onChange={(e) => setEditStatus(e.target.value as ReconciliationExceptionStatus)}
                          className="rounded border border-border px-2 py-1 text-xs bg-surface text-text-primary"
                          aria-label="Change status"
                        >
                          {STATUS_OPTIONS.map((s) => (
                            <option key={s} value={s}>{s}</option>
                          ))}
                        </select>
                      ) : (
                        <Badge label={ex.status.toUpperCase()} variant={statusVariant(ex.status)} />
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-text-secondary">
                      {new Date(ex.created_at).toLocaleDateString()}
                    </td>
                    <td className="px-4 py-3 text-sm text-text-secondary max-w-xs">
                      {editingId === ex.id ? (
                        <textarea
                          value={editNotes}
                          onChange={(e) => setEditNotes(e.target.value)}
                          rows={2}
                          maxLength={4000}
                          aria-label="Investigation notes"
                          className="w-full rounded border border-border px-2 py-1 text-xs bg-surface text-text-primary"
                        />
                      ) : (
                        <span className="truncate">{ex.notes ?? '—'}</span>
                      )}
                    </td>
                    {canManage && (
                      <td className="px-4 py-3">
                        {editingId === ex.id ? (
                          <div className="flex gap-1">
                            <Button
                              size="sm"
                              variant="primary"
                              onClick={() => void saveEdit(ex.id)}
                              disabled={saving}
                            >
                              Save
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => setEditingId(null)}
                              disabled={saving}
                            >
                              Cancel
                            </Button>
                          </div>
                        ) : (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => startEdit(ex)}
                            aria-label={`Edit exception ${ex.id}`}
                          >
                            Edit
                          </Button>
                        )}
                      </td>
                    )}
                  </tr>
                ))
              )}
            </tbody>
          </Table>
        </Card>
      )}
    </PageContainer>
  );
}
