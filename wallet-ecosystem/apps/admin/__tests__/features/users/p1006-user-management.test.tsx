/**
 * ADM-P1-006 — User Management
 * US-P1-006-001: Admin searches and filters users
 * US-P1-006-002: Admin views user detail
 * US-P1-006-003: Admin suspends a user
 * US-P1-006-004: Admin reactivates a suspended user
 * US-P1-006-005: Admin exports user list
 */
import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ back: jest.fn(), push: jest.fn(), replace: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

// ── API mocks ─────────────────────────────────────────────────────────────────

jest.mock('@/src/modules/users/api/admin-portal', () => ({
  searchAdminUsers: jest.fn(),
  exportAdminUsers: jest.fn(),
}));

const mockGetUser         = jest.fn();
const mockUpdateUserStatus = jest.fn();
const mockListWallets     = jest.fn();
const mockListTransactions = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    getUser: mockGetUser,
    updateUserStatus: mockUpdateUserStatus,
    listWallets: mockListWallets,
    listTransactions: mockListTransactions,
  })),
}));

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports ───────────────────────────────────────────────────────────────────

import { UserManagementPage } from '@/src/modules/users/pages/user-management';
import { UserDetailPage } from '@/src/modules/users/pages/user-detail';
import * as adminPortalApi from '@/src/modules/users/api/admin-portal';
import * as authContext from '@/lib/auth/context';

const mockSearchUsers  = adminPortalApi.searchAdminUsers as jest.MockedFunction<typeof adminPortalApi.searchAdminUsers>;
const mockExportUsers  = adminPortalApi.exportAdminUsers as jest.MockedFunction<typeof adminPortalApi.exportAdminUsers>;
const mockUseAuth      = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const MOCK_CLIENT = {} as never;

// ── Fixtures ──────────────────────────────────────────────────────────────────

const USER_ACTIVE = {
  id: 'user-001',
  email: 'alice@example.com',
  status: 'active',
  kyc_tier: 2,
  created_at: '2026-01-15T00:00:00Z',
  profile: { first_name: 'Alice', last_name: 'Smith', phone_number: '+26771000001' },
  kyc: { tier: 2, status: 'verified' },
};

const USER_SUSPENDED = {
  id: 'user-002',
  email: 'bob@example.com',
  status: 'suspended',
  kyc_tier: 1,
  created_at: '2026-02-20T00:00:00Z',
  profile: { first_name: 'Bob', last_name: 'Jones', phone_number: '+26771000002' },
  kyc: { tier: 1, status: 'pending' },
};

const PAGINATED_USERS = {
  data: [USER_ACTIVE, USER_SUSPENDED],
  meta: { current_page: 1, per_page: 20, total: 2, last_page: 1 },
};

const ADMIN_USER_DETAIL = {
  id: 'user-001',
  email: 'alice@example.com',
  full_name: 'Alice Smith',
  phone_number: '+26771000001',
  status: 'active',
  kyc_tier: 2,
  wallet_count: 1,
  actor_type: 'customer',
  created_at: '2026-01-15T00:00:00Z',
};

const ADMIN_USER_SUSPENDED_DETAIL = { ...ADMIN_USER_DETAIL, id: 'user-002', status: 'suspended' };

const WALLET = {
  id: 'wallet-001',
  user_id: 'user-001',
  currency: 'BWP',
  balance_minor_units: 100000,
  status: 'active',
  frozen_at: null,
  frozen_by: null,
  freeze_reason: null,
};

const TRANSACTION = {
  id: 'tx-001',
  type: 'transfer',
  status: 'completed',
  amount_minor_units: 50000,
  currency: 'BWP',
  wallet_id: 'wallet-001',
  reversal_eligible: false,
  created_at: '2026-10-01T10:00:00Z',
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
  setupAuth();
  mockSearchUsers.mockResolvedValue(PAGINATED_USERS);
  mockExportUsers.mockResolvedValue({ export_id: 'exp-001', status: 'queued' });
  mockGetUser.mockResolvedValue({ data: ADMIN_USER_DETAIL });
  mockUpdateUserStatus.mockResolvedValue({ data: { ...ADMIN_USER_DETAIL, status: 'suspended' } });
  mockListWallets.mockResolvedValue({ data: [WALLET], meta: { total: 1, per_page: 10, current_page: 1, last_page: 1 } });
  mockListTransactions.mockResolvedValue({ data: [TRANSACTION], meta: { total: 1, per_page: 10, current_page: 1, last_page: 1 } });
});

