'use client';

import { useCallback, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { useAuth } from '@/lib/auth/context';
import { useReconciliationExceptions } from '../hooks/useReconciliationExceptions';
import { CurrencyAmount } from '../components/CurrencyAmount';
import type { ReconciliationExceptionStatus } from '@/lib/api/payment';

const STATUS_OPTIONS: ReconciliationExceptionStatus[] = ['open', 'investigating', 'escalated', 'resolved'];

function statusVariant(s: ReconciliationExceptionStatus) {
  if (s === 'open') return 'error' as const;
  if (s === 'investigating') return 'warning' as const;
  if (s === 'escalated') return 'info' as const;
  if (s === 'resolved') return 'success' as const;
  return 'neutral' as const;
}

export function ReconciliationExceptionsFinancePage() {
  const { hasPermission } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();

  const statusParam = (searchParams.get('status') as ReconciliationExceptionStatus | null) ?? 'open';
  const [localStatus, setLocalStatus] = useState<ReconciliationExceptionStatus | ''>(statusParam);

  const { exceptions, isLoading, error, page, lastPage, setPage, refetch, updateException } =
    useReconciliationExceptions(localStatus ? { status: localStatus } : {});

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editStatus, setEditStatus] = useState<ReconciliationExceptionStatus>('open');
  const [editNotes, setEditNotes] = useState('');
  const [saving, setSaving] = useState(false);

  const canManage = hasPermission('payment.reconciliation.manage');

  const handleStatusChange = useCallback((s: ReconciliationExceptionStatus | '') => {
    setLocalStatus(s);
    setPage(1);
    const params = new URLSearchParams(searchParams.toString());
    if (s) { params.set('status', s); } else { params.delete('status'); }
    router.push(`?${params.toString()}`);
  }, [router, searchParams, setPage]);

  const handleUpdateSubmit = useCallback(async (id: string) => {
    setSaving(true);
    try {
      await updateException(id, editNotes ? { status: editStatus, notes: editNotes } : { status: editStatus });
      setEditingId(null);
    } catch {
      // error stays inline
    } finally {
      setSaving(false);
    }
  }, [updateException, editStatus, editNotes]);

  if (!hasPermission('payment.reconciliation.view')) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to view reconciliation exceptions.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Reconciliation Exceptions" />

      <div className="mb-4 flex flex-wrap gap-3">
        <select
          aria-label="Filter by status"
          value={localStatus}
          onChange={(e) => handleStatusChange(e.target.value as ReconciliationExceptionStatus | '')}
          className="rounded-md border border-ok-border bg-white px-3 py-1.5 text-sm"
        >
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {isLoading && <LoadingState label="Loading reconciliation exceptions…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={refetch} />}

      {!isLoading && !error && (
        <Card>
          {exceptions.length === 0 ? (
            <p className="py-8 text-center text-sm text-text-secondary">No exceptions found.</p>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-ok-border text-sm">
                  <thead className="bg-neutral-50">
                    <tr>
                      {['Type', 'Provider', 'Amount', 'Status', 'Notes', 'Created', ...(canManage ? ['Actions'] : [])].map((h) => (
                        <th key={h} scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ok-border bg-white">
                    {exceptions.map((ex) => {
                      const isResolved = ex.status === 'resolved';
                      return (
                        <tr key={ex.id} className={isResolved ? 'opacity-50' : ''}>
                          <td className="px-4 py-3 text-text-secondary">{ex.type.replace(/_/g, ' ')}</td>
                          <td className="px-4 py-3 text-text-secondary">{ex.provider_code ?? '—'}</td>
                          <td className="px-4 py-3 tabular-nums">
                            {ex.amount_minor != null ? ex.amount_minor.toLocaleString() : '—'}
                          </td>
                          <td className="px-4 py-3">
                            <Badge label={ex.status} variant={statusVariant(ex.status)} />
                          </td>
                          <td className="max-w-xs px-4 py-3 text-xs text-text-secondary truncate">{ex.notes ?? '—'}</td>
                          <td className="px-4 py-3 text-text-secondary">{new Date(ex.created_at).toLocaleString()}</td>
                          {canManage && (
                            <td className="px-4 py-3">
                              {editingId === ex.id ? (
                                <div className="flex flex-col gap-1">
                                  <select
                                    aria-label="New status"
                                    value={editStatus}
                                    onChange={(e) => setEditStatus(e.target.value as ReconciliationExceptionStatus)}
                                    className="rounded border border-ok-border px-2 py-1 text-xs"
                                  >
                                    {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                                  </select>
                                  <textarea
                                    aria-label="Notes"
                                    value={editNotes}
                                    onChange={(e) => setEditNotes(e.target.value)}
                                    rows={2}
                                    placeholder="Notes…"
                                    className="rounded border border-ok-border px-2 py-1 text-xs"
                                  />
                                  <div className="flex gap-1">
                                    <button type="button" disabled={saving} onClick={() => void handleUpdateSubmit(ex.id)} className="rounded bg-blue-600 px-2 py-1 text-xs text-white disabled:opacity-50">Save</button>
                                    <button type="button" onClick={() => setEditingId(null)} className="rounded border border-ok-border px-2 py-1 text-xs">Cancel</button>
                                  </div>
                                </div>
                              ) : (
                                <button
                                  type="button"
                                  onClick={() => { setEditingId(ex.id); setEditStatus(ex.status); setEditNotes(ex.notes ?? ''); }}
                                  className="text-xs text-blue-600 hover:underline"
                                >
                                  Update Status
                                </button>
                              )}
                            </td>
                          )}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="flex items-center justify-between border-t border-ok-border px-4 py-3">
                <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded border border-ok-border px-3 py-1.5 text-sm disabled:opacity-40">Previous</button>
                <span className="text-sm text-text-secondary">Page {page} of {lastPage}</span>
                <button type="button" disabled={page >= lastPage} onClick={() => setPage(page + 1)} className="rounded border border-ok-border px-3 py-1.5 text-sm disabled:opacity-40">Next</button>
              </div>
            </>
          )}
        </Card>
      )}
    </PageContainer>
  );
}
