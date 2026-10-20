'use client';

import { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';

interface OrderLine {
  id: string;
  description: string;
  quantity: number;
  unit_amount_minor: number;
  total_amount_minor: number;
  currency: string;
}

interface PaymentAttempt {
  id: string;
  status: string;
  amount_minor: number;
  currency: string;
  provider_reference: string | null;
  created_at: string;
}

interface Receipt {
  id: string;
  external_ref: string;
  amount_minor: number;
  currency: string;
  issued_at: string;
}

interface Order {
  id: string;
  organisation_id: string;
  service_type: string;
  jurisdiction: string;
  consumer_id: string;
  status: string;
  unit_amount_minor: number;
  total_amount_minor: number;
  fee_amount_minor: number;
  currency: string;
  quantity: number;
  idempotency_key: string;
  confirmed_at: string | null;
  cancelled_at: string | null;
  created_at: string;
  lines: OrderLine[];
  payment_attempts: PaymentAttempt[];
  receipt: Receipt | null;
}

function formatAmount(minor: number, currency: string): string {
  return `${currency} ${(minor / 100).toFixed(2)}`;
}

export function OrderDetailPage() {
  const { authenticatedClient } = useAuth();
  const params = useParams<{ id: string }>();
  const router = useRouter();

  const [order, setOrder] = useState<Order | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchOrder = useCallback(async () => {
    if (!authenticatedClient || !params.id) return;
    setLoading(true);
    setError(null);
    try {
      const res = await authenticatedClient.get<Order>(`/v1/community/admin/orders/${params.id}`);
      setOrder(res.data);
    } catch {
      setError('Failed to load order.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, params.id]);

  useEffect(() => { void fetchOrder(); }, [fetchOrder]);

  if (loading) return <PageContainer><Loading label="Loading order…" /></PageContainer>;
  if (error || !order) return (
    <PageContainer>
      <ErrorState message={error ?? 'Order not found.'} onRetry={() => void fetchOrder()} />
    </PageContainer>
  );

  return (
    <PageContainer>
      <PageHeader
        title={`Order ${order.id.slice(0, 8)}…`}
        actions={<Button variant="secondary" onClick={() => router.back()}>Back</Button>}
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          <Card>
            <h2 className="text-base font-semibold text-text-primary mb-4">Order Details</h2>
            <dl className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <dt className="text-text-secondary">Service Type</dt>
                <dd className="text-text-primary mt-1 capitalize">{order.service_type}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Status</dt>
                <dd className="mt-1">
                  <Badge label={order.status.toUpperCase()} variant={order.status === 'confirmed' ? 'success' : order.status === 'failed' ? 'error' : 'neutral'} />
                </dd>
              </div>
              <div>
                <dt className="text-text-secondary">Total</dt>
                <dd className="text-text-primary mt-1">{formatAmount(order.total_amount_minor, order.currency)}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Fee</dt>
                <dd className="text-text-primary mt-1">{formatAmount(order.fee_amount_minor, order.currency)}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Jurisdiction</dt>
                <dd className="text-text-primary mt-1">{order.jurisdiction}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Quantity</dt>
                <dd className="text-text-primary mt-1">{order.quantity}</dd>
              </div>
            </dl>
          </Card>

          {order.lines.length > 0 && (
            <Card>
              <h2 className="text-base font-semibold text-text-primary mb-4">Order Lines</h2>
              <table className="w-full text-sm" aria-label="Order lines">
                <thead>
                  <tr className="border-b text-text-secondary">
                    <th className="text-left py-2">Description</th>
                    <th className="text-right py-2">Qty</th>
                    <th className="text-right py-2">Unit</th>
                    <th className="text-right py-2">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {order.lines.map((l) => (
                    <tr key={l.id} className="border-b border-[#f0f1f4]">
                      <td className="py-2">{l.description}</td>
                      <td className="py-2 text-right">{l.quantity}</td>
                      <td className="py-2 text-right">{formatAmount(l.unit_amount_minor, l.currency)}</td>
                      <td className="py-2 text-right">{formatAmount(l.total_amount_minor, l.currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}

          <Card>
            <h2 className="text-base font-semibold text-text-primary mb-4">Payment Attempts</h2>
            {order.payment_attempts.length === 0 ? (
              <p className="text-sm text-text-secondary">No payment attempts yet.</p>
            ) : (
              <ul className="space-y-2">
                {order.payment_attempts.map((a) => (
                  <li key={a.id} className="flex justify-between items-center text-sm border rounded px-3 py-2">
                    <span className="font-mono text-xs">{a.provider_reference ?? a.id.slice(0, 8)}</span>
                    <span>{formatAmount(a.amount_minor, a.currency)}</span>
                    <Badge label={a.status.toUpperCase()} variant={a.status === 'confirmed' ? 'success' : a.status === 'failed' ? 'error' : 'warning'} />
                    <span className="text-text-secondary">{new Date(a.created_at).toLocaleString()}</span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <div className="space-y-6">
          {order.receipt && (
            <Card>
              <h2 className="text-base font-semibold text-text-primary mb-4">Receipt</h2>
              <dl className="text-sm space-y-2">
                <div>
                  <dt className="text-text-secondary">Reference</dt>
                  <dd className="font-mono text-text-primary mt-1">{order.receipt.external_ref}</dd>
                </div>
                <div>
                  <dt className="text-text-secondary">Amount</dt>
                  <dd className="text-text-primary mt-1">{formatAmount(order.receipt.amount_minor, order.receipt.currency)}</dd>
                </div>
                <div>
                  <dt className="text-text-secondary">Issued</dt>
                  <dd className="text-text-primary mt-1">{new Date(order.receipt.issued_at).toLocaleString()}</dd>
                </div>
              </dl>
            </Card>
          )}
        </div>
      </div>
    </PageContainer>
  );
}
