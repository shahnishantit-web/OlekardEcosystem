/**
 * ADM-P1-010 — Agent Management
 * US-P1-010-001: Admin views and searches agents
 * US-P1-010-002: Admin approves/rejects an agent application
 * US-P1-010-003: Admin suspends/reactivates an agent
 * US-P1-010-004: Admin views agent performance metrics
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

const mockListAgents        = jest.fn();
const mockGetAgent          = jest.fn();
const mockApproveAgent      = jest.fn();
const mockRejectAgent       = jest.fn();
const mockUpdateAgentStatus = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    listAgents:         mockListAgents,
    getAgent:           mockGetAgent,
    approveAgent:       mockApproveAgent,
    rejectAgent:        mockRejectAgent,
    updateAgentStatus:  mockUpdateAgentStatus,
  })),
}));

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports ───────────────────────────────────────────────────────────────────

import { AgentManagementPage } from '@/src/modules/agents/pages/agent-management';
import { AgentDetailPage }     from '@/src/modules/agents/pages/agent-detail';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

// ── Fixtures ──────────────────────────────────────────────────────────────────

const AGENT_PENDING = {
  id: 'agt-001',
  agent_id: 'agt-001',
  user_id: 'usr-001',
  full_name: 'Thabo Sithole',
  phone_number: '+267 74 555 001',
  business_name: 'Sithole General Store',
  status: 'pending_approval',
  agent_status: 'pending_approval',
  float_balance_minor_units: 0,
  float_limit_minor_units: 5000000,
  float_currency: 'BWP',
  commission_rate: 0.015,
  total_cash_in_today_minor_units: 0,
  total_cash_out_today_minor_units: 0,
  location: 'Gaborone, Plot 12345',
  approved_at: null,
  approved_by: null,
  created_at: '2026-09-20T08:00:00Z',
};

const AGENT_ACTIVE = {
  id: 'agt-002',
  agent_id: 'agt-002',
  user_id: 'usr-002',
  full_name: 'Kefilwe Mokoena',
  phone_number: '+267 75 555 002',
  business_name: 'Mokoena Traders',
  status: 'active',
  agent_status: 'active',
  float_balance_minor_units: 500000,
  float_limit_minor_units: 5000000,
  float_currency: 'BWP',
  commission_rate: 0.02,
  total_cash_in_today_minor_units: 120000,
  total_cash_out_today_minor_units: 85000,
  location: 'Francistown, Block 5',
  approved_at: '2026-09-25T10:00:00Z',
  approved_by: 'Admin User',
  created_at: '2026-09-20T08:00:00Z',
};

const AGENT_SUSPENDED = {
  id: 'agt-003',
  agent_id: 'agt-003',
  user_id: 'usr-003',
  full_name: 'Mpho Kgosi',
  phone_number: '+267 76 555 003',
  business_name: 'Kgosi Cash Corner',
  status: 'suspended',
  agent_status: 'suspended',
  float_balance_minor_units: 100000,
  float_limit_minor_units: 5000000,
  float_currency: 'BWP',
  commission_rate: 0.015,
  total_cash_in_today_minor_units: 0,
  total_cash_out_today_minor_units: 0,
  location: 'Maun, Old Mall',
  approved_at: '2026-09-25T10:00:00Z',
  approved_by: 'Admin User',
  suspension_reason: 'Suspicious float activity',
  suspended_at: '2026-10-01T09:00:00Z',
  created_at: '2026-09-20T08:00:00Z',
};

const AGENT_ACTIVE_DETAIL = {
  ...AGENT_ACTIVE,
  transactions_30d: 412,
  commission_earned_30d_minor_units: 18500,
  cash_in_30d_minor_units: 9500000,
  cash_out_30d_minor_units: 7200000,
};

function paginatedResponse(items: unknown[]) {
  return { data: { data: items, total: items.length, meta: { total: items.length } } };
}

function singleResponse(item: unknown) {
  return { data: item };
}

// ── Helpers ────────────────────────────────────────────────────────────────────

beforeEach(() => {
  jest.clearAllMocks();
  mockUseAuth.mockReturnValue({ authenticatedClient: MOCK_CLIENT } as never);
});

// ─────────────────────────────────────────────────────────────────────────────
// US-P1-010-001: Admin views and searches agents
// ─────────────────────────────────────────────────────────────────────────────

describe('US-P1-010-001: Agent list', () => {
  it('renders list table with agent columns', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([AGENT_PENDING, AGENT_ACTIVE, AGENT_SUSPENDED]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(mockListAgents).toHaveBeenCalledTimes(1));
    expect(screen.getByRole('table', { name: /agents list/i })).toBeInTheDocument();
    const ths = screen.getAllByRole('columnheader');
    const headers = ths.map(th => th.textContent?.toLowerCase());
    expect(headers.some(h => h?.includes('agent name'))).toBe(true);
    expect(headers.some(h => h?.includes('status'))).toBe(true);
    expect(headers.some(h => h?.includes('float balance'))).toBe(true);
    expect(headers.some(h => h?.includes('region'))).toBe(true);
  });

  it('displays pending agent name and badge', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([AGENT_PENDING]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(screen.getByText('Thabo Sithole')).toBeInTheDocument());
    expect(screen.getByText('PENDING APPROVAL')).toBeInTheDocument();
  });

  it('displays active agent', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([AGENT_ACTIVE]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(screen.getByText('Kefilwe Mokoena')).toBeInTheDocument());
    expect(screen.getByText('ACTIVE')).toBeInTheDocument();
  });

  it('displays suspended agent', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([AGENT_SUSPENDED]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(screen.getByText('Mpho Kgosi')).toBeInTheDocument());
    expect(screen.getByText('SUSPENDED')).toBeInTheDocument();
  });

  it('shows float balance for active agent', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([AGENT_ACTIVE]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(screen.getByText('Kefilwe Mokoena')).toBeInTheDocument());
    expect(screen.getByText(/5,000\.00/)).toBeInTheDocument();
  });

  it('shows region column', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([AGENT_ACTIVE]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(screen.getByText(/Francistown/)).toBeInTheDocument());
  });

  it('shows View button linking to agent detail', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([AGENT_ACTIVE]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(screen.getByText('Kefilwe Mokoena')).toBeInTheDocument());
    const [viewBtn] = screen.getAllByRole('button', { name: /view agent Kefilwe Mokoena/i });
    expect(viewBtn!.closest('a')).toHaveAttribute('href', '/agents/agt-002');
  });

  it('shows empty state when no agents', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(screen.getByText(/no agents found/i)).toBeInTheDocument());
  });

  it('shows error state on fetch failure', async () => {
    mockListAgents.mockRejectedValueOnce(new Error('Network error'));
    render(<AgentManagementPage />);
    await waitFor(() => expect(screen.getByText(/failed to load agents/i)).toBeInTheDocument());
  });

  it('renders search input', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(mockListAgents).toHaveBeenCalled());
    expect(screen.getByRole('searchbox', { name: /search agents/i })).toBeInTheDocument();
  });

  it('submits search parameter on input change', async () => {
    const user = userEvent.setup();
    mockListAgents.mockResolvedValue(paginatedResponse([]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(mockListAgents).toHaveBeenCalledTimes(1));
    const searchInput = screen.getByRole('searchbox', { name: /search agents/i });
    await user.type(searchInput, 'Thabo');
    await waitFor(() => {
      const lastCall = mockListAgents.mock.calls.at(-1)?.[0] as Record<string, string> | undefined;
      expect(lastCall?.search).toBe('Thabo');
    });
  });

  it('renders status filter', async () => {
    mockListAgents.mockResolvedValueOnce(paginatedResponse([]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(mockListAgents).toHaveBeenCalled());
    expect(screen.getByRole('combobox', { name: /filter by status/i })).toBeInTheDocument();
  });

  it('filters by status', async () => {
    const user = userEvent.setup();
    mockListAgents.mockResolvedValue(paginatedResponse([]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(mockListAgents).toHaveBeenCalledTimes(1));
    const statusSelect = screen.getByRole('combobox', { name: /filter by status/i });
    await user.selectOptions(statusSelect, 'active');
    await waitFor(() => {
      const lastCall = mockListAgents.mock.calls.at(-1)?.[0] as Record<string, string> | undefined;
      expect(lastCall?.status).toBe('active');
    });
  });

  it('clears filters on Clear Filters click', async () => {
    const user = userEvent.setup();
    mockListAgents.mockResolvedValue(paginatedResponse([]));
    render(<AgentManagementPage />);
    await waitFor(() => expect(mockListAgents).toHaveBeenCalledTimes(1));
    const clearBtn = screen.getByRole('button', { name: /clear filters/i });
    await user.click(clearBtn);
    await waitFor(() => {
      const lastCall = mockListAgents.mock.calls.at(-1)?.[0] as Record<string, string> | undefined;
      expect(lastCall?.search).toBeUndefined();
      expect(lastCall?.status).toBeUndefined();
    });
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// US-P1-010-002: Admin approves/rejects an agent application
// ─────────────────────────────────────────────────────────────────────────────

describe('US-P1-010-002: Agent approve/reject', () => {
  it('shows Approve and Reject buttons for pending agent', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_PENDING));
    render(<AgentDetailPage agentId="agt-001" />);
    // Wait for agent data to load — pending agent shows approve button
    await waitFor(() => expect(screen.getByRole('button', { name: /approve application/i })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /reject application/i })).toBeInTheDocument();
  });

  it('does not show Approve/Reject for active agent', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE));
    render(<AgentDetailPage agentId="agt-002" />);
    // Active agent shows Suspend button — wait for that as loaded indicator
    await waitFor(() => expect(screen.getByRole('button', { name: /suspend agent/i })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /approve application/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /reject application/i })).not.toBeInTheDocument();
  });

  it('opens approve dialog and shows maker-checker note', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_PENDING));
    render(<AgentDetailPage agentId="agt-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /approve application/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /approve application/i }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/approve agent application/i)).toBeInTheDocument();
    expect(within(dialog).getByText(/second approver/i)).toBeInTheDocument();
  });

  it('calls approveAgent and shows approval ID on confirm', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValue(singleResponse(AGENT_PENDING));
    mockApproveAgent.mockResolvedValueOnce({ data: { approval_id: 'PAD-001', status: 'pending' } });
    render(<AgentDetailPage agentId="agt-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /approve application/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /approve application/i }));
    const dialog = screen.getByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /confirm/i }));
    await waitFor(() => {
      expect(mockApproveAgent).toHaveBeenCalledWith('agt-001', expect.any(String));
      expect(screen.getByRole('status')).toHaveTextContent(/PAD-001/);
    });
  });

  it('opens reject dialog and requires reason', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_PENDING));
    render(<AgentDetailPage agentId="agt-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /reject application/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /reject application/i }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/reject agent application/i)).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/reason/i)).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: /confirm/i })).toBeDisabled();
  });

  it('calls rejectAgent with reason and shows success', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValue(singleResponse(AGENT_PENDING));
    mockRejectAgent.mockResolvedValueOnce({ data: { ...AGENT_PENDING, agent_status: 'rejected' } });
    render(<AgentDetailPage agentId="agt-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /reject application/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /reject application/i }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Incomplete documents');
    await user.click(within(dialog).getByRole('button', { name: /confirm/i }));
    await waitFor(() => {
      expect(mockRejectAgent).toHaveBeenCalledWith('agt-001', 'Incomplete documents', expect.any(String));
      expect(screen.getByRole('status')).toHaveTextContent(/rejected/i);
    });
  });

  it('shows error on AGENT_INVALID_STATUS_TRANSITION', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_PENDING));
    const err = Object.assign(new Error('Invalid transition'), { code: 'AGENT_INVALID_STATUS_TRANSITION' });
    mockApproveAgent.mockRejectedValueOnce(err);
    render(<AgentDetailPage agentId="agt-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /approve application/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /approve application/i }));
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: /confirm/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/not permitted/i));
  });

  it('closes dialog on Cancel', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_PENDING));
    render(<AgentDetailPage agentId="agt-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /approve application/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /approve application/i }));
    const dialog = screen.getByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /cancel/i }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// US-P1-010-003: Admin suspends/reactivates an agent
// ─────────────────────────────────────────────────────────────────────────────

describe('US-P1-010-003: Agent suspend/reactivate', () => {
  it('shows Suspend button for active agent', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /suspend agent/i })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /reactivate/i })).not.toBeInTheDocument();
  });

  it('shows Reactivate button for suspended agent', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_SUSPENDED));
    render(<AgentDetailPage agentId="agt-003" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /reactivate agent/i })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /suspend agent/i })).not.toBeInTheDocument();
  });

  it('opens suspend dialog and requires reason', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /suspend agent/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /suspend agent/i }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/suspend agent/i)).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/reason/i)).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: /confirm/i })).toBeDisabled();
  });

  it('calls updateAgentStatus with suspend action and reason', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValue(singleResponse(AGENT_ACTIVE));
    mockUpdateAgentStatus.mockResolvedValueOnce({ data: { ...AGENT_ACTIVE, agent_status: 'suspended' } });
    render(<AgentDetailPage agentId="agt-002" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /suspend agent/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /suspend agent/i }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Policy violation');
    await user.click(within(dialog).getByRole('button', { name: /confirm/i }));
    await waitFor(() => {
      expect(mockUpdateAgentStatus).toHaveBeenCalledWith('agt-002', 'suspend', 'Policy violation', expect.any(String));
      expect(screen.getByRole('status')).toHaveTextContent(/suspended/i);
    });
  });

  it('calls updateAgentStatus with reactivate action and reason', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValue(singleResponse(AGENT_SUSPENDED));
    mockUpdateAgentStatus.mockResolvedValueOnce({ data: { ...AGENT_SUSPENDED, agent_status: 'active' } });
    render(<AgentDetailPage agentId="agt-003" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /reactivate agent/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /reactivate agent/i }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Issue resolved');
    await user.click(within(dialog).getByRole('button', { name: /confirm/i }));
    await waitFor(() => {
      expect(mockUpdateAgentStatus).toHaveBeenCalledWith('agt-003', 'reactivate', 'Issue resolved', expect.any(String));
      expect(screen.getByRole('status')).toHaveTextContent(/reactivated/i);
    });
  });

  it('shows suspension reason on suspended agent detail', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_SUSPENDED));
    render(<AgentDetailPage agentId="agt-003" />);
    await waitFor(() => expect(screen.getByText(/Suspicious float activity/)).toBeInTheDocument());
  });

  it('does not show Suspend/Reactivate for pending agent (only approve/reject)', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_PENDING));
    render(<AgentDetailPage agentId="agt-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /approve application/i })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /suspend agent/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /reactivate agent/i })).not.toBeInTheDocument();
  });

  it('shows error on updateAgentStatus failure', async () => {
    const user = userEvent.setup();
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE));
    mockUpdateAgentStatus.mockRejectedValueOnce(new Error('Server error'));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /suspend agent/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /suspend agent/i }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(/reason/i), 'Test reason');
    await user.click(within(dialog).getByRole('button', { name: /confirm/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/action failed/i));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// US-P1-010-004: Admin views agent performance metrics
// ─────────────────────────────────────────────────────────────────────────────

describe('US-P1-010-004: Agent performance metrics', () => {
  // Shared wait helper: active agent → suspend button is the loaded indicator
  async function waitForActiveAgentLoaded() {
    await waitFor(() => expect(screen.getByRole('button', { name: /suspend agent/i })).toBeInTheDocument());
  }

  it('renders float balance section with balance, limit, utilization', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    const floatSection = screen.getByRole('region', { name: /float balance/i });
    expect(floatSection).toBeInTheDocument();
    expect(within(floatSection).getByText(/current balance/i)).toBeInTheDocument();
    expect(within(floatSection).getByText(/float limit/i)).toBeInTheDocument();
    expect(within(floatSection).getByText(/utilization/i)).toBeInTheDocument();
  });

  it('calculates float utilization percentage', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    // 500000 / 5000000 = 10.0%
    expect(screen.getByText('10.0%')).toBeInTheDocument();
  });

  it('renders performance metrics section', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    expect(screen.getByRole('region', { name: /performance metrics/i })).toBeInTheDocument();
  });

  it('shows commission rate', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    // 0.02 → 2.00%
    expect(screen.getByText('2.00%')).toBeInTheDocument();
  });

  it('shows cash-in today', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    // 120000 → 1,200.00
    expect(screen.getAllByText(/1,200\.00/).length).toBeGreaterThanOrEqual(1);
  });

  it('shows cash-out today', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    // 85000 → 850.00
    expect(screen.getByText(/850\.00/)).toBeInTheDocument();
  });

  it('shows 30-day transaction count when available', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    expect(screen.getByText('412')).toBeInTheDocument();
  });

  it('shows dash for tx count when not available', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    const metricsSection = screen.getByRole('region', { name: /performance metrics/i });
    expect(within(metricsSection).getByText('—')).toBeInTheDocument();
  });

  it('shows commission earned 30d when available', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    // 18500 → 185.00
    expect(screen.getByText(/185\.00/)).toBeInTheDocument();
  });

  it('renders profile details with phone and business name', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    expect(screen.getByText('+267 75 555 002')).toBeInTheDocument();
    expect(screen.getByText('Mokoena Traders')).toBeInTheDocument();
    expect(screen.getByText('Francistown, Block 5')).toBeInTheDocument();
  });

  it('links user_id to user detail page', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    const userLink = screen.getByRole('link', { name: /usr-002/i });
    expect(userLink).toHaveAttribute('href', '/users/usr-002');
  });

  it('shows breadcrumb link back to agents list', async () => {
    mockGetAgent.mockResolvedValueOnce(singleResponse(AGENT_ACTIVE_DETAIL));
    render(<AgentDetailPage agentId="agt-002" />);
    await waitForActiveAgentLoaded();
    const agentsLink = screen.getByRole('link', { name: /^agents$/i });
    expect(agentsLink).toHaveAttribute('href', '/agents');
  });

  it('shows error state on fetch failure', async () => {
    mockGetAgent.mockRejectedValueOnce(new Error('Network error'));
    render(<AgentDetailPage agentId="agt-999" />);
    await waitFor(() => expect(screen.getByText(/failed to load agent/i)).toBeInTheDocument());
  });
});
