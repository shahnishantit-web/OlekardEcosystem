'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { searchAdminTransactions } from '@/src/modules/users/api/admin-portal';
import type { AdminTransaction } from '@/src/modules/users/api/admin-portal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';

type StatusTab = 'All' | 'completed' | 'pending' | 'failed';

const TABS: StatusTab[] = ['All', 'completed', 'pending', 'failed'];

const STATUS_VARIANT: Record<string, 'success' | 'error' | 'warning' | 'neutral'> = {
  completed: 'success',
  failed:    'error',
  pending:   'warning',
  reversed:  'neutral',
};

function formatAmount(amount: number, currency: string) {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
  }).format(amount / 100);
}

export function RecentTransactionsTable() {
  const { authenticatedClient } = useAuth();
  const [transactions, setTransactions] = useState<AdminTransaction[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [tab, setTab] = useState<StatusTab>('All');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  // Debounce search
  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(t);
  }, [search]);

  const fetchTransactions = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(false);
    try {
      const params: { status?: string; q?: string; per_page: number; page: number } = {
        per_page: 8,
        page,
      };
      if (tab !== 'All') params.status = tab;
      if (debouncedSearch) params.q = debouncedSearch;
      const result = await searchAdminTransactions(authenticatedClient, params);
      setTransactions(result.data);
      setLastPage(result.meta.last_page);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, tab, debouncedSearch, page]);

  useEffect(() => {
    void fetchTransactions();
  }, [fetchTransactions]);

  const handleTabChange = (t: StatusTab) => {
    setTab(t);
    setPage(1);
  };

  return (
    <div className="bg-ok-card-bg border border-ok-card-border rounded-[11px] overflow-hidden flex flex-col">
      {/* Card header */}
      <div className="flex items-center justify-between px-4 pt-4 pb-2">
        <h2 className="text-sm font-semibold text-text-primary">Recent transactions</h2>
        <Link
          href="/admin-portal/transactions"
          className="text-xs text-ok-primary hover:underline"
        >
          View all →
        </Link>
      </div>

      {/* Tab bar */}
      <div className="flex gap-1 px-4 pb-2">
        {TABS.map((t) => (
          <button
            key={t}
            type="button"
            onClick={() => handleTabChange(t)}
            className={[
              'px-3 py-1.5 text-xs rounded-md transition-colors',
              tab === t
                ? 'bg-ok-info-bg text-text-primary font-medium'
                : 'text-text-secondary hover:text-text-primary',
            ].join(' ')}
          >
            {t.charAt(0).toUpperCase() + t.slice(1)}
          </button>
        ))}
      </div>

      {/* Search */}
      <div className="px-4 pb-2">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search by reference or email…"
          className="w-full rounded border border-ok-border bg-transparent px-3 py-1.5 text-sm text-text-primary placeholder:text-text-secondary focus:outline-none focus:border-ok-primary"
          aria-label="Search transactions"
        />
      </div>

      {/* Table */}
      <div className="overflow-x-auto flex-1">
        {loading && (
          <div className="px-4 py-6 space-y-2">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-3 rounded bg-neutral-200 animate-pulse" />
            ))}
          </div>
        )}

        {error && (
          <div className="px-4 py-6 text-center">
            <p className="text-sm text-ok-error mb-2">Failed to load transactions</p>
            <button
              type="button"
              onClick={() => void fetchTransactions()}
              className="text-xs text-ok-primary hover:underline"
            >
              Retry
            </button>
          </div>
        )}

        {!loading && !error && transactions.length === 0 && (
          <p className="px-4 py-6 text-sm text-text-secondary text-center">
            No transactions match your filters
          </p>
        )}

        {!loading && !error && transactions.length > 0 && (
          <table className="min-w-full" aria-label="Recent transactions">
            <thead>
              <tr className="border-b border-ok-border">
                {['Transaction', 'Amount', 'Status', 'Date'].map((h) => (
                  <th
                    key={h}
                    className="py-2 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider"
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {transactions.map((tx) => (
                <tr key={tx.id} className="border-b border-ok-border hover:bg-neutral-50">
                  <td className="py-2 px-4 text-sm text-text-primary capitalize">
                    {tx.type.replace(/_/g, ' ')}
                  </td>
                  <td className="py-2 px-4 text-sm tabular-nums text-text-primary">
                    {formatAmount(tx.amount, tx.currency_code)}
                  </td>
                  <td className="py-2 px-4">
                    <Badge
                      label={tx.status.toUpperCase()}
                      variant={STATUS_VARIANT[tx.status] ?? 'neutral'}
                    />
                  </td>
                  <td className="py-2 px-4 text-xs text-text-secondary">
                    {new Date(tx.created_at).toLocaleDateString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Pagination */}
      {!loading && !error && lastPage > 1 && (
        <div className="flex items-center justify-between px-4 py-2 border-t border-ok-border">
          <span className="text-xs text-text-secondary">Page {page} of {lastPage}</span>
          <div className="flex gap-2">
            <Button
              variant="ghost"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1}
              aria-label="Previous page"
            >
              ←
            </Button>
            <Button
              variant="ghost"
              onClick={() => setPage((p) => p + 1)}
              disabled={page >= lastPage}
              aria-label="Next page"
            >
              →
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
