'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth/context';
import type { Bank, BankLifecycleStatus } from '@/lib/api/payments/banks';
import { BankStatusBadge } from '../components/BankStatusBadge';

type ListedBank = Bank & { environment: string; is_enabled: boolean };

export function BankList() {
  const { authenticatedClient, hasPermission } = useAuth();
  const allowed = hasPermission('payment.configuration');
  const [status, setStatus] = useState<BankLifecycleStatus | 'all'>('all');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [banks, setBanks] = useState<ListedBank[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!authenticatedClient || !allowed) return;
    const controller = new AbortController();
    setLoading(true);
    setError(false);
    const query = new URLSearchParams({ page: String(page), per_page: '25' });
    if (status !== 'all') query.set('status', status);
    authenticatedClient.get<ListedBank[]>(`/api/v1/admin/payments/banks?${query}`, { signal: controller.signal })
      .then(response => {
        if (controller.signal.aborted) return;
        setBanks(response.data);
        setLastPage(Number(response.meta?.['last_page'] ?? 1));
      })
      .catch(() => { if (!controller.signal.aborted) setError(true); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [authenticatedClient, allowed, page, status]);

  if (!allowed) return <p role="alert">You do not have permission to view bank configuration.</p>;

  return <div className="space-y-4">
    <div className="flex gap-2" aria-label="Filter banks by status">
      {(['all', 'draft', 'active', 'suspended', 'retired'] as const).map(value => (
        <button key={value} type="button" aria-pressed={status === value}
          onClick={() => { setStatus(value); setPage(1); }}
          className="rounded-full border border-gray-200 px-3 py-1 text-xs font-medium capitalize aria-pressed:border-blue-600 aria-pressed:text-blue-600">
          {value}
        </button>
      ))}
    </div>
    {loading ? <p role="status">Loading banks…</p> : error ? <p role="alert">Unable to load banks. Refresh the page to retry.</p> :
      banks.length === 0 ? <p>No banks match this filter.</p> : <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-left text-sm">
          <caption className="sr-only">Configured bank integrations</caption>
          <thead><tr className="border-b"><th scope="col" className="p-4">Bank</th><th scope="col" className="p-4">Code</th><th scope="col" className="p-4">Status</th><th scope="col" className="p-4">Connection</th></tr></thead>
          <tbody>{banks.map(bank => <tr key={bank.id} className="border-b last:border-0">
            <td className="p-4"><a className="font-medium text-blue-600 hover:underline" href={`/payments/banks/${bank.id}`}>{bank.display_name}</a>
              {bank.description && <p className="mt-1 text-xs text-gray-500">{bank.description}</p>}</td>
            <td className="p-4">{bank.code}</td>
            <td className="p-4"><BankStatusBadge status={bank.bank_lifecycle_status} type="bank" /></td>
            <td className="p-4">{bank.environment === 'mock' ? 'Development mock · test only' : bank.environment} · {bank.is_enabled ? 'Enabled' : 'Disabled'}</td>
          </tr>)}</tbody>
        </table>
      </div>}
    <div className="flex items-center gap-3">
      <button type="button" disabled={loading || page <= 1} onClick={() => setPage(page - 1)}>Previous</button>
      <span>Page {page} of {lastPage}</span>
      <button type="button" disabled={loading || page >= lastPage} onClick={() => setPage(page + 1)}>Next</button>
    </div>
  </div>;
}
