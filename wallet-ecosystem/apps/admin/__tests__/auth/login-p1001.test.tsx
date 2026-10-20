/**
 * ADM-P1-001 additions to the login page:
 *  - "Forgot password?" link
 *  - Reset success banner (?reset=success)
 *  - Banner dismissibility
 *  - Redirect on ACCOUNT_LOCKED / ACCOUNT_DISABLED error codes
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockReplace = jest.fn();
const mockGet     = jest.fn((_key: string) => null as string | null);

jest.mock('next/navigation', () => ({
  useRouter:      () => ({ replace: mockReplace, push: jest.fn() }),
  useSearchParams: () => ({ get: mockGet }),
}));

jest.mock('@/src/modules/auth/api/auth', () => ({
  loginWithPassword: jest.fn(),
  verifyMfa: jest.fn(),
  initiateStepUp: jest.fn(),
  verifyStepUp: jest.fn(),
}));

import { AdminLoginPage } from '@/src/modules/auth/pages/login';
import { AuthProvider } from '@/lib/auth/context';

function renderLoginPage() {
  return render(
    <AuthProvider>
      <AdminLoginPage />
    </AuthProvider>,
  );
}

beforeEach(() => {
  jest.clearAllMocks();
  mockGet.mockReturnValue(null);
});

describe('Login page — ADM-P1-001 additions', () => {
  it('renders "Forgot password?" link next to the password label', () => {
    renderLoginPage();
    const link = screen.getByRole('link', { name: /forgot password/i });
    expect(link).toBeInTheDocument();
    expect(link).toHaveAttribute('href', '/forgot-password');
  });

  it('shows password reset success banner when ?reset=success', () => {
    mockGet.mockImplementation((key: string) => (key === 'reset' ? 'success' : null));
    renderLoginPage();
    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.getByText(/password.*reset/i)).toBeInTheDocument();
  });

  it('dismisses the reset success banner when close button clicked', async () => {
    mockGet.mockImplementation((key: string) => (key === 'reset' ? 'success' : null));
    renderLoginPage();
    const banner = screen.getByRole('status');
    expect(banner).toBeInTheDocument();

    const closeBtn = screen.getByRole('button', { name: /dismiss/i });
    await userEvent.click(closeBtn);
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('redirects to /account-locked when auth returns ACCOUNT_LOCKED code', async () => {
    render(
      <AuthProvider
        initialPhase={{
          kind: 'unauthenticated',
          error: 'Account locked.',
          errorCode: 'ACCOUNT_LOCKED',
        }}
      >
        <AdminLoginPage />
      </AuthProvider>,
    );
    await waitFor(() =>
      expect(mockReplace).toHaveBeenCalledWith('/account-locked'),
    );
  });

  it('redirects to /account-disabled when auth returns ACCOUNT_DISABLED code', async () => {
    render(
      <AuthProvider
        initialPhase={{
          kind: 'unauthenticated',
          error: 'Account disabled.',
          errorCode: 'ACCOUNT_DISABLED',
        }}
      >
        <AdminLoginPage />
      </AuthProvider>,
    );
    await waitFor(() =>
      expect(mockReplace).toHaveBeenCalledWith('/account-disabled'),
    );
  });

  it('redirects to /account-locked when auth returns legacy IDENTITY_004 code', async () => {
    render(
      <AuthProvider
        initialPhase={{
          kind: 'unauthenticated',
          error: 'Account is locked.',
          errorCode: 'IDENTITY_004',
        }}
      >
        <AdminLoginPage />
      </AuthProvider>,
    );
    await waitFor(() =>
      expect(mockReplace).toHaveBeenCalledWith('/account-locked'),
    );
  });
});
