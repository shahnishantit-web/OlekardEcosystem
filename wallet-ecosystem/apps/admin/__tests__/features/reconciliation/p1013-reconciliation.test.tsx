/**
 * ADM-P1-013 — Reconciliation & Settlement
 *
 * US-P1-013-001: Admin views reconciliation batches
 * US-P1-013-002: Admin reviews a reconciliation batch (detail view)
 * US-P1-013-003: Admin approves settlement (maker-checker dialog)
 * US-P1-013-004: Admin views settlement history
 */

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ReconciliationBatchListPage } from '@/src/modules/reconciliation/pages/batch-list';
import { ReconciliationBatchDetailPage } from '@/src/modules/reconciliation/pages/batch-detail';
import { SettlementListPage } from '@/src/modules/reconciliation/pages/settlement-list';
import { SettlementDetailPage } from '@/src/modules/reconciliation/pages/settlement-detail';

// ── Mocks ────────────────────────────────────────────────────────────────────

beforeEach(() => jest.clearAllMocks());

const mockListReconciliation = jest.fn();
const mockGetReconciliationBatch = jest.fn();
const mockListSettlement = jest.fn();
const mockGetSettlement = jest.fn();
const mockApproveSettlement = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    listReconciliation:    mockListReconciliation,
    getReconciliationBatch: mockGetReconciliationBatch,
    listSettlement:        mockListSettlement,
    getSettlement:         mockGetSettlement,
    approveSettlement:     mockApproveSettlement,
  })),
}));

const mockPush = jest.fn();
jest.mock('next/navigation', () => ({
  useSearchParams: () => new URLSearchParams(),
  useRouter: () => ({ push: mockPush }),
}));

jest.mock('next/link', () => {
  const Link = ({ children, href, 'aria-label': ariaLabel }: { children: React.ReactNode; href: string; 'aria-label'?: string }) => (
    <a href={href} aria-label={ariaLabel}>{children}</a>
  );
  Link.displayName = 'Link';
  return Link;
});

const mockAuthClient = { get: jest.fn(), post: jest.fn(), patch: jest.fn() };

jest.mock('@/lib/auth/context', () => ({
  useAuth: () => ({ authenticatedClient: mockAuthClient }),
}));

jest.mock('@/lib/utils/idempotency', () => ({
  generateIdempotencyKey: (seed: string) => `idem-${seed}`,
}));

// ── Fixtures ──────────────────────────────────────────────────────────────────

const BATCH_1 = {
  batch_id: 'batch-001',
  period_start: '2026-10-01',
  period_end: '2026-10-07',
  status: 'completed',
  total_transactions: 500,
  matched_count: 490,
  unmatched_count: 10,
  discrepancy_amount_minor_units: 5000,
  currency_code: 'BWP',
  run_at: '2026-10-08T02:00:00Z',
  completed_at: '2026-10-08T02:15:00Z',
};

const BATCH_DETAIL = {
  ...BATCH_1,
  unmatched_transaction_ids: ['tx-aaa', 'tx-bbb', 'tx-ccc'],
};

const SETTLEMENT_PENDING = {
  settlement_id: 'settle-001',
  settlement_date: '2026-10-08',
  status: 'pending_approval',
  total_amount_minor_units: 1000000,
  currency_code: 'BWP',
  net_position_minor_units: 950000,
  provider: 'BancABC',
  reference: 'REF-2026-001',
};

const SETTLEMENT_APPROVED = { ...SETTLEMENT_PENDING, status: 'approved' };

const SETTLEMENT_EXECUTED = {
  settlement_id: 'settle-002',
  settlement_date: '2026-10-01',
  status: 'executed',
  total_amount_minor_units: 2000000,
  currency_code: 'BWP',
  net_position_minor_units: 1900000,
  provider: 'First National Bank',
  reference: 'REF-2026-002',
};

function paginatedResponse<T>(items: T[]) {
  return {
    data: items,
    meta: { total: items.length, per_page: 25, current_page: 1, last_page: 1 },
  };
}

// ── US-P1-013-001: Reconciliation batch list ──────────────────────────────────

