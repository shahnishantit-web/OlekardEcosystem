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
import type { AdminTransaction, PaginatedMeta, PaginatedResponse } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

function TxStatusBadge({ status }: { status: string }) {
  const variant =
    status === 'completed' ? 'success' :
    status === 'failed'    ? 'error'   :
    status === 'reversed'  ? 'warning' : 'neutral';
  return <Badge label={status.toUpperCase()} variant={variant} />;
}

function TxRow({ transaction }: { transaction: AdminTransaction }) {
  const txId       = transaction.transaction_id ?? transaction.id;
  const ref        = transaction.reference_number ?? txId.substring(0, 8) + '…';
  const currency   = transaction.currency_code ?? transaction.currency;
  const amountStr  = (transaction.amount_minor_units / 100).toLocaleString(undefined, { minimumFractionDigits: 2 });
  const initiatorId = transaction.initiator_user_id ?? null;
  const initiatorName = transaction.initiator_name ?? '—';

  return (
    <tr className="border-b border-ok-border hover:bg-ok-hover-bg transition-colors">
      <td className="py-3 px-4 text-sm font-mono text-text-secondary">{ref}</td>
      <td className="py-3 px-4 text-sm text-text-secondary capitalize">
        {transaction.type.replace(/_/g, ' ')}
      </td>
      <td className="py-3 px-4 text-sm text-text-primary tabular-nums">
        {currency} {amountStr}
      </td>
      <td className="py-3 px-4">
        <TxStatusBadge status={transaction.status} />
      </td>
      <td className="py-3 px-4 text-sm text-text-secondary">
        {initiatorId ? (
          <Link href={`/admin-portal/users/${initiatorId}`} className="text-ok-primary underline hover:text-ok-primary-hover">
            {initiatorName}
          </Link>
        ) : (
          <span>{initiatorName}</span>
        )}
      </td>
      <td className="py-3 px-4 text-sm text-text-secondary">
        {new Date(transaction.created_at).toLocaleString()}
      </td>
      <td className="py-3 px-4">
        <Link href={`/admin-portal/transactions/${txId}`}>
          <Button variant="secondary" aria-label={`View transaction ${ref}`}>
            View
          </Button>
        </Link>
      </td>
    </tr>
  );
}

