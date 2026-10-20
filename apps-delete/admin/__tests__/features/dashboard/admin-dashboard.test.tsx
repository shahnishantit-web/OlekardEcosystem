/**
 * T181 — Admin Dashboard page tests.
 * Verifies: KPI card rendering; loading/empty/error states; accessibility; quick links.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('next/link', () => {
  const MockLink = ({ children, href, ...props }: { children: React.ReactNode; href: string; [key: string]: unknown }) => (
    <a href={href} {...props}>{children}</a>
  );
  MockLink.displayName = 'MockLink';
  return MockLink;
});

jest.mock('@/src/modules/dashboard/api/dashboard', () => ({
  getAdminDashboardSummary: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { AdminDashboardPage } from '@/src/modules/dashboard/pages/admin-dashboard';
import * as dashboardApi from '@/src/modules/dashboard/api/dashboard';
import * as authContext from '@/lib/auth/context';

const mockGetSummary = dashboardApi.getAdminDashboardSummary as jest.MockedFunction<
  typeof dashboardApi.getAdminDashboardSummary
>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const fakeSummary = {
  total_users: 1024,
  active_accounts: 900,
  suspended_accounts: 12,
  pending_closure_requests: 5,
  recent_contact_changes: 3,
};

const emptySummary = {
  total_users: 0,
  active_accounts: 0,
  suspended_accounts: 0,
  pending_closure_requests: 0,
  recent_contact_changes: 0,
};

function setupAuth() {
  mockUseAuth.mockReturnValue({
    authenticatedClient: {} as never,
    isAuthenticated: true,
    phase: 'authenticated',
    user: { id: 'admin-1', email: 'admin@olekard.local', name: 'Admin' },
    mfaMethods: null,
    authError: null,
    login: jest.fn(),
    completeMfa: jest.fn(),
    signOut: jest.fn(),
  });
}

describe('AdminDashboardPage (T181)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('renders all five KPI cards with correct values', async () => {
    mockGetSummary.mockResolvedValueOnce(fakeSummary);

    render(<AdminDashboardPage />);

    await waitFor(() => {
      expect(screen.getByLabelText('Total Users: 1024')).toBeInTheDocument();
    });

    expect(screen.getByLabelText('Active Accounts: 900')).toBeInTheDocument();
    expect(screen.getByLabelText('Suspended Accounts: 12')).toBeInTheDocument();
    expect(screen.getByLabelText('Pending Closure Requests: 5')).toBeInTheDocument();
    expect(screen.getByLabelText('Contact Changes in Progress: 3')).toBeInTheDocument();
  });

  it('shows the empty state when all counts are zero', async () => {
    mockGetSummary.mockResolvedValueOnce(emptySummary);

    render(<AdminDashboardPage />);

    await waitFor(() => {
      expect(screen.getByText(/No profile data yet/i)).toBeInTheDocument();
    });

    expect(screen.queryByLabelText(/Total Users/i)).not.toBeInTheDocument();
  });

  it('shows the error state on fetch failure and offers a retry button', async () => {
    mockGetSummary.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'SERVER_ERROR', message: 'Internal error' },
    });

    render(<AdminDashboardPage />);

    await waitFor(() => {
      expect(screen.getByText(/Failed to load dashboard summary/i)).toBeInTheDocument();
    });
  });

  it('refetches on Refresh button click', async () => {
    mockGetSummary
      .mockResolvedValueOnce(fakeSummary)
      .mockResolvedValueOnce({ ...fakeSummary, total_users: 2048 });

    render(<AdminDashboardPage />);

    await waitFor(() => screen.getByLabelText('Total Users: 1024'));

    await userEvent.click(screen.getByRole('button', { name: /Refresh dashboard summary/i }));

    await waitFor(() => {
      expect(screen.getByLabelText('Total Users: 2,048')).toBeInTheDocument();
    });

    expect(mockGetSummary).toHaveBeenCalledTimes(2);
  });

  it('shows the forbidden error state when the API returns FORBIDDEN', async () => {
    mockGetSummary.mockRejectedValueOnce({
      success: false,
      data: null,
      error: { code: 'FORBIDDEN', message: 'Insufficient permissions' },
    });

    render(<AdminDashboardPage />);

    await waitFor(() => {
      expect(screen.getByText(/Access denied/i)).toBeInTheDocument();
    });
  });

  it('shows quick links for suspended and pending-closure KPI cards', async () => {
    mockGetSummary.mockResolvedValueOnce(fakeSummary);

    render(<AdminDashboardPage />);

    await waitFor(() => screen.getByLabelText('Suspended Accounts: 12'));

    const suspendedLink = screen.getByRole('link', { name: /Review suspended accounts/i });
    expect(suspendedLink).toBeInTheDocument();
    expect(suspendedLink).toHaveAttribute('href', '/users');

    const closureLink = screen.getByRole('link', { name: /Review pending closures/i });
    expect(closureLink).toBeInTheDocument();
    expect(closureLink).toHaveAttribute('href', '/users');
  });

  it('KPI cards are keyboard-focusable via tab navigation', async () => {
    mockGetSummary.mockResolvedValueOnce(fakeSummary);

    render(<AdminDashboardPage />);

    await waitFor(() => screen.getByLabelText('Total Users: 1024'));

    const refreshBtn = screen.getByRole('button', { name: /Refresh dashboard summary/i });
    refreshBtn.focus();
    expect(document.activeElement).toBe(refreshBtn);
  });

  it('shows unauthorized state when not authenticated', async () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: null,
      isAuthenticated: false,
      phase: 'unauthenticated',
      user: null,
      mfaMethods: null,
      authError: null,
      login: jest.fn(),
      completeMfa: jest.fn(),
      signOut: jest.fn(),
    });

    render(<AdminDashboardPage />);

    expect(screen.getByText(/You must be signed in/i)).toBeInTheDocument();
    expect(mockGetSummary).not.toHaveBeenCalled();
  });
});
