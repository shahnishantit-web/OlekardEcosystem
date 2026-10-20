/**
 * T185 — WCAG AA accessibility tests for Admin auth and configuration pages.
 * Constitution Principle 11: All public-facing UI MUST meet WCAG 2.1 AA.
 */
import React from 'react';
import { render } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';

expect.extend(toHaveNoViolations);

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('@/src/modules/auth/api/auth', () => ({
  loginWithPassword: jest.fn(),
  verifyMfa: jest.fn(),
  initiateStepUp: jest.fn(),
  verifyStepUp: jest.fn(),
}));

jest.mock('@/src/modules/auth/api/sessions', () => ({
  listAdminSessions: jest.fn(),
  revokeAdminSession: jest.fn(),
}));

jest.mock('@/src/modules/configuration/api/security-config', () => ({
  listSecurityConfig: jest.fn(),
  updateSecurityConfigKey: jest.fn(),
  CONFIG_SECTIONS: {
    Authentication: ['auth.pin.length'],
    OTP: ['auth.otp.ttl'],
  },
}));

import { AdminLoginPage } from '@/src/modules/auth/pages/login';
import { AdminSessionsPage } from '@/src/modules/auth/pages/sessions';
import { AdminSecurityConfigPage } from '@/src/modules/configuration/pages/security-config';
import { StepUpOverlay } from '@/components/auth/StepUpOverlay';
import { AuthProvider } from '@/lib/auth/context';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { ConfigEntry } from '@/src/modules/configuration/api/security-config';
import * as configApi from '@/src/modules/configuration/api/security-config';
import * as sessionsApi from '@/src/modules/auth/api/sessions';
import * as authApi from '@/src/modules/auth/api/auth';

expect.extend(toHaveNoViolations);

const mockListSecurityConfig = configApi.listSecurityConfig as jest.MockedFunction<typeof configApi.listSecurityConfig>;
const mockListSessions = sessionsApi.listAdminSessions as jest.MockedFunction<typeof sessionsApi.listAdminSessions>;
const mockInitiateStepUp = authApi.initiateStepUp as jest.MockedFunction<typeof authApi.initiateStepUp>;

const STUB_CONFIG_ENTRIES: ConfigEntry[] = [
  {
    key: 'auth.pin.length',
    value: '6',
    default_value: '6',
    description: 'PIN length (minimum 6)',
    data_type: 'integer',
    min: 6,
    max: 12,
    last_updated_at: '2026-08-21T09:00:00Z',
    last_updated_by: 'system',
    is_sensitive: false,
  },
  {
    key: 'auth.otp.ttl',
    value: '300',
    default_value: '300',
    description: 'OTP validity in seconds',
    data_type: 'integer',
    min: 60,
    max: 3600,
    last_updated_at: '2026-08-21T09:00:00Z',
    last_updated_by: 'system',
    is_sensitive: false,
  },
];

const STUB_SESSIONS = [
  {
    session_id: 'ses-001',
    user_id: 'usr-001',
    user_email: 'customer@example.com',
    device_name: 'iPhone 15',
    platform: 'ios',
    actor_type: 'USER',
    status: 'active',
    last_activity_at: '2026-08-21T09:45:00Z',
    expires_at: '2026-08-21T10:15:00Z',
    is_current: false,
  },
];

function withAuth(children: React.ReactNode) {
  return <AuthProvider>{children}</AuthProvider>;
}

function withAuthenticatedContext(children: React.ReactNode) {
  return (
    <AuthProvider
      initialPhase={{
        kind: 'authenticated',
        user: { id: 'admin-1', email: 'admin@example.com', name: 'Admin' },
        sessionId: 'ses-admin-1',
      }}
      initialAccessToken="eyJtest.stub.token"
    >
      {children}
    </AuthProvider>
  );
}

// ── Login page ────────────────────────────────────────────────────────────────

