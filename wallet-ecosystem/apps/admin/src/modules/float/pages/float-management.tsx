'use client';

import { Dialog } from '@/components/ui/Dialog';

import { useCallback, useEffect, useState, Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AdminFloatRecord, PendingAction } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

// ---------------------------------------------------------------------------
// Utilization badge
// ---------------------------------------------------------------------------
function UtilBadge({ pct }: { pct: number }) {
  const variant = pct >= 80 ? 'error' : pct >= 50 ? 'warning' : 'success';
  return <Badge label={`${pct.toFixed(1)}%`} variant={variant} />;
}

// ---------------------------------------------------------------------------
// Top-up dialog state
// ---------------------------------------------------------------------------
interface TopUpDialog {
  open: boolean;
  agentId: string;
  agentName: string;
  currency: string;
  amount: string;
  reason: string;
}

// ---------------------------------------------------------------------------
// Limit dialog state
// ---------------------------------------------------------------------------
interface LimitDialog {
  open: boolean;
  agentId: string;
  agentName: string;
  currency: string;
  currentLimitMinorUnits: number;
  newLimit: string;
  reason: string;
}

// ---------------------------------------------------------------------------
// Inner component (needs useSearchParams)
// ---------------------------------------------------------------------------
function FloatManagementInner() {
  const { authenticatedClient } = useAuth();
  const router       = useRouter();
  const searchParams = useSearchParams();

  const [records, setRecords]   = useState<AdminFloatRecord[]>([]);
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState<string | null>(null);
  const [totalCount, setTotalCount] = useState(0);
  const [page, setPage]         = useState(1);
  const pageSize = 25;

  const [lowFloat, setLowFloat] = useState(searchParams.get('low_float') === '1');
  const [search, setSearch]     = useState(searchParams.get('q') ?? '');

  const [actionLoading, setActionLoading] = useState(false);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const EMPTY_TOPUP: TopUpDialog = { open: false, agentId: '', agentName: '', currency: 'BWP', amount: '', reason: '' };
  const EMPTY_LIMIT: LimitDialog = { open: false, agentId: '', agentName: '', currency: 'BWP', currentLimitMinorUnits: 0, newLimit: '', reason: '' };
  const [topUp, setTopUp]   = useState<TopUpDialog>(EMPTY_TOPUP);
  const [limitDlg, setLimitDlg] = useState<LimitDialog>(EMPTY_LIMIT);

  const fetchFloat = useCallback(async (q: string, lf: boolean, pg: number) => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string> = { page: String(pg), per_page: String(pageSize) };
      if (q) params.q = q;
      if (lf) params.low_float = '1';

      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listFloat(params);
      const body = res.data as unknown as {
        data: AdminFloatRecord[];
        total?: number;
        meta?: { total?: number; last_page?: number };
      };
      const items = Array.isArray(body) ? body : (body.data ?? []);
      const total = body.total ?? body.meta?.total ?? items.length;
      setRecords(items);
      setTotalCount(total);
    } catch {
      setError('Failed to load float balances. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => { void fetchFloat(search, lowFloat, page); }, [fetchFloat, search, lowFloat, page]);

  const applyFilter = (q: string, lf: boolean) => {
    setSearch(q);
    setLowFloat(lf);
    setPage(1);
    const qs = new URLSearchParams();
    if (q) qs.set('q', q);
    if (lf) qs.set('low_float', '1');
    router.replace(`/float?${qs.toString()}`);
  };

  // --- Top-Up ---
  const openTopUp = (rec: AdminFloatRecord) => {
    setSuccessMsg(null);
    setActionError(null);
    setTopUp({ open: true, agentId: rec.agent_id, agentName: rec.full_name, currency: rec.float_currency, amount: '', reason: '' });
  };
  const closeTopUp = () => setTopUp(EMPTY_TOPUP);

  const handleTopUp = async () => {
    if (!authenticatedClient || !topUp.amount || !topUp.reason) return;
    const amountMinorUnits = Math.round(parseFloat(topUp.amount) * 100);
    if (isNaN(amountMinorUnits) || amountMinorUnits <= 0) {
      setActionError('Invalid amount.');
      return;
    }
    setActionLoading(true);
    setActionError(null);
    try {
      const client  = new AdminAppClient(authenticatedClient);
      const idemKey = generateIdempotencyKey(`float-topup-${topUp.agentId}-${amountMinorUnits}`);
      const res = await client.topUpFloat(topUp.agentId, amountMinorUnits, topUp.currency, topUp.reason.trim(), idemKey);
      const result = res.data as unknown as PendingAction;
      setSuccessMsg(`Top-up submitted for checker review. Approval ID: ${result.approval_id}`);
      closeTopUp();
      void fetchFloat(search, lowFloat, page);
    } catch (err) {
      const code = (err as { code?: string })?.code;
      if (code === 'ADMINAPP_FLOAT_001') {
        setActionError('Amount would exceed the agent float limit.');
      } else {
        setActionError('Failed to submit top-up. Please try again.');
      }
    } finally {
      setActionLoading(false);
    }
  };

  // --- Limit update ---
  const openLimit = (rec: AdminFloatRecord) => {
    setSuccessMsg(null);
    setActionError(null);
    const currentLimitStr = rec.float_limit_minor_units > 0
      ? (rec.float_limit_minor_units / 100).toFixed(2)
      : '';
    setLimitDlg({
      open: true,
      agentId: rec.agent_id,
      agentName: rec.full_name,
      currency: rec.float_currency,
      currentLimitMinorUnits: rec.float_limit_minor_units,
      newLimit: currentLimitStr,
      reason: '',
    });
  };
  const closeLimit = () => setLimitDlg(EMPTY_LIMIT);

  const handleLimitUpdate = async () => {
    if (!authenticatedClient || !limitDlg.newLimit || !limitDlg.reason) return;
    const newLimitMinorUnits = Math.round(parseFloat(limitDlg.newLimit) * 100);
    if (isNaN(newLimitMinorUnits) || newLimitMinorUnits <= 0) {
      setActionError('Invalid limit value.');
      return;
    }
    setActionLoading(true);
    setActionError(null);
    try {
      const client  = new AdminAppClient(authenticatedClient);
      const idemKey = generateIdempotencyKey(`float-limit-${limitDlg.agentId}-${newLimitMinorUnits}`);
      await client.updateFloatLimit(limitDlg.agentId, newLimitMinorUnits, limitDlg.reason.trim(), idemKey);
      setSuccessMsg(`Float limit updated for ${limitDlg.agentName}.`);
      closeLimit();
      void fetchFloat(search, lowFloat, page);
    } catch {
      setActionError('Failed to update float limit. Please try again.');
    } finally {
      setActionLoading(false);
    }
  };

  const totalPages = Math.max(1, Math.ceil(totalCount / pageSize));

  const fmt = (minor: number, currency: string) =>
    `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;

  const utilPct = (rec: AdminFloatRecord) => {
    if (rec.utilization_pct != null) return Number(rec.utilization_pct);
    if (!rec.float_limit_minor_units) return 0;
    return (rec.float_balance_minor_units / rec.float_limit_minor_units) * 100;
  };

  return (
    <PageContainer>
      <PageHeader title="Agent Float Management" />

      {/* Filters */}
      <Card className="mb-4 p-4">
        <div className="flex flex-wrap gap-3 items-end">
          <div className="flex-1 min-w-[200px]">
            <label htmlFor="float-search" className="block text-xs font-medium text-text-secondary mb-1">
              Search
            </label>
            <input
              id="float-search"
              type="search"
              value={search}
              onChange={e => applyFilter(e.target.value, lowFloat)}
              placeholder="Agent name or phone…"
              className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              aria-label="Search agents by name"
            />
          </div>

          <div className="flex items-center gap-2 pb-1">
            <input
              id="low-float-filter"
              type="checkbox"
              checked={lowFloat}
              onChange={e => applyFilter(search, e.target.checked)}
              className="rounded border border-ok-border"
              aria-label="Show only low-float agents"
            />
            <label htmlFor="low-float-filter" className="text-sm text-text-secondary cursor-pointer">
              Low-float only (&lt;20%)
            </label>
          </div>

          <Button variant="secondary" onClick={() => applyFilter('', false)}>
            Clear Filters
          </Button>
        </div>
      </Card>

      {/* Status messages */}
      {successMsg && (
        <div role="status" className="mb-4 rounded bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
          {successMsg}
        </div>
      )}
      {actionError && (
        <div role="alert" className="mb-4 rounded bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
          {actionError}
        </div>
      )}

      {/* Table */}
      {loading ? (
        <Loading label="Loading float balances…" />
      ) : error ? (
        <ErrorState message={error} onRetry={() => void fetchFloat(search, lowFloat, page)} />
      ) : (
        <>
          <div className="overflow-x-auto rounded border border-ok-border">
            <table className="min-w-full" aria-label="Agent float balances">
              <thead className="bg-ok-surface">
                <tr>
                  {['Agent', 'Agent Code', 'Currency', 'Float Balance', 'Float Limit', 'Utilization', 'Last Movement', ''].map(h => (
                    <th key={h} className="py-2 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {records.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="py-8 text-center text-sm text-text-secondary">
                      No float records found.
                    </td>
                  </tr>
                ) : (
                  records.map(rec => {
                    const pct = utilPct(rec);
                    const isLow = pct < 20;
                    return (
                      <tr
                        key={rec.agent_id}
                        className={`border-t border-ok-border transition-colors ${isLow ? 'bg-amber-50 hover:bg-amber-100' : 'hover:bg-ok-hover-bg'}`}
                      >
                        <td className="py-2 px-4 text-sm font-medium text-text-primary">
                          <Link
                            href={`/agents/${rec.agent_id}`}
                            className="text-ok-primary underline hover:text-ok-primary-hover"
                          >
                            {rec.full_name}
                          </Link>
                          {isLow && (
                            <span className="ml-2 text-xs font-medium text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">
                              Low
                            </span>
                          )}
                        </td>
                        <td className="py-2 px-4 text-sm font-mono text-text-secondary">
                          {rec.agent_id.substring(0, 8)}…
                        </td>
                        <td className="py-2 px-4 text-sm text-text-secondary">{rec.float_currency}</td>
                        <td className="py-2 px-4 text-sm tabular-nums text-text-primary">
                          {fmt(rec.float_balance_minor_units, rec.float_currency)}
                        </td>
                        <td className="py-2 px-4 text-sm tabular-nums text-text-secondary">
                          {fmt(rec.float_limit_minor_units, rec.float_currency)}
                        </td>
                        <td className="py-2 px-4">
                          <UtilBadge pct={pct} />
                        </td>
                        <td className="py-2 px-4 text-sm text-text-secondary">
                          {rec.last_top_up_at ? new Date(rec.last_top_up_at).toLocaleDateString() : '—'}
                        </td>
                        <td className="py-2 px-4 text-right">
                          <div className="flex gap-2 justify-end">
                            <Button
                              variant="primary"
                              size="sm"
                              onClick={() => openTopUp(rec)}
                              aria-label={`Top up float for ${rec.full_name}`}
                            >
                              Top Up
                            </Button>
                            <Button
                              variant="secondary"
                              size="sm"
                              onClick={() => openLimit(rec)}
                              aria-label={`Adjust float limit for ${rec.full_name}`}
                            >
                              Adjust Limit
                            </Button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex justify-between items-center mt-4 text-sm text-text-secondary">
              <span>{totalCount} record{totalCount !== 1 ? 's' : ''}</span>
              <div className="flex gap-2 items-center">
                <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
                  Previous
                </Button>
                <span className="py-1">Page {page} of {totalPages}</span>
                <Button variant="secondary" size="sm" disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>
                  Next
                </Button>
              </div>
            </div>
          )}
        </>
      )}

      {/* Top-Up dialog */}
      {topUp.open && (
        <Dialog open showCloseButton={false} onClose={closeTopUp} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="topup-dialog-title">
            <h2 id="topup-dialog-title" className="text-lg font-semibold text-text-primary mb-1">
              Top Up Float
            </h2>
            <p className="text-sm text-text-secondary mb-4">
              Agent: <strong>{topUp.agentName}</strong>
              <br />
              This top-up will be posted through the double-entry ledger and requires a second approver.
            </p>
            <div className="mb-3">
              <label htmlFor="topup-amount" className="block text-sm font-medium text-text-secondary mb-1">
                Amount ({topUp.currency}) <span aria-hidden="true">*</span>
              </label>
              <input
                id="topup-amount"
                type="number"
                min="0.01"
                step="0.01"
                value={topUp.amount}
                onChange={e => setTopUp(d => ({ ...d, amount: e.target.value }))}
                placeholder="0.00"
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              />
            </div>
            <div className="mb-4">
              <label htmlFor="topup-reason" className="block text-sm font-medium text-text-secondary mb-1">
                Reason <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="topup-reason"
                value={topUp.reason}
                onChange={e => setTopUp(d => ({ ...d, reason: e.target.value }))}
                rows={3}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="Reason for top-up…"
              />
            </div>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={closeTopUp} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                onClick={() => void handleTopUp()}
                disabled={!topUp.amount || !topUp.reason.trim() || actionLoading}
                loading={actionLoading}
              >
                Submit for Approval
              </Button>
            </div>
          </Dialog>
      )}

      {/* Limit dialog */}
      {limitDlg.open && (
        <Dialog open showCloseButton={false} onClose={closeLimit} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="limit-dialog-title">
            <h2 id="limit-dialog-title" className="text-lg font-semibold text-text-primary mb-1">
              Adjust Float Limit
            </h2>
            <p className="text-sm text-text-secondary mb-4">
              Agent: <strong>{limitDlg.agentName}</strong>
              <br />
              Current limit: <strong>{fmt(limitDlg.currentLimitMinorUnits, limitDlg.currency)}</strong>
            </p>
            <div className="mb-3">
              <label htmlFor="limit-value" className="block text-sm font-medium text-text-secondary mb-1">
                New Limit ({limitDlg.currency}) <span aria-hidden="true">*</span>
              </label>
              <input
                id="limit-value"
                type="number"
                min="0.01"
                step="0.01"
                value={limitDlg.newLimit}
                onChange={e => setLimitDlg(d => ({ ...d, newLimit: e.target.value }))}
                placeholder="0.00"
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              />
            </div>
            <div className="mb-4">
              <label htmlFor="limit-reason" className="block text-sm font-medium text-text-secondary mb-1">
                Reason <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="limit-reason"
                value={limitDlg.reason}
                onChange={e => setLimitDlg(d => ({ ...d, reason: e.target.value }))}
                rows={3}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="Reason for limit change…"
              />
            </div>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={closeLimit} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                onClick={() => void handleLimitUpdate()}
                disabled={!limitDlg.newLimit || !limitDlg.reason.trim() || actionLoading}
                loading={actionLoading}
              >
                Save Limit
              </Button>
            </div>
          </Dialog>
      )}
    </PageContainer>
  );
}

// ---------------------------------------------------------------------------
// Public export (wraps inner with Suspense for useSearchParams)
// ---------------------------------------------------------------------------
export function FloatManagementAdminPortalPage() {
  return (
    <Suspense fallback={<PageContainer><Loading label="Loading…" /></PageContainer>}>
      <FloatManagementInner />
    </Suspense>
  );
}
