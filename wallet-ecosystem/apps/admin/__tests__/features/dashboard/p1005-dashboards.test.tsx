/**
 * ADM-P1-005 — Operational Dashboards
 * US-P1-005-001: Admin views the main operational dashboard
 * US-P1-005-002: Admin views the financial dashboard
 * US-P1-005-003: Admin views the risk dashboard
 * US-P1-005-004: Dashboard shows loading, empty, and error states
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ── Next.js navigation mock ───────────────────────────────────────────────────

const mockReplace = jest.fn();
const mockPush = jest.fn();
let mockSearchParamsValue = new URLSearchParams();

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: mockReplace, push: mockPush }),
  useSearchParams: () => mockSearchParamsValue,
}));

// ── API mocks ─────────────────────────────────────────────────────────────────

jest.mock('@/src/modules/dashboard/api/dashboard', () => ({
  getAdminDashboardSummary: jest.fn(),
}));

jest.mock('@/src/modules/dashboard/api/kpi-summary', () => ({
  getActiveWalletsSummary: jest.fn(),
  getTransactionsTodaySummary: jest.fn(),
  getKycPendingSummary: jest.fn(),
}));

const mockGetFinancial = jest.fn();
const mockGetRisk = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    getFinancialDashboard: mockGetFinancial,
    getRiskDashboard: mockGetRisk,
  })),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

// Dashboard sub-components — stub out to avoid deep renders
jest.mock('@/components/dashboard/MetricChart', () => ({
  MetricChart: ({ period }: { period: string }) => (
    <div data-testid="metric-chart" data-period={period} />
  ),
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

// ── Imports ───────────────────────────────────────────────────────────────────

import { AdminDashboardPage } from '@/src/modules/dashboard/pages/admin-dashboard';
import { FinancialDashboardPage } from '@/src/modules/dashboard/pages/financial-dashboard';
import { RiskDashboardPage } from '@/src/modules/dashboard/pages/risk-dashboard';
import * as dashboardApi from '@/src/modules/dashboard/api/dashboard';
import * as kpiSummaryApi from '@/src/modules/dashboard/api/kpi-summary';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const mockGetUsers = dashboardApi.getAdminDashboardSummary as jest.MockedFunction<typeof dashboardApi.getAdminDashboardSummary>;
const mockGetWallets = kpiSummaryApi.getActiveWalletsSummary as jest.MockedFunction<typeof kpiSummaryApi.getActiveWalletsSummary>;
const mockGetTx = kpiSummaryApi.getTransactionsTodaySummary as jest.MockedFunction<typeof kpiSummaryApi.getTransactionsTodaySummary>;
const mockGetKyc = kpiSummaryApi.getKycPendingSummary as jest.MockedFunction<typeof kpiSummaryApi.getKycPendingSummary>;

const MOCK_CLIENT = {} as never;

const FINANCIAL_DATA = {
  total_wallet_balance_minor_units: 500000,
  currency: 'BWP',
  transactions_today_count: 42,
  transactions_today_volume_minor_units: 420000,
  pending_settlements_minor_units: 100000,
  failed_transactions_today: 0,
  cached_at: new Date().toISOString(),
};

const RISK_DATA = {
  frozen_wallets_count: 3,
  active_fraud_alerts: 1,
  aml_flags_today: 2,
  high_risk_transactions_today: 5,
  blocked_users_count: 10,
  cached_at: new Date().toISOString(),
};

function setupAuth() {
  mockUseAuth.mockReturnValue({
    authenticatedClient: MOCK_CLIENT,
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

beforeEach(() => {
  jest.clearAllMocks();
  mockSearchParamsValue = new URLSearchParams();
  setupAuth();

  // Main dashboard KPI defaults
  mockGetUsers.mockResolvedValue({
    total_users: 100,
    active_accounts: 90,
    suspended_accounts: 5,
    pending_closure_requests: 2,
    recent_contact_changes: 1,
  });
  mockGetWallets.mockResolvedValue({ active_wallets: 80 });
  mockGetTx.mockResolvedValue({ transactions_today: 50, total_value_today_minor_units: 500000 });
  mockGetKyc.mockResolvedValue({ pending_reviews: 10 });

  // AdminAppClient instance mock defaults
  mockGetFinancial.mockResolvedValue({ data: FINANCIAL_DATA });
  mockGetRisk.mockResolvedValue({ data: RISK_DATA });
});

// ── US-P1-005-001: Main operational dashboard ─────────────────────────────────

describe('AdminDashboardPage — US-P1-005-001', () => {
  it('renders the page heading', async () => {
    render(<AdminDashboardPage />);
    await waitFor(() => expect(screen.getByText(/business overview/i)).toBeInTheDocument());
  });

  it('renders 4 KPI card labels once data loads', async () => {
    render(<AdminDashboardPage />);
    await waitFor(() => expect(screen.getByText('Total Users')).toBeInTheDocument());
    expect(screen.getByText('Active Wallets')).toBeInTheDocument();
    expect(screen.getByText('Transactions Today')).toBeInTheDocument();
    expect(screen.getByText('KYC Pending')).toBeInTheDocument();
  });

  it('renders the transaction volume chart', async () => {
    render(<AdminDashboardPage />);
    await waitFor(() => expect(screen.getByTestId('metric-chart')).toBeInTheDocument());
  });

  it('renders the quick actions panel', async () => {
    render(<AdminDashboardPage />);
    expect(screen.getByTestId('quick-actions')).toBeInTheDocument();
  });

  it('renders the activity feed', async () => {
    render(<AdminDashboardPage />);
    expect(screen.getByTestId('activity-feed')).toBeInTheDocument();
  });

  it('renders the recent transactions table', async () => {
    render(<AdminDashboardPage />);
    expect(screen.getByTestId('transactions-table')).toBeInTheDocument();
  });

  it('renders a period selector', async () => {
    render(<AdminDashboardPage />);
    expect(screen.getByRole('combobox', { name: /select reporting period/i })).toBeInTheDocument();
  });

  it('defaults to 7-day period', async () => {
    render(<AdminDashboardPage />);
    const selector = screen.getByRole('combobox', { name: /select reporting period/i }) as HTMLSelectElement;
    expect(selector.value).toBe('7');
  });

  it('reads period from URL ?period=30d', async () => {
    mockSearchParamsValue = new URLSearchParams('period=30d');
    render(<AdminDashboardPage />);
    const selector = screen.getByRole('combobox', { name: /select reporting period/i }) as HTMLSelectElement;
    expect(selector.value).toBe('30');
  });

  it('reads period from URL ?period=90d', async () => {
    mockSearchParamsValue = new URLSearchParams('period=90d');
    render(<AdminDashboardPage />);
    const selector = screen.getByRole('combobox', { name: /select reporting period/i }) as HTMLSelectElement;
    expect(selector.value).toBe('90');
  });

  it('calls router.replace with ?period=30d when period changes to 30', async () => {
    render(<AdminDashboardPage />);
    const selector = screen.getByRole('combobox', { name: /select reporting period/i });
    await userEvent.selectOptions(selector, '30');
    expect(mockReplace).toHaveBeenCalledWith(
      expect.stringContaining('period=30d'),
      expect.objectContaining({ scroll: false }),
    );
  });

  it('calls router.replace with ?period=90d when period changes to 90', async () => {
    render(<AdminDashboardPage />);
    const selector = screen.getByRole('combobox', { name: /select reporting period/i });
    await userEvent.selectOptions(selector, '90');
    expect(mockReplace).toHaveBeenCalledWith(
      expect.stringContaining('period=90d'),
      expect.objectContaining({ scroll: false }),
    );
  });
});

// ── US-P1-005-004 (main dashboard): loading / error states ────────────────────

describe('AdminDashboardPage — US-P1-005-004', () => {
  it('shows quick actions immediately (not gated by loading)', () => {
    // API calls are pending — quick actions must already be visible
    mockGetWallets.mockReturnValue(new Promise(() => undefined));
    render(<AdminDashboardPage />);
    expect(screen.getByTestId('quick-actions')).toBeInTheDocument();
  });

  it('KPI cards show skeleton while loading', () => {
    mockGetWallets.mockReturnValue(new Promise(() => undefined));
    render(<AdminDashboardPage />);
    // KpiCard in loading mode renders aria-busy="true" articles
    const busyCards = screen.queryAllByRole('article', { busy: true });
    expect(busyCards.length).toBeGreaterThan(0);
  });

  it('shows error retry button on KPI card failure', async () => {
    mockGetWallets.mockRejectedValueOnce(new Error('Network error'));
    render(<AdminDashboardPage />);
    await waitFor(() => expect(screen.getAllByText(/failed to load/i).length).toBeGreaterThan(0));
    expect(screen.getAllByRole('button', { name: /retry/i }).length).toBeGreaterThan(0);
  });

  it('AdminShell not rendered inside page component (shell preserved upstream)', () => {
    // AdminDashboardPage renders PageContainer, not AdminShell.
    // Shell integrity is the layout's responsibility — confirmed structurally.
    render(<AdminDashboardPage />);
    expect(screen.queryByRole('navigation')).toBeNull();
  });
});

// ── US-P1-005-002: Financial dashboard ───────────────────────────────────────

describe('FinancialDashboardPage — US-P1-005-002', () => {
  it('renders the financial dashboard heading', async () => {
    render(<FinancialDashboardPage />);
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /financial dashboard/i })).toBeInTheDocument(),
    );
  });

  it('shows loading state while fetching', () => {
    mockGetFinancial.mockReturnValue(new Promise(() => undefined));
    render(<FinancialDashboardPage />);
    expect(screen.getByLabelText(/loading financial metrics/i)).toBeInTheDocument();
  });

  it('renders wallet balance KPI card once data loads', async () => {
    render(<FinancialDashboardPage />);
    await waitFor(() =>
      expect(screen.getByText(/total wallet balance/i)).toBeInTheDocument(),
    );
  });

  it('renders transactions today count', async () => {
    render(<FinancialDashboardPage />);
    await waitFor(() => expect(screen.getByText('Transactions Today')).toBeInTheDocument());
    expect(screen.getByText('42')).toBeInTheDocument();
  });

  it('renders failed transactions today', async () => {
    render(<FinancialDashboardPage />);
    await waitFor(() => expect(screen.getByText(/failed transactions today/i)).toBeInTheDocument());
  });

  it('shows error state with retry when API fails', async () => {
    mockGetFinancial.mockRejectedValueOnce(new Error('Server error'));
    render(<FinancialDashboardPage />);
    await waitFor(() =>
      expect(screen.getByText(/failed to load financial dashboard/i)).toBeInTheDocument(),
    );
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });

  it('shows ACTION NEEDED badge when failed transactions > 0', async () => {
    mockGetFinancial.mockResolvedValueOnce({
      data: { ...FINANCIAL_DATA, failed_transactions_today: 3 },
    });
    render(<FinancialDashboardPage />);
    await waitFor(() =>
      expect(screen.getByLabelText(/action needed/i)).toBeInTheDocument(),
    );
  });
});

// ── US-P1-005-003: Risk dashboard ─────────────────────────────────────────────

describe('RiskDashboardPage — US-P1-005-003', () => {
  it('renders the risk dashboard heading', async () => {
    render(<RiskDashboardPage />);
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /risk.*compliance dashboard/i })).toBeInTheDocument(),
    );
  });

  it('shows loading state while fetching', () => {
    mockGetRisk.mockReturnValue(new Promise(() => undefined));
    render(<RiskDashboardPage />);
    expect(screen.getByLabelText(/loading risk metrics/i)).toBeInTheDocument();
  });

  it('renders frozen wallets metric', async () => {
    render(<RiskDashboardPage />);
    await waitFor(() => expect(screen.getByText(/frozen wallets/i)).toBeInTheDocument());
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('renders open fraud alerts metric', async () => {
    render(<RiskDashboardPage />);
    await waitFor(() => expect(screen.getByText(/open fraud alerts/i)).toBeInTheDocument());
    expect(screen.getByText('1')).toBeInTheDocument();
  });

  it('renders AML flags today metric', async () => {
    render(<RiskDashboardPage />);
    await waitFor(() => expect(screen.getByText(/aml flags today/i)).toBeInTheDocument());
  });

  it('renders blocked accounts metric', async () => {
    render(<RiskDashboardPage />);
    await waitFor(() => expect(screen.getByText(/blocked accounts/i)).toBeInTheDocument());
    expect(screen.getByText('10')).toBeInTheDocument();
  });

  it('shows REVIEW badge for non-zero alert counts', async () => {
    render(<RiskDashboardPage />);
    await waitFor(() => screen.getByText(/frozen wallets/i));
    const reviewBadges = screen.getAllByLabelText(/review/i);
    expect(reviewBadges.length).toBeGreaterThan(0);
  });

  it('shows error state with retry when API fails', async () => {
    mockGetRisk.mockRejectedValueOnce(new Error('Server error'));
    render(<RiskDashboardPage />);
    await waitFor(() =>
      expect(screen.getByText(/failed to load risk dashboard/i)).toBeInTheDocument(),
    );
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });
});
