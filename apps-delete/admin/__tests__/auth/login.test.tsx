/**
 * T184 — E2E component tests for Admin login + mandatory MFA flow.
 * Verifies: AC-018 (MFA cannot be bypassed), AC-028 (TOTP/FIDO2 only; no SMS).
 */
import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// Mock next/navigation before importing the component
jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

// Mock the auth API module
jest.mock('@/src/modules/auth/api/auth', () => ({
  loginWithPassword: jest.fn(),
  verifyMfa: jest.fn(),
  initiateStepUp: jest.fn(),
  verifyStepUp: jest.fn(),
}));

import { AdminLoginPage } from '@/src/modules/auth/pages/login';
import { AuthProvider } from '@/lib/auth/context';
import * as authApi from '@/src/modules/auth/api/auth';

const mockLoginWithPassword = authApi.loginWithPassword as jest.MockedFunction<typeof authApi.loginWithPassword>;
const mockVerifyMfa = authApi.verifyMfa as jest.MockedFunction<typeof authApi.verifyMfa>;

function renderLoginPage() {
  return render(
    <AuthProvider>
      <AdminLoginPage />
    </AuthProvider>,
  );
}

const MFA_PENDING_RESPONSE: authApi.MfaPendingResponse = {
  mfa_required: true,
  mfa_token: 'opaque-mfa-token-xyz',
  methods: ['totp', 'fido2'],
  expires_in: 300,
};

const AUTHENTICATED_RESPONSE: authApi.AuthenticatedTokenResponse = {
  access_token: 'eyJtest.access.token',
  refresh_token: 'opaque-refresh-token',
  token_type: 'Bearer',
  expires_in: 900,
  session_id: 'ses-test-123',
};

describe('Admin login — credentials phase (T184, AC-018, AC-028)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders email, password fields and Continue button', () => {
    renderLoginPage();

    expect(screen.getByRole('main')).toBeInTheDocument();
    expect(screen.getByText('Admin Sign In')).toBeInTheDocument();
    expect(screen.getByLabelText('Email address')).toBeInTheDocument();
    expect(screen.getByLabelText('Password')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Continue' })).toBeInTheDocument();
  });

  it('informs the user that MFA is mandatory', () => {
    renderLoginPage();
    expect(screen.getByText(/Multi-factor authentication is required/i)).toBeInTheDocument();
  });

  it('shows validation error when email is empty', async () => {
    renderLoginPage();

    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

    expect(screen.getByText('Email is required.')).toBeInTheDocument();
    expect(mockLoginWithPassword).not.toHaveBeenCalled();
  });

  it('shows validation error when password is empty', async () => {
    renderLoginPage();

    await userEvent.type(screen.getByLabelText('Email address'), 'admin@example.com');
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

    expect(screen.getByText('Password is required.')).toBeInTheDocument();
    expect(mockLoginWithPassword).not.toHaveBeenCalled();
  });

  it('calls loginWithPassword with email and password on submit', async () => {
    mockLoginWithPassword.mockResolvedValueOnce(MFA_PENDING_RESPONSE);
    renderLoginPage();

    await userEvent.type(screen.getByLabelText('Email address'), 'admin@example.com');
    await userEvent.type(screen.getByLabelText('Password'), 'Secret1234!');
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

    await waitFor(() => {
      expect(mockLoginWithPassword).toHaveBeenCalledWith('admin@example.com', 'Secret1234!');
    });
  });

  it('shows API error when login fails', async () => {
    mockLoginWithPassword.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'IDENTITY_002', message: 'Invalid credentials.', details: [] },
      correlation_id: 'corr-1',
      meta: null,
    });
    renderLoginPage();

    await userEvent.type(screen.getByLabelText('Email address'), 'admin@example.com');
    await userEvent.type(screen.getByLabelText('Password'), 'wrong');
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('Invalid credentials.');
    });
  });
});

describe('Admin login — MFA phase (T184, AC-018, AC-028)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  async function advanceToMfa() {
    mockLoginWithPassword.mockResolvedValueOnce(MFA_PENDING_RESPONSE);
    renderLoginPage();

    await userEvent.type(screen.getByLabelText('Email address'), 'admin@example.com');
    await userEvent.type(screen.getByLabelText('Password'), 'Secret1234!');
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

    await waitFor(() => {
      expect(screen.getByText('Two-Factor Authentication')).toBeInTheDocument();
    });
  }

  it('transitions to MFA phase after valid credentials', async () => {
    await advanceToMfa();

    expect(screen.getByText('Two-Factor Authentication')).toBeInTheDocument();
    expect(screen.getByText(/Enter the code from your authenticator app/i)).toBeInTheDocument();
  });

  it('AC-018 — no skip MFA button is present', async () => {
    await advanceToMfa();

    // No "Skip" or "Skip MFA" option
    expect(screen.queryByRole('button', { name: /skip/i })).not.toBeInTheDocument();
    expect(screen.queryByText(/skip/i)).not.toBeInTheDocument();
  });

  it('AC-028 — shows TOTP and FIDO2 methods; SMS not offered', async () => {
    await advanceToMfa();

    // Both TOTP and FIDO2 method labels visible
    expect(screen.getByText(/Authenticator app \(TOTP\)/i)).toBeInTheDocument();
    expect(screen.getByText(/Security key \(FIDO2\)/i)).toBeInTheDocument();

    // SMS not offered
    expect(screen.queryByText(/SMS/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/text message/i)).not.toBeInTheDocument();
  });

  it('shows validation error when code is empty', async () => {
    await advanceToMfa();

    await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

    expect(screen.getByText('Verification code is required.')).toBeInTheDocument();
    expect(mockVerifyMfa).not.toHaveBeenCalled();
  });

  it('calls verifyMfa with mfa_token, method, and code', async () => {
    mockVerifyMfa.mockResolvedValueOnce(AUTHENTICATED_RESPONSE);
    await advanceToMfa();

    await userEvent.type(screen.getByLabelText(/6-digit code/i), '123456');
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

    await waitFor(() => {
      expect(mockVerifyMfa).toHaveBeenCalledWith(
        'opaque-mfa-token-xyz',
        'totp',
        '123456',
      );
    });
  });

  it('shows API error when MFA code is wrong', async () => {
    mockVerifyMfa.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'IDENTITY_014', message: 'Invalid or expired MFA code.', details: [] },
      correlation_id: 'corr-2',
      meta: null,
    });
    await advanceToMfa();

    await userEvent.type(screen.getByLabelText(/6-digit code/i), '999999');
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('Invalid or expired MFA code.');
    });
  });

  it('allows going back to credentials phase', async () => {
    await advanceToMfa();

    await userEvent.click(screen.getByRole('button', { name: 'Back to sign in' }));

    expect(screen.getByText('Admin Sign In')).toBeInTheDocument();
    expect(screen.getByLabelText('Email address')).toBeInTheDocument();
  });

  it('only accepts numeric input in the code field', async () => {
    await advanceToMfa();

    const codeInput = screen.getByLabelText(/6-digit code/i);
    await userEvent.type(codeInput, 'abc12def3456');

    expect((codeInput as HTMLInputElement).value).toBe('123456');
  });
});
