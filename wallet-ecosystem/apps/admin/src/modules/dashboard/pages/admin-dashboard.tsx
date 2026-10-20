'use client';

import { useCallback, useEffect, useState } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { ErrorState } from '@/components/ui/ErrorState';
import { KpiCard } from '@/components/dashboard/KpiCard';
import { MetricChart } from '@/components/dashboard/MetricChart';
import type { MetricChartPeriod } from '@/components/dashboard/MetricChart';
import { ActivityFeed } from '@/components/dashboard/ActivityFeed';
import { RecentTransactionsTable } from '@/components/dashboard/RecentTransactionsTable';
import { QuickActionsPanel } from '@/components/dashboard/QuickActionsPanel';
import { getAdminDashboardSummary } from '@/src/modules/dashboard/api/dashboard';
import {
  getActiveWalletsSummary,
  getTransactionsTodaySummary,
  getKycPendingSummary,
} from '@/src/modules/dashboard/api/kpi-summary';
import type { AdminDashboardSummary } from '@/src/modules/dashboard/api/dashboard';
import type {
  WalletsSummary,
  TransactionsTodaySummary,
  KycPendingSummary,
} from '@/src/modules/dashboard/api/kpi-summary';

interface CardState<T> {
  data: T | null;
  loading: boolean;
  error: boolean;
}

function initialCard<T>(): CardState<T> {
  return { data: null, loading: true, error: false };
}

const PERIOD_OPTIONS: { value: MetricChartPeriod; label: string }[] = [
  { value: '7',  label: 'Last 7 days' },
  { value: '30', label: 'Last 30 days' },
  { value: '90', label: 'Last 90 days' },
];

function periodFromParam(param: string | null): MetricChartPeriod {
  if (param === '7d' || param === '7') return '7';
  if (param === '30d' || param === '30') return '30';
  if (param === '90d' || param === '90') return '90';
  return '7';
}

export function AdminDashboardPage() {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const searchParams = useSearchParams();
  const router = useRouter();
  const [period, setPeriod] = useState<MetricChartPeriod>(() =>
    periodFromParam(searchParams.get('period')),
  );

  const [users, setUsers]             = useState<CardState<AdminDashboardSummary>>(initialCard);
  const [wallets, setWallets]         = useState<CardState<WalletsSummary>>(initialCard);
  const [transactions, setTx]         = useState<CardState<TransactionsTodaySummary>>(initialCard);
  const [kyc, setKyc]                 = useState<CardState<KycPendingSummary>>(initialCard);

  const fetchUsers = useCallback(async () => {
    if (!authenticatedClient) return;
    setUsers({ data: null, loading: true, error: false });
    try {
      const d = await getAdminDashboardSummary(authenticatedClient);
      setUsers({ data: d, loading: false, error: false });
    } catch {
      setUsers({ data: null, loading: false, error: true });
    }
  }, [authenticatedClient]);

  const fetchWallets = useCallback(async () => {
    if (!authenticatedClient) return;
    setWallets({ data: null, loading: true, error: false });
    try {
      const d = await getActiveWalletsSummary(authenticatedClient);
      setWallets({ data: d, loading: false, error: false });
    } catch {
      setWallets({ data: null, loading: false, error: true });
    }
  }, [authenticatedClient]);

  const fetchTx = useCallback(async () => {
    if (!authenticatedClient) return;
    setTx({ data: null, loading: true, error: false });
    try {
      const d = await getTransactionsTodaySummary(authenticatedClient);
      setTx({ data: d, loading: false, error: false });
    } catch {
      setTx({ data: null, loading: false, error: true });
    }
  }, [authenticatedClient]);

  const fetchKyc = useCallback(async () => {
    if (!authenticatedClient) return;
    setKyc({ data: null, loading: true, error: false });
    try {
      const d = await getKycPendingSummary(authenticatedClient);
      setKyc({ data: d, loading: false, error: false });
    } catch {
      setKyc({ data: null, loading: false, error: true });
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void fetchUsers();
    void fetchWallets();
    void fetchTx();
    void fetchKyc();
  }, [fetchUsers, fetchWallets, fetchTx, fetchKyc]);

  function handlePeriodChange(value: MetricChartPeriod) {
    setPeriod(value);
    const params = new URLSearchParams(searchParams.toString());
    params.set('period', `${value}d`);
    router.replace(`?${params.toString()}`, { scroll: false });
  }

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to view the dashboard."
      />
    );
  }

  return (
    <PageContainer>
      {/* Page title row */}
      <div className="flex items-start justify-between gap-4 mb-6 flex-wrap">
        <div>
          <p className="text-xs font-semibold uppercase tracking-widest text-text-secondary/60 mb-1">
            OPERATIONS / DASHBOARD
          </p>
          <h1 className="text-2xl font-bold text-text-primary">Business overview</h1>
          <p className="text-sm text-text-secondary mt-1">
            Key metrics and activity across the OleKard ecosystem
          </p>
        </div>
        <div className="flex items-center gap-3 shrink-0">
          <select
            value={period}
            onChange={(e) => handlePeriodChange(e.target.value as MetricChartPeriod)}
            className="h-[35px] rounded-lg border border-ok-border-strong bg-ok-surface px-3 text-sm text-text-primary font-medium focus:outline-none focus:border-ok-primary focus:ring-1 focus:ring-ok-focus-ring"
            aria-label="Select reporting period"
          >
            {PERIOD_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
          <Link
            href="/reports"
            className="inline-flex items-center h-[35px] px-3 rounded-lg bg-ok-primary-dark text-white text-sm font-medium hover:bg-ok-primary transition-colors whitespace-nowrap"
          >
            ↗ Export report
          </Link>
        </div>
      </div>

      {/* KPI grid — 2 cols on mobile, 4 cols on sm+ */}
      <section aria-label="Key metrics" className="mb-4">
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <KpiCard
            label="Total Users"
            value={users.data?.total_users ?? null}
            loading={users.loading}
            error={users.error}
            onRetry={fetchUsers}
          />
          <KpiCard
            label="Active Wallets"
            value={wallets.data?.active_wallets ?? null}
            loading={wallets.loading}
            error={wallets.error}
            onRetry={fetchWallets}
          />
          <KpiCard
            label="Transactions Today"
            value={transactions.data?.transactions_today ?? null}
            loading={transactions.loading}
            error={transactions.error}
            onRetry={fetchTx}
          />
          <KpiCard
            label="KYC Pending"
            value={kyc.data?.pending_reviews ?? null}
            loading={kyc.loading}
            error={kyc.error}
            onRetry={fetchKyc}
          />
        </div>
      </section>

      {/* Chart + activity row */}
      <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1.7fr)_minmax(270px,0.85fr)] gap-4 mb-4">
        <MetricChart period={period} />
        <ActivityFeed />
      </div>

      {/* Transactions + quick actions row */}
      <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1.7fr)_minmax(270px,0.85fr)] gap-4">
        <RecentTransactionsTable />
        <QuickActionsPanel />
      </div>
    </PageContainer>
  );
}
