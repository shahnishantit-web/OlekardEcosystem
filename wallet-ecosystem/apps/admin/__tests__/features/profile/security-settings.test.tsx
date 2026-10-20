/**
 * ADM-P1-003 US-P1-003-002 / US-P1-003-003 — Security Settings page tests.
 * Verifies: change-password form submission; incorrect-password error;
 *           MFA device list rendering; device removal; add-device link.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ children, href }: { children: React.ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
}));

jest.mock('@/src/modules/profile/api/security', () => ({
  changePassword: jest.fn(),
  listMfaMethods: jest.fn(),
  disableMfaMethod: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { SecuritySettingsPage } from '@/src/modules/profile/pages/security-settings';
import * as securityApi from '@/src/modules/profile/api/security';
import * as authContext from '@/lib/auth/context';

const mockChangePassword = securityApi.changePassword as jest.MockedFunction<typeof securityApi.changePassword>;
const mockListMfaMethods = securityApi.listMfaMethods as jest.MockedFunction<typeof securityApi.listMfaMethods>;
const mockDisableMfaMethod = securityApi.disableMfaMethod as jest.MockedFunction<typeof securityApi.disableMfaMethod>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const MOCK_CLIENT = {} as never;

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
  mockListMfaMethods.mockResolvedValue({ methods: ['totp'], enrolled: true });
});

// ── Change password ───────────────────────────────────────────────────────────

describe('SecuritySettingsPage — change password', () => {
  it('renders the change-password form', async () => {
    render(<SecuritySettingsPage />);
    await waitFor(() => expect(screen.getByLabelText(/current password/i)).toBeInTheDocument());
    // Two password inputs contain "new password" in their label — check both exist
    expect(screen.getAllByLabelText(/new password/i)).toHaveLength(2);
    expect(screen.getByLabelText(/confirm new password/i)).toBeInTheDocument();
  });

  it('calls changePassword API with form values on submit', async () => {
    mockChangePassword.mockResolvedValueOnce(undefined);
    render(<SecuritySettingsPage />);
    await waitFor(() => expect(screen.getByLabelText(/current password/i)).toBeInTheDocument());

    await userEvent.type(screen.getByLabelText(/current password/i), 'OldPass1!');
    await userEvent.type(screen.getByLabelText(/^new password$/i), 'NewPass2@2024');
    await userEvent.type(screen.getByLabelText(/confirm new password/i), 'NewPass2@2024');
    await userEvent.click(screen.getByRole('button', { name: /change password/i }));

    await waitFor(() =>
      expect(mockChangePassword).toHaveBeenCalledWith(MOCK_CLIENT, {
        current_password: 'OldPass1!',
        new_password: 'NewPass2@2024',
        new_password_confirmation: 'NewPass2@2024',
      }),
    );
  });

  it('shows success toast after successful password change', async () => {
    mockChangePassword.mockResolvedValueOnce(undefined);
    render(<SecuritySettingsPage />);
    await waitFor(() => expect(screen.getByLabelText(/current password/i)).toBeInTheDocument());

    await userEvent.type(screen.getByLabelText(/current password/i), 'OldPass1!');
    await userEvent.type(screen.getByLabelText(/^new password$/i), 'NewPass2@2024');
    await userEvent.type(screen.getByLabelText(/confirm new password/i), 'NewPass2@2024');
    await userEvent.click(screen.getByRole('button', { name: /change password/i }));

    await waitFor(() =>
      expect(screen.getByText(/password changed successfully/i)).toBeInTheDocument(),
    );
  });

  it('shows error message when current password is incorrect', async () => {
    mockChangePassword.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'IDENTITY_INVALID_CURRENT_PASSWORD', message: 'Current password is incorrect.' },
      correlation_id: 'test-id',
      meta: null,
    });
    render(<SecuritySettingsPage />);
    await waitFor(() => expect(screen.getByLabelText(/current password/i)).toBeInTheDocument());

    await userEvent.type(screen.getByLabelText(/current password/i), 'Wrong!');
    await userEvent.type(screen.getByLabelText(/^new password$/i), 'NewPass2@2024');
    await userEvent.type(screen.getByLabelText(/confirm new password/i), 'NewPass2@2024');
    await userEvent.click(screen.getByRole('button', { name: /change password/i }));

    await waitFor(() =>
      expect(screen.getByText(/current password is incorrect/i)).toBeInTheDocument(),
    );
  });
});

// ── MFA devices ───────────────────────────────────────────────────────────────

describe('SecuritySettingsPage — MFA devices', () => {
  it('renders enrolled MFA methods', async () => {
    mockListMfaMethods.mockResolvedValueOnce({ methods: ['totp', 'fido2'], enrolled: true });
    render(<SecuritySettingsPage />);
    await waitFor(() =>
      expect(screen.getByText(/authenticator app/i)).toBeInTheDocument(),
    );
    expect(screen.getByText(/security key/i)).toBeInTheDocument();
  });

  it('shows empty state when no MFA devices enrolled', async () => {
    mockListMfaMethods.mockResolvedValueOnce({ methods: [], enrolled: false });
    render(<SecuritySettingsPage />);
    await waitFor(() =>
      expect(screen.getByText(/no mfa devices enrolled/i)).toBeInTheDocument(),
    );
  });

  it('calls disableMfaMethod when Remove is clicked', async () => {
    mockDisableMfaMethod.mockResolvedValueOnce(undefined);
    render(<SecuritySettingsPage />);
    await waitFor(() => expect(screen.getByText(/authenticator app/i)).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /remove authenticator app/i }));

    await waitFor(() =>
      expect(mockDisableMfaMethod).toHaveBeenCalledWith(MOCK_CLIENT, 'totp'),
    );
  });

  it('removes device from list after successful removal', async () => {
    mockDisableMfaMethod.mockResolvedValueOnce(undefined);
    render(<SecuritySettingsPage />);
    await waitFor(() => expect(screen.getByText(/authenticator app/i)).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /remove authenticator app/i }));
    await waitFor(() =>
      expect(screen.queryByText(/authenticator app/i)).not.toBeInTheDocument(),
    );
  });

  it('renders "Add device" link pointing to /mfa-enrollment', async () => {
    render(<SecuritySettingsPage />);
    await waitFor(() => expect(screen.getByText(/add device/i)).toBeInTheDocument());
    expect(screen.getByRole('link', { name: /add device/i })).toHaveAttribute(
      'href',
      '/mfa-enrollment',
    );
  });
});
