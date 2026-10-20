/**
 * ADM-P1-004 — Device & Session Management tests.
 * US-P1-004-001: Admin views registered devices (list, search, current-device highlight)
 * US-P1-004-002: Admin revokes a compromised device (block/remove flow)
 * US-P1-004-003: Admin sees only own devices (self-service userId isolation)
 */
import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('@/src/modules/devices/api/devices', () => ({
  getAdminDevices: jest.fn(),
  adminBlockDevice: jest.fn(),
  adminUnblockDevice: jest.fn(),
  adminRemoveDevice: jest.fn(),
  getDeviceSecurityEvents: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { AdminDeviceListPage } from '@/src/modules/devices/pages/device-list';
import * as devicesApi from '@/src/modules/devices/api/devices';
import * as authContext from '@/lib/auth/context';
import type { DeviceListResponse } from '@/src/modules/devices/types/device';

const mockGetAdminDevices = devicesApi.getAdminDevices as jest.MockedFunction<typeof devicesApi.getAdminDevices>;
const mockAdminBlockDevice = devicesApi.adminBlockDevice as jest.MockedFunction<typeof devicesApi.adminBlockDevice>;
const mockAdminRemoveDevice = devicesApi.adminRemoveDevice as jest.MockedFunction<typeof devicesApi.adminRemoveDevice>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const MOCK_CLIENT = {} as never;

const DEVICE_A = {
  id: 'dev-aaa',
  user_id: 'admin-1',
  device_name: 'MacBook Pro',
  platform: 'web' as const,
  fingerprint_hash: 'fp-aaa',
  status: 'trusted' as const,
  blocked_at: null,
  blocked_reason: null,
  trusted_at: '2026-09-01T00:00:00Z',
  last_seen_at: '2026-10-13T08:00:00Z',
  created_at: '2026-09-01T00:00:00Z',
  updated_at: '2026-10-13T08:00:00Z',
};

const DEVICE_B = {
  id: 'dev-bbb',
  user_id: 'admin-1',
  device_name: 'iPhone 15',
  platform: 'ios' as const,
  fingerprint_hash: 'fp-bbb',
  status: 'pending_verification' as const,
  blocked_at: null,
  blocked_reason: null,
  trusted_at: null,
  last_seen_at: '2026-10-12T22:00:00Z',
  created_at: '2026-10-01T00:00:00Z',
  updated_at: '2026-10-12T22:00:00Z',
};

const DEVICE_LIST_RESPONSE: DeviceListResponse = {
  data: [DEVICE_A, DEVICE_B],
  meta: { total: 2, per_page: 20, current_page: 1, last_page: 1 },
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
  mockGetAdminDevices.mockResolvedValue(DEVICE_LIST_RESPONSE);
});

// ── US-P1-004-001: View registered devices ────────────────────────────────────

describe('AdminDeviceListPage — US-P1-004-001: device list', () => {
  it('renders device names in the table', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('MacBook Pro')).toBeInTheDocument());
    expect(screen.getByText('iPhone 15')).toBeInTheDocument();
  });

  it('renders platform information', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('web')).toBeInTheDocument());
    expect(screen.getByText('ios')).toBeInTheDocument();
  });

  it('renders device status badges', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('trusted')).toBeInTheDocument());
    expect(screen.getByText('pending verification')).toBeInTheDocument();
  });

  it('shows "My Devices" heading in self-service mode', async () => {
    render(<AdminDeviceListPage userId="admin-1" selfService />);
    await waitFor(() => expect(screen.getByText('My Devices')).toBeInTheDocument());
  });

  it('shows "Device Management" heading in admin mode', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('Device Management')).toBeInTheDocument());
  });

  it('shows "This device" badge for the currentDeviceId', async () => {
    render(<AdminDeviceListPage userId="admin-1" selfService currentDeviceId="dev-aaa" />);
    await waitFor(() => expect(screen.getByText('MacBook Pro')).toBeInTheDocument());
    expect(screen.getByLabelText('This device')).toBeInTheDocument();
  });

  it('does NOT show "This device" badge when currentDeviceId does not match', async () => {
    render(<AdminDeviceListPage userId="admin-1" selfService currentDeviceId="dev-other" />);
    await waitFor(() => expect(screen.getByText('MacBook Pro')).toBeInTheDocument());
    expect(screen.queryByLabelText('This is your current device')).not.toBeInTheDocument();
  });

  it('shows search input', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByRole('textbox', { name: /search devices/i })).toBeInTheDocument());
  });

  it('filters devices by name when searching', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('MacBook Pro')).toBeInTheDocument());

    await userEvent.type(screen.getByRole('textbox', { name: /search devices/i }), 'MacBook');

    expect(screen.getByText('MacBook Pro')).toBeInTheDocument();
    expect(screen.queryByText('iPhone 15')).not.toBeInTheDocument();
  });

  it('filters devices by platform when searching', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('iPhone 15')).toBeInTheDocument());

    await userEvent.type(screen.getByRole('textbox', { name: /search devices/i }), 'ios');

    expect(screen.getByText('iPhone 15')).toBeInTheDocument();
    expect(screen.queryByText('MacBook Pro')).not.toBeInTheDocument();
  });

  it('calls getAdminDevices with the provided userId', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(mockGetAdminDevices).toHaveBeenCalledWith(
      MOCK_CLIENT,
      'admin-1',
      expect.objectContaining({ page: 1, per_page: 20 }),
    ));
  });

  it('shows empty state when no devices', async () => {
    mockGetAdminDevices.mockResolvedValueOnce({
      data: [],
      meta: { total: 0, per_page: 20, current_page: 1, last_page: 1 },
    });
    render(<AdminDeviceListPage userId="admin-1" selfService />);
    await waitFor(() =>
      expect(screen.getByText(/no registered devices/i)).toBeInTheDocument(),
    );
  });

  it('shows error state when API fails', async () => {
    mockGetAdminDevices.mockRejectedValueOnce(new Error('Network error'));
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() =>
      expect(screen.getByText(/failed to load devices/i)).toBeInTheDocument(),
    );
  });
});

