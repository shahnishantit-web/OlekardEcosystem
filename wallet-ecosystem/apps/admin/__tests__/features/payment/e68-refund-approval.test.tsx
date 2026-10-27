/**
 * E68 — Refund Approval & Payout Tests (US3)
 * T030/T031: Jest tests for RefundApprovalQueuePage and PayoutStatusBadge
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ── Mocks ─────────────────────────────────────────────────────────────────────

const mockListRefundManagement = jest.fn();
const mockApproveRefund        = jest.fn();
const mockRejectRefund         = jest.fn();
const mockListPayoutRequests   = jest.fn();
const mockApprovePayoutReservation = jest.fn();

jest.mock('@/lib/api/payment', () => {
  const actual = jest.requireActual('@/lib/api/payment');
  return {
    ...actual,
    PaymentApiClient: jest.fn().mockImplementation(() => ({
      listRefundManagement:      mockListRefundManagement,
      approveRefund:             mockApproveRefund,
      rejectRefund:              mockRejectRefund,
      listPayoutRequests:        mockListPayoutRequests,
      approvePayoutReservation:  mockApprovePayoutReservation,
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

// ── Imports ───────────────────────────────────────────────────────────────────

import { RefundApprovalQueuePage } from '@/src/modules/payment/pages/refund-approval-queue';
import { PayoutRequestsPage }      from '@/src/modules/payment/pages/payout-requests';
import { RefundStatusBadge }       from '@/src/modules/payment/components/RefundStatusBadge';
import { PayoutStatusBadge }       from '@/src/modules/payment/components/PayoutStatusBadge';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

const mockRefund = {
  id: 'refund-1',
  payment_intent_id: 'intent-abc-123456',
  amount_minor: 5000,
  currency_code: 'BWP',
  status: 'pending_approval' as const,
  requested_at: '2026-10-01T09:00:00Z',
  resolved_at: null,
  rejection_reason: null,
};

const mockPayout = {
  id: 'payout-1',
  beneficiary_id: 'ben-1',
  fund_reference_id: 'fund-ref-abc12345',
  purpose_type: 'settlement',
  currency_code: 'BWP',
  computed_payable_minor: 100000,
  status: 'reservation_pending' as const,
  organisation_id: 'org-1',
  created_at: '2026-10-01T08:00:00Z',
  updated_at: '2026-10-01T08:05:00Z',
};

const PAYOUT_PAGE = { data: [mockPayout], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } };
const REFUND_PAGE = { data: [mockRefund], meta: { current_page: 1, per_page: 50, total: 1, last_page: 1 } };

// ── RefundStatusBadge ──────────────────────────────────────────────────────────

describe('RefundStatusBadge', () => {
  it('renders Pending Approval for pending_approval', () => {
    render(<RefundStatusBadge status="pending_approval" />);
    expect(screen.getByText('Pending Approval')).toBeTruthy();
  });
  it('renders Completed for completed', () => {
    render(<RefundStatusBadge status="completed" />);
    expect(screen.getByText('Completed')).toBeTruthy();
  });
  it('renders Rejected for rejected', () => {
    render(<RefundStatusBadge status="rejected" />);
    expect(screen.getByText('Rejected')).toBeTruthy();
  });
});

// ── PayoutStatusBadge ──────────────────────────────────────────────────────────

describe('PayoutStatusBadge', () => {
  it('renders Reservation Pending for reservation_pending', () => {
    render(<PayoutStatusBadge status="reservation_pending" />);
    expect(screen.getByText('Reservation Pending')).toBeTruthy();
  });
  it('renders Settled for settled', () => {
    render(<PayoutStatusBadge status="settled" />);
    expect(screen.getByText('Settled')).toBeTruthy();
  });
  it('renders Executing for executing', () => {
    render(<PayoutStatusBadge status="executing" />);
    expect(screen.getByText('Executing')).toBeTruthy();
  });
});

// ── RefundApprovalQueuePage ────────────────────────────────────────────────────

describe('RefundApprovalQueuePage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => p === 'payment.refunds.approve',
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    mockListRefundManagement.mockResolvedValue({ data: REFUND_PAGE });
  });

  it('shows permission-denied when user lacks payment.refunds.approve', () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: () => false,
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    render(<RefundApprovalQueuePage />);
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.getByText(/do not have permission/i)).toBeTruthy();
  });

  it('shows empty state when no refunds pending', async () => {
    mockListRefundManagement.mockResolvedValue({ data: { data: [], meta: { last_page: 1 } } });
    render(<RefundApprovalQueuePage />);
    await waitFor(() => {
      expect(screen.getByText(/No refunds pending approval/)).toBeTruthy();
    });
  });

  it('renders Approve and Reject buttons for pending refund', async () => {
    render(<RefundApprovalQueuePage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /approve/i })).toBeTruthy();
      expect(screen.getByRole('button', { name: /reject/i })).toBeTruthy();
    });
  });

  it('approve flow calls API with idempotency key', async () => {
    mockApproveRefund.mockResolvedValue({ data: { message: 'Approved' } });
    render(<RefundApprovalQueuePage />);
    await waitFor(() => screen.getByRole('button', { name: /approve/i }));
    await userEvent.click(screen.getByRole('button', { name: /approve/i }));
    await waitFor(() => {
      expect(mockApproveRefund).toHaveBeenCalledWith(
        'refund-1',
        expect.stringMatching(/^[0-9a-f-]{36}$/i),
      );
    });
  });

  it('shows 403 self-approval inline error', async () => {
    const err = Object.assign(new Error('Forbidden'), { response: { status: 403 } });
    mockApproveRefund.mockRejectedValue(err);
    render(<RefundApprovalQueuePage />);
    await waitFor(() => screen.getByRole('button', { name: /approve/i }));
    await userEvent.click(screen.getByRole('button', { name: /approve/i }));
    await waitFor(() => {
      expect(screen.getByText(/cannot approve your own refund/i)).toBeTruthy();
    });
  });

  it('shows 409 conflict inline error', async () => {
    const err = Object.assign(new Error('Conflict'), { response: { status: 409 } });
    mockApproveRefund.mockRejectedValue(err);
    render(<RefundApprovalQueuePage />);
    await waitFor(() => screen.getByRole('button', { name: /approve/i }));
    await userEvent.click(screen.getByRole('button', { name: /approve/i }));
    await waitFor(() => {
      expect(screen.getByText(/already been processed/i)).toBeTruthy();
    });
  });

  it('reject modal requires non-empty reason — submit disabled when empty', async () => {
    render(<RefundApprovalQueuePage />);
    await waitFor(() => screen.getAllByRole('button', { name: /reject/i }));
    // Click the row-level Reject button (the one not in a dialog — no disabled attr initially)
    const allRejectBtns = screen.getAllByRole('button', { name: /reject/i });
    const rowRejectBtn  = allRejectBtns.find(b => !b.hasAttribute('disabled'));
    expect(rowRejectBtn).toBeTruthy();
    await userEvent.click(rowRejectBtn!);
    // After opening modal, find the dialog submit button (disabled when reason is empty)
    await waitFor(() => screen.getByRole('dialog'));
    const dialogSubmit = screen.getAllByRole('button', { name: /reject/i })
      .find(b => b.hasAttribute('disabled'));
    expect(dialogSubmit).toBeTruthy();
  });

  it('successful approval removes item from queue', async () => {
    mockApproveRefund.mockResolvedValue({ data: { message: 'Approved' } });
    render(<RefundApprovalQueuePage />);
    await waitFor(() => screen.getByRole('button', { name: /approve/i }));
    await userEvent.click(screen.getByRole('button', { name: /approve/i }));
    await waitFor(() => {
      // Item should be removed — refund amount no longer visible
      expect(screen.queryByText(/BWP.*50\.00/)).toBeNull();
    });
  });
});

// ── PayoutRequestsPage ─────────────────────────────────────────────────────────

describe('PayoutRequestsPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => ['payment.payout.view', 'payment.payout.approve'].includes(p),
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    mockListPayoutRequests.mockResolvedValue({ data: PAYOUT_PAGE });
  });

  it('renders Approve Reservation button for reservation_pending with permission', async () => {
    render(<PayoutRequestsPage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Approve Reservation/i })).toBeTruthy();
    });
  });

  it('approve-reservation button absent for user without payment.payout.approve', async () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: MOCK_CLIENT,
      hasPermission: (p: string) => p === 'payment.payout.view',
      user: null,
      login: jest.fn(),
      logout: jest.fn(),
      isLoading: false,
    } as never);
    render(<PayoutRequestsPage />);
    await waitFor(() => screen.getByText('Reservation Pending'));
    expect(screen.queryByRole('button', { name: /Approve Reservation/i })).toBeNull();
  });

  it('shows Pending badge for executing status — no action button', async () => {
    mockListPayoutRequests.mockResolvedValue({
      data: { data: [{ ...mockPayout, status: 'executing' }], meta: { last_page: 1 } },
    });
    render(<PayoutRequestsPage />);
    await waitFor(() => {
      expect(screen.getByText(/Executing/)).toBeTruthy();
      expect(screen.getByText(/Pending — not yet available/)).toBeTruthy();
      expect(screen.queryByRole('button', { name: /Approve Reservation/i })).toBeNull();
    });
  });

  it('shows Pending badge for settled status', async () => {
    mockListPayoutRequests.mockResolvedValue({
      data: { data: [{ ...mockPayout, status: 'settled' }], meta: { last_page: 1 } },
    });
    render(<PayoutRequestsPage />);
    await waitFor(() => {
      expect(screen.getByText(/Settled/)).toBeTruthy();
      expect(screen.getByText(/Pending — not yet available/)).toBeTruthy();
    });
  });
});
