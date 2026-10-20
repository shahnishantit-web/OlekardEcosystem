'use client';

import { useCallback, useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { FinancialDashboard } from '@/lib/api/admin-app-client';

function formatMinorUnits(amount: number, currency: string): string {
  return `${currency} ${(amount / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
}

function cachedAtLabel(cachedAt: string): string {
  const diffMs = Date.now() - new Date(cachedAt).getTime();
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return 'just now';
  return `${diffMin} min ago`;
}

export function FinancialDashboardPage() {
  const { authenticatedClient } = useAuth();

  const [data, setData] = useState<FinancialDashboard | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetch = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getFinancialDashboard();
      setData(res.data);
    } catch {
      setError('Failed to load financial dashboard. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void fetch();
    const interval = setInterval(() => void fetch(), 300_000);
    return () => clearInterval(interval);
  }, [fetch]);

  return (
    <PageContainer>
        <PageHeader
          title="Financial Dashboard"
          actions={
            <Button variant="secondary" onClick={() => void fetch()} aria-label="Refresh financial metrics">
              Refresh
            </Button>
          }
        />
        {data && (
          <p className="text-xs text-text-secondary mb-4" aria-live="polite">
            Last updated {cachedAtLabel(data.cached_at)}
          </p>
        )}

      {loading && <Loading label="Loading financial metrics…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetch()} />}

      {!loading && !error && data && (
        <section aria-label="Financial metrics">
          <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card as="div">
              <dt className="text-sm font-medium text-text-secondary">Total Wallet Balance</dt>
              <dd className="mt-2 text-2xl font-bold tabular-nums">
                {formatMinorUnits(data.total_wallet_balance_minor_units, data.currency)}
              </dd>
            </Card>
            <Card as="div">
              <dt className="text-sm font-medium text-text-secondary">Transactions Today</dt>
              <dd className="mt-2 text-2xl font-bold tabular-nums">{data.transactions_today_count.toLocaleString()}</dd>
            </Card>
            <Card as="div">
              <dt className="text-sm font-medium text-text-secondary">Volume Today</dt>
              <dd className="mt-2 text-2xl font-bold tabular-nums">
                {formatMinorUnits(data.transactions_today_volume_minor_units, data.currency)}
              </dd>
            </Card>
            <Card as="div">
              <dt className="text-sm font-medium text-text-secondary">Pending Settlements</dt>
              <dd className="mt-2 text-2xl font-bold tabular-nums">
                {formatMinorUnits(data.pending_settlements_minor_units, data.currency)}
              </dd>
            </Card>
            <Card as="div">
              <dt className="text-sm font-medium text-text-secondary">Failed Transactions Today</dt>
              <dd className="mt-2 flex items-center gap-2">
                <span className="text-2xl font-bold tabular-nums">{data.failed_transactions_today}</span>
                {data.failed_transactions_today > 0 && (
                  <Badge label="ACTION NEEDED" variant="error" />
                )}
              </dd>
            </Card>
          </dl>
        </section>
      )}
    </PageContainer>
  );
}
