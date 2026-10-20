/**
 * ADM-P1-009 — Transaction Management
 * US-P1-009-001: Admin searches transactions
 * US-P1-009-002: Admin views transaction detail
 * US-P1-009-003: Admin reverses a transaction
 * US-P1-009-004: Admin exports transactions
 */
import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ back: jest.fn(), push: jest.fn(), replace: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ children, href }: { children: React.ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
}));

// ── API mocks ────────────────────────────────────────────────────────────────

const mockListTransactions   = jest.fn();
const mockGetTransaction     = jest.fn();
const mockReverseTransaction = jest.fn();
const mockExportTransactions = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    listTransactions:   mockListTransactions,
    getTransaction:     mockGetTransaction,
    reverseTransaction: mockReverseTransaction,
    exportTransactions: mockExportTransactions,
  })),
}));

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports ───────────────────────────────────────────────────────────────────

import { TransactionManagementPage } from '@/src/modules/transactions/pages/transaction-management';
import { TransactionDetailPage }     from '@/src/modules/transactions/pages/transaction-detail';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

// ── Fixtures ──────────────────────────────────────────────────────────────────

const TX_COMPLETED = {
  id: 'txn-001',
  transaction_id: 'txn-001',
  reference_number: 'TXN-20261005-001234',
  type: 'wallet_transfer',
  status: 'completed',
  amount_minor_units: 50000,
  currency: 'BWP',
  currency_code: 'BWP',
  fee_minor_units: 100,
  initiator_user_id: 'usr-001',
  initiator_name: 'Kgomotso Modise',
  source_wallet_id: 'wlt-001',
  destination_wallet_id: 'wlt-002',
  reversal_eligible: true,
  reversal_id: null,
  fraud_flags: [],
  created_at: '2026-10-05T07:00:00Z',
  completed_at: '2026-10-05T07:00:01Z',
  wallet_id: 'wlt-001',
};

const TX_FAILED = {
  id: 'txn-002',
  transaction_id: 'txn-002',
  reference_number: 'TXN-20261005-001235',
  type: 'funding',
  status: 'failed',
  amount_minor_units: 100000,
  currency: 'BWP',
  currency_code: 'BWP',
  initiator_user_id: 'usr-002',
  initiator_name: 'Tebogo Sithole',
  reversal_eligible: false,
  reversal_id: null,
  fraud_flags: [],
  created_at: '2026-10-05T08:00:00Z',
  failed_at: '2026-10-05T08:00:05Z',
  failure_reason: 'Insufficient bank balance',
  retry_count: 2,
  wallet_id: 'wlt-002',
};

const TX_REVERSED = {
  ...TX_COMPLETED,
  id: 'txn-003',
  transaction_id: 'txn-003',
  reference_number: 'TXN-20261005-001236',
  status: 'reversed',
  reversal_eligible: false,
  reversal_id: 'txn-rev-001',
};

const TX_DETAIL: typeof TX_COMPLETED & {
  ledger_entries: unknown[];
  fraud_assessment: unknown;
  correlation_id: string;
} = {
  ...TX_COMPLETED,
  correlation_id: 'corr-uuid-001',
  ledger_entries: [
    {
      entry_id: 'entry-001',
      ledger_account: 'customer.kgomotso.debit',
      amount_minor_units: 50100,
      direction: 'debit',
      posted_at: '2026-10-05T07:00:01Z',
    },
    {
      entry_id: 'entry-002',
      ledger_account: 'customer.tebogo.credit',
      amount_minor_units: 50000,
      direction: 'credit',
      posted_at: '2026-10-05T07:00:01Z',
    },
  ],
  fraud_assessment: {
    risk_score: 12,
    flags: [],
    assessed_at: '2026-10-05T07:00:00Z',
  },
};

const PENDING_ACTION = {
  approval_id: 'appr-tx-001',
  action_type: 'adminapp.transaction.reverse',
  status: 'pending_approval',
  expires_at: '2026-10-07T08:50:00Z',
};

const PAGINATED_TRANSACTIONS = {
  data: [TX_COMPLETED, TX_FAILED],
  meta: { total: 2, per_page: 25, current_page: 1, last_page: 1 },
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function renderList() {
  mockUseAuth.mockReturnValue({ authenticatedClient: MOCK_CLIENT } as never);
  return render(<TransactionManagementPage />);
}

function renderDetail(txId = 'txn-001') {
  mockUseAuth.mockReturnValue({ authenticatedClient: MOCK_CLIENT } as never);
  return render(<TransactionDetailPage transactionId={txId} />);
}

// ── Setup / teardown ──────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks();
  mockListTransactions.mockResolvedValue(PAGINATED_TRANSACTIONS);
  mockGetTransaction.mockResolvedValue({ data: TX_DETAIL });
  mockReverseTransaction.mockResolvedValue({ data: PENDING_ACTION });
  mockExportTransactions.mockResolvedValue({ data: { export_id: 'exp-001', status: 'queued' } });
});

