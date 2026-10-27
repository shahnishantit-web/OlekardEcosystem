'use client';

import { useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { useAuth } from '@/lib/auth/context';
import { useDisputeCases } from '../hooks/useDisputeCases';
import { DisputeStatusBadge } from '../components/DisputeStatusBadge';
import type { DisputeStatus } from '@/lib/api/payment';

export function DisputeCasesPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState<DisputeStatus | ''>('');
  const [requiresReview, setRequiresReview] = useState(false);

  const { cases, isLoading, error, page, lastPage, setPage, refetch } = useDisputeCases(
    Object.fromEntries([
      status ? ['status', status] : [],
      requiresReview ? ['requires_finance_review', true] : [],
    ].filter((e) => e.length > 0)),
  );

  if (!hasPermission('payment.disputes.view')) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to view dispute cases.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Dispute Cases" />

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <select
          aria-label="Filter by status"
          value={status}
          onChange={(e) => { setStatus(e.target.value as DisputeStatus | ''); setPage(1); }}
          className="rounded-md border border-ok-border bg-white px-3 py-1.5 text-sm"
        >
          <option value="">All statuses</option>
          {(['open', 'on_hold', 'under_review', 'won', 'lost', 'closed_reversed'] as DisputeStatus[]).map((s) => (
            <option key={s} value={s}>{s.replace('_', ' ')}</option>
          ))}
        </select>
        <label className="flex items-center gap-2 text-sm text-text-primary">
          <input
            type="checkbox"
            checked={requiresReview}
            onChange={(e) => { setRequiresReview(e.target.checked); setPage(1); }}
          />
          Requires finance review
        </label>
      </div>

      {isLoading && <LoadingState label="Loading dispute cases…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={refetch} />}

      {!isLoading && !error && (
        <Card>
          {cases.length === 0 ? (
            <p className="py-8 text-center text-sm text-text-secondary">No dispute cases found.</p>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-ok-border text-sm">
                  <thead className="bg-neutral-50">
                    <tr>
                      {['Case ID', 'Status', 'Type', 'Intent', 'Finance Review', 'Deadline', 'Created'].map((h) => (
                        <th key={h} scope="col" className="px-4 py-3 text-left text-xs font-medium uppercase text-text-secondary">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ok-border bg-white">
                    {cases.map((c) => (
                      <tr key={c.id}>
                        <td className="px-4 py-3 font-mono text-xs">
                          <Link href={`/payments/finance/disputes/${c.id}`} className="text-blue-600 hover:underline">
                            {c.id.slice(0, 8)}…
                          </Link>
                        </td>
                        <td className="px-4 py-3"><DisputeStatusBadge status={c.status} /></td>
                        <td className="px-4 py-3 text-text-secondary">{c.case_type}</td>
                        <td className="px-4 py-3 font-mono text-xs">
                          {c.payment_intent_id ? (
                            <Link href={`/payments/finance/intents/${c.payment_intent_id}`} className="text-blue-600 hover:underline">
                              {c.payment_intent_id.slice(0, 8)}…
                            </Link>
                          ) : '—'}
                        </td>
                        <td className="px-4 py-3 text-center">
                          {c.requires_finance_review ? (
                            <span className="text-xs font-medium text-ok-warning-fg">Required</span>
                          ) : '—'}
                        </td>
                        <td className="px-4 py-3 text-text-secondary">
                          {c.deadline_at ? new Date(c.deadline_at).toLocaleDateString() : '—'}
                        </td>
                        <td className="px-4 py-3 text-text-secondary">
                          {new Date(c.created_at).toLocaleString()}
                        </td>
                      </tr>
                    ))}
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
