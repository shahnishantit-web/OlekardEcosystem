/**
 * E68 — Payment Reports Tests (US5)
 * T042: Jest tests for PaymentReportsPage, ExportProgressBar, usePaymentExport
 */
import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const mockGetPaymentHistory = jest.fn();
const mockRequestExport     = jest.fn();
const mockGetExportStatus   = jest.fn();

jest.mock('@/lib/api/payment', () => {
  const actual = jest.requireActual('@/lib/api/payment');
  return {
    ...actual,
    PaymentApiClient: jest.fn().mockImplementation(() => ({
      getPaymentHistory: mockGetPaymentHistory,
      requestExport:     mockRequestExport,
      getExportStatus:   mockGetExportStatus,
    })),
  };
});

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));
jest.mock('next/link', () => {
  const Link = ({ children, href }: { children: React.ReactNode; href: string }) =>
    React.createElement('a', { href }, children);
  return Link;
});
jest.mock('@tanstack/react-query', () => {
  const actual = jest.requireActual('@tanstack/react-query');
  return {
    ...actual,
    useQuery: jest.fn(),
  };
});

import { PaymentReportsPage } from '@/src/modules/payment/pages/payment-reports';
import { ExportProgressBar }  from '@/src/modules/payment/components/ExportProgressBar';
import * as authContext from '@/lib/auth/context';
import * as reactQuery from '@tanstack/react-query';

const mockUseAuth  = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const mockUseQuery = reactQuery.useQuery as jest.MockedFunction<typeof reactQuery.useQuery>;
const MOCK_CLIENT  = {} as never;

const BWP_INTENT = {
  id: 'intent-bwp-1',
  status: 'fulfilled' as const,
  amount_minor: 10000,
  currency_code: 'BWP',
  provider_code: 'bank_a',
  purpose_type: 'payment',
  organisation_id: 'org-1',
  created_at: '2026-10-01T10:00:00Z',
  updated_at: '2026-10-01T10:05:00Z',
  has_pending_fulfilment: false,
};

const USD_INTENT = {
  id: 'intent-usd-1',
  status: 'failed' as const,
  amount_minor: 5000,
  currency_code: 'USD',
  provider_code: 'bank_b',
  purpose_type: 'payment',
  organisation_id: 'org-1',
  created_at: '2026-10-02T09:00:00Z',
  updated_at: '2026-10-02T09:10:00Z',
  has_pending_fulfilment: false,
};

// ── ExportProgressBar ─────────────────────────────────────────────────────────

describe('ExportProgressBar', () => {
  it('returns null for idle status', () => {
    const { container } = render(<ExportProgressBar status="idle" />);
    expect(container.firstChild).toBeNull();
  });

  it('shows spinner for pending status', () => {
    render(<ExportProgressBar status="pending" rowCount={500} />);
    expect(screen.getByRole('status')).toBeTruthy();
    expect(screen.getByText(/Exporting 500 rows/)).toBeTruthy();
  });

  it('shows spinner for processing status', () => {
    render(<ExportProgressBar status="processing" />);
    expect(screen.getByText(/Exporting/)).toBeTruthy();
  });

  it('shows download link for downloadable status', () => {
    render(<ExportProgressBar status="downloadable" downloadUrl="/dl/token" />);
    expect(screen.getByRole('link', { name: /Download CSV/i })).toBeTruthy();
    expect(screen.getByRole('link', { name: /Download CSV/i }).getAttribute('href')).toBe('/dl/token');
  });

  it('shows error state with retry button', () => {
    const onRetry = jest.fn();
    render(<ExportProgressBar status="error" onRetry={onRetry} />);
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText(/Export failed/)).toBeTruthy();
    expect(screen.getByRole('button', { name: /Retry/i })).toBeTruthy();
  });

  it('calls onRetry when retry clicked', async () => {
    const onRetry = jest.fn();
    render(<ExportProgressBar status="error" onRetry={onRetry} />);
    await userEvent.click(screen.getByRole('button', { name: /Retry/i }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('hides retry button when no onRetry provided', () => {
    render(<ExportProgressBar status="error" />);
    expect(screen.queryByRole('button')).toBeNull();
  });
});

// ── PaymentReportsPage — permission guard ─────────────────────────────────────

describe('PaymentReportsPage (permission guard)', () => {
  it('shows access denied when user lacks payment.export.read.organisation', () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: () => false,
      user: null, login: jest.fn(), logout: jest.fn(), isLoading: false,
    } as never);
    mockUseQuery.mockReturnValue({ data: undefined, isLoading: false, error: null, refetch: jest.fn() } as never);
    render(<PaymentReportsPage />);
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText(/Access Denied/i)).toBeTruthy();
  });
});