// ── US-P1-006-001: Search and filter users ────────────────────────────────────

describe('UserManagementPage — US-P1-006-001', () => {
  it('renders the page heading', async () => {
    render(<UserManagementPage />);
    await waitFor(() => expect(screen.getByText('User Management')).toBeInTheDocument());
  });

  it('shows Name, Email, Phone, KYC Status, Account Status, Created columns', async () => {
    render(<UserManagementPage />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    const table = screen.getByRole('table', { name: /users table/i });
    const headers = within(table).getAllByRole('columnheader').map(h => h.textContent ?? '');
    expect(headers.join('|')).toMatch(/Name/i);
    expect(headers.join('|')).toMatch(/Email/i);
    expect(headers.join('|')).toMatch(/Phone/i);
    expect(headers.join('|')).toMatch(/KYC Status/i);
    expect(headers.join('|')).toMatch(/Account Status/i);
    expect(headers.join('|')).toMatch(/Created/i);
  });

  it('renders full name and phone for each row', async () => {
    render(<UserManagementPage />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    expect(screen.getByText('Bob Jones')).toBeInTheDocument();
    expect(screen.getByText('+26771000001')).toBeInTheDocument();
  });

  it('renders KYC status badges', async () => {
    render(<UserManagementPage />);
    await waitFor(() => expect(screen.getByText('VERIFIED')).toBeInTheDocument());
    expect(screen.getByText('PENDING')).toBeInTheDocument();
  });

  it('renders a search input', async () => {
    render(<UserManagementPage />);
    expect(screen.getByRole('searchbox')).toBeInTheDocument();
  });

  it('includes Status filter with Closed option', async () => {
    render(<UserManagementPage />);
    const statusSelect = screen.getByLabelText(/^Status$/i);
    const options = within(statusSelect as HTMLElement).getAllByRole('option').map(o => o.textContent ?? '');
    expect(options).toContain('Active');
    expect(options).toContain('Suspended');
    expect(options).toContain('Closed');
  });

  it('includes KYC filter with Verified/Pending/Failed options', async () => {
    render(<UserManagementPage />);
    const kycSelect = screen.getByLabelText(/^KYC$/i);
    const options = within(kycSelect as HTMLElement).getAllByRole('option').map(o => o.textContent ?? '');
    expect(options).toContain('Verified');
    expect(options).toContain('Pending');
    expect(options).toContain('Failed');
  });

  it('renders Created from and Created to date filters', async () => {
    render(<UserManagementPage />);
    expect(screen.getByLabelText(/created from/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/created to/i)).toBeInTheDocument();
  });

  it('calls searchAdminUsers with q param when search form submitted', async () => {
    render(<UserManagementPage />);
    await waitFor(() => expect(mockSearchUsers).toHaveBeenCalled());

    await userEvent.type(screen.getByRole('searchbox'), 'alice');
    await userEvent.click(screen.getByRole('button', { name: /^search$/i }));

    await waitFor(() =>
      expect(mockSearchUsers).toHaveBeenCalledWith(
        MOCK_CLIENT,
        expect.objectContaining({ q: 'alice' }),
      ),
    );
  });

  it('calls searchAdminUsers with kyc_status filter', async () => {
    render(<UserManagementPage />);
    const kycSelect = screen.getByLabelText(/^KYC$/i);
    await userEvent.selectOptions(kycSelect, 'verified');
    await userEvent.click(screen.getByRole('button', { name: /^search$/i }));

    await waitFor(() =>
      expect(mockSearchUsers).toHaveBeenCalledWith(
        MOCK_CLIENT,
        expect.objectContaining({ kyc_status: 'verified' }),
      ),
    );
  });

  it('shows empty state when no users found', async () => {
    mockSearchUsers.mockResolvedValueOnce({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } });
    render(<UserManagementPage />);
    await waitFor(() => expect(screen.getByText(/no users found/i)).toBeInTheDocument());
  });

  it('shows error state with retry when API fails', async () => {
    mockSearchUsers.mockRejectedValueOnce(new Error('Network error'));
    render(<UserManagementPage />);
    await waitFor(() => expect(screen.getByText(/failed to load users/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });

  it('shows pagination controls', async () => {
    render(<UserManagementPage />);
    await waitFor(() => expect(screen.getByRole('button', { name: /previous page/i })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /next page/i })).toBeInTheDocument();
  });
});

// ── US-P1-006-005: Export user list ──────────────────────────────────────────

describe('UserManagementPage — US-P1-006-005', () => {
  it('renders an Export CSV button', async () => {
    render(<UserManagementPage />);
    expect(screen.getByRole('button', { name: /export csv/i })).toBeInTheDocument();
  });

  it('calls exportAdminUsers when Export CSV is clicked', async () => {
    render(<UserManagementPage />);
    await userEvent.click(screen.getByRole('button', { name: /export csv/i }));
    await waitFor(() => expect(mockExportUsers).toHaveBeenCalledWith(
      MOCK_CLIENT,
      expect.objectContaining({ format: 'csv' }),
    ));
  });

  it('shows a toast notification after export is queued', async () => {
    render(<UserManagementPage />);
    await userEvent.click(screen.getByRole('button', { name: /export csv/i }));
    await waitFor(() =>
      expect(screen.getByText(/export queued/i)).toBeInTheDocument(),
    );
  });

  it('passes current search filter to export call', async () => {
    render(<UserManagementPage />);
    await waitFor(() => expect(mockSearchUsers).toHaveBeenCalled());

    const statusSelect = screen.getByLabelText(/^Status$/i);
    await userEvent.selectOptions(statusSelect, 'active');
    await userEvent.click(screen.getByRole('button', { name: /export csv/i }));

    await waitFor(() =>
      expect(mockExportUsers).toHaveBeenCalledWith(
        MOCK_CLIENT,
        expect.objectContaining({ status: 'active' }),
      ),
    );
  });
});

// ── US-P1-006-002: View user detail ──────────────────────────────────────────

describe('UserDetailPage — US-P1-006-002', () => {
  it('renders user profile info (name, email, phone, status, joined)', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    expect(screen.getByText('alice@example.com')).toBeInTheDocument();
    expect(screen.getByText('+26771000001')).toBeInTheDocument();
    // Status badge — Badge sets aria-label from label prop ("ACTIVE")
    expect(screen.getByLabelText('ACTIVE')).toBeInTheDocument();
  });

  it('renders wallet balances section', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByRole('region', { name: /wallet balances/i })).toBeInTheDocument());
    expect(screen.getByText('BWP 1,000.00')).toBeInTheDocument();
  });

  it('renders a "View all" link for wallets', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    const walletSection = screen.getByRole('region', { name: /wallet balances/i });
    const walletLink = within(walletSection).getByRole('link', { name: /view all →/i });
    expect(walletLink).toBeInTheDocument();
  });

  it('renders recent transactions section', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByRole('region', { name: /recent transactions/i })).toBeInTheDocument());
    expect(screen.getByRole('table', { name: /recent transactions/i })).toBeInTheDocument();
  });

  it('renders transaction rows with type, amount, status, date', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText('transfer')).toBeInTheDocument());
    expect(screen.getByText('BWP 500.00')).toBeInTheDocument();
  });

  it('renders KYC status section', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByRole('region', { name: /kyc status/i })).toBeInTheDocument());
    const kycSection = screen.getByRole('region', { name: /kyc status/i });
    expect(within(kycSection).getByText('Tier 2')).toBeInTheDocument();
  });

  it('renders devices and sessions section with links', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByRole('region', { name: /devices and sessions/i })).toBeInTheDocument());
    expect(screen.getByRole('link', { name: /enrolled devices/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /active sessions/i })).toBeInTheDocument();
  });

  it('shows loading state', () => {
    mockGetUser.mockReturnValue(new Promise(() => undefined));
    render(<UserDetailPage userId="user-001" />);
    expect(screen.getByLabelText(/loading user/i)).toBeInTheDocument();
  });

  it('shows error state with retry when user fetch fails', async () => {
    mockGetUser.mockRejectedValueOnce(new Error('Server error'));
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText(/failed to load user/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });
});

