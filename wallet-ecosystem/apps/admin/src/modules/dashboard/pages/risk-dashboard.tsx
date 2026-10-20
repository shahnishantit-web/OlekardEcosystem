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
import type { RiskDashboard } from '@/lib/api/admin-app-client';

function cachedAtLabel(cachedAt: string): string {
  const diffMin = Math.floor((Date.now() - new Date(cachedAt).getTime()) / 60000);
  return diffMin < 1 ? 'just now' : `${diffMin} min ago`;
}

export function RiskDashboardPage() {
  const { authenticatedClient } = useAuth();

  const [data, setData] = useState<RiskDashboard | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getRiskDashboard();
      setData(res.data);
    } catch {
      setError('Failed to load risk dashboard. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void fetchData();
    const interval = setInterval(() => void fetchData(), 300_000);
    return () => clearInterval(interval);
  }, [fetchData]);

  return (
    <PageContainer>
        <PageHeader
          title="Risk & Compliance Dashboard"
          actions={
            <Button variant="secondary" onClick={() => void fetchData()} aria-label="Refresh risk metrics">
              Refresh
            </Button>
          }
        />
        {data && (
          <p className="text-xs text-text-secondary mb-4" aria-live="polite">
            Last updated {cachedAtLabel(data.cached_at)}
          </p>
        )}

      {loading && <Loading label="Loading risk metrics…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchData()} />}

      {!loading && !error && data && (
        <section aria-label="Risk metrics">
          <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {[
              { label: 'Open Fraud Alerts',           value: data.active_fraud_alerts,          warn: data.active_fraud_alerts > 0 },
              { label: 'AML Flags Today',             value: data.aml_flags_today,              warn: data.aml_flags_today > 0 },
              { label: 'High-Risk Transactions Today',value: data.high_risk_transactions_today, warn: data.high_risk_transactions_today > 0 },
              { label: 'Blocked Accounts',            value: data.blocked_users_count,          warn: false },
              { label: 'Frozen Wallets',               value: data.frozen_wallets_count,         warn: false },
            ].map(({ label, value, warn }) => (
              <Card key={label} as="div">
                <dt className="text-sm font-medium text-text-secondary">{label}</dt>
                <dd className="mt-2 flex items-center gap-2">
                  <span className="text-2xl font-bold tabular-nums">{value.toLocaleString()}</span>
                  {warn && <Badge label="REVIEW" variant="warning" />}
                </dd>
              </Card>
            ))}
          </dl>
        </section>
      )}
    </PageContainer>
  );
}
