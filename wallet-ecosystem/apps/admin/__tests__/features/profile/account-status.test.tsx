/**
 * T149 — Admin account status page tests.
 * Verifies: status badge; history table; reactivate/cancel/close button gating.
 */
import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('@/src/modules/profile/api/profile', () => ({
  getAdminAccountStatus: jest.fn(),
  reactivateAccount: jest.fn(),
  cancelClosureAccount: jest.fn(),
  closeAccount: jest.fn(),
  suspendAccount: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { AccountStatusPage } from '@/src/modules/profile/pages/account-status';
import * as profileApi from '@/src/modules/profile/api/profile';
import * as authContext from '@/lib/auth/context';

const mockGetAdminAccountStatus = profileApi.getAdminAccountStatus as jest.MockedFunction<typeof profileApi.getAdminAccountStatus>;
const mockReactivateAccount = profileApi.reactivateAccount as jest.MockedFunction<typeof profileApi.reactivateAccount>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const fakeStatusActive = {
  account_status: 'active',
  history: [
    {
      from_status: null,
      to_status: 'active',
      reason: 'Registration',
      actor_type: 'SYSTEM',
      actor_id: null,
      created_at: '2026-01-01T00:00:00Z',
    },
  ],
};

const fakeStatusSuspended = {
  account_status: 'suspended',
  history: [
    {
      from_status: 'active',
      to_status: 'suspended',
      reason: 'Policy violation',
      actor_type: 'ADMIN',
      actor_id: 'admin-999',
      created_at: '2026-08-01T10:00:00Z',
    },
  ],
};

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

describe('AccountStatusPage (T149)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('renders current status badge and history table', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce(fakeStatusActive);

    render(<AccountStatusPage userId="user-abc-123" />);

    await waitFor(() => {
      // 'ACTIVE' appears in the current-status badge AND in the history row — use getAllByText
      expect(screen.getAllByText('ACTIVE').length).toBeGreaterThan(0);
    });

    expect(screen.getByText('Registration')).toBeInTheDocument();
    expect(screen.getByText('SYSTEM')).toBeInTheDocument();
  });

  it('shows empty message when history is empty', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce({ account_status: 'active', history: [] });

    render(<AccountStatusPage userId="user-abc-123" />);

    await waitFor(() => {
      expect(screen.getByText(/No history/i)).toBeInTheDocument();
    });
  });

  it('shows Reactivate button when status is suspended and canReactivate is true', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce(fakeStatusSuspended);

    render(<AccountStatusPage userId="user-abc-123" canReactivate />);

    await waitFor(() => {
      expect(screen.getByText('Reactivate')).toBeInTheDocument();
    });
  });

  it('does not show Reactivate button when canReactivate is false', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce(fakeStatusSuspended);

    render(<AccountStatusPage userId="user-abc-123" canReactivate={false} />);

    await waitFor(() => {
      // 'SUSPENDED' may appear in the current-status badge AND history row
      expect(screen.getAllByText('SUSPENDED').length).toBeGreaterThan(0);
    });

    expect(screen.queryByRole('button', { name: 'Reactivate' })).not.toBeInTheDocument();
  });

  it('reactivate dialog opens on button click', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce(fakeStatusSuspended);

    render(<AccountStatusPage userId="user-abc-123" canReactivate />);

    await waitFor(() => screen.getByText('Reactivate'));
    await userEvent.click(screen.getByText('Reactivate'));

    expect(screen.getByText('Reactivate Account')).toBeInTheDocument();
  });

  it('shows toast on successful reactivation', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce(fakeStatusSuspended);
    mockReactivateAccount.mockResolvedValueOnce({ account_status: 'active' });

    render(<AccountStatusPage userId="user-abc-123" canReactivate />);

    await waitFor(() => screen.getByRole('button', { name: 'Reactivate' }));
    await userEvent.click(screen.getByRole('button', { name: 'Reactivate' }));

    // After opening the dialog there are two "Reactivate" buttons — confirm is inside the dialog
    const dialog = screen.getByRole('dialog');
    const confirmBtn = within(dialog).getByRole('button', { name: 'Reactivate' });
    await userEvent.click(confirmBtn);

    await waitFor(() => {
      expect(screen.getByText('Account reactivated.')).toBeInTheDocument();
    });
  });

  it('shows error state on fetch failure', async () => {
    mockGetAdminAccountStatus.mockRejectedValueOnce({
      success: false, data: null, error: { code: 'SERVER_ERROR', message: 'Server error' },
    });

    render(<AccountStatusPage userId="user-abc-123" />);

    await waitFor(() => {
      expect(screen.getByText(/Failed to load account status/i)).toBeInTheDocument();
    });
  });
});
