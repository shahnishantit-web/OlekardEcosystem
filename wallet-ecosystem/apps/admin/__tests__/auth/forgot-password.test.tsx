/**
 * ADM-P1-001 US-P1-001-001
 * ForgotPasswordPage — form, validation, submit, success, and error states.
 */
import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('@/src/modules/auth/api/password-recovery', () => ({
  requestPasswordReset: jest.fn(),
}));

import { ForgotPasswordPage } from '@/src/modules/auth/pages/forgot-password';
import * as recoveryApi from '@/src/modules/auth/api/password-recovery';

const mockRequestReset = recoveryApi.requestPasswordReset as jest.MockedFunction<
  typeof recoveryApi.requestPasswordReset
>;

beforeEach(() => jest.clearAllMocks());

describe('ForgotPasswordPage (US-P1-001-001)', () => {
  it('renders email input and submit button', () => {
    render(<ForgotPasswordPage />);
    expect(screen.getByLabelText(/email address/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /send reset link/i })).toBeInTheDocument();
  });

  it('renders Back to sign in link', () => {
    render(<ForgotPasswordPage />);
    const link = screen.getByRole('link', { name: /back to sign in/i });
    expect(link).toBeInTheDocument();
    expect(link).toHaveAttribute('href', '/login');
  });

  it('shows inline error for empty submit', async () => {
    render(<ForgotPasswordPage />);
    await userEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(mockRequestReset).not.toHaveBeenCalled();
  });

  it('shows inline error for invalid email format', async () => {
    render(<ForgotPasswordPage />);
    await userEvent.type(screen.getByLabelText(/email address/i), 'notanemail');
    await userEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    expect(screen.getByText(/valid email/i)).toBeInTheDocument();
    expect(mockRequestReset).not.toHaveBeenCalled();
  });

  it('calls requestPasswordReset with trimmed email on valid submit', async () => {
    mockRequestReset.mockResolvedValueOnce({ message: 'sent' });
    render(<ForgotPasswordPage />);
    await userEvent.type(screen.getByLabelText(/email address/i), '  admin@olekard.local  ');
    await userEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    await waitFor(() => expect(mockRequestReset).toHaveBeenCalledWith('admin@olekard.local'));
  });

  it('shows check-your-email success state after submit', async () => {
    mockRequestReset.mockResolvedValueOnce({ message: 'sent' });
    render(<ForgotPasswordPage />);
    await userEvent.type(screen.getByLabelText(/email address/i), 'admin@olekard.local');
    await userEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /check your email/i })).toBeInTheDocument(),
    );
  });

  it('displays the submitted email in the success message', async () => {
    mockRequestReset.mockResolvedValueOnce({ message: 'sent' });
    render(<ForgotPasswordPage />);
    await userEvent.type(screen.getByLabelText(/email address/i), 'admin@olekard.local');
    await userEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    await waitFor(() => expect(screen.getByText('admin@olekard.local')).toBeInTheDocument());
  });

  it('shows API error message in banner on failure', async () => {
    const apiErr = Object.assign(new Error('Not found'), {
      success: false,
      data: null,
      error: { code: 'NOT_FOUND', message: 'No account with that email.', details: [] },
      correlation_id: 'cid-1',
      meta: null,
    });
    mockRequestReset.mockRejectedValueOnce(apiErr);
    render(<ForgotPasswordPage />);
    await userEvent.type(screen.getByLabelText(/email address/i), 'unknown@example.com');
    await userEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText(/no account with that email/i)).toBeInTheDocument();
  });
});
