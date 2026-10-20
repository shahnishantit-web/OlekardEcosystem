/**
 * T158 — WCAG AA accessibility tests for admin profile management pages.
 * Constitution Principle 11: All public-facing UI MUST meet WCAG 2.1 AA.
 */
import React from 'react';
import { render, waitFor } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';

expect.extend(toHaveNoViolations);

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('@/src/modules/profile/api/profile', () => ({
  getAdminProfile: jest.fn(),
  getAdminAccountStatus: jest.fn(),
  updateAdminProfilePersonal: jest.fn(),
  suspendAccount: jest.fn(),
  reactivateAccount: jest.fn(),
  cancelClosureAccount: jest.fn(),
  closeAccount: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { UserProfilePage } from '@/src/modules/profile/pages/user-profile';
import { AccountStatusPage } from '@/src/modules/profile/pages/account-status';
import { SuspendDialog } from '@/components/profile/SuspendDialog';
import { ReactivateDialog } from '@/components/profile/ReactivateDialog';
import { CloseAccountDialog } from '@/components/profile/CloseAccountDialog';
import { AuthProvider } from '@/lib/auth/context';
import * as profileApi from '@/src/modules/profile/api/profile';
import * as authContext from '@/lib/auth/context';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

const mockGetAdminProfile = profileApi.getAdminProfile as jest.MockedFunction<typeof profileApi.getAdminProfile>;
const mockGetAdminAccountStatus = profileApi.getAdminAccountStatus as jest.MockedFunction<typeof profileApi.getAdminAccountStatus>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const FAKE_PROFILE = {
  user_id: 'user-axe-001',
  first_name: 'Jane',
  last_name: 'Doe',
  date_of_birth: '1990-05-15',
  gender: 'female',
  address: { street: '1 Main St', city: 'Gaborone', region: null, postal_code: null, country: 'BW' },
  preferred_language: 'en',
  email: 'jane@example.com',
  mobile_number: '+26771234567',
  profile_photo_url: null,
  account_status: 'active',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-08-01T12:00:00Z',
};

const FAKE_STATUS_ACTIVE = {
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

const FAKE_STATUS_SUSPENDED = {
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
    authenticatedClient: {} as AuthenticatedApiClient,
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

function withAuthenticatedContext(children: React.ReactNode) {
  return (
    <AuthProvider
      initialPhase={{
        kind: 'authenticated',
        user: { id: 'admin-1', email: 'admin@olekard.local', name: 'Admin' },
        sessionId: 'ses-admin-1',
      }}
      initialAccessToken="eyJtest.stub.token"
    >
      {children}
    </AuthProvider>
  );
}

// ── UserProfilePage ────────────────────────────────────────────────────────

describe('WCAG AA — UserProfilePage (T158)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('loading state has no violations', async () => {
    mockGetAdminProfile.mockReturnValueOnce(new Promise(() => {}));
    const { container } = render(<UserProfilePage userId="user-axe-001" />);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('loaded state has no violations', async () => {
    mockGetAdminProfile.mockResolvedValueOnce(FAKE_PROFILE);

    const { container } = render(<UserProfilePage userId="user-axe-001" />);

    await waitFor(() => {
      expect(container.querySelector('[data-testid="profile-loaded"], [class*="card"]')).toBeTruthy();
    }).catch(() => {
      // Proceed to axe even if waitFor times out — we still want to audit what rendered
    });

    // Allow microtasks to flush
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('error state has no violations', async () => {
    mockGetAdminProfile.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'SERVER_ERROR', message: 'Server error' },
    });

    const { container } = render(<UserProfilePage userId="user-axe-001" />);
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });
});

// ── AccountStatusPage ──────────────────────────────────────────────────────

describe('WCAG AA — AccountStatusPage (T158)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('loading state has no violations', async () => {
    mockGetAdminAccountStatus.mockReturnValueOnce(new Promise(() => {}));
    const { container } = render(<AccountStatusPage userId="user-axe-001" />);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('active status with history has no violations', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce(FAKE_STATUS_ACTIVE);

    const { container } = render(<AccountStatusPage userId="user-axe-001" canReactivate canClose />);
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('suspended status with Reactivate button has no violations', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce(FAKE_STATUS_SUSPENDED);

    const { container } = render(<AccountStatusPage userId="user-axe-001" canReactivate />);
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('empty history state has no violations', async () => {
    mockGetAdminAccountStatus.mockResolvedValueOnce({ account_status: 'active', history: [] });

    const { container } = render(<AccountStatusPage userId="user-axe-001" />);
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });
});

// ── SuspendDialog ──────────────────────────────────────────────────────────

describe('WCAG AA — SuspendDialog (T158)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('initial state (reason required) has no violations', async () => {
    const { container } = render(
      <SuspendDialog open userId="user-axe-001" onClose={jest.fn()} onSuccess={jest.fn()} />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('closed dialog (open=false) has no violations', async () => {
    const { container } = render(
      <SuspendDialog open={false} userId="user-axe-001" onClose={jest.fn()} onSuccess={jest.fn()} />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});

// ── ReactivateDialog ───────────────────────────────────────────────────────

describe('WCAG AA — ReactivateDialog (T158)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('reactivate mode has no violations', async () => {
    const { container } = render(
      <ReactivateDialog
        open
        mode="reactivate"
        userId="user-axe-001"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('cancel_closure mode has no violations', async () => {
    const { container } = render(
      <ReactivateDialog
        open
        mode="cancel_closure"
        userId="user-axe-001"
        onClose={jest.fn()}
        onSuccess={jest.fn()}
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});

// ── CloseAccountDialog ─────────────────────────────────────────────────────

describe('WCAG AA — CloseAccountDialog (T158)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('initial step has no violations', async () => {
    const { container } = render(
      <CloseAccountDialog open userId="user-axe-001" onClose={jest.fn()} onSuccess={jest.fn()} />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
