/**
 * T149 — SuspendDialog tests.
 * Verifies: reason required before submit enabled; two-step confirmation;
 * success callback on API success; error shown on failure;
 * dialog hidden when hasPermission is false.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('@/src/modules/profile/api/profile', () => ({
  suspendAccount: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { SuspendDialog } from '@/components/profile/SuspendDialog';
import * as profileApi from '@/src/modules/profile/api/profile';
import * as authContext from '@/lib/auth/context';

const mockSuspendAccount = profileApi.suspendAccount as jest.MockedFunction<typeof profileApi.suspendAccount>;
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

describe('SuspendDialog (T149)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('renders reason input when open', () => {
    render(
      <SuspendDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    expect(screen.getByLabelText('Reason for suspension')).toBeInTheDocument();
  });

  it('Suspend Account button is disabled when reason is empty', () => {
    render(
      <SuspendDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    const btn = screen.getByRole('button', { name: 'Suspend Account' });
    expect(btn).toBeDisabled();
  });

  it('Suspend Account button is enabled after reason is entered', async () => {
    render(
      <SuspendDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    await userEvent.type(screen.getByLabelText('Reason for suspension'), 'Policy violation');

    const btn = screen.getByRole('button', { name: 'Suspend Account' });
    expect(btn).not.toBeDisabled();
  });

  it('advances to confirmation step on first click', async () => {
    render(
      <SuspendDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    await userEvent.type(screen.getByLabelText('Reason for suspension'), 'Policy violation');
    await userEvent.click(screen.getByRole('button', { name: 'Suspend Account' }));

    expect(screen.getByText(/Are you sure/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Confirm Suspend' })).toBeInTheDocument();
  });

  it('calls suspendAccount and onSuccess on confirm', async () => {
    const onSuccess = jest.fn();
    mockSuspendAccount.mockResolvedValueOnce({ account_status: 'suspended' });

    render(
      <SuspendDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={onSuccess}
      />,
    );

    await userEvent.type(screen.getByLabelText('Reason for suspension'), 'Policy violation');
    await userEvent.click(screen.getByRole('button', { name: 'Suspend Account' }));
    await userEvent.click(screen.getByRole('button', { name: 'Confirm Suspend' }));

    await waitFor(() => {
      expect(mockSuspendAccount).toHaveBeenCalledWith(
        expect.anything(),
        'user-1',
        'Policy violation',
      );
      expect(onSuccess).toHaveBeenCalledWith('suspended');
    });
  });

  it('shows error message when API call fails', async () => {
    mockSuspendAccount.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'INVALID_STATUS_TRANSITION', message: 'Account is not active.' },
    });

    render(
      <SuspendDialog
        open
        userId="user-1"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );

    await userEvent.type(screen.getByLabelText('Reason for suspension'), 'Policy violation');
    await userEvent.click(screen.getByRole('button', { name: 'Suspend Account' }));
    await userEvent.click(screen.getByRole('button', { name: 'Confirm Suspend' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('Account is not active.');
    });
  });

  it('renders nothing when hasPermission is false', () => {
    const { container } = render(
      <SuspendDialog
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
