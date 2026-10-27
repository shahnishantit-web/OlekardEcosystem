/**
 * E68 — Disputes & Reconciliation Tests (US4)
 * T038: Jest tests for DisputeCasesPage, DisputeStatusBadge, ReconciliationExceptionsFinancePage
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const mockListDisputeCases          = jest.fn();
const mockGetDisputeCase            = jest.fn();
const mockListReconciliationExceptions = jest.fn();
const mockUpdateExceptionStatus     = jest.fn();

jest.mock('@/lib/api/payment', () => {
  const actual = jest.requireActual('@/lib/api/payment');
  return {
    ...actual,
    PaymentApiClient: jest.fn().mockImplementation(() => ({
      listDisputeCases:              mockListDisputeCases,
      getDisputeCase:                mockGetDisputeCase,
      listReconciliationExceptions:  mockListReconciliationExceptions,
      updateExceptionStatus:         mockUpdateExceptionStatus,
    })),
  };
});

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));
jest.mock('next/navigation', () => ({
  useRouter:      () => ({ push: jest.fn() }),
  useSearchParams: () => new URLSearchParams('status=open'),
}));
jest.mock('next/link', () => {
  const Link = ({ children, href }: { children: React.ReactNode; href: string }) =>
    React.createElement('a', { href }, children);
  return Link;
});

// ── Imports ───────────────────────────────────────────────────────────────────

import { DisputeCasesPage }  from '@/src/modules/payment/pages/dispute-cases';
import { DisputeCaseDetailPage } from '@/src/modules/payment/pages/dispute-case-detail';
import { DisputeStatusBadge } from '@/src/modules/payment/components/DisputeStatusBadge';
import { ReconciliationExceptionsFinancePage } from '@/src/modules/payment/pages/reconciliation-exceptions-finance';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

const mockDispute = {
  id: 'dispute-1',
  status: 'open' as const,
  case_type: 'chargeback',
  payment_intent_id: 'intent-abc-123456',
  payment_attempt_id: null,
  linked_support_case_id: 'support-xyz-789',
  requires_finance_review: true,
  deadline_at: '2026-11-01T00:00:00Z',
  created_at: '2026-10-01T09:00:00Z',
};

const mockException = {
  id: 'exception-1',
  type: 'unmatched_payment' as const,
  provider_code: 'bank_a',
  amount_minor: 5000,
  status: 'open' as const,
  notes: null,
  created_at: '2026-10-01T08:00:00Z',
  updated_at: '2026-10-01T08:00:00Z',
};

const DISPUTE_PAGE   = { data: [mockDispute],   meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } };
const EXCEPTION_PAGE = { data: [mockException], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } };

// ── DisputeStatusBadge ────────────────────────────────────────────────────────

describe('DisputeStatusBadge', () => {
  it('renders Open for open status', () => {
    render(<DisputeStatusBadge status="open" />);
    expect(screen.getByText('Open')).toBeTruthy();
  });
  it('renders Under Review for under_review', () => {
    render(<DisputeStatusBadge status="under_review" />);
    expect(screen.getByText('Under Review')).toBeTruthy();
  });
  it('renders Won for won', () => {
    render(<DisputeStatusBadge status="won" />);
    expect(screen.getByText('Won')).toBeTruthy();
  });
  it('renders Lost for lost', () => {
    render(<DisputeStatusBadge status="lost" />);
    expect(screen.getByText('Lost')).toBeTruthy();
  });
  it('renders Closed/Reversed for closed_reversed', () => {
    render(<DisputeStatusBadge status="closed_reversed" />);
    expect(screen.getByText('Closed/Reversed')).toBeTruthy();
  });
});

// ── DisputeCasesPage ──────────────────────────────────────────────────────────

describe('DisputeCasesPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => p === 'payment.disputes.view',
      user: null, login: jest.fn(), logout: jest.fn(), isLoading: false,
    } as never);
    mockListDisputeCases.mockResolvedValue({ data: DISPUTE_PAGE });
  });

  it('shows permission-denied when user lacks payment.disputes.view', () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: () => false,
      user: null, login: jest.fn(), logout: jest.fn(), isLoading: false,
    } as never);
    render(<DisputeCasesPage />);
    expect(screen.getByRole('alert')).toBeTruthy();
  });

  it('renders dispute status badge', async () => {
    render(<DisputeCasesPage />);
    await waitFor(() => {
      expect(screen.getByText('Open')).toBeTruthy();
    });
  });

  it('renders linked intent ID as link', async () => {
    render(<DisputeCasesPage />);
    await waitFor(() => screen.getByText('Open'));
    const allLinks = screen.getAllByRole('link');
    const intentLink = allLinks.find(l => l.getAttribute('href')?.includes('intent-abc-123456'));
    expect(intentLink).toBeTruthy();
  });

  it('renders "requires finance review" label', async () => {
    render(<DisputeCasesPage />);
    await waitFor(() => {
      expect(screen.getByText(/Required/)).toBeTruthy();
    });
  });
});

// ── DisputeCaseDetailPage ─────────────────────────────────────────────────────

describe('DisputeCaseDetailPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => ['payment.disputes.view', 'payment.disputes.manage'].includes(p),
      user: null, login: jest.fn(), logout: jest.fn(), isLoading: false,
    } as never);
    mockGetDisputeCase.mockResolvedValue({ data: mockDispute });
  });

  it('shows "case not found" label when linked_support_case_id is null', async () => {
    mockGetDisputeCase.mockResolvedValue({ data: { ...mockDispute, linked_support_case_id: null } });
    render(<DisputeCaseDetailPage id="dispute-1" />);
    await waitFor(() => {
      expect(screen.getByText(/case not found/i)).toBeTruthy();
    });
  });

  it('renders Authorise Hold button for user with disputes.manage', async () => {
    render(<DisputeCaseDetailPage id="dispute-1" />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Authorise Hold/i })).toBeTruthy();
    });
  });

  it('does not show Authorise Hold button without disputes.manage', async () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => p === 'payment.disputes.view',
      user: null, login: jest.fn(), logout: jest.fn(), isLoading: false,
    } as never);
    render(<DisputeCaseDetailPage id="dispute-1" />);
    await waitFor(() => screen.getByText('Open'));
    expect(screen.queryByRole('button', { name: /Authorise Hold/i })).toBeNull();
  });
});

// ── ReconciliationExceptionsFinancePage ───────────────────────────────────────

describe('ReconciliationExceptionsFinancePage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => ['payment.reconciliation.view', 'payment.reconciliation.manage'].includes(p),
      user: null, login: jest.fn(), logout: jest.fn(), isLoading: false,
    } as never);
    mockListReconciliationExceptions.mockResolvedValue({ data: EXCEPTION_PAGE });
  });

  it('default view passes status=open filter', async () => {
    render(<ReconciliationExceptionsFinancePage />);
    await waitFor(() => screen.getByText(/unmatched payment/i));
    expect(mockListReconciliationExceptions).toHaveBeenCalledWith(
      expect.objectContaining({ status: 'open' }),
    );
  });

  it('resolved items have muted styling (opacity-50)', async () => {
    mockListReconciliationExceptions.mockResolvedValue({
      data: { data: [{ ...mockException, status: 'resolved' }], meta: { last_page: 1 } },
    });
    render(<ReconciliationExceptionsFinancePage />);
    await waitFor(() => {
      const row = screen.getByText(/unmatched payment/i).closest('tr');
      expect(row?.className).toMatch(/opacity-50/);
    });
  });

  it('"Update Status" action calls PATCH with correct body', async () => {
    mockUpdateExceptionStatus.mockResolvedValue({ data: {} });
    render(<ReconciliationExceptionsFinancePage />);
    await waitFor(() => screen.getByRole('button', { name: /Update Status/i }));
    await userEvent.click(screen.getByRole('button', { name: /Update Status/i }));
    // Select investigating
    const select = screen.getByRole('combobox', { name: /New status/i });
    await userEvent.selectOptions(select, 'investigating');
    await userEvent.click(screen.getByRole('button', { name: /^Save$/i }));
    await waitFor(() => {
      expect(mockUpdateExceptionStatus).toHaveBeenCalledWith(
        'exception-1',
        expect.objectContaining({ status: 'investigating' }),
      );
    });
  });
});