// ── US-P1-006-003: Suspend user ───────────────────────────────────────────────

describe('UserDetailPage — US-P1-006-003', () => {
  it('shows enabled Suspend button for active user', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    const btn = screen.getByRole('button', { name: /suspend this user/i });
    expect(btn).not.toBeDisabled();
  });

  it('shows disabled Suspend button for suspended user (tooltip "User is already suspended")', async () => {
    mockGetUser.mockResolvedValueOnce({ data: ADMIN_USER_SUSPENDED_DETAIL });
    render(<UserDetailPage userId="user-002" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    const btn = screen.getByRole('button', { name: /suspend this user/i });
    expect(btn).toBeDisabled();
    // Tooltip via title on wrapper span
    expect(btn.closest('[title]')?.getAttribute('title')).toBe('User is already suspended');
  });

  it('opens suspend dialog when Suspend is clicked', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    await userEvent.click(screen.getByRole('button', { name: /suspend this user/i }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText('Suspend User')).toBeInTheDocument();
  });

  it('calls updateUserStatus with suspend + reason', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /suspend this user/i }));
    const dialog = screen.getByRole('dialog');
    await userEvent.type(within(dialog).getByRole('textbox'), 'Fraudulent activity');
    await userEvent.click(within(dialog).getByRole('button', { name: /confirm suspension/i }));

    await waitFor(() =>
      expect(mockUpdateUserStatus).toHaveBeenCalledWith(
        'user-001',
        'suspend',
        'Fraudulent activity',
      ),
    );
  });

  it('shows success message after suspension', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /suspend this user/i }));
    await userEvent.type(screen.getByRole('textbox'), 'Reason');
    await userEvent.click(screen.getByRole('button', { name: /confirm suspension/i }));

    await waitFor(() =>
      expect(screen.getByRole('status')).toHaveTextContent(/suspension submitted/i),
    );
  });

  it('Confirm button disabled when reason is empty', async () => {
    render(<UserDetailPage userId="user-001" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /suspend this user/i }));
    const confirmBtn = screen.getByRole('button', { name: /confirm suspension/i });
    expect(confirmBtn).toBeDisabled();
  });
});