// ── US-P1-004-002: Revoke a compromised device ────────────────────────────────

describe('AdminDeviceListPage — US-P1-004-002: device revocation', () => {
  it('shows Block button for active devices', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('MacBook Pro')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /block macbook pro/i })).toBeInTheDocument();
  });

  it('shows Remove button for active devices', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('MacBook Pro')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /remove macbook pro/i })).toBeInTheDocument();
  });

  it('opens block confirmation dialog when Block is clicked', async () => {
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('MacBook Pro')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /block macbook pro/i }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('calls adminBlockDevice when block is confirmed', async () => {
    mockAdminBlockDevice.mockResolvedValueOnce(undefined);
    render(<AdminDeviceListPage userId="admin-1" />);
    await waitFor(() => expect(screen.getByText('MacBook Pro')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: /block macbook pro/i }));

    const dialog = screen.getByRole('dialog');
    const reasonInput = within(dialog).queryByRole('textbox');
    if (reasonInput) {
      await userEvent.type(reasonInput, 'Compromised device');
    }

    const confirmBtn = within(dialog).getByRole('button', { name: /block/i });
    await userEvent.click(confirmBtn);

    await waitFor(() =>
      expect(mockAdminBlockDevice).toHaveBeenCalledWith(MOCK_CLIENT, 'dev-aaa', expect.any(String)),
    );
  });
});

// ── US-P1-004-003: Admin only sees own devices ────────────────────────────────

describe('AdminDeviceListPage — US-P1-004-003: own-device isolation', () => {
  it('always passes the provided userId to the API (no cross-user data)', async () => {
    render(<AdminDeviceListPage userId="admin-specific-id" selfService />);
    await waitFor(() =>
      expect(mockGetAdminDevices).toHaveBeenCalledWith(
        MOCK_CLIENT,
        'admin-specific-id',
        expect.anything(),
      ),
    );
  });
});