// ── US-P1-009-001: Admin searches transactions ────────────────────────────────

describe('US-P1-009-001: Transaction list', () => {
  it('renders transactions table with required columns', async () => {
    renderList();
    await waitFor(() => {
      expect(screen.getByRole('table', { name: /transactions table/i })).toBeInTheDocument();
    });
    expect(screen.getAllByText(/reference/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/type/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/amount/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/status/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/user/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/date/i).length).toBeGreaterThanOrEqual(1);
  });

  it('renders reference numbers', async () => {
    renderList();
    await waitFor(() => {
      expect(screen.getByText('TXN-20261005-001234')).toBeInTheDocument();
      expect(screen.getByText('TXN-20261005-001235')).toBeInTheDocument();
    });
  });

  it('renders initiator name linked to user detail', async () => {
    renderList();
    await waitFor(() => {
      expect(screen.getByText('Kgomotso Modise')).toBeInTheDocument();
    });
    const link = screen.getByRole('link', { name: 'Kgomotso Modise' });
    expect(link).toHaveAttribute('href', '/admin-portal/users/usr-001');
  });

  it('shows View button per row navigating to detail', async () => {
    renderList();
    await waitFor(() => {
      expect(screen.getAllByRole('button', { name: /view transaction/i }).length).toBeGreaterThanOrEqual(1);
    });
    const [viewBtn] = screen.getAllByRole('button', { name: /view transaction TXN-20261005-001234/i });
    expect(viewBtn!.closest('a')).toHaveAttribute('href', '/admin-portal/transactions/txn-001');
  });

  it('sends search query on form submit', async () => {
    const user = userEvent.setup();
    renderList();
    await user.type(screen.getByPlaceholderText(/tx id, reference or user/i), 'TXN-001');
    await user.click(screen.getByRole('button', { name: /search/i }));
    await waitFor(() => {
      const lastCall = mockListTransactions.mock.calls[mockListTransactions.mock.calls.length - 1][0] as Record<string, string>;
      expect(lastCall.q).toBe('TXN-001');
    });
  });

  it('sends type filter param', async () => {
    const user = userEvent.setup();
    renderList();
    await user.selectOptions(screen.getByLabelText(/^type/i), 'wallet_transfer');
    await waitFor(() => {
      const lastCall = mockListTransactions.mock.calls[mockListTransactions.mock.calls.length - 1][0] as Record<string, string>;
      expect(lastCall.type).toBe('wallet_transfer');
    });
  });

  it('sends status filter param', async () => {
    const user = userEvent.setup();
    renderList();
    await user.selectOptions(screen.getByLabelText(/^status/i), 'completed');
    await waitFor(() => {
      const lastCall = mockListTransactions.mock.calls[mockListTransactions.mock.calls.length - 1][0] as Record<string, string>;
      expect(lastCall.status).toBe('completed');
    });
  });

  it('sends currency filter param', async () => {
    const user = userEvent.setup();
    renderList();
    await user.selectOptions(screen.getByLabelText(/^currency/i), 'BWP');
    await waitFor(() => {
      const lastCall = mockListTransactions.mock.calls[mockListTransactions.mock.calls.length - 1][0] as Record<string, string>;
      expect(lastCall.currency).toBe('BWP');
    });
  });

  it('converts amount range to minor units in params', async () => {
    const user = userEvent.setup();
    renderList();
    await user.type(screen.getByLabelText(/min/i), '10');
    await user.type(screen.getByLabelText(/max/i), '500');
    await user.click(screen.getByRole('button', { name: /search/i }));
    await waitFor(() => {
      const lastCall = mockListTransactions.mock.calls[mockListTransactions.mock.calls.length - 1][0] as Record<string, string>;
      expect(lastCall.min_amount).toBe('1000');
      expect(lastCall.max_amount).toBe('50000');
    });
  });

  it('shows empty state when no transactions', async () => {
    mockListTransactions.mockResolvedValue({ data: [], meta: { total: 0, per_page: 25, current_page: 1, last_page: 1 } });
    renderList();
    await waitFor(() => {
      expect(screen.getByText(/no transactions found/i)).toBeInTheDocument();
    });
  });

  it('shows error state on fetch failure', async () => {
    mockListTransactions.mockRejectedValue(new Error('Network'));
    renderList();
    await waitFor(() => {
      expect(screen.getByText(/failed to load transactions/i)).toBeInTheDocument();
    });
  });

  it('shows Export CSV button', async () => {
    renderList();
    expect(screen.getByRole('button', { name: /export csv/i })).toBeInTheDocument();
  });
});

