/**
 * E68 — Finance Dashboard & Operations Tests (US1)
 * T013: Jest tests for FinanceDashboard, FinanceKpiTile, StaleDataBanner, IntentStatusBadge
 */
import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const mockGetDashboardSummary = jest.fn();
const mockQueryIntentStatus   = jest.fn();
const mockGetPaymentHistory   = jest.fn();

jest.mock('@/lib/api/payment', () => {
  const actual = jest.requireActual('@/lib/api/payment');
  return {
    ...actual,
    PaymentApiClient: jest.fn().mockImplementation(() => ({
      getDashboardSummary:   mockGetDashboardSummary,
      queryIntentStatus:     mockQueryIntentStatus,
      getPaymentHistory:     mockGetPaymentHistory,
    })),
  };
});

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));
jest.mock('next/link', () => {
  const Link = ({ children, href }: { children: React.ReactNode; href: string }) =>
    React.createElement('a', { href }, children);
  return Link;
});

// ── Imports (after mocks) ──────────────────────────────────────────────────────

import { FinanceDashboardPage }  from '@/src/modules/payment/pages/finance-dashboard';
import { FinanceKpiTile }        from '@/src/modules/payment/components/FinanceKpiTile';
import { StaleDataBanner }       from '@/src/modules/payment/components/StaleDataBanner';
import { IntentStatusBadge }     from '@/src/modules/payment/components/IntentStatusBadge';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

const mockSummary = {
  volume_by_currency: [
    { currency_code: 'BWP', total_minor: 500000, count: 10 },
    { currency_code: 'USD', total_minor: 200000, count: 4 },
  ],
  attempt_counts_by_status: { created: 2, captured: 5, fulfilled: 10, failed: 1, cancelled: 0 },
  fulfilment_backlog_count: 3,
  fetched_at: new Date().toISOString(),
};

const mockBacklogPage = {
  data: [
    {
      id: 'intent-uuid-001',
      status: 'captured' as const,
      amount_minor: 10000,
      currency_code: 'BWP',
      provider_code: 'bank_a',
      purpose_type: 'transfer',
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      has_pending_fulfilment: true,
    },
  ],
  meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
};

// ── FinanceKpiTile ─────────────────────────────────────────────────────────────

describe('FinanceKpiTile', () => {
  it('renders label and numeric value', () => {
    render(<FinanceKpiTile label="Volume" value={1500} />);
    expect(screen.getByText('Volume')).toBeTruthy();
    expect(screen.getByText(/1,?500/)).toBeTruthy();
  });

  it('renders currency code when provided', () => {
    render(<FinanceKpiTile label="Volume" value={1500} currency="BWP" />);
    expect(screen.getByText('BWP')).toBeTruthy();
  });

  it('renders trend icon when trend=up', () => {
    render(<FinanceKpiTile label="Count" value={5} trend="up" />);
    expect(screen.getByLabelText('trend up')).toBeTruthy();
  });
});

// ── IntentStatusBadge ─────────────────────────────────────────────────────────

describe('IntentStatusBadge', () => {
  it('renders Fulfilled for fulfilled status', () => {
    render(<IntentStatusBadge status="fulfilled" />);
    expect(screen.getByText('Fulfilled')).toBeTruthy();
  });

  it('renders Captured for captured status', () => {
    render(<IntentStatusBadge status="captured" />);
    expect(screen.getByText('Captured')).toBeTruthy();
  });

  it('renders Failed for failed status', () => {
    render(<IntentStatusBadge status="failed" />);
    expect(screen.getByText('Failed')).toBeTruthy();
  });

  it('renders Pending Ledger for pending_ledger status', () => {
    render(<IntentStatusBadge status="pending_ledger" />);
    expect(screen.getByText('Pending Ledger')).toBeTruthy();
  });
});

// ── StaleDataBanner ───────────────────────────────────────────────────────────

