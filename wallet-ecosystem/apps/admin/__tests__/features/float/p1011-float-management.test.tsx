/**
 * ADM-P1-011 — Agent Float Management
 * US-P1-011-001: Admin views agent float balances
 * US-P1-011-002: Admin tops up agent float (maker-checker)
 * US-P1-011-003: Admin adjusts float limit
 */
import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ back: jest.fn(), push: jest.fn(), replace: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ children, href }: { children: React.ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
}));

// ── API mocks ────────────────────────────────────────────────────────────────

const mockListFloat        = jest.fn();
const mockTopUpFloat       = jest.fn();
const mockUpdateFloatLimit = jest.fn();
const mockAdjustFloat      = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    listFloat:         mockListFloat,
    topUpFloat:        mockTopUpFloat,
    updateFloatLimit:  mockUpdateFloatLimit,
    adjustFloat:       mockAdjustFloat,
  })),
}));

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports ───────────────────────────────────────────────────────────────────

import { FloatManagementAdminPortalPage } from '@/src/modules/float/pages/float-management';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

// ── Fixtures ──────────────────────────────────────────────────────────────────

const FLOAT_HEALTHY = {
  agent_id: 'agt-001',
  full_name: 'Thabo Sithole',
  float_balance_minor_units: 450000,   // BWP 4,500.00
  float_currency: 'BWP',
  float_limit_minor_units: 1000000,    // BWP 10,000.00
  utilization_pct: 45.0,
  last_top_up_at: '2026-10-10T09:00:00Z',
};

const FLOAT_LOW = {
  agent_id: 'agt-002',
  full_name: 'Kefilwe Mokoena',
  float_balance_minor_units: 15000,    // BWP 150.00 — 3% of 5000
  float_currency: 'BWP',
  float_limit_minor_units: 500000,     // BWP 5,000.00
  utilization_pct: 3.0,
  last_top_up_at: '2026-10-05T14:00:00Z',
};

const FLOAT_HIGH = {
  agent_id: 'agt-003',
  full_name: 'Mpho Kgosi',
  float_balance_minor_units: 900000,   // BWP 9,000.00
  float_currency: 'BWP',
  float_limit_minor_units: 1000000,
  utilization_pct: 90.0,
  last_top_up_at: '2026-10-11T11:00:00Z',
};

function paginatedResponse(items: unknown[]) {
  return { data: { data: items, total: items.length, meta: { total: items.length, last_page: 1 } } };
}

// ── Setup ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks();
  mockUseAuth.mockReturnValue({ authenticatedClient: MOCK_CLIENT } as never);
});

// ─────────────────────────────────────────────────────────────────────────────
// US-P1-011-001: Admin views agent float balances
// ─────────────────────────────────────────────────────────────────────────────