// ── US-P1-009-002: Admin views transaction detail ─────────────────────────────

describe('US-P1-009-002: Transaction detail', () => {
  it('loads and shows reference number', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getAllByText('TXN-20261005-001234').length).toBeGreaterThanOrEqual(1);
    });
    expect(mockGetTransaction).toHaveBeenCalledWith('txn-001');
  });

  it('shows transaction type', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText(/wallet transfer/i)).toBeInTheDocument();
    });
  });

  it('shows amount in major units', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getAllByText(/500\.00/).length).toBeGreaterThanOrEqual(1);
    });
  });

  it('shows status badge', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByLabelText('COMPLETED')).toBeInTheDocument();
    });
  });

  it('links initiator name to user detail', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'Kgomotso Modise' })).toHaveAttribute('href', '/admin-portal/users/usr-001');
    });
  });

  it('links source wallet to wallet detail', async () => {
    renderDetail();
    await waitFor(() => {
      const walletLinks = screen.getAllByRole('link').filter(l =>
        l.getAttribute('href')?.includes('/admin-portal/wallets/wlt-001'),
      );
      expect(walletLinks.length).toBeGreaterThanOrEqual(1);
    });
  });

  it('shows breadcrumb back to transactions', async () => {
    renderDetail();
    await waitFor(() => {
      const txLink = screen.getByRole('link', { name: /transactions/i });
      expect(txLink).toHaveAttribute('href', '/admin-portal/transactions');
    });
  });

  it('shows correlation ID', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText('corr-uuid-001')).toBeInTheDocument();
    });
  });

  it('renders ledger entries table', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByRole('table', { name: /ledger entries/i })).toBeInTheDocument();
    });
  });

  it('shows ledger entry account names', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText('customer.kgomotso.debit')).toBeInTheDocument();
      expect(screen.getByText('customer.tebogo.credit')).toBeInTheDocument();
    });
  });

  it('shows fraud assessment section', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText(/fraud assessment/i)).toBeInTheDocument();
      expect(screen.getByText('12')).toBeInTheDocument();
    });
  });

  it('shows failure reason for failed transactions', async () => {
    mockGetTransaction.mockResolvedValue({ data: TX_FAILED });
    renderDetail('txn-002');
    await waitFor(() => {
      expect(screen.getByText('Insufficient bank balance')).toBeInTheDocument();
    });
  });

  it('shows retry count for failed transactions', async () => {
    mockGetTransaction.mockResolvedValue({ data: TX_FAILED });
    renderDetail('txn-002');
    await waitFor(() => {
      expect(screen.getByText('2')).toBeInTheDocument();
    });
  });

  it('shows loading state', () => {
    mockGetTransaction.mockReturnValue(new Promise(() => {}));
    renderDetail();
    expect(screen.getByText(/loading transaction/i)).toBeInTheDocument();
  });

  it('shows error state on fetch failure', async () => {
    mockGetTransaction.mockRejectedValue(new Error('Not found'));
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText(/failed to load transaction/i)).toBeInTheDocument();
    });
  });
});

// ── US-P1-009-003: Admin reverses a transaction ───────────────────────────────

