/**
 * ADM-P1-001 US-P1-001-002
 * ResetPasswordPage — invalid token, strength checklist, submit, success redirect, expired token.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockReplace = jest.fn();
jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: mockReplace }),
}));

jest.mock('@/src/modules/auth/api/password-recovery', () => ({
  resetPassword: jest.fn(),
}));

import { ResetPasswordPage } from '@/src/modules/auth/pages/reset-password';
import * as recoveryApi from '@/src/modules/auth/api/password-recovery';

const mockResetPassword = recoveryApi.resetPassword as jest.MockedFunction<
  typeof recoveryApi.resetPassword
>;

beforeEach(() => jest.clearAllMocks());

describe('ResetPasswordPage (US-P1-001-002)', () => {
  describe('no token', () => {
    it('shows invalid link state', () => {
      render(<ResetPasswordPage token={null} />);
      expect(screen.getByRole('heading', { name: /invalid reset link/i })).toBeInTheDocument();
    });

    it('shows request new link button pointing to /forgot-password', () => {
      render(<ResetPasswordPage token={null} />);
      const link = screen.getByRole('link', { name: /request new link/i });
      expect(link).toHaveAttribute('href', '/forgot-password');
    });
  });

  describe('with token — form', () => {
    it('renders password and confirm fields', () => {
      render(<ResetPasswordPage token="tok-abc" />);
      expect(screen.getByLabelText('New password')).toBeInTheDocument();
      expect(screen.getByLabelText('Confirm new password')).toBeInTheDocument();
    });

    it('renders all four strength checklist rules', () => {
      render(<ResetPasswordPage token="tok-abc" />);
      expect(screen.getByText(/at least 10 characters/i)).toBeInTheDocument();
      expect(screen.getByText(/uppercase/i)).toBeInTheDocument();
      expect(screen.getByText(/number/i)).toBeInTheDocument();
      expect(screen.getByText(/special character/i)).toBeInTheDocument();
    });

    it('submit button disabled until all rules met and confirm matches', async () => {
      render(<ResetPasswordPage token="tok-abc" />);
      const btn = screen.getByRole('button', { name: /set new password/i });
      expect(btn).toBeDisabled();
      await userEvent.type(screen.getByLabelText('New password'), 'Admin@12345');
      await userEvent.type(screen.getByLabelText('Confirm new password'), 'Admin@12345');
      expect(btn).not.toBeDisabled();
    });

    it('shows confirm mismatch error', async () => {
      render(<ResetPasswordPage token="tok-abc" />);
      await userEvent.type(screen.getByLabelText('New password'), 'Admin@12345');
      await userEvent.type(screen.getByLabelText('Confirm new password'), 'Different1!');
      expect(screen.getByText(/passwords do not match/i)).toBeInTheDocument();
    });

    it('calls resetPassword and redirects to /login?reset=success on success', async () => {
      mockResetPassword.mockResolvedValueOnce({ message: 'ok' });
      render(<ResetPasswordPage token="tok-abc" />);
      await userEvent.type(screen.getByLabelText('New password'), 'Admin@12345');
      await userEvent.type(screen.getByLabelText('Confirm new password'), 'Admin@12345');
      await userEvent.click(screen.getByRole('button', { name: /set new password/i }));
      await waitFor(() =>
        expect(mockReplace).toHaveBeenCalledWith('/login?reset=success'),
      );
      expect(mockResetPassword).toHaveBeenCalledWith('tok-abc', 'Admin@12345');
    });
  });

  describe('expired token', () => {
    it('shows expired state when API returns TOKEN_EXPIRED', async () => {
      const apiErr = Object.assign(new Error('Token expired'), {
        success: false,
        data: null,
        error: { code: 'TOKEN_EXPIRED', message: 'Link has expired.', details: [] },
        correlation_id: 'cid-2',
        meta: null,
      });
      mockResetPassword.mockRejectedValueOnce(apiErr);
      render(<ResetPasswordPage token="tok-old" />);
      await userEvent.type(screen.getByLabelText('New password'), 'Admin@12345');
      await userEvent.type(screen.getByLabelText('Confirm new password'), 'Admin@12345');
      await userEvent.click(screen.getByRole('button', { name: /set new password/i }));
      await waitFor(() =>
        expect(screen.getByRole('heading', { name: /this link has expired/i })).toBeInTheDocument(),
      );
    });

    it('shows request new link button after expiry', async () => {
      const apiErr = Object.assign(new Error('Token expired'), {
        success: false,
        data: null,
        error: { code: 'TOKEN_EXPIRED', message: 'Link has expired.', details: [] },
        correlation_id: 'cid-3',
        meta: null,
      });
      mockResetPassword.mockRejectedValueOnce(apiErr);
      render(<ResetPasswordPage token="tok-old" />);
      await userEvent.type(screen.getByLabelText('New password'), 'Admin@12345');
      await userEvent.type(screen.getByLabelText('Confirm new password'), 'Admin@12345');
      await userEvent.click(screen.getByRole('button', { name: /set new password/i }));
      await waitFor(() =>
        expect(screen.getByRole('link', { name: /request new link/i })).toBeInTheDocument(),
      );
    });
  });
});
