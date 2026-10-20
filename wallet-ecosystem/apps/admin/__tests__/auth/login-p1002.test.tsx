/**
 * ADM-P1-002 US-P1-002-004 — MFA enrollment gate tests (auth-context layer).
 *
 * Verifies that when the login API returns mfa_enrollment_required: true:
 *  1. The admin_mfa_setup=required cookie is written so middleware can gate the session.
 *  2. The login page still routes to /dashboard; the middleware (tested separately in
 *     __tests__/middleware.test.ts) is responsible for the /mfa-enrollment redirect.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockReplace = jest.fn();
const mockGet     = jest.fn((_key: string) => null as string | null);

jest.mock('next/navigation', () => ({
  useRouter:       () => ({ replace: mockReplace, push: jest.fn() }),
  useSearchParams: () => ({ get: mockGet }),
}));

jest.mock('@/src/modules/auth/api/auth', () => ({
  loginWithPassword: jest.fn(),
  verifyMfa:         jest.fn(),
  initiateStepUp:    jest.fn(),
  verifyStepUp:      jest.fn(),
}));

import { AdminLoginPage }  from '@/src/modules/auth/pages/login';
import { AuthProvider }    from '@/lib/auth/context';
import * as authApi        from '@/src/modules/auth/api/auth';

const mockLogin = authApi.loginWithPassword as jest.MockedFunction<typeof authApi.loginWithPassword>;

const BASE_RESPONSE: authApi.AuthenticatedTokenResponse = {
  access_token:  'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyLTAwMSIsInBlcm1pc3Npb25zIjpbXX0.sig',
  refresh_token: 'opaque-refresh',
  token_type:    'Bearer',
  expires_in:    900,
  session_id:    'ses-mfa-gate-test',
};

function renderPage() {
  return render(
    <AuthProvider>
      <AdminLoginPage />
    </AuthProvider>,
  );
}

async function submitLogin(email = 'admin@example.com', password = 'Admin123!') {
  await userEvent.type(screen.getByLabelText('Email address'), email);
  await userEvent.type(screen.getByLabelText('Password'), password);
  await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
}

beforeEach(() => {
  jest.clearAllMocks();
  mockGet.mockReturnValue(null);
  // Clear the MFA setup cookie between tests
  document.cookie = 'admin_mfa_setup=; path=/; max-age=0; SameSite=Lax';
});

// ── US-P1-002-004 — MFA enrollment gate (context layer) ──────────────────────

describe('US-P1-002-004 — MFA enrollment gate (login context)', () => {
  it('writes admin_mfa_setup=required cookie when login returns mfa_enrollment_required: true', async () => {
    mockLogin.mockResolvedValueOnce({ ...BASE_RESPONSE, mfa_enrollment_required: true });
    renderPage();
    await submitLogin();

    await waitFor(() => expect(document.cookie).toContain('admin_mfa_setup'));
    expect(document.cookie).toContain('required');
  });

  it('does NOT write admin_mfa_setup cookie when mfa_enrollment_required is false', async () => {
    mockLogin.mockResolvedValueOnce({ ...BASE_RESPONSE, mfa_enrollment_required: false });
    renderPage();
    await submitLogin();

    await waitFor(() => expect(mockReplace).toHaveBeenCalledWith('/dashboard'));
    // Cookie should not be set (or should be cleared)
    expect(document.cookie).not.toContain('admin_mfa_setup=required');
  });

  it('does NOT write admin_mfa_setup cookie when field is absent from response', async () => {
    mockLogin.mockResolvedValueOnce(BASE_RESPONSE); // no mfa_enrollment_required field
    renderPage();
    await submitLogin();

    await waitFor(() => expect(mockReplace).toHaveBeenCalledWith('/dashboard'));
    expect(document.cookie).not.toContain('admin_mfa_setup=required');
  });

  it('still redirects to /dashboard after login (middleware handles /mfa-enrollment redirect)', async () => {
    mockLogin.mockResolvedValueOnce({ ...BASE_RESPONSE, mfa_enrollment_required: true });
    renderPage();
    await submitLogin();

    await waitFor(() => expect(mockReplace).toHaveBeenCalledWith('/dashboard'));
  });
});
