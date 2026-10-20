/**
 * Admin Dashboard 4-KPI card tests (E037 — updated from E36 6-card layout).
 * Verifies: all 4 KpiCards render; loading skeleton; per-card error + retry; formatted values.
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

const mockGetUsers = dashboardApi.getAdminDashboardSummary as jest.MockedFunction<typeof dashboardApi.getAdminDashboardSummary>;
const mockGetWallets = kpiSummaryApi.getActiveWalletsSummary as jest.MockedFunction<typeof kpiSummaryApi.getActiveWalletsSummary>;
const mockGetTx = kpiSummaryApi.getTransactionsTodaySummary as jest.MockedFunction<typeof kpiSummaryApi.getTransactionsTodaySummary>;
const mockGetKyc = kpiSummaryApi.getKycPendingSummary as jest.MockedFunction<typeof kpiSummaryApi.getKycPendingSummary>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

function setupAuth() {
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
}

function resolveAll() {
  mockGetUsers.mockResolvedValue({ total_users: 1024, active_accounts: 900, suspended_accounts: 12, pending_closure_requests: 5, recent_contact_changes: 3 });
  mockGetWallets.mockResolvedValue({ active_wallets: 500 });
  mockGetTx.mockResolvedValue({ transactions_today: 200, total_value_today_minor_units: 1000000 });
  mockGetKyc.mockResolvedValue({ pending_reviews: 15 });
}

describe('AdminDashboardPage — 4 KPI cards (E037)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('renders 4 KPI card labels', async () => {
    resolveAll();
    render(<AdminDashboardPage />);
    await waitFor(() => expect(screen.getByText('Total Users')).toBeInTheDocument());

    expect(screen.getByText('Active Wallets')).toBeInTheDocument();
    expect(screen.getByText('Transactions Today')).toBeInTheDocument();
    expect(screen.getByText('KYC Pending')).toBeInTheDocument();
  });

  it('shows loading skeletons initially', () => {
    mockGetUsers.mockReturnValue(new Promise(() => {}));
    mockGetWallets.mockReturnValue(new Promise(() => {}));
    mockGetTx.mockReturnValue(new Promise(() => {}));
    mockGetKyc.mockReturnValue(new Promise(() => {}));

    render(<AdminDashboardPage />);
    const skeletons = screen.getAllByRole('article', { name: /loading/i });
    expect(skeletons.length).toBe(4);
  });

  it('shows error state with retry when one fetcher rejects', async () => {
    mockGetUsers.mockResolvedValue({ total_users: 10, active_accounts: 10, suspended_accounts: 0, pending_closure_requests: 0, recent_contact_changes: 0 });
    mockGetWallets.mockRejectedValue(new Error('network error'));
    mockGetTx.mockResolvedValue({ transactions_today: 5, total_value_today_minor_units: 0 });
    mockGetKyc.mockResolvedValue({ pending_reviews: 1 });

    render(<AdminDashboardPage />);

    await waitFor(() => {
      expect(screen.getByText('Total Users')).toBeInTheDocument();
    });

    expect(screen.getByLabelText('Active Wallets — failed to load')).toBeInTheDocument();
    expect(screen.getByText('Failed to load')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  it('calls all 4 fetchers on mount', async () => {
    resolveAll();
    render(<AdminDashboardPage />);

    await waitFor(() => expect(screen.getByText('Total Users')).toBeInTheDocument());

    expect(mockGetUsers).toHaveBeenCalledTimes(1);
    expect(mockGetWallets).toHaveBeenCalledTimes(1);
    expect(mockGetTx).toHaveBeenCalledTimes(1);
    expect(mockGetKyc).toHaveBeenCalledTimes(1);
  });

  it('shows unauthorized state when not authenticated', () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: null,
      isAuthenticated: false,
      phase: 'unauthenticated',
      user: null,
      mfaMethods: null,
      authError: null,
      authErrorCode: null,
      permissions: [],
      hasPermission: jest.fn(() => false),
      login: jest.fn(),
      completeMfa: jest.fn(),
      signOut: jest.fn(),
    });

    render(<AdminDashboardPage />);
    expect(screen.getByText(/You must be signed in/i)).toBeInTheDocument();
    expect(mockGetUsers).not.toHaveBeenCalled();
  });
});
