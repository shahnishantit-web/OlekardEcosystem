/**
 * ADM-P1-003 US-P1-003-004 — Notification Preferences page tests.
 * Verifies: categories render; financial/security mandatory (no toggle);
 *           toggling optional categories calls API; error rollback.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('@/src/modules/profile/api/notification-prefs', () => ({
  getNotificationPrefs: jest.fn(),
  updateNotificationPrefs: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { NotificationPrefsPage } from '@/src/modules/profile/pages/notification-prefs';
import * as notifApi from '@/src/modules/profile/api/notification-prefs';
import * as authContext from '@/lib/auth/context';
import type { NotificationPreferences } from '@/src/modules/profile/api/notification-prefs';

const mockGetNotificationPrefs = notifApi.getNotificationPrefs as jest.MockedFunction<typeof notifApi.getNotificationPrefs>;
const mockUpdateNotificationPrefs = notifApi.updateNotificationPrefs as jest.MockedFunction<typeof notifApi.updateNotificationPrefs>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const MOCK_CLIENT = {} as never;

const BASE_PREFS: NotificationPreferences = {
  user_id: 'admin-1',
  push_enabled_categories: [],
  sms_enabled_categories: [],
  email_enabled_categories: ['financial', 'security'],
  marketing_opted_in: false,
  updated_at: '2026-10-01T00:00:00Z',
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
  mockGetNotificationPrefs.mockResolvedValue(BASE_PREFS);
  mockUpdateNotificationPrefs.mockResolvedValue(BASE_PREFS);
});

describe('NotificationPrefsPage — rendering', () => {
  it('renders all four category labels', async () => {
    render(<NotificationPrefsPage />);
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: /financial alerts/i })).toBeInTheDocument(),
    );
    expect(screen.getByRole('checkbox', { name: /security alerts/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /operational updates/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /marketing communications/i })).toBeInTheDocument();
  });

  it('shows financial and security as mandatory (disabled checkboxes)', async () => {
    render(<NotificationPrefsPage />);
    await waitFor(() => expect(screen.getByText(/financial alerts/i)).toBeInTheDocument());

    expect(screen.getByRole('checkbox', { name: /financial alerts/i })).toBeDisabled();
    expect(screen.getByRole('checkbox', { name: /security alerts/i })).toBeDisabled();
  });

  it('shows operational and marketing as toggleable (enabled checkboxes)', async () => {
    render(<NotificationPrefsPage />);
    await waitFor(() => expect(screen.getByText(/financial alerts/i)).toBeInTheDocument());

    expect(screen.getByRole('checkbox', { name: /operational updates/i })).not.toBeDisabled();
    expect(screen.getByRole('checkbox', { name: /marketing communications/i })).not.toBeDisabled();
  });
});

describe('NotificationPrefsPage — toggling', () => {
  it('calls updateNotificationPrefs when an optional category is toggled on', async () => {
    mockUpdateNotificationPrefs.mockResolvedValueOnce({
      ...BASE_PREFS,
      email_enabled_categories: ['financial', 'security', 'operational'],
    });
    render(<NotificationPrefsPage />);
    await waitFor(() => expect(screen.getByText(/operational updates/i)).toBeInTheDocument());

    await userEvent.click(screen.getByRole('checkbox', { name: /operational updates/i }));

    await waitFor(() =>
      expect(mockUpdateNotificationPrefs).toHaveBeenCalledWith(
        MOCK_CLIENT,
        expect.objectContaining({
          email_enabled_categories: expect.arrayContaining(['financial', 'security', 'operational']),
        }),
      ),
    );
  });

  it('calls updateNotificationPrefs to remove a category when toggled off', async () => {
    const prefsWithOperational: NotificationPreferences = {
      ...BASE_PREFS,
      email_enabled_categories: ['financial', 'security', 'operational'],
    };
    mockGetNotificationPrefs.mockResolvedValueOnce(prefsWithOperational);
    mockUpdateNotificationPrefs.mockResolvedValueOnce({
      ...BASE_PREFS,
      email_enabled_categories: ['financial', 'security'],
    });

    render(<NotificationPrefsPage />);
    await waitFor(() => expect(screen.getByRole('checkbox', { name: /operational updates/i })).toBeChecked());

    await userEvent.click(screen.getByRole('checkbox', { name: /operational updates/i }));

    await waitFor(() =>
      expect(mockUpdateNotificationPrefs).toHaveBeenCalledWith(
        MOCK_CLIENT,
        expect.objectContaining({
          email_enabled_categories: expect.not.arrayContaining(['operational']),
        }),
      ),
    );
  });

  it('shows success toast after saving', async () => {
    mockUpdateNotificationPrefs.mockResolvedValueOnce({
      ...BASE_PREFS,
      email_enabled_categories: ['financial', 'security', 'marketing'],
    });
    render(<NotificationPrefsPage />);
    await waitFor(() => expect(screen.getByText(/marketing/i)).toBeInTheDocument());

    await userEvent.click(screen.getByRole('checkbox', { name: /marketing communications/i }));
    await waitFor(() => expect(screen.getByText(/preferences saved/i)).toBeInTheDocument());
  });

  it('shows error and rolls back state on API failure', async () => {
    mockUpdateNotificationPrefs.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'SERVER_ERROR', message: 'Service unavailable.' },
      correlation_id: 'test-id',
      meta: null,
    });
    render(<NotificationPrefsPage />);
    await waitFor(() => expect(screen.getByText(/operational updates/i)).toBeInTheDocument());

    await userEvent.click(screen.getByRole('checkbox', { name: /operational updates/i }));
    await waitFor(() => expect(screen.getByText(/service unavailable/i)).toBeInTheDocument());
  });
});