describe('WCAG AA — AdminLoginPage (T185)', () => {
  it('credentials phase has no violations', async () => {
    const { container } = render(withAuth(<AdminLoginPage />));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('credentials phase with error has no violations', async () => {
    const { container } = render(
      <AuthProvider initialPhase={{ kind: 'unauthenticated', error: 'Invalid credentials.' }}>
        <AdminLoginPage />
      </AuthProvider>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('MFA phase has no violations', async () => {
    const { container } = render(
      <AuthProvider
        initialPhase={{
          kind: 'mfa_pending',
          mfaToken: 'opaque-mfa-token',
          methods: ['totp', 'fido2'],
        }}
      >
        <AdminLoginPage />
      </AuthProvider>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});

// ── Sessions page ─────────────────────────────────────────────────────────────

describe('WCAG AA — AdminSessionsPage (T185)', () => {
  beforeEach(() => jest.clearAllMocks());

  it('loading state has no violations', async () => {
    // Never resolves — keeps loading spinner visible
    mockListSessions.mockReturnValueOnce(new Promise(() => {}));
    const { container } = render(withAuthenticatedContext(<AdminSessionsPage />));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('empty state has no violations', async () => {
    mockListSessions.mockResolvedValueOnce({
      sessions: [],
      pagination: { page: 1, per_page: 20, total: 0 },
    });
    const { container } = render(withAuthenticatedContext(<AdminSessionsPage />));
    // Wait for empty state to render
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('sessions table has no violations', async () => {
    mockListSessions.mockResolvedValueOnce({
      sessions: STUB_SESSIONS,
      pagination: { page: 1, per_page: 20, total: 1 },
    });
    const { container } = render(withAuthenticatedContext(<AdminSessionsPage />));
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('unauthorized state has no violations', async () => {
    const { container } = render(withAuth(<AdminSessionsPage />));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('error state has no violations', async () => {
    mockListSessions.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'INTERNAL_ERROR', message: 'Server error.', details: [] },
      correlation_id: 'corr-1',
      meta: null,
    });
    const { container } = render(withAuthenticatedContext(<AdminSessionsPage />));
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });
});

// ── Security config page ──────────────────────────────────────────────────────

describe('WCAG AA — AdminSecurityConfigPage (T185)', () => {
  beforeEach(() => jest.clearAllMocks());

  it('loading state has no violations', async () => {
    mockListSecurityConfig.mockReturnValueOnce(new Promise(() => {}));
    const { container } = render(withAuthenticatedContext(<AdminSecurityConfigPage />));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('config table (read view) has no violations', async () => {
    mockListSecurityConfig.mockResolvedValueOnce({
      configuration: STUB_CONFIG_ENTRIES,
      total: STUB_CONFIG_ENTRIES.length,
    });
    const { container } = render(withAuthenticatedContext(<AdminSecurityConfigPage />));
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('unauthorized state has no violations', async () => {
    const { container } = render(withAuth(<AdminSecurityConfigPage />));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('error state has no violations', async () => {
    mockListSecurityConfig.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'INTERNAL_ERROR', message: 'Failed to load.', details: [] },
      correlation_id: 'corr-2',
      meta: null,
    });
    const { container } = render(withAuthenticatedContext(<AdminSecurityConfigPage />));
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });
});

// ── Step-up overlay ───────────────────────────────────────────────────────────

describe('WCAG AA — StepUpOverlay (T185)', () => {
  const stubClient = {} as AuthenticatedApiClient;

  it('overlay (loading phase) has no violations', async () => {
    // Keep the overlay in loading state by making initiateStepUp never resolve
    mockInitiateStepUp.mockReturnValueOnce(new Promise(() => {}));
    const { container } = render(
      <StepUpOverlay
        open
        scope="configuration:update"
        client={stubClient}
        onSuccess={jest.fn()}
        onClose={jest.fn()}
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('overlay (verify phase) has no violations', async () => {
    mockInitiateStepUp.mockResolvedValueOnce({
      step_up_required: true,
      step_up_token: 'stu-abc',
      methods: ['totp', 'fido2'],
      expires_at: '2026-08-25T10:05:00Z',
    });
    const { container } = render(
      <StepUpOverlay
        open
        scope="configuration:update"
        client={stubClient}
        onSuccess={jest.fn()}
        onClose={jest.fn()}
      />,
    );
    // Wait for verify phase to render
    await new Promise((r) => setTimeout(r, 0));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('overlay (closed) has no violations', async () => {
    const { container } = render(
      <StepUpOverlay
        open={false}
        scope="configuration:update"
        client={stubClient}
        onSuccess={jest.fn()}
        onClose={jest.fn()}
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
