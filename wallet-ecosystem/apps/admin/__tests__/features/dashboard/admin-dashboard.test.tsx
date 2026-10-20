/**
 * Admin Dashboard smoke tests — updated for E037 4-KPI card layout.
 * Full coverage lives in __tests__/dashboard/admin-dashboard.test.tsx (T040).
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

jest.mock('@/src/modules/dashboard/api/dashboard', () => ({
  getAdminDashboardSummary: jest.fn(),
}));

jest.mock('@/src/modules/dashboard/api/kpi-summary', () => ({
  getActiveWalletsSummary: jest.fn(),
  getTransactionsTodaySummary: jest.fn(),
  getKycPendingSummary: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

jest.mock('@/components/dashboard/MetricChart', () => ({
  MetricChart: () => <div data-testid="metric-chart" />,
}));

jest.mock('@/components/dashboard/ActivityFeed', () => ({
  ActivityFeed: () => <div data-testid="activity-feed" />,
}));

jest.mock('@/components/dashboard/RecentTransactionsTable', () => ({
  RecentTransactionsTable: () => <div data-testid="transactions-table" />,
}));

jest.mock('@/components/dashboard/QuickActionsPanel', () => ({
  QuickActionsPanel: () => <div data-testid="quick-actions" />,
}));

import { AdminDashboardPage } from '@/src/modules/dashboard/pages/admin-dashboard';
import * as dashboardApi from '@/src/modules/dashboard/api/dashboard';
import * as kpiSummaryApi from '@/src/modules/dashboard/api/kpi-summary';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const mockGetUsers = dashboardApi.getAdminDashboardSummary as jest.MockedFunction<typeof dashboardApi.getAdminDashboardSummary>;
const mockGetWallets = kpiSummaryApi.getActiveWalletsSummary as jest.MockedFunction<typeof kpiSummaryApi.getActiveWalletsSummary>;
const mockGetTx = kpiSummaryApi.getTransactionsTodaySummary as jest.MockedFunction<typeof kpiSummaryApi.getTransactionsTodaySummary>;
const mockGetKyc = kpiSummaryApi.getKycPendingSummary as jest.MockedFunction<typeof kpiSummaryApi.getKycPendingSummary>;

beforeEach(() => {
  jest.clearAllMocks();
  mockUseAuth.mockReturnValue({
    authenticatedClient: {} as never,
    isAuthenticated: true,
    phase: 'authenticated',
    user: { id: 'admin-1', email: 'admin@olekard.local', name: 'Admin' },
    mfaMethods: null,
    authError: null,
    authErrorCode: null,
    permissions: [],
    hasPermission: jest.fn(() => false),
    login: jest.fn(),
    completeMfa: jest.fn(),
    signOut: jest.fn(),
  });
  mockGetUsers.mockResolvedValue({ total_users: 100, active_accounts: 90, suspended_accounts: 5, pending_closure_requests: 2, recent_contact_changes: 1 });
  mockGetWallets.mockResolvedValue({ active_wallets: 80 });
  mockGetTx.mockResolvedValue({ transactions_today: 50, total_value_today_minor_units: 500000 });
  mockGetKyc.mockResolvedValue({ pending_reviews: 10 });
});

describe('AdminDashboardPage (smoke)', () => {
  it('renders the dashboard heading', async () => {
    render(<AdminDashboardPage />);
    await waitFor(() => expect(screen.getByText(/business overview/i)).toBeInTheDocument());
  });

  it('renders 4 KPI card labels after data loads', async () => {
    render(<AdminDashboardPage />);
    await waitFor(() => expect(screen.getByText('Total Users')).toBeInTheDocument());

    expect(screen.getByText('Active Wallets')).toBeInTheDocument();
    expect(screen.getByText('Transactions Today')).toBeInTheDocument();
    expect(screen.getByText('KYC Pending')).toBeInTheDocument();
  });
});