describe('US-P1-011-001: Float balance list', () => {
  it('renders table with required columns', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(mockListFloat).toHaveBeenCalledTimes(1));
    const table = screen.getByRole('table', { name: /float balances/i });
    expect(table).toBeInTheDocument();
    const ths = screen.getAllByRole('columnheader');
    const headers = ths.map(th => th.textContent?.toLowerCase());
    expect(headers.some(h => h?.includes('agent'))).toBe(true);
    expect(headers.some(h => h?.includes('currency'))).toBe(true);
    expect(headers.some(h => h?.includes('float balance'))).toBe(true);
    expect(headers.some(h => h?.includes('float limit'))).toBe(true);
    expect(headers.some(h => h?.includes('last movement'))).toBe(true);
  });

  it('shows agent name as a link to agent detail', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    const link = screen.getByRole('link', { name: 'Thabo Sithole' });
    expect(link).toHaveAttribute('href', '/admin-portal/agents/agt-001');
  });

  it('displays float balance formatted correctly', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.getAllByText(/4,500\.00/).length).toBeGreaterThanOrEqual(1);
  });

  it('displays float limit', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.getAllByText(/10,000\.00/).length).toBeGreaterThanOrEqual(1);
  });

  it('shows utilization badge for each record', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.getByText('45.0%')).toBeInTheDocument();
  });

  it('highlights low-float agents (< 20%)', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_LOW]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Kefilwe Mokoena')).toBeInTheDocument());
    expect(screen.getByText('Low')).toBeInTheDocument();
  });

  it('does not show Low badge for healthy agent', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.queryByText('Low')).not.toBeInTheDocument();
  });

  it('shows last movement date', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.getByText('10/10/2026')).toBeInTheDocument();
  });

  it('shows dash for missing last movement', async () => {
    const noMovement = { ...FLOAT_HEALTHY, last_top_up_at: null };
    mockListFloat.mockResolvedValueOnce(paginatedResponse([noMovement]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('shows empty state when no records', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText(/no float records found/i)).toBeInTheDocument());
  });

  it('shows error state on fetch failure', async () => {
    mockListFloat.mockRejectedValueOnce(new Error('Network error'));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText(/failed to load float/i)).toBeInTheDocument());
  });

  it('renders search input', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(mockListFloat).toHaveBeenCalled());
    expect(screen.getByRole('searchbox', { name: /search agents/i })).toBeInTheDocument();
  });

  it('renders low-float filter checkbox', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(mockListFloat).toHaveBeenCalled());
    expect(screen.getByRole('checkbox', { name: /low-float/i })).toBeInTheDocument();
  });

  it('passes low_float param when filter is checked', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValue(paginatedResponse([]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(mockListFloat).toHaveBeenCalledTimes(1));
    const checkbox = screen.getByRole('checkbox', { name: /low-float/i });
    await user.click(checkbox);
    await waitFor(() => {
      const lastCall = mockListFloat.mock.calls.at(-1)?.[0] as Record<string, string> | undefined;
      expect(lastCall?.low_float).toBe('1');
    });
  });

  it('shows Top Up and Adjust Limit buttons per row', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /top up float for Thabo Sithole/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i })).toBeInTheDocument();
  });

  it('shows multiple agents in table', async () => {
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY, FLOAT_LOW, FLOAT_HIGH]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.getByText('Kefilwe Mokoena')).toBeInTheDocument();
    expect(screen.getByText('Mpho Kgosi')).toBeInTheDocument();
  });

  it('calculates utilization when utilization_pct absent', async () => {
    const noUtilPct = { ...FLOAT_HEALTHY, utilization_pct: null };
    mockListFloat.mockResolvedValueOnce(paginatedResponse([noUtilPct]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    // 450000 / 1000000 * 100 = 45.0%
    expect(screen.getByText('45.0%')).toBeInTheDocument();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// US-P1-011-002: Admin tops up agent float
// ─────────────────────────────────────────────────────────────────────────────

describe('US-P1-011-002: Float top-up (maker-checker)', () => {
  it('opens top-up dialog when Top Up button clicked', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText(/top up float/i)).toBeInTheDocument();
  });

  it('shows agent name in top-up dialog', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('Thabo Sithole')).toBeInTheDocument();
  });

  it('shows maker-checker note in top-up dialog', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/second approver/i)).toBeInTheDocument();
  });

  it('requires amount and reason before submit is enabled', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    // Both fields empty → button disabled
    expect(within(dialog).getByRole('button', { name: /submit for approval/i })).toBeDisabled();
  });

  it('calls topUpFloat with correct params and shows approval ID', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValue(paginatedResponse([FLOAT_HEALTHY]));
    mockTopUpFloat.mockResolvedValueOnce({ data: { approval_id: 'PA-FLOAT-001', status: 'pending' } });
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(/amount/i), '500');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Monthly float replenishment');
    await user.click(within(dialog).getByRole('button', { name: /submit for approval/i }));
    await waitFor(() => {
      expect(mockTopUpFloat).toHaveBeenCalledWith(
        'agt-001',
        50000,        // 500 * 100
        'BWP',
        'Monthly float replenishment',
        expect.any(String),
      );
      expect(screen.getByRole('status')).toHaveTextContent(/PA-FLOAT-001/);
    });
  });

  it('shows ADMINAPP_FLOAT_001 error when limit exceeded', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    const err = Object.assign(new Error('Limit exceeded'), { code: 'ADMINAPP_FLOAT_001' });
    mockTopUpFloat.mockRejectedValueOnce(err);
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(/amount/i), '99999');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Test');
    await user.click(within(dialog).getByRole('button', { name: /submit for approval/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/exceed.*float limit/i));
  });

  it('shows generic error on topUpFloat failure', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    mockTopUpFloat.mockRejectedValueOnce(new Error('Server error'));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(/amount/i), '100');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Test');
    await user.click(within(dialog).getByRole('button', { name: /submit for approval/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/failed to submit top-up/i));
  });

  it('closes top-up dialog on Cancel', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /cancel/i }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('uses idempotency key for topUpFloat', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValue(paginatedResponse([FLOAT_HEALTHY]));
    mockTopUpFloat.mockResolvedValueOnce({ data: { approval_id: 'PA-001', status: 'pending' } });
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /top up float for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(/amount/i), '200');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Test');
    await user.click(within(dialog).getByRole('button', { name: /submit for approval/i }));
    await waitFor(() => {
      expect(mockTopUpFloat).toHaveBeenCalledWith(
        expect.any(String),
        expect.any(Number),
        expect.any(String),
        expect.any(String),
        expect.stringMatching(/.+/), // non-empty idempotency key
      );
    });
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// US-P1-011-003: Admin adjusts float limit
// ─────────────────────────────────────────────────────────────────────────────