// ── US-P1-006-004: Reactivate user ───────────────────────────────────────────

describe('UserDetailPage — US-P1-006-004', () => {
  beforeEach(() => {
    mockGetUser.mockResolvedValue({ data: ADMIN_USER_SUSPENDED_DETAIL });
  });

  it('shows Reactivate button for suspended user', async () => {
    render(<UserDetailPage userId="user-002" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /reactivate this user/i })).toBeInTheDocument();
  });

  it('opens reactivate dialog', async () => {
    render(<UserDetailPage userId="user-002" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());
    await userEvent.click(screen.getByRole('button', { name: /reactivate this user/i }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText('Reactivate User')).toBeInTheDocument();
  });

  it('calls updateUserStatus with reactivate + reason', async () => {
    render(<UserDetailPage userId="user-002" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /reactivate this user/i }));
    const dialog = screen.getByRole('dialog');
    await userEvent.type(within(dialog).getByRole('textbox'), 'Issue resolved');
    await userEvent.click(within(dialog).getByRole('button', { name: /confirm reactivation/i }));

    await waitFor(() =>
      expect(mockUpdateUserStatus).toHaveBeenCalledWith(
        'user-002',
        'reactivate',
        'Issue resolved',
      ),
    );
  });

  it('shows success message after reactivation', async () => {
    render(<UserDetailPage userId="user-002" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /reactivate this user/i }));
    await userEvent.type(screen.getByRole('textbox'), 'Resolved');
    await userEvent.click(screen.getByRole('button', { name: /confirm reactivation/i }));

    await waitFor(() =>
      expect(screen.getByRole('status')).toHaveTextContent(/reactivation submitted/i),
    );
  });

  it('status badge updates after reactivation (re-fetch triggered)', async () => {
    mockGetUser
      .mockResolvedValueOnce({ data: ADMIN_USER_SUSPENDED_DETAIL })
      .mockResolvedValueOnce({ data: ADMIN_USER_DETAIL });

    render(<UserDetailPage userId="user-002" />);
    await waitFor(() => expect(screen.getByText('Alice Smith')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /reactivate this user/i }));
    await userEvent.type(screen.getByRole('textbox'), 'Resolved');
    await userEvent.click(screen.getByRole('button', { name: /confirm reactivation/i }));

    await waitFor(() => expect(mockGetUser).toHaveBeenCalledTimes(2));
  });
});