describe('StaleDataBanner', () => {
  it('does not render when data is fresh', () => {
    const { container } = render(
      <StaleDataBanner fetchedAt={new Date()} thresholdSeconds={300} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders banner when data is stale', () => {
    const staleDate = new Date(Date.now() - 400_000);
    render(<StaleDataBanner fetchedAt={staleDate} thresholdSeconds={300} />);
    expect(screen.getByRole('status')).toBeTruthy();
    expect(screen.getByText(/Data may be outdated/)).toBeTruthy();
  });

  it('renders refresh button when onRefresh provided', () => {
    const staleDate = new Date(Date.now() - 400_000);
    const onRefresh = jest.fn();
    render(<StaleDataBanner fetchedAt={staleDate} thresholdSeconds={300} onRefresh={onRefresh} />);
    const refreshBtn = screen.getByRole('button', { name: /refresh/i });
    expect(refreshBtn).toBeTruthy();
  });

  it('calls onRefresh when refresh button clicked', async () => {
    const staleDate = new Date(Date.now() - 400_000);
    const onRefresh = jest.fn();
    render(<StaleDataBanner fetchedAt={staleDate} thresholdSeconds={300} onRefresh={onRefresh} />);
    await userEvent.click(screen.getByRole('button', { name: /refresh/i }));
    expect(onRefresh).toHaveBeenCalledTimes(1);
  });
});

// ── FinanceDashboardPage ───────────────────────────────────────────────────────

describe('FinanceDashboardPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => ['payment.history.read.platform', 'payment.intents.admin'].includes(p),
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    mockGetDashboardSummary.mockResolvedValue({ data: mockSummary });
    mockGetPaymentHistory.mockResolvedValue({ data: mockBacklogPage });
    mockQueryIntentStatus.mockResolvedValue({ data: { message: 'Status query dispatched.' } });
  });

  it('shows loading skeleton on initial render', () => {
    mockGetDashboardSummary.mockReturnValue(new Promise(() => {}));
    render(<FinanceDashboardPage />);
    expect(screen.getByRole('status')).toBeTruthy();
  });

  it('renders KPI tiles with currency-separated values', async () => {
    render(<FinanceDashboardPage />);
    await waitFor(() => {
      expect(screen.getByText(/Volume \(BWP\)/i)).toBeTruthy();
      expect(screen.getByText(/Volume \(USD\)/i)).toBeTruthy();
    });
  });

  it('renders empty state when volume_by_currency is empty', async () => {
    mockGetDashboardSummary.mockResolvedValue({
      data: { ...mockSummary, volume_by_currency: [] },
    });
    render(<FinanceDashboardPage />);
    await waitFor(() => {
      expect(screen.getByText(/No volume data for today/)).toBeTruthy();
    });
  });

  it('shows error state when API fails and retry button is present', async () => {
    mockGetDashboardSummary.mockRejectedValue(new Error('API error'));
    render(<FinanceDashboardPage />);
    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeTruthy();
      expect(screen.getByText(/Try again|retry/i)).toBeTruthy();
    });
  });

  it('shows "Query Status" button for each backlog row when user has permission', async () => {
    render(<FinanceDashboardPage />);
    await waitFor(() => {
      expect(screen.getAllByRole('button', { name: /Query Status/i }).length).toBeGreaterThan(0);
    });
  });

  it('calls queryIntentStatus when "Query Status" clicked', async () => {
    render(<FinanceDashboardPage />);
    await waitFor(() => screen.getAllByRole('button', { name: /Query Status/i }));
    const btn = screen.getAllByRole('button', { name: /Query Status/i })[0]!;
    await userEvent.click(btn);
    await waitFor(() => expect(mockQueryIntentStatus).toHaveBeenCalledWith('intent-uuid-001'));
  });

  it('shows permission-denied message when user lacks payment.history.read.platform', () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: () => false,
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    render(<FinanceDashboardPage />);
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText(/do not have permission/i)).toBeTruthy();
  });
});
