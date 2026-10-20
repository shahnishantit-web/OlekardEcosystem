/**
 * ADM-P1-001 US-P1-001-005
 * VerifyEmailPage — auto-verify, success, expired, check-email (no token), resend.
 */
import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockReplace = jest.fn();
jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: mockReplace }),
}));

jest.mock('@/src/modules/auth/api/password-recovery', () => ({
  verifyEmail:          jest.fn(),
  resendVerification:   jest.fn(),
}));

import { VerifyEmailPage } from '@/src/modules/auth/pages/verify-email';
import * as recoveryApi from '@/src/modules/auth/api/password-recovery';

const mockVerifyEmail        = recoveryApi.verifyEmail as jest.MockedFunction<typeof recoveryApi.verifyEmail>;
const mockResendVerification = recoveryApi.resendVerification as jest.MockedFunction<typeof recoveryApi.resendVerification>;

beforeEach(() => jest.clearAllMocks());

describe('VerifyEmailPage (US-P1-001-005)', () => {
  describe('with token — auto-verification', () => {
    it('shows verifying state immediately', () => {
      mockVerifyEmail.mockReturnValue(new Promise(() => {}));
      render(<VerifyEmailPage token="tok-ve-1" email={null} />);
      expect(screen.getByRole('heading', { name: /verifying/i })).toBeInTheDocument();
    });

    it('shows verified success state after API resolves', async () => {
      mockVerifyEmail.mockResolvedValueOnce({ message: 'verified' });
      render(<VerifyEmailPage token="tok-ve-2" email={null} />);
      await waitFor(() =>
        expect(screen.getByRole('heading', { name: /email verified/i })).toBeInTheDocument(),
      );
    });

    it('shows Go to sign in button in verified state', async () => {
      mockVerifyEmail.mockResolvedValueOnce({ message: 'verified' });
      render(<VerifyEmailPage token="tok-ve-3" email={null} />);
      await waitFor(() =>
        expect(screen.getByRole('button', { name: /go to sign in/i })).toBeInTheDocument(),
      );
    });

    it('shows expired state when API returns TOKEN_EXPIRED', async () => {
      const apiErr = Object.assign(new Error('Expired'), {
        success: false, data: null,
        error: { code: 'TOKEN_EXPIRED', message: 'Link expired.', details: [] },
        correlation_id: 'cid-ve-1', meta: null,
      });
      mockVerifyEmail.mockRejectedValueOnce(apiErr);
      render(<VerifyEmailPage token="tok-old" email={null} />);
      await waitFor(() =>
        expect(screen.getByRole('heading', { name: /link has expired/i })).toBeInTheDocument(),
      );
    });

    it('shows resend form in expired state', async () => {
      const apiErr = Object.assign(new Error('Expired'), {
        success: false, data: null,
        error: { code: 'TOKEN_EXPIRED', message: 'Link expired.', details: [] },
        correlation_id: 'cid-ve-2', meta: null,
      });
      mockVerifyEmail.mockRejectedValueOnce(apiErr);
      render(<VerifyEmailPage token="tok-old" email={null} />);
      await waitFor(() =>
        expect(screen.getByRole('button', { name: /resend verification email/i })).toBeInTheDocument(),
      );
    });
  });

  describe('without token — check email state', () => {
    it('shows check your email heading', () => {
      render(<VerifyEmailPage token={null} email={null} />);
      expect(screen.getByRole('heading', { name: /check your email/i })).toBeInTheDocument();
    });

    it('shows prefilled email when email prop provided', () => {
      render(<VerifyEmailPage token={null} email="user@example.com" />);
      expect(screen.getByText('user@example.com')).toBeInTheDocument();
    });

    it('shows email input when no email prop', () => {
      render(<VerifyEmailPage token={null} email={null} />);
      expect(screen.getByLabelText(/email address/i)).toBeInTheDocument();
    });

    it('renders resend button', () => {
      render(<VerifyEmailPage token={null} email={null} />);
      expect(screen.getByRole('button', { name: /resend verification email/i })).toBeInTheDocument();
    });

    it('calls resendVerification and shows resent state', async () => {
      mockResendVerification.mockResolvedValueOnce(undefined);
      render(<VerifyEmailPage token={null} email={null} />);
      await userEvent.type(screen.getByLabelText(/email address/i), 'test@example.com');
      await userEvent.click(screen.getByRole('button', { name: /resend verification email/i }));
      await waitFor(() =>
        expect(screen.getByRole('heading', { name: /verification email sent/i })).toBeInTheDocument(),
      );
      expect(mockResendVerification).toHaveBeenCalledWith('test@example.com');
    });

    it('does not call verifyEmail when no token', () => {
      render(<VerifyEmailPage token={null} email={null} />);
      expect(mockVerifyEmail).not.toHaveBeenCalled();
    });
  });
});