export function TransactionManagementPage() {
  const { authenticatedClient } = useAuth();

  const [transactions, setTransactions] = useState<PaginatedResponse<AdminTransaction> | null>(null);
  const [loading, setLoading]           = useState(false);
  const [error, setError]               = useState<string | null>(null);
  const [exportMessage, setExportMessage] = useState<string | null>(null);
  const [exporting, setExporting]       = useState(false);

  const [filters, setFilters] = useState({
    q:          '',
    type:       '',
    status:     '',
    currency:   '',
    from_date:  '',
    to_date:    '',
    min_amount: '',
    max_amount: '',
  });
  const [page, setPage] = useState(1);

  const fetchTransactions = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const params: Record<string, string> = { page: String(page), per_page: '25' };
      if (filters.q)          params.q          = filters.q;
      if (filters.type)       params.type        = filters.type;
      if (filters.status)     params.status      = filters.status;
      if (filters.currency)   params.currency    = filters.currency;
      if (filters.from_date)  params.from_date   = filters.from_date;
      if (filters.to_date)    params.to_date     = filters.to_date;
      if (filters.min_amount) params.min_amount  = String(Math.round(parseFloat(filters.min_amount) * 100));
      if (filters.max_amount) params.max_amount  = String(Math.round(parseFloat(filters.max_amount) * 100));
      const res = await client.listTransactions(params);
      setTransactions({ data: res.data, meta: res.meta as unknown as PaginatedMeta });
    } catch {
      setError('Failed to load transactions. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, filters, page]);

  useEffect(() => { void fetchTransactions(); }, [fetchTransactions]);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    void fetchTransactions();
  };

  const handleExport = async () => {
    if (!authenticatedClient) return;
    setExporting(true);
    setExportMessage(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const params: Record<string, string> = { format: 'csv' };
      if (filters.q)         params.q         = filters.q;
      if (filters.type)      params.type       = filters.type;
      if (filters.status)    params.status     = filters.status;
      if (filters.currency)  params.currency   = filters.currency;
      if (filters.from_date) params.from_date  = filters.from_date;
      if (filters.to_date)   params.to_date    = filters.to_date;
      const idemKey = generateIdempotencyKey('tx-export');
      const res = await client.exportTransactions(params, idemKey);
      const result = res.data as unknown as { export_id: string; status: string };
      setExportMessage(
        `Export queued (ID: ${result.export_id}). You will be notified when the download is ready.`,
      );
    } catch {
      setExportMessage('Failed to queue export. Please try again.');
    } finally {
      setExporting(false);
    }
  };

  return (
    <PageContainer>
      <PageHeader
        title="Transaction Management"
        actions={
          <Button
            variant="secondary"
            onClick={() => void handleExport()}
            disabled={exporting}
          >
            {exporting ? 'Queuing…' : 'Export CSV'}
          </Button>
        }
      />

      {exportMessage && (
        <div role="status" aria-live="polite" className="mb-4 rounded border border-ok-border bg-ok-surface p-3 text-sm text-text-secondary">
          {exportMessage}
        </div>
      )}

      {/* Filters */}
      <Card className="mb-6 p-4">
        <form onSubmit={handleSearch} className="flex flex-wrap gap-3 items-end">
          <div className="flex-1 min-w-[200px]">
            <label htmlFor="tx-search" className="block text-sm font-medium text-text-secondary mb-1">
              Search
            </label>
            <input
              id="tx-search"
              type="search"
              value={filters.q}
              onChange={e => setFilters(f => ({ ...f, q: e.target.value }))}
              placeholder="Tx ID, reference or user…"
              className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            />
          </div>

          <div>
            <label htmlFor="tx-type" className="block text-sm font-medium text-text-secondary mb-1">
              Type
            </label>
            <select
              id="tx-type"
              value={filters.type}
              onChange={e => { setFilters(f => ({ ...f, type: e.target.value })); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            >
              <option value="">All types</option>
              <option value="wallet_transfer">Transfer</option>
              <option value="agent_cash_in">Agent Cash-In</option>
              <option value="agent_cash_out">Agent Cash-Out</option>
              <option value="funding">Funding</option>
              <option value="withdrawal">Withdrawal</option>
              <option value="bill_payment">Bill Payment</option>
            </select>
          </div>

          <div>
            <label htmlFor="tx-status" className="block text-sm font-medium text-text-secondary mb-1">
              Status
            </label>
            <select
              id="tx-status"
              value={filters.status}
              onChange={e => { setFilters(f => ({ ...f, status: e.target.value })); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            >
              <option value="">All statuses</option>
              <option value="pending">Pending</option>
              <option value="completed">Completed</option>
              <option value="failed">Failed</option>
              <option value="reversed">Reversed</option>
            </select>
          </div>

          <div>
            <label htmlFor="tx-currency" className="block text-sm font-medium text-text-secondary mb-1">
              Currency
            </label>
            <select
              id="tx-currency"
              value={filters.currency}
              onChange={e => { setFilters(f => ({ ...f, currency: e.target.value })); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            >
              <option value="">All currencies</option>
              <option value="BWP">BWP</option>
              <option value="USD">USD</option>
              <option value="ZAR">ZAR</option>
            </select>
          </div>

          <div>
            <label htmlFor="tx-from" className="block text-sm font-medium text-text-secondary mb-1">
              From
            </label>
            <input
              id="tx-from"
              type="date"
              value={filters.from_date}
              onChange={e => { setFilters(f => ({ ...f, from_date: e.target.value })); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            />
          </div>

          <div>
            <label htmlFor="tx-to" className="block text-sm font-medium text-text-secondary mb-1">
              To
            </label>
            <input
              id="tx-to"
              type="date"
              value={filters.to_date}
              onChange={e => { setFilters(f => ({ ...f, to_date: e.target.value })); setPage(1); }}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
            />
          </div>

          <div>
            <label htmlFor="tx-min" className="block text-sm font-medium text-text-secondary mb-1">
              Min (BWP)
            </label>
            <input
              id="tx-min"
              type="number"
              min="0"
              step="0.01"
              value={filters.min_amount}
              onChange={e => setFilters(f => ({ ...f, min_amount: e.target.value }))}
              className="w-24 rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              placeholder="0.00"
            />
          </div>

          <div>
            <label htmlFor="tx-max" className="block text-sm font-medium text-text-secondary mb-1">
              Max (BWP)
            </label>
            <input
              id="tx-max"
              type="number"
              min="0"
              step="0.01"
              value={filters.max_amount}
              onChange={e => setFilters(f => ({ ...f, max_amount: e.target.value }))}
              className="w-24 rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              placeholder="0.00"
            />
          </div>

          <Button type="submit" variant="primary">
            Search
          </Button>
        </form>
      </Card>

      {loading && <Loading label="Loading transactions…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchTransactions()} />}
      {!loading && !error && transactions?.data.length === 0 && (
        <Empty title="No transactions found" description="Try adjusting your search or filters." />
      )}

      {!loading && !error && transactions && transactions.data.length > 0 && (
        <>
          <div className="overflow-x-auto rounded border border-ok-border">
            <table className="min-w-full" aria-label="Transactions table">
              <thead className="bg-ok-surface">
                <tr>
                  {['Reference', 'Type', 'Amount', 'Status', 'User', 'Date', 'Actions'].map(h => (
                    <th key={h} className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {transactions.data.map(tx => (
                  <TxRow key={tx.transaction_id ?? tx.id} transaction={tx} />
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center justify-between text-sm text-text-secondary">
            <span>
              Page {transactions.meta.current_page} of {transactions.meta.last_page} · {transactions.meta.total} total
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
                disabled={page >= transactions.meta.last_page}
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
