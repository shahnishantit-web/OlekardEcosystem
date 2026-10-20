'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { SettlementRecord, PaginatedMeta } from '@/lib/api/admin-app-client';
import { PageContainer } from '@/components/layout/PageContainer';

export function SettlementReportPage() {
  const { authenticatedClient } = useAuth();
  const headingId = useId();

  const [settlements, setSettlements] = useState<{ data: SettlementRecord[]; meta: PaginatedMeta } | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  const fetchSettlements = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listSettlement({ page: String(page) });
      setSettlements({ data: Array.isArray(res.data) ? res.data : [], meta: res.meta });
    } catch {
      setError('Failed to load settlement records.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, page]);

  useEffect(() => { void fetchSettlements(); }, [fetchSettlements]);

  return (
    <PageContainer>
      <h1 id={headingId} className="text-xl font-semibold text-text-primary mb-6">Settlement Records</h1>

      {loading && <Loading label="Loading settlement records…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchSettlements()} />}
      {!loading && !error && settlements && settlements.data.length === 0 && (
        <Empty title="No settlement records" description="No settlements found." />
      )}

      {!loading && !error && settlements && settlements.data.length > 0 && (
        <>
          <div className="overflow-x-auto rounded border border-ok-border">
            <table className="min-w-full" aria-label="Settlement records">
              <thead className="bg-ok-surface">
                <tr>
                  {['ID', 'Status', 'Net Position', 'Period', 'Actions'].map(h => (
                    <th key={h} className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {settlements.data.map(s => (
                  <tr key={s.settlement_id} className="border-b border-ok-border hover:bg-ok-hover-bg">
                    <td className="py-3 px-4 text-sm font-mono">{s.settlement_id.substring(0, 8)}…</td>
                    <td className="py-3 px-4">
                      <Badge
                        label={s.status.toUpperCase()}
                        variant={s.status === 'executed' ? 'success' : s.status === 'failed' ? 'error' : 'warning'}
                      />
                    </td>
                    <td className="py-3 px-4 text-sm tabular-nums">{s.currency_code} {(s.net_position_minor_units / 100).toLocaleString()}</td>
                    <td className="py-3 px-4 text-xs text-text-secondary">{new Date(s.settlement_date).toLocaleDateString()}</td>
                    <td className="py-3 px-4">
                      <a
                        href={`/reconciliation/settlements/${s.settlement_id}`}
                        className="text-sm text-brand-primary underline hover:no-underline"
                        aria-label={`View settlement ${s.settlement_id}`}
                      >
                        Detail
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center justify-between text-sm text-text-secondary">
            <span>Page {settlements.meta.current_page} of {settlements.meta.last_page}</span>
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page <= 1} aria-label="Previous page">Previous</Button>
              <Button variant="secondary" onClick={() => setPage(p => p + 1)} disabled={page >= settlements.meta.last_page} aria-label="Next page">Next</Button>
            </div>
          </div>
        </>
      )}
    </PageContainer>
  );
}