describe('US-P1-013-001 — Reconciliation batch list', () => {
  beforeEach(() => {
    mockListReconciliation.mockResolvedValue(paginatedResponse([BATCH_1]));
  });

  it('renders batch list table', async () => {
    render(<ReconciliationBatchListPage />);
    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument());
  });

  it('shows batch period column', async () => {
    render(<ReconciliationBatchListPage />);
    await waitFor(() =>
      expect(screen.getByText('2026-10-01 → 2026-10-07')).toBeInTheDocument(),
    );
  });

  it('shows matched and unmatched counts', async () => {
    render(<ReconciliationBatchListPage />);
    await waitFor(() => {
      expect(screen.getByText('490')).toBeInTheDocument();
      expect(screen.getByText('10')).toBeInTheDocument();
    });
  });

  it('shows status badge', async () => {
    render(<ReconciliationBatchListPage />);
    await waitFor(() => expect(screen.getByText('COMPLETED')).toBeInTheDocument());
  });

  it('shows View link for each batch', async () => {
    render(<ReconciliationBatchListPage />);
    await waitFor(() => {
      const link = screen.getByRole('link', { name: /view reconciliation batch batch-001/i });
      expect(link).toHaveAttribute('href', '/reconciliation/batches/batch-001');
    });
  });

  it('shows total count', async () => {
    render(<ReconciliationBatchListPage />);
    await waitFor(() => expect(screen.getByText(/1 batch found/i)).toBeInTheDocument());
  });

  it('shows Refresh button', async () => {
    render(<ReconciliationBatchListPage />);
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /refresh reconciliation batches/i })).toBeInTheDocument(),
    );
  });

  it('shows error state on fetch failure', async () => {
    mockListReconciliation.mockRejectedValueOnce(new Error('Network error'));
    render(<ReconciliationBatchListPage />);
    await waitFor(() =>
      expect(screen.getByText(/failed to load reconciliation batches/i)).toBeInTheDocument(),
    );
  });
});

// ── US-P1-013-002: Reconciliation batch detail ────────────────────────────────

describe('US-P1-013-002 — Reconciliation batch detail', () => {
  beforeEach(() => {
    mockGetReconciliationBatch.mockResolvedValue({ data: BATCH_DETAIL });
  });

  it('renders batch details card', async () => {
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() => expect(screen.getByText('Batch ID')).toBeInTheDocument());
  });

  it('shows batch ID', async () => {
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() => expect(screen.getByText('batch-001')).toBeInTheDocument());
  });

  it('shows discrepancy amount', async () => {
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() => expect(screen.getByText(/BWP 50\.00/)).toBeInTheDocument());
  });

  it('shows match rate percentage', async () => {
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    // 490/500 = 98%
    await waitFor(() => expect(screen.getByText('98%')).toBeInTheDocument());
  });

  it('shows unmatched transaction IDs list', async () => {
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() => {
      expect(screen.getByText('tx-aaa')).toBeInTheDocument();
      expect(screen.getByText('tx-bbb')).toBeInTheDocument();
      expect(screen.getByText('tx-ccc')).toBeInTheDocument();
    });
  });

  it('shows unmatched transactions section heading', async () => {
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() =>
      expect(screen.getByText(/unmatched transactions \(3\)/i)).toBeInTheDocument(),
    );
  });

  it('notes exception resolution requires compliance workflow', async () => {
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() =>
      expect(screen.getByText(/exception resolution.*compliance workflow/i)).toBeInTheDocument(),
    );
  });

  it('shows Back button', async () => {
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /back to reconciliation batches/i })).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /back to reconciliation batches/i }));
    expect(mockPush).toHaveBeenCalledWith('/reconciliation/batches');
  });

  it('shows error state on fetch failure', async () => {
    mockGetReconciliationBatch.mockRejectedValueOnce(new Error('Not found'));
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() =>
      expect(screen.getByText(/failed to load batch details/i)).toBeInTheDocument(),
    );
  });

  it('does not show unmatched list when empty', async () => {
    mockGetReconciliationBatch.mockResolvedValueOnce({
      data: { ...BATCH_DETAIL, unmatched_transaction_ids: [] },
    });
    render(<ReconciliationBatchDetailPage batchId="batch-001" />);
    await waitFor(() => expect(screen.queryByText('tx-aaa')).not.toBeInTheDocument());
  });
});

// ── US-P1-013-004: Settlement history list ────────────────────────────────────