// ── PaymentReportsPage — normal flow ─────────────────────────────────────────

describe('PaymentReportsPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: () => true,
      user: null, login: jest.fn(), logout: jest.fn(), isLoading: false,
    } as never);
  });

  it('shows loading state', () => {
    mockUseQuery.mockReturnValue({ data: undefined, isLoading: true, error: null, refetch: jest.fn() } as never);
    render(<PaymentReportsPage />);
    expect(screen.getByRole('status')).toBeTruthy();
    expect(screen.getByText(/Loading report/i)).toBeTruthy();
  });

  it('shows error state with retry', () => {
    const refetch = jest.fn();
    mockUseQuery.mockReturnValue({
      data: undefined, isLoading: false, error: new Error('network'), refetch,
    } as never);
    render(<PaymentReportsPage />);
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText(/Failed to load/i)).toBeTruthy();
  });

  it('shows empty state when no intents returned', () => {
    mockUseQuery.mockReturnValue({ data: [], isLoading: false, error: null, refetch: jest.fn() } as never);
    render(<PaymentReportsPage />);
    expect(screen.getByText(/No payment data/i)).toBeTruthy();
  });

  it('renders separate row per currency (BWP and USD never aggregated)', async () => {
    mockUseQuery.mockReturnValue({
      data: [BWP_INTENT, USD_INTENT],
      isLoading: false, error: null, refetch: jest.fn(),
    } as never);
    render(<PaymentReportsPage />);
    await waitFor(() => {
      expect(screen.getByText('BWP')).toBeTruthy();
      expect(screen.getByText('USD')).toBeTruthy();
    });
    const rows = screen.getAllByRole('row');
    // header + 2 data rows
    expect(rows.length).toBeGreaterThanOrEqual(3);
  });

  it('shows audit link per currency row', async () => {
    mockUseQuery.mockReturnValue({
      data: [BWP_INTENT],
      isLoading: false, error: null, refetch: jest.fn(),
    } as never);
    render(<PaymentReportsPage />);
    await waitFor(() => screen.getByText('BWP'));
    const auditLinks = screen.getAllByRole('link', { name: /Audit/i });
    expect(auditLinks.length).toBeGreaterThanOrEqual(1);
    expect(auditLinks[0]!.getAttribute('href')).toContain('entity_type=payment_report');
  });

  it('export button is present and enabled when idle', async () => {
    mockUseQuery.mockReturnValue({
      data: [BWP_INTENT], isLoading: false, error: null, refetch: jest.fn(),
    } as never);
    render(<PaymentReportsPage />);
    await waitFor(() => screen.getByText('BWP'));
    const exportBtn = screen.getByRole('button', { name: /Export to CSV/i });
    expect(exportBtn).toBeTruthy();
    expect(exportBtn.getAttribute('disabled')).toBeNull();
  });

  it('freshness footnote is rendered', async () => {
    mockUseQuery.mockReturnValue({
      data: [BWP_INTENT], isLoading: false, error: null, refetch: jest.fn(),
    } as never);
    render(<PaymentReportsPage />);
    await waitFor(() => {
      expect(screen.getByText(/each currency is shown as a separate row/i)).toBeTruthy();
    });
  });
});
