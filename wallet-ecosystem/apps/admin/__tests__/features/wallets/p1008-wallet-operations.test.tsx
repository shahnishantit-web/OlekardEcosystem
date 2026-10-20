/**
 * ADM-P1-008 — Wallet Operations
 * US-P1-008-001: Admin searches wallets
 * US-P1-008-002: Admin views wallet detail and ledger
 * US-P1-008-003: Admin freezes/unfreezes a wallet
 * US-P1-008-004: Admin performs a controlled balance adjustment
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

const mockListWallets   = jest.fn();
const mockGetWallet     = jest.fn();
const mockFreezeWallet  = jest.fn();
const mockUnfreezeWallet = jest.fn();
const mockGetWalletLedger = jest.fn();
const mockAdjustWallet  = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    listWallets:      mockListWallets,
    getWallet:        mockGetWallet,
    freezeWallet:     mockFreezeWallet,
    unfreezeWallet:   mockUnfreezeWallet,
    getWalletLedger:  mockGetWalletLedger,
    adjustWallet:     mockAdjustWallet,
  })),
}));

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports ───────────────────────────────────────────────────────────────────

import { WalletManagementPage } from '@/src/modules/wallets/pages/wallet-management';
import { WalletDetailPage }     from '@/src/modules/wallets/pages/wallet-detail';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

// ── Fixtures ──────────────────────────────────────────────────────────────────

const WALLET_ACTIVE = {
  id: 'wlt-001',
  user_id: 'usr-001',
  currency: 'BWP',
  currency_code: 'BWP',
  balance_minor_units: 245000,
  status: 'active',
  frozen_at: null,
  frozen_by: null,
  freeze_reason: null,
  created_at: '2026-09-01T09:00:00Z',
  wallet_number: 'OLK-000001',
  owner_name: 'Kgomotso Modise',
  owner_user_id: 'usr-001',
  available_balance_minor_units: 245000,
};

const WALLET_FROZEN = {
  id: 'wlt-002',
  user_id: 'usr-002',
  currency: 'BWP',
  currency_code: 'BWP',
  balance_minor_units: 100000,
  status: 'frozen',
  frozen_at: '2026-10-01T12:00:00Z',
  frozen_by: 'admin-001',
  freeze_reason: 'Suspected fraud',
  created_at: '2026-08-15T10:00:00Z',
  wallet_number: 'OLK-000002',
  owner_name: 'Tebogo Sithole',
  owner_user_id: 'usr-002',
};

const WALLET_CLOSED = {
  id: 'wlt-003',
  user_id: 'usr-003',
  currency: 'BWP',
  currency_code: 'BWP',
  balance_minor_units: 0,
  status: 'closed',
  frozen_at: null,
  frozen_by: null,
  freeze_reason: null,
  created_at: '2026-07-01T08:00:00Z',
  wallet_number: 'OLK-000003',
  owner_name: 'Naledi Dube',
  owner_user_id: 'usr-003',
};

const LEDGER_ENTRIES = [
  {
    entry_id: 'entry-001',
    ledger_account: 'CUSTOMER_WALLET',
    direction: 'credit' as const,
    amount_minor_units: 50000,
    running_balance_minor_units: 245000,
    type: 'wallet_fund',
    reference: 'TXN-001',
    posted_at: '2026-10-05T09:00:00Z',
  },
  {
    entry_id: 'entry-002',
    ledger_account: 'CUSTOMER_WALLET',
    direction: 'debit' as const,
    amount_minor_units: 10000,
    running_balance_minor_units: 195000,
    type: 'wallet_transfer',
    reference: 'TXN-002',
    posted_at: '2026-10-04T14:00:00Z',
  },
];

const PENDING_ACTION = {
  approval_id: 'appr-xyz-001',
  action_type: 'wallet.freeze',
  status: 'pending',
  expires_at: '2026-10-14T00:00:00Z',
};

const PAGINATED_WALLETS = {
  data: [WALLET_ACTIVE, WALLET_FROZEN],
  meta: { total: 2, per_page: 20, current_page: 1, last_page: 1 },
};

const PAGINATED_LEDGER = {
  data: LEDGER_ENTRIES,
  meta: { total: 2, per_page: 20, current_page: 1, last_page: 1 },
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function renderList() {
  mockUseAuth.mockReturnValue({ authenticatedClient: MOCK_CLIENT } as never);
  return render(<WalletManagementPage />);
}

function renderDetail(walletId = 'wlt-001') {
  mockUseAuth.mockReturnValue({ authenticatedClient: MOCK_CLIENT } as never);
  return render(<WalletDetailPage walletId={walletId} />);
}

// ── Setup / teardown ──────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks();
  mockListWallets.mockResolvedValue(PAGINATED_WALLETS);
  mockGetWallet.mockResolvedValue({ data: WALLET_ACTIVE });
  mockGetWalletLedger.mockResolvedValue({ data: PAGINATED_LEDGER });
  mockFreezeWallet.mockResolvedValue({ data: PENDING_ACTION });
  mockUnfreezeWallet.mockResolvedValue({ data: PENDING_ACTION });
  mockAdjustWallet.mockResolvedValue({ data: { ...PENDING_ACTION, action_type: 'wallet.adjustment' } });
});

// ── US-P1-008-001: Admin searches wallets ────────────────────────────────────

describe('US-P1-008-001: Wallet list', () => {
  it('renders wallet list with required columns', async () => {
    renderList();
    await waitFor(() => {
      expect(screen.getByRole('table', { name: /wallets table/i })).toBeInTheDocument();
    });
    // Use queryAllByText for headings that may appear in both filter labels and table headers
    expect(screen.getAllByText(/wallet id/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/owner/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/currency/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/balance/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/status/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(/created/i).length).toBeGreaterThanOrEqual(1);
  });

  it('renders wallet numbers and owner names', async () => {
    renderList();
    await waitFor(() => {
      expect(screen.getByText('OLK-000001')).toBeInTheDocument();
      expect(screen.getByText('OLK-000002')).toBeInTheDocument();
    });
    expect(screen.getByText('Kgomotso Modise')).toBeInTheDocument();
    expect(screen.getByText('Tebogo Sithole')).toBeInTheDocument();
  });

  it('links owner name to user detail page', async () => {
    renderList();
    await waitFor(() => {
      expect(screen.getByText('Kgomotso Modise')).toBeInTheDocument();
    });
    // next/link mock renders as plain <a>, accessible name = text content
    const ownerLink = screen.getByRole('link', { name: 'Kgomotso Modise' });
    expect(ownerLink).toHaveAttribute('href', '/admin-portal/users/usr-001');
  });

  it('shows View button per row navigating to wallet detail', async () => {
    renderList();
    await waitFor(() => {
      expect(screen.getAllByRole('button', { name: /view wallet OLK-000001/i }).length).toBeGreaterThanOrEqual(1);
    });
    // The Link wrapping the button — find the enclosing <a> by traversing from the button
    const [viewBtn] = screen.getAllByRole('button', { name: /view wallet OLK-000001/i });
    expect(viewBtn!.closest('a')).toHaveAttribute('href', '/admin-portal/wallets/wlt-001');
  });

  it('renders currency filter dropdown', async () => {
    renderList();
    expect(screen.getByLabelText(/currency/i)).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'BWP' })).toBeInTheDocument();
  });

  it('renders status filter dropdown', async () => {
    renderList();
    expect(screen.getByLabelText(/status/i)).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Active' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Frozen' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Closed' })).toBeInTheDocument();
  });

  it('submits search query on form submit', async () => {
    const user = userEvent.setup();
    renderList();

    const input = screen.getByPlaceholderText(/wallet id, owner email or phone/i);
    await user.type(input, 'kgomotso');

    const btn = screen.getByRole('button', { name: /search/i });
    await user.click(btn);

    await waitFor(() => {
      const lastCall = mockListWallets.mock.calls[mockListWallets.mock.calls.length - 1][0] as Record<string, string>;
      expect(lastCall.q).toBe('kgomotso');
    });
  });

  it('sends status filter param', async () => {
    const user = userEvent.setup();
    renderList();

    const select = screen.getByLabelText(/status/i);
    await user.selectOptions(select, 'frozen');

    await waitFor(() => {
      const lastCall = mockListWallets.mock.calls[mockListWallets.mock.calls.length - 1][0] as Record<string, string>;
      expect(lastCall.status).toBe('frozen');
    });
  });

  it('sends currency filter param', async () => {
    const user = userEvent.setup();
    renderList();

    const select = screen.getByLabelText(/currency/i);
    await user.selectOptions(select, 'BWP');

    await waitFor(() => {
      const lastCall = mockListWallets.mock.calls[mockListWallets.mock.calls.length - 1][0] as Record<string, string>;
      expect(lastCall.currency).toBe('BWP');
    });
  });

  it('shows empty state when no wallets found', async () => {
    mockListWallets.mockResolvedValue({ data: [], meta: { total: 0, per_page: 20, current_page: 1, last_page: 1 } });
    renderList();
    await waitFor(() => {
      expect(screen.getByText(/no wallets found/i)).toBeInTheDocument();
    });
  });

  it('shows error state on fetch failure', async () => {
    mockListWallets.mockRejectedValue(new Error('Network error'));
    renderList();
    await waitFor(() => {
      expect(screen.getByText(/failed to load wallets/i)).toBeInTheDocument();
    });
  });
});

// ── US-P1-008-002: Admin views wallet detail ──────────────────────────────────

describe('US-P1-008-002: Wallet detail', () => {
  it('loads and displays wallet info', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getAllByText('OLK-000001').length).toBeGreaterThanOrEqual(1);
    });
    expect(mockGetWallet).toHaveBeenCalledWith('wlt-001');
  });

  it('displays balance in major units', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getAllByText(/2,450\.00/).length).toBeGreaterThanOrEqual(1);
    });
  });

  it('displays currency code', async () => {
    renderDetail();
    await waitFor(() => {
      // Currency shown in balance field and detail fields
      expect(screen.getAllByText('BWP').length).toBeGreaterThanOrEqual(1);
    });
  });

  it('links owner to user detail page', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'Kgomotso Modise' })).toBeInTheDocument();
    });
    expect(screen.getByRole('link', { name: 'Kgomotso Modise' })).toHaveAttribute('href', '/admin-portal/users/usr-001');
  });

  it('shows breadcrumb back to wallets list', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByRole('link', { name: /wallets/i })).toBeInTheDocument();
    });
    const breadcrumbLink = screen.getByRole('link', { name: /wallets/i });
    expect(breadcrumbLink).toHaveAttribute('href', '/admin-portal/wallets');
  });

  it('renders ledger entries table', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByRole('table', { name: /wallet ledger entries/i })).toBeInTheDocument();
    });
    expect(mockGetWalletLedger).toHaveBeenCalledWith('wlt-001', expect.objectContaining({ page: '1', per_page: '20' }));
  });

  it('displays ledger entry columns', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText(/type/i)).toBeInTheDocument();
      expect(screen.getByText(/reference/i)).toBeInTheDocument();
      expect(screen.getByText(/amount/i)).toBeInTheDocument();
      expect(screen.getByText(/running balance/i)).toBeInTheDocument();
    });
  });

  it('shows credit entry as green with plus sign', async () => {
    renderDetail();
    await waitFor(() => {
      // Entry 1 is a credit of 500.00
      expect(screen.getByText('+500.00')).toBeInTheDocument();
    });
  });

  it('shows debit entry with minus sign', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText('−100.00')).toBeInTheDocument();
    });
  });

  it('shows empty ledger message when no entries', async () => {
    mockGetWalletLedger.mockResolvedValue({ data: { data: [], meta: { total: 0, per_page: 20, current_page: 1, last_page: 0 } } });
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText(/no ledger entries found/i)).toBeInTheDocument();
    });
  });

  it('shows loading state for wallet', async () => {
    mockGetWallet.mockReturnValue(new Promise(() => {}));
    renderDetail();
    expect(screen.getByText(/loading wallet/i)).toBeInTheDocument();
  });

  it('shows error state when wallet fetch fails', async () => {
    mockGetWallet.mockRejectedValue(new Error('Not found'));
    renderDetail();
    await waitFor(() => {
      expect(screen.getByText(/failed to load wallet details/i)).toBeInTheDocument();
    });
  });
});

// ── US-P1-008-003: Freeze / Unfreeze ─────────────────────────────────────────

describe('US-P1-008-003: Freeze and unfreeze', () => {
  it('shows Freeze Wallet button for active wallet', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /freeze wallet/i })).toBeInTheDocument();
    });
  });

  it('does NOT show Unfreeze button for active wallet', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /unfreeze wallet/i })).not.toBeInTheDocument();
    });
  });

  it('shows Unfreeze Wallet button for frozen wallet', async () => {
    mockGetWallet.mockResolvedValue({ data: WALLET_FROZEN });
    renderDetail('wlt-002');
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /unfreeze wallet/i })).toBeInTheDocument();
    });
  });

  it('does NOT show Freeze button for frozen wallet', async () => {
    mockGetWallet.mockResolvedValue({ data: WALLET_FROZEN });
    renderDetail('wlt-002');
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /freeze wallet/i })).not.toBeInTheDocument();
    });
  });

  it('opens freeze dialog on click', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /freeze wallet/i }));

    await user.click(screen.getByRole('button', { name: /freeze wallet/i }));
    expect(screen.getByRole('dialog', { name: /freeze wallet/i })).toBeInTheDocument();
  });

  it('confirm button disabled until reason is entered', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /freeze wallet/i }));

    await user.click(screen.getByRole('button', { name: /freeze wallet/i }));
    expect(screen.getByRole('button', { name: /confirm freeze/i })).toBeDisabled();
  });

  it('submits freeze with reason and idempotency key', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /freeze wallet/i }));

    await user.click(screen.getByRole('button', { name: /freeze wallet/i }));
    await user.type(screen.getByLabelText(/reason/i), 'Suspected fraud activity');
    await user.click(screen.getByRole('button', { name: /confirm freeze/i }));

    await waitFor(() => {
      expect(mockFreezeWallet).toHaveBeenCalledWith(
        'wlt-001',
        'Suspected fraud activity',
        expect.any(String),
      );
    });
  });

  it('shows approval ID in success message after freeze', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /freeze wallet/i }));

    await user.click(screen.getByRole('button', { name: /freeze wallet/i }));
    await user.type(screen.getByLabelText(/reason/i), 'AML investigation');
    await user.click(screen.getByRole('button', { name: /confirm freeze/i }));

    await waitFor(() => {
      expect(screen.getByRole('status')).toHaveTextContent('appr-xyz-001');
    });
  });

  it('submits unfreeze with reason', async () => {
    const user = userEvent.setup();
    mockGetWallet.mockResolvedValue({ data: WALLET_FROZEN });
    renderDetail('wlt-002');
    await waitFor(() => screen.getByRole('button', { name: /unfreeze wallet/i }));

    await user.click(screen.getByRole('button', { name: /unfreeze wallet/i }));
    await user.type(screen.getByLabelText(/reason/i), 'Investigation cleared');
    await user.click(screen.getByRole('button', { name: /confirm unfreeze/i }));

    await waitFor(() => {
      expect(mockUnfreezeWallet).toHaveBeenCalledWith(
        'wlt-002',
        'Investigation cleared',
        expect.any(String),
      );
    });
  });

  it('neither freeze nor unfreeze shown for closed wallet', async () => {
    mockGetWallet.mockResolvedValue({ data: WALLET_CLOSED });
    renderDetail('wlt-003');
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /freeze wallet/i })).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /unfreeze wallet/i })).not.toBeInTheDocument();
    });
  });
});

// ── US-P1-008-004: Balance adjustment ────────────────────────────────────────

describe('US-P1-008-004: Balance adjustment', () => {
  it('shows Balance Adjustment button for active wallet', async () => {
    renderDetail();
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /balance adjustment/i })).toBeInTheDocument();
    });
  });

  it('does NOT show adjustment button for closed wallet', async () => {
    mockGetWallet.mockResolvedValue({ data: WALLET_CLOSED });
    renderDetail('wlt-003');
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /balance adjustment/i })).not.toBeInTheDocument();
    });
  });

  it('shows adjustment button for frozen wallet', async () => {
    mockGetWallet.mockResolvedValue({ data: WALLET_FROZEN });
    renderDetail('wlt-002');
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /balance adjustment/i })).toBeInTheDocument();
    });
  });

  it('opens adjustment dialog on click', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /balance adjustment/i }));

    await user.click(screen.getByRole('button', { name: /balance adjustment/i }));
    expect(screen.getByRole('dialog', { name: /balance adjustment/i })).toBeInTheDocument();
  });

  it('dialog shows direction, amount, reference, reason fields', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /balance adjustment/i }));

    await user.click(screen.getByRole('button', { name: /balance adjustment/i }));
    const dialog = screen.getByRole('dialog', { name: /balance adjustment/i });
    expect(within(dialog).getByLabelText(/direction/i)).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/amount/i)).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/reference/i)).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/reason/i)).toBeInTheDocument();
  });

  it('dialog explains double-entry ledger posting', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /balance adjustment/i }));

    await user.click(screen.getByRole('button', { name: /balance adjustment/i }));
    expect(screen.getByText(/double-entry ledger/i)).toBeInTheDocument();
  });

  it('submit button disabled until all fields filled', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /balance adjustment/i }));

    await user.click(screen.getByRole('button', { name: /balance adjustment/i }));
    expect(screen.getByRole('button', { name: /submit for approval/i })).toBeDisabled();

    await user.type(screen.getByLabelText(/amount/i), '100');
    expect(screen.getByRole('button', { name: /submit for approval/i })).toBeDisabled();

    await user.type(screen.getByLabelText(/reference/i), 'ADJ-001');
    expect(screen.getByRole('button', { name: /submit for approval/i })).toBeDisabled();

    await user.type(screen.getByLabelText(/reason/i), 'Correction');
    expect(screen.getByRole('button', { name: /submit for approval/i })).not.toBeDisabled();
  });

  it('calls adjustWallet with correct payload on submit', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /balance adjustment/i }));

    await user.click(screen.getByRole('button', { name: /balance adjustment/i }));

    const dialog = screen.getByRole('dialog', { name: /balance adjustment/i });
    await user.selectOptions(within(dialog).getByLabelText(/direction/i), 'credit');
    await user.type(within(dialog).getByLabelText(/amount/i), '250.00');
    await user.type(within(dialog).getByLabelText(/reference/i), 'ADJ-2026-001');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Goodwill credit');
    await user.click(screen.getByRole('button', { name: /submit for approval/i }));

    await waitFor(() => {
      expect(mockAdjustWallet).toHaveBeenCalledWith(
        'wlt-001',
        expect.objectContaining({
          direction: 'credit',
          amount_minor_units: 25000,
          currency: 'BWP',
          reason: 'Goodwill credit',
          reference: 'ADJ-2026-001',
        }),
        expect.any(String),
      );
    });
  });

  it('shows maker-checker success message with approval ID', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /balance adjustment/i }));

    await user.click(screen.getByRole('button', { name: /balance adjustment/i }));
    const dialog = screen.getByRole('dialog', { name: /balance adjustment/i });
    await user.type(within(dialog).getByLabelText(/amount/i), '50');
    await user.type(within(dialog).getByLabelText(/reference/i), 'ADJ-TEST');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Test correction');
    await user.click(screen.getByRole('button', { name: /submit for approval/i }));

    await waitFor(() => {
      const statusEl = screen.getByRole('status');
      expect(statusEl).toHaveTextContent(/submitted for checker review/i);
      expect(statusEl).toHaveTextContent('appr-xyz-001');
    });
  });

  it('debit direction sends correct direction in payload', async () => {
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /balance adjustment/i }));

    await user.click(screen.getByRole('button', { name: /balance adjustment/i }));
    const dialog = screen.getByRole('dialog', { name: /balance adjustment/i });
    await user.selectOptions(within(dialog).getByLabelText(/direction/i), 'debit');
    await user.type(within(dialog).getByLabelText(/amount/i), '100');
    await user.type(within(dialog).getByLabelText(/reference/i), 'ADJ-DEBIT-001');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Reversal correction');
    await user.click(screen.getByRole('button', { name: /submit for approval/i }));

    await waitFor(() => {
      expect(mockAdjustWallet).toHaveBeenCalledWith(
        'wlt-001',
        expect.objectContaining({ direction: 'debit' }),
        expect.any(String),
      );
    });
  });

  it('shows error if adjustment fails', async () => {
    mockAdjustWallet.mockRejectedValue(new Error('Server error'));
    const user = userEvent.setup();
    renderDetail();
    await waitFor(() => screen.getByRole('button', { name: /balance adjustment/i }));

    await user.click(screen.getByRole('button', { name: /balance adjustment/i }));
    const dialog = screen.getByRole('dialog', { name: /balance adjustment/i });
    await user.type(within(dialog).getByLabelText(/amount/i), '100');
    await user.type(within(dialog).getByLabelText(/reference/i), 'ADJ-ERR');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Error test');
    await user.click(screen.getByRole('button', { name: /submit for approval/i }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent(/failed to submit adjustment/i);
    });
  });
});