describe('US-P1-013-004 — Settlement history list', () => {
  beforeEach(() => {
    mockListSettlement.mockResolvedValue(paginatedResponse([SETTLEMENT_PENDING, SETTLEMENT_EXECUTED]));
  });

  it('renders settlement list table', async () => {
    render(<SettlementListPage />);
    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument());
  });

  it('shows settlement dates', async () => {
    render(<SettlementListPage />);
    await waitFor(() => {
      expect(screen.getByText('2026-10-08')).toBeInTheDocument();
      expect(screen.getByText('2026-10-01')).toBeInTheDocument();
    });
  });

  it('shows PENDING APPROVAL badge for pending settlement', async () => {
    render(<SettlementListPage />);
    await waitFor(() => expect(screen.getByText('PENDING APPROVAL')).toBeInTheDocument());
  });

  it('shows EXECUTED badge for executed settlement', async () => {
    render(<SettlementListPage />);
    await waitFor(() => expect(screen.getByText('EXECUTED')).toBeInTheDocument());
  });

  it('shows View link for each settlement', async () => {
    render(<SettlementListPage />);
    await waitFor(() => {
      const link = screen.getByRole('link', { name: /view settlement settle-001/i });
      expect(link).toHaveAttribute('href', '/reconciliation/settlements/settle-001');
    });
  });

  it('shows total count', async () => {
    render(<SettlementListPage />);
    await waitFor(() => expect(screen.getByText(/2 settlements found/i)).toBeInTheDocument());
  });

  it('shows provider and reference columns', async () => {
    render(<SettlementListPage />);
    await waitFor(() => {
      expect(screen.getByText('BancABC')).toBeInTheDocument();
      expect(screen.getByText('REF-2026-001')).toBeInTheDocument();
    });
  });

  it('shows formatted amounts', async () => {
    render(<SettlementListPage />);
    // 1000000 minor units = BWP 10,000.00
    await waitFor(() => expect(screen.getByText(/BWP 10,000\.00/)).toBeInTheDocument());
  });

  it('shows error state on fetch failure', async () => {
    mockListSettlement.mockRejectedValueOnce(new Error('Network error'));
    render(<SettlementListPage />);
    await waitFor(() =>
      expect(screen.getByText(/failed to load settlements/i)).toBeInTheDocument(),
    );
  });
});

// ── US-P1-013-003: Settlement approval (detail page) ─────────────────────────

describe('US-P1-013-003 — Settlement approval', () => {
  beforeEach(() => {
    mockGetSettlement.mockResolvedValue({ data: SETTLEMENT_PENDING });
    mockApproveSettlement.mockResolvedValue({ data: SETTLEMENT_APPROVED });
  });

  it('renders settlement details', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() => expect(screen.getByText('settle-001')).toBeInTheDocument());
  });

  it('shows Approve Settlement button for pending_approval status', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /approve settlement/i })).toBeInTheDocument(),
    );
  });

  it('does NOT show Approve button for executed settlement', async () => {
    mockGetSettlement.mockResolvedValueOnce({ data: SETTLEMENT_EXECUTED });
    render(<SettlementDetailPage settlementId="settle-002" />);
    await waitFor(() => expect(screen.getByText('settle-002')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /approve settlement/i })).not.toBeInTheDocument();
  });

  it('opens confirmation dialog on Approve click', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /approve settlement/i })).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /approve settlement/i }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /approve settlement/i })).toBeInTheDocument();
  });

  it('dialog mentions maker-checker policy', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      fireEvent.click(screen.getByRole('button', { name: /approve settlement/i })),
    );
    expect(screen.getByText(/maker-checker policy/i)).toBeInTheDocument();
  });

  it('calls approveSettlement on confirm', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      fireEvent.click(screen.getByRole('button', { name: /approve settlement/i })),
    );
    fireEvent.click(screen.getByRole('button', { name: /confirm settlement approval/i }));
    await waitFor(() => expect(mockApproveSettlement).toHaveBeenCalledWith(
      'settle-001',
      'idem-approve-settlement-settle-001',
    ));
  });

  it('shows success message after approval', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      fireEvent.click(screen.getByRole('button', { name: /approve settlement/i })),
    );
    fireEvent.click(screen.getByRole('button', { name: /confirm settlement approval/i }));
    await waitFor(() =>
      expect(screen.getByText(/settlement approved successfully/i)).toBeInTheDocument(),
    );
  });

  it('closes dialog after successful approval', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      fireEvent.click(screen.getByRole('button', { name: /approve settlement/i })),
    );
    fireEvent.click(screen.getByRole('button', { name: /confirm settlement approval/i }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('shows error when approval fails (maker-checker violation)', async () => {
    mockApproveSettlement.mockRejectedValueOnce(new Error('Maker-checker violation'));
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      fireEvent.click(screen.getByRole('button', { name: /approve settlement/i })),
    );
    fireEvent.click(screen.getByRole('button', { name: /confirm settlement approval/i }));
    await waitFor(() =>
      expect(screen.getByText(/failed to approve settlement/i)).toBeInTheDocument(),
    );
  });

  it('Cancel button closes dialog without calling API', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      fireEvent.click(screen.getByRole('button', { name: /approve settlement/i })),
    );
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(mockApproveSettlement).not.toHaveBeenCalled();
  });

  it('shows Back button to settlement history', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /back to settlement history/i })).toBeInTheDocument(),
    );
  });

  it('shows formatted total amount', async () => {
    render(<SettlementDetailPage settlementId="settle-001" />);
    // 1000000 minor = BWP 10,000.00
    await waitFor(() => expect(screen.getAllByText(/BWP 10,000\.00/).length).toBeGreaterThan(0));
  });
});
