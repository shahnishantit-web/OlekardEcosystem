/**
 * T149 — CloseAccountDialog tests.
 * Verifies: two-step confirmation; obligation list displayed when blocked;
 * success callback on API success; hasPermission=false hides dialog.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('@/src/modules/profile/api/profile', () => ({
  closeAccount: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { CloseAccountDialog } from '@/components/profile/CloseAccountDialog';
import * as profileApi from '@/src/modules/profile/api/profile';
import * as authContext from '@/lib/auth/context';

const mockCloseAccount = profileApi.closeAccount as jest.MockedFunction<typeof profileApi.closeAccount>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

function setupAuth() {
  mockUseAuth.mockReturnValue({
    authenticatedClient: {} as never,
    isAuthenticated: true,
    phase: 'authenticated',
    user: { id: 'admin-1', email: 'admin@olekard.local', name: 'Admin' },
    mfaMethods: null,
    authError: null,
    login: jest.fn(),
    completeMfa: jest.fn(),
    signOut: jest.fn(),
  });
}

describe('CloseAccountDialog (T149)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('renders initial step with Close Account button', () => {
    render(
      <CloseAccountDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    expect(screen.getByText(/permanent/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Close Account' })).toBeInTheDocument();
  });

  it('advances to confirmation step on first click', async () => {
    render(
      <CloseAccountDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Close Account' }));

    expect(screen.getByText(/Are you absolutely sure/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Yes, Permanently Close' })).toBeInTheDocument();
  });

  it('calls closeAccount and onSuccess on final confirmation', async () => {
    const onSuccess = jest.fn();
    mockCloseAccount.mockResolvedValueOnce({ account_status: 'closed' });

    render(
      <CloseAccountDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={onSuccess}
      />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Close Account' }));
    await userEvent.click(screen.getByRole('button', { name: 'Yes, Permanently Close' }));

    await waitFor(() => {
      expect(mockCloseAccount).toHaveBeenCalledWith(expect.anything(), 'user-1', undefined);
      expect(onSuccess).toHaveBeenCalledWith('closed');
    });
  });

  it('displays obligation list when closure is blocked', async () => {
    mockCloseAccount.mockRejectedValueOnce({
      success: false,
      data: null,
      error: {
        code: 'CLOSURE_BLOCKED_BY_OBLIGATIONS',
        message: 'Account closure is blocked by unresolved obligations.',
        details: { blocked_by: ['wallet_balance', 'pending_transactions'] },
      },
    });

    render(
      <CloseAccountDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Close Account' }));
    await userEvent.click(screen.getByRole('button', { name: 'Yes, Permanently Close' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent(
        'Account closure is blocked by unresolved obligations.',
      );
      expect(screen.getByText('wallet_balance')).toBeInTheDocument();
      expect(screen.getByText('pending_transactions')).toBeInTheDocument();
    });
  });

  it('shows generic error when API fails without obligation detail', async () => {
    mockCloseAccount.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'SERVER_ERROR', message: 'Internal server error.' },
    });

    render(
      <CloseAccountDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Close Account' }));
    await userEvent.click(screen.getByRole('button', { name: 'Yes, Permanently Close' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('Internal server error.');
    });
  });

  it('renders nothing when hasPermission is false', () => {
    const { container } = render(
      <CloseAccountDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
        hasPermission={false}
      />,
    );

    expect(container.firstChild).toBeNull();
  });
});
