'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';

interface Order {
  id: string;
  organisation_id: string;
  service_type: string;
  status: string;
  total_amount_minor: number;
  currency: string;
  consumer_id: string;
  created_at: string;
}

type StatusVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

function statusVariant(status: string): StatusVariant {
  switch (status) {
    case 'confirmed': return 'success';
    case 'pending':   return 'warning';
    case 'cancelled': return 'neutral';
    case 'refunded':  return 'info';
    case 'failed':    return 'error';
    default:          return 'neutral';
  }
}

function formatAmount(minor: number, currency: string): string {
  return `${currency} ${(minor / 100).toFixed(2)}`;
}

export function OrderListPage() {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [serviceFilter, setServiceFilter] = useState('');

  const fetchOrders = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (statusFilter) params.set('status', statusFilter);
      if (serviceFilter) params.set('service_type', serviceFilter);
      const res = await authenticatedClient.get<{ data: Order[] }>(
        `/v1/community/admin/orders?${params.toString()}`
      );
      setOrders(res.data.data ?? []);
    } catch {
      setError('Failed to load orders.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, statusFilter, serviceFilter]);

  useEffect(() => { void fetchOrders(); }, [fetchOrders]);

  return (
    <PageContainer>
      <PageHeader title="Community Orders" />

      <div className="mb-4 flex gap-3 flex-wrap">
        <select
          aria-label="Filter by status"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="confirmed">Confirmed</option>
          <option value="cancelled">Cancelled</option>
          <option value="refunded">Refunded</option>
          <option value="failed">Failed</option>
        </select>
        <select
          aria-label="Filter by service type"
          value={serviceFilter}
          onChange={(e) => setServiceFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All services</option>
          <option value="funeral">Funeral</option>
          <option value="events">Events</option>
          <option value="raffle">Raffle</option>
        </select>
        <Button variant="secondary" onClick={() => void fetchOrders()}>Refresh</Button>
      </div>

      {loading && <Loading label="Loading orders…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchOrders()} />}

      {!loading && !error && (
        <Card>
          <Table caption="Community orders list">
            <thead>
              <tr className="border-b border-ok-border bg-neutral-50">
                {['Service', 'Consumer', 'Amount', 'Status', 'Created'].map((h) => (
                  <th key={h} scope="col" className="px-4 py-3 text-sm font-medium text-text-secondary">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {orders.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-8 text-center text-text-secondary text-sm">
                    No orders found.
                  </td>
                </tr>
              ) : (
                orders.map((o) => (
                  <tr
                    key={o.id}
                    className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg cursor-pointer"
                    onClick={() => router.push(`/community/orders/${o.id}`)}
                    tabIndex={0}
                    role="row"
                    aria-label={`View order ${o.id}`}
                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') router.push(`/community/orders/${o.id}`); }}
                  >
                    <td className="px-4 py-3 text-sm text-text-primary capitalize">{o.service_type}</td>
                    <td className="px-4 py-3 text-sm font-mono text-text-primary">{o.consumer_id.slice(0, 8)}…</td>
                    <td className="px-4 py-3 text-sm text-text-primary">{formatAmount(o.total_amount_minor, o.currency)}</td>
                    <td className="px-4 py-3">
                      <Badge label={o.status.toUpperCase()} variant={statusVariant(o.status)} />
                    </td>
                    <td className="px-4 py-3 text-sm text-text-primary">{new Date(o.created_at).toLocaleDateString()}</td>
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