describe('US-P1-009-003: Transaction reversal', () => {
  it('shows Reverse Transaction button for eligible completed transaction', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /reverse transaction/i })).toBeInTheDocument();
    });
  });

  it('does NOT show Reverse button for ineligible transaction (funding)', async () => {
    mockGetTransaction.mockResolvedValue({ data: TX_FAILED });
    renderDetail('txn-002');
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /reverse transaction/i })).not.toBeInTheDocument();
    });
  });

  it('does NOT show Reverse button for already reversed transaction', async () => {
    mockGetTransaction.mockResolvedValue({ data: TX_REVERSED });
    renderDetail('txn-003');
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /reverse transaction/i })).not.toBeInTheDocument();
    });
  });

  it('shows REVERSED badge for already reversed transaction', async () => {
    mockGetTransaction.mockResolvedValue({ data: TX_REVERSED });
    renderDetail('txn-003');
    await waitFor(() => {
      expect(screen.getAllByLabelText('REVERSED').length).toBeGreaterThanOrEqual(1);
    });
  });

  it('opens reversal dialog on click', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /reverse transaction/i }));

    await user.click(screen.getByRole('button', { name: /reverse transaction/i }));
    expect(screen.getByRole('dialog', { name: /reverse transaction/i })).toBeInTheDocument();
  });

  it('Reverse confirm button disabled until reason is entered', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /reverse transaction/i }));

    await user.click(screen.getByRole('button', { name: /reverse transaction/i }));
    expect(screen.getByRole('button', { name: /confirm transaction reversal/i })).toBeDisabled();
  });

  it('dialog mentions compensating ledger entries', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /reverse transaction/i }));

    await user.click(screen.getByRole('button', { name: /reverse transaction/i }));
    expect(screen.getByText(/compensating double-entry ledger entries/i)).toBeInTheDocument();
  });

  it('submits reversal with reason and idempotency key', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /reverse transaction/i }));

    await user.click(screen.getByRole('button', { name: /reverse transaction/i }));
    await user.type(screen.getByLabelText(/reason/i), 'Customer dispute');
    await user.click(screen.getByRole('button', { name: /confirm transaction reversal/i }));

    await waitFor(() => {
      expect(mockReverseTransaction).toHaveBeenCalledWith(
        'txn-001',
        'Customer dispute',
        expect.any(String),
      );
    });
  });

  it('shows approval ID in success message', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /reverse transaction/i }));

    await user.click(screen.getByRole('button', { name: /reverse transaction/i }));
    await user.type(screen.getByLabelText(/reason/i), 'Fraud confirmed');
    await user.click(screen.getByRole('button', { name: /confirm transaction reversal/i }));

    await waitFor(() => {
      expect(screen.getByRole('status')).toHaveTextContent('appr-tx-001');
    });
  });

  it('shows ineligible error for ADMINAPP_015 code', async () => {
    mockReverseTransaction.mockRejectedValue({ code: 'ADMINAPP_015' });
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /reverse transaction/i }));

    await user.click(screen.getByRole('button', { name: /reverse transaction/i }));
    await user.type(screen.getByLabelText(/reason/i), 'Test');
    await user.click(screen.getByRole('button', { name: /confirm transaction reversal/i }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent(/not eligible for reversal/i);
    });
  });

  it('shows generic error on unknown failure', async () => {
    mockReverseTransaction.mockRejectedValue(new Error('Server error'));
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /reverse transaction/i }));

    await user.click(screen.getByRole('button', { name: /reverse transaction/i }));
    await user.type(screen.getByLabelText(/reason/i), 'Test');
    await user.click(screen.getByRole('button', { name: /confirm transaction reversal/i }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent(/failed to submit reversal/i);
    });
  });
});

// ── US-P1-009-004: Admin exports transactions ─────────────────────────────────

describe('US-P1-009-004: Export transactions', () => {
  it('Export CSV button is present', async () => {
    renderList();
    expect(screen.getByRole('button', { name: /export csv/i })).toBeInTheDocument();
  });

  it('calls exportTransactions on click', async () => {
    const user = userEvent.setup();
    renderList();
    await user.click(screen.getByRole('button', { name: /export csv/i }));

    await waitFor(() => {
      expect(mockExportTransactions).toHaveBeenCalledWith(
        expect.objectContaining({ format: 'csv' }),
        expect.any(String),
      );
    });
  });

  it('shows export queued message with export_id', async () => {
    const user = userEvent.setup();
    renderList();
    await user.click(screen.getByRole('button', { name: /export csv/i }));

    await waitFor(() => {
      expect(screen.getByRole('status')).toHaveTextContent('exp-001');
    });
  });

  it('includes active filters in export params', async () => {
    const user = userEvent.setup();
    renderList();
    await user.selectOptions(screen.getByLabelText(/^status/i), 'completed');
    await user.selectOptions(screen.getByLabelText(/^currency/i), 'BWP');

    await waitFor(() =>
      mockListTransactions.mock.calls[mockListTransactions.mock.calls.length - 1][0],
    );

    await user.click(screen.getByRole('button', { name: /export csv/i }));

    await waitFor(() => {
      const exportCall = mockExportTransactions.mock.calls[0][0] as Record<string, string>;
      expect(exportCall.status).toBe('completed');
      expect(exportCall.currency).toBe('BWP');
    });
  });

  it('shows failure message on export error', async () => {
    mockExportTransactions.mockRejectedValue(new Error('Server error'));
    const user = userEvent.setup();
    renderList();
    await user.click(screen.getByRole('button', { name: /export csv/i }));

    await waitFor(() => {
      expect(screen.getByRole('status')).toHaveTextContent(/failed to queue export/i);
    });
  });
});