describe('US-P1-011-003: Float limit adjustment', () => {
  it('opens limit dialog when Adjust Limit clicked', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText(/adjust float limit/i)).toBeInTheDocument();
  });

  it('shows agent name and current limit in dialog', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('Thabo Sithole')).toBeInTheDocument();
    // Current limit: BWP 10,000.00
    expect(within(dialog).getByText(/10,000\.00/)).toBeInTheDocument();
  });

  it('pre-fills limit input with current limit', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    const input = within(dialog).getByLabelText(/new limit/i) as HTMLInputElement;
    expect(input.value).toBe('10000.00');
  });

  it('requires reason before Save Limit is enabled', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    // Limit field has value but reason is empty → disabled
    expect(within(dialog).getByRole('button', { name: /save limit/i })).toBeDisabled();
  });

  it('calls updateFloatLimit with new limit and reason', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValue(paginatedResponse([FLOAT_HEALTHY]));
    mockUpdateFloatLimit.mockResolvedValueOnce({
      data: { agent_id: 'agt-001', float_limit_minor_units: 2000000, updated_at: '2026-10-13T10:00:00Z' },
    });
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    const limitInput = within(dialog).getByLabelText(/new limit/i);
    await user.clear(limitInput);
    await user.type(limitInput, '20000');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Increased volume approval');
    await user.click(within(dialog).getByRole('button', { name: /save limit/i }));
    await waitFor(() => {
      expect(mockUpdateFloatLimit).toHaveBeenCalledWith(
        'agt-001',
        2000000,   // 20000 * 100
        'Increased volume approval',
        expect.any(String),
      );
      expect(screen.getByRole('status')).toHaveTextContent(/float limit updated/i);
    });
  });

  it('shows error on updateFloatLimit failure', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    mockUpdateFloatLimit.mockRejectedValueOnce(new Error('Server error'));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    const limitInput = within(dialog).getByLabelText(/new limit/i);
    await user.clear(limitInput);
    await user.type(limitInput, '15000');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Test reason');
    await user.click(within(dialog).getByRole('button', { name: /save limit/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/failed to update float limit/i));
  });

  it('closes limit dialog on Cancel', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValueOnce(paginatedResponse([FLOAT_HEALTHY]));
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /cancel/i }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('uses idempotency key for updateFloatLimit', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValue(paginatedResponse([FLOAT_HEALTHY]));
    mockUpdateFloatLimit.mockResolvedValueOnce({
      data: { agent_id: 'agt-001', float_limit_minor_units: 1500000, updated_at: '2026-10-13T10:00:00Z' },
    });
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    const limitInput = within(dialog).getByLabelText(/new limit/i);
    await user.clear(limitInput);
    await user.type(limitInput, '15000');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Audit approved');
    await user.click(within(dialog).getByRole('button', { name: /save limit/i }));
    await waitFor(() => {
      expect(mockUpdateFloatLimit).toHaveBeenCalledWith(
        expect.any(String),
        expect.any(Number),
        expect.any(String),
        expect.stringMatching(/.+/), // non-empty idempotency key
      );
    });
  });

  it('refreshes list after successful limit update', async () => {
    const user = userEvent.setup();
    mockListFloat.mockResolvedValue(paginatedResponse([FLOAT_HEALTHY]));
    mockUpdateFloatLimit.mockResolvedValueOnce({
      data: { agent_id: 'agt-001', float_limit_minor_units: 2000000, updated_at: '2026-10-13T10:00:00Z' },
    });
    render(<FloatManagementAdminPortalPage />);
    await waitFor(() => expect(mockListFloat).toHaveBeenCalledTimes(1));
    await user.click(screen.getByRole('button', { name: /adjust float limit for Thabo Sithole/i }));
    const dialog = screen.getByRole('dialog');
    const limitInput = within(dialog).getByLabelText(/new limit/i);
    await user.clear(limitInput);
    await user.type(limitInput, '20000');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Refresh test');
    await user.click(within(dialog).getByRole('button', { name: /save limit/i }));
    await waitFor(() => expect(mockListFloat).toHaveBeenCalledTimes(2));
  });
});
