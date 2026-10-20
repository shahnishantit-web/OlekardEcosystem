/**
 * ADM-P1-003 US-P1-003-005 — Own Sessions page tests.
 * Verifies: session list rendering; revoke action; current session no-revoke;
 *           empty state; error state.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('@/src/modules/auth/api/sessions', () => ({
  listOwnSessions: jest.fn(),
  revokeOwnSession: jest.fn(),
  // Keep other exports so other tests that mock this module don't break
  listAdminSessions: jest.fn(),
  revokeAdminSession: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { OwnSessionsPage } from '@/src/modules/profile/pages/own-sessions';
import * as sessionsApi from '@/src/modules/auth/api/sessions';
import * as authContext from '@/lib/auth/context';
import type { OwnSessionItem } from '@/src/modules/auth/api/sessions';

const mockListOwnSessions = sessionsApi.listOwnSessions as jest.MockedFunction<typeof sessionsApi.listOwnSessions>;
const mockRevokeOwnSession = sessionsApi.revokeOwnSession as jest.MockedFunction<typeof sessionsApi.revokeOwnSession>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const MOCK_CLIENT = {} as never;
const NOW = '2026-10-13T10:00:00Z';

const SESSION_A: OwnSessionItem = {
  id: 'ses-aaa',
  device_id: 'device-aaa',
  actor_type: 'ADMIN',
  status: 'active',
  last_activity_at: NOW,
  expires_at: '2026-10-14T10:00:00Z',
  created_at: NOW,
};

const SESSION_B: OwnSessionItem = {
  id: 'ses-bbb',
  device_id: 'device-bbb',
  actor_type: 'ADMIN',
  status: 'active',
  last_activity_at: NOW,
  expires_at: '2026-10-14T10:00:00Z',
  created_at: NOW,
};

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
});

describe('OwnSessionsPage — rendering', () => {
  it('renders session list', async () => {
    mockListOwnSessions.mockResolvedValueOnce([SESSION_A, SESSION_B]);
    render(<OwnSessionsPage />);
    await waitFor(() => expect(screen.getByText('device-aaa')).toBeInTheDocument());
    expect(screen.getByText('device-bbb')).toBeInTheDocument();
  });

  it('shows empty state when no sessions', async () => {
    mockListOwnSessions.mockResolvedValueOnce([]);
    render(<OwnSessionsPage />);
    await waitFor(() =>
      expect(screen.getByText(/no active sessions found/i)).toBeInTheDocument(),
    );
  });

  it('shows error state when list fails', async () => {
    mockListOwnSessions.mockRejectedValueOnce(new Error('Network error'));
    render(<OwnSessionsPage />);
    await waitFor(() =>
      expect(screen.getByText(/failed to load active sessions/i)).toBeInTheDocument(),
    );
  });

  it('shows "This session" badge for the first session (no cookie set)', async () => {
    mockListOwnSessions.mockResolvedValueOnce([SESSION_A, SESSION_B]);
    render(<OwnSessionsPage />);
    await waitFor(() => expect(screen.getByText('This session')).toBeInTheDocument());
  });
});

describe('OwnSessionsPage — revocation', () => {
  it('renders Revoke button only for non-current sessions', async () => {
    mockListOwnSessions.mockResolvedValueOnce([SESSION_A, SESSION_B]);
    render(<OwnSessionsPage />);
    await waitFor(() => expect(screen.getByText('device-bbb')).toBeInTheDocument());

    // SESSION_A is marked as current (first session, no cookie), so only SESSION_B has Revoke
    const revokeButtons = screen.getAllByRole('button', { name: /revoke session from/i });
    expect(revokeButtons).toHaveLength(1);
  });

  it('calls revokeOwnSession with the correct session ID', async () => {
    mockListOwnSessions.mockResolvedValueOnce([SESSION_A, SESSION_B]);
    mockRevokeOwnSession.mockResolvedValueOnce(undefined);
    render(<OwnSessionsPage />);
    await waitFor(() => expect(screen.getByText('device-bbb')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /revoke session from device-bbb/i }));
    await waitFor(() =>
      expect(mockRevokeOwnSession).toHaveBeenCalledWith(MOCK_CLIENT, 'ses-bbb'),
    );
  });

  it('removes session from list after successful revocation', async () => {
    mockListOwnSessions.mockResolvedValueOnce([SESSION_A, SESSION_B]);
    mockRevokeOwnSession.mockResolvedValueOnce(undefined);
    render(<OwnSessionsPage />);
    await waitFor(() => expect(screen.getByText('device-bbb')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /revoke session from device-bbb/i }));
    await waitFor(() =>
      expect(screen.queryByText('device-bbb')).not.toBeInTheDocument(),
    );
  });

  it('shows success toast after revoking', async () => {
    mockListOwnSessions.mockResolvedValueOnce([SESSION_A, SESSION_B]);
    mockRevokeOwnSession.mockResolvedValueOnce(undefined);
    render(<OwnSessionsPage />);
    await waitFor(() => expect(screen.getByText('device-bbb')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /revoke session from device-bbb/i }));
    await waitFor(() => expect(screen.getByText(/session revoked/i)).toBeInTheDocument());
  });

  it('shows error message when revocation fails', async () => {
    mockListOwnSessions.mockResolvedValueOnce([SESSION_A, SESSION_B]);
    mockRevokeOwnSession.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'IDENTITY_011', message: 'Session not found or already revoked.' },
      correlation_id: 'test-id',
      meta: null,
    });
    render(<OwnSessionsPage />);
    await waitFor(() => expect(screen.getByText('device-bbb')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /revoke session from device-bbb/i }));
    await waitFor(() =>
      expect(screen.getByText(/session not found or already revoked/i)).toBeInTheDocument(),
    );
  });
});
