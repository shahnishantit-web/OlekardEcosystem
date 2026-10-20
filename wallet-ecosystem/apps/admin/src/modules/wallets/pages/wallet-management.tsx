'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AdminWallet, PaginatedMeta, PaginatedResponse } from '@/lib/api/admin-app-client';

function WalletStatusBadge({ wallet }: { wallet: AdminWallet }) {
  if (wallet.frozen_at !== null) return <Badge label="FROZEN" variant="error" />;
  const variant = wallet.status === 'active' ? 'success' : wallet.status === 'closed' ? 'neutral' : 'warning';
  return <Badge label={wallet.status.toUpperCase()} variant={variant} />;
}

function WalletRow({ wallet }: { wallet: AdminWallet }) {
  const ownerUserId = wallet.owner_user_id ?? wallet.user_id;
  const ownerName   = wallet.owner_name ?? ownerUserId.substring(0, 8) + '…';
  const currency    = wallet.currency_code ?? wallet.currency;
  const balanceStr  = (wallet.balance_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 });

  return (
    <tr className="border-b border-ok-border hover:bg-ok-hover-bg transition-colors">
      <td className="py-3 px-4 text-sm font-mono text-text-secondary">
        {wallet.wallet_number ?? wallet.id.substring(0, 8) + '…'}
      </td>
      <td className="py-3 px-4 text-sm text-text-primary">
        <Link
          href={`/admin-portal/users/${ownerUserId}`}
          className="text-ok-primary underline hover:text-ok-primary-hover"
          aria-label={`View user ${ownerName}`}
        >
          {ownerName}
        </Link>
      </td>
      <td className="py-3 px-4 text-sm text-text-secondary font-mono">{currency}</td>
      <td className="py-3 px-4 text-sm text-text-primary tabular-nums">
        {balanceStr}
      </td>
      <td className="py-3 px-4">
        <WalletStatusBadge wallet={wallet} />
      </td>
      <td className="py-3 px-4 text-sm text-text-secondary">
        {wallet.created_at ? new Date(wallet.created_at).toLocaleDateString() : '—'}
      </td>
      <td className="py-3 px-4">
        <Link href={`/admin-portal/wallets/${wallet.id}`}>
          <Button variant="secondary" aria-label={`View wallet ${wallet.wallet_number ?? wallet.id.substring(0, 8)}`}>
            View
          </Button>
        </Link>
      </td>
    </tr>
  );
}

export function WalletManagementPage() {
  const { authenticatedClient } = useAuth();

  const [wallets, setWallets]           = useState<PaginatedResponse<AdminWallet> | null>(null);
  const [loading, setLoading]           = useState(false);
  const [error, setError]               = useState<string | null>(null);
  const [query, setQuery]               = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [currencyFilter, setCurrencyFilter] = useState('all');
  const [page, setPage]                 = useState(1);

  const fetchWallets = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const params: Record<string, string> = { page: String(page), per_page: '20' };
      if (query)                     params.q = query;
      if (statusFilter !== 'all')    params.status = statusFilter;
      if (currencyFilter !== 'all')  params.currency = currencyFilter;
      const res = await client.listWallets(params);
      setWallets({ data: res.data, meta: res.meta as unknown as PaginatedMeta });
    } catch {
      setError('Failed to load wallets. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, query, statusFilter, currencyFilter, page]);

  useEffect(() => { void fetchWallets(); }, [fetchWallets]);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    void fetchWallets();
  };

  return (
    <PageContainer>
      <PageHeader title="Wallet Management" />

      <Card className="mb-6 p-4">
        <form onSubmit={handleSearch} className="flex flex-wrap gap-3 items-end">
          <div className="flex-1 min-w-[200px]">
            <label htmlFor="wallet-search" className="block text-sm font-medium text-text-secondary mb-1">
              Search
            </label>
            <input
              id="wallet-search"
              type="search"
              value={query}
              onChange={e => setQuery(e.target.value)}
              placeholder="Wallet ID, owner email or phone…"
              className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            />
          </div>

          <div>
            <label htmlFor="wallet-status" className="block text-sm font-medium text-text-secondary mb-1">
              Status
            </label>
            <select
              id="wallet-status"
              value={statusFilter}
              onChange={e => { setStatusFilter(e.target.value); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            >
              <option value="all">All Statuses</option>
              <option value="active">Active</option>
              <option value="frozen">Frozen</option>
              <option value="closed">Closed</option>
            </select>
          </div>

          <div>
            <label htmlFor="wallet-currency" className="block text-sm font-medium text-text-secondary mb-1">
              Currency
            </label>
            <select
              id="wallet-currency"
              value={currencyFilter}
              onChange={e => { setCurrencyFilter(e.target.value); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            >
              <option value="all">All Currencies</option>
              <option value="BWP">BWP</option>
              <option value="USD">USD</option>
              <option value="ZAR">ZAR</option>
            </select>
          </div>

          <Button type="submit" variant="primary">
            Search
          </Button>
        </form>
      </Card>

      {loading && <Loading label="Loading wallets…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchWallets()} />}
      {!loading && !error && wallets?.data.length === 0 && (
        <Empty title="No wallets found" description="Try adjusting your search or filter." />
      )}

      {!loading && !error && wallets && wallets.data.length > 0 && (
        <>
          <div className="overflow-x-auto rounded border border-ok-border">
            <table className="min-w-full" aria-label="Wallets table">
              <thead className="bg-ok-surface">
                <tr>
                  {['Wallet ID', 'Owner', 'Currency', 'Balance', 'Status', 'Created', 'Actions'].map(h => (
                    <th key={h} className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {wallets.data.map(wallet => (
                  <WalletRow key={wallet.id} wallet={wallet} />
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center justify-between text-sm text-text-secondary">
            <span>
              Page {wallets.meta.current_page} of {wallets.meta.last_page} · {wallets.meta.total} total
            </span>
            <div className="flex gap-2">
              <Button
                variant="secondary"
                onClick={() => setPage(p => Math.max(1, p - 1))}
                disabled={page <= 1}
                aria-label="Previous page"
              >
                Previous
              </Button>
              <Button
                variant="secondary"
                onClick={() => setPage(p => p + 1)}
                disabled={page >= wallets.meta.last_page}
                aria-label="Next page"
              >
                Next
              </Button>
            </div>
          </div>
        </>
      )}
    </PageContainer>
  );
}
