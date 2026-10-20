/**
 * ADM-P1-014 — Fee Rules Management
 * US-P1-014-001: Admin creates a fee rule and views detail
 * US-P1-014-002: Admin deactivates a fee rule
 * US-P1-014-003: Admin simulates fee impact
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ children, href }: { children: React.ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
}));

// ── Fees API mocks ────────────────────────────────────────────────────────────

const mockGetFeeRule        = jest.fn();
const mockDeactivateFeeRule = jest.fn();
const mockCalculateFee      = jest.fn();

jest.mock('@/src/modules/fees/api/fees', () => ({
  getFeeRule:        (...args: unknown[]) => mockGetFeeRule(...args),
  deactivateFeeRule: (...args: unknown[]) => mockDeactivateFeeRule(...args),
  calculateFee:      (...args: unknown[]) => mockCalculateFee(...args),
}));

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports ───────────────────────────────────────────────────────────────────

import { FeeRulesListPage }  from '@/src/modules/fees/pages/fee-rules-list';
import { FeeRuleDetailPage } from '@/src/modules/fees/pages/fee-rule-detail';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;
const mockRouterPush = jest.fn();

// ── Fixtures ──────────────────────────────────────────────────────────────────

const ACTIVE_RULE = {
  id: 'fee-rule-001',
  transaction_type: 'transfer' as const,
  fee_type: 'percentage' as const,
  rate: 0.5,
  amount_minor: null,
  min_fee_minor: null,
  max_fee_minor: null,
  currency: 'BWP',
  subject_type: 'global' as const,
  subject_id: null,
  valid_from: '2026-01-01T00:00:00Z',
  valid_to: null,
  status: 'active' as const,
  pricing_version_id: null,
  created_by: 'admin-001',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
};

const DEACTIVATED_RULE = {
  ...ACTIVE_RULE,
  id: 'fee-rule-002',
  status: 'deactivated' as const,
};

const EXPIRED_RULE = {
  ...ACTIVE_RULE,
  id: 'fee-rule-003',
  status: 'expired' as const,
  valid_to: '2026-06-01T00:00:00Z',
};

const FEE_RESULT_NORMAL = {
  fee_amount_minor: 500,
  fee_type: 'percentage' as const,
  rule_id: 'fee-rule-001',
  has_applicable_rule: true,
  min_fee_applied: false,
  max_fee_applied: false,
};

const FEE_RESULT_MIN_APPLIED = {
  fee_amount_minor: 100,
  fee_type: 'percentage' as const,
  rule_id: 'fee-rule-001',
  has_applicable_rule: true,
  min_fee_applied: true,
  max_fee_applied: false,
};

const FEE_RESULT_NO_RULE = {
  fee_amount_minor: 0,
  fee_type: null,
  rule_id: null,
  has_applicable_rule: false,
  min_fee_applied: false,
  max_fee_applied: false,
};

// ── Setup ─────────────────────────────────────────────────────────────────────

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: mockRouterPush, replace: jest.fn(), back: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

function setupAuth(overrides: Partial<ReturnType<typeof authContext.useAuth>> = {}) {
  mockUseAuth.mockReturnValue({
    authenticatedClient: MOCK_CLIENT,
    hasPermission: (slug: string) => slug === 'fees.rules.delete',
    permissions: ['fees.rules.delete', 'fees.rules.view', 'fees.rules.create'],
    phase: 'authenticated',
    user: { id: 'admin-001', email: 'admin@olekard.local', name: 'Admin' },
    mfaMethods: null,
    isAuthenticated: true,
    authError: null,
    authErrorCode: null,
    login: jest.fn(),
    completeMfa: jest.fn(),
    signOut: jest.fn(),
    ...overrides,
  } as never);
}

beforeEach(() => {
  jest.clearAllMocks();
  mockRouterPush.mockClear();
  setupAuth();
});

// =============================================================================
// US-P1-014-001: Fee Rule Detail
// =============================================================================

describe('US-P1-014-001: Fee Rule Detail', () => {
  it('(a) renders loading state on mount', () => {
    mockGetFeeRule.mockReturnValue(new Promise(() => {})); // never resolves
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    expect(screen.getByRole('status')).toBeInTheDocument();
  });

  it('(b) renders rule attributes after fetch resolves', async () => {
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalledWith(MOCK_CLIENT, 'fee-rule-001'));
    expect(screen.getByText('0.5%')).toBeInTheDocument();
    expect(screen.getByText('ACTIVE')).toBeInTheDocument();
    expect(screen.getByText('BWP')).toBeInTheDocument();
    expect(screen.getByText('global')).toBeInTheDocument();
  });

  it('(c) shows error state when API returns 404', async () => {
    mockGetFeeRule.mockRejectedValueOnce({ status: 404 });
    render(<FeeRuleDetailPage ruleId="nonexistent" />);
    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText(/fee rule not found/i)).toBeInTheDocument();
  });

  it('(d) navigates to /fees/rules/{id} when list row clicked', async () => {
    const user = userEvent.setup();
    const listRules = [ACTIVE_RULE];
    mockUseAuth.mockReturnValue({
      authenticatedClient: {
        get: jest.fn().mockResolvedValue({ data: listRules, meta: { total: 1 } }),
      },
      hasPermission: jest.fn().mockReturnValue(true),
      permissions: [],
      phase: 'authenticated',
      user: null,
      mfaMethods: null,
      isAuthenticated: true,
      authError: null,
      authErrorCode: null,
      login: jest.fn(),
      completeMfa: jest.fn(),
      signOut: jest.fn(),
    } as never);
    render(<FeeRulesListPage />);
    await waitFor(() => expect(screen.getAllByText('transfer').length).toBeGreaterThan(0));
    // Find the table cell (not the filter option)
    const cells = screen.getAllByText('transfer');
    const tableCell = cells.find((el) => el.tagName === 'TD')!;
    await user.click(tableCell.closest('tr')!);
    expect(mockRouterPush).toHaveBeenCalledWith('/fees/rules/fee-rule-001');
  });
});

// =============================================================================
// US-P1-014-002: Deactivate Fee Rule
// =============================================================================

describe('US-P1-014-002: Deactivate Fee Rule', () => {
  it('(a) renders Deactivate button for active rule with fees.rules.delete permission', async () => {
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    expect(screen.getByRole('button', { name: /deactivate this fee rule/i })).toBeInTheDocument();
  });

  it('(b) does NOT render Deactivate button when rule.status !== active', async () => {
    mockGetFeeRule.mockResolvedValueOnce(DEACTIVATED_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-002" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    expect(screen.queryByRole('button', { name: /deactivate this fee rule/i })).not.toBeInTheDocument();
  });

  it('(c) does NOT render Deactivate button when user lacks fees.rules.delete permission', async () => {
    setupAuth({ hasPermission: () => false });
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    expect(screen.queryByRole('button', { name: /deactivate this fee rule/i })).not.toBeInTheDocument();
  });

  it('(d) opens confirmation dialog on Deactivate click', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /deactivate this fee rule/i }));
    expect(screen.getByRole('dialog', { name: /deactivate fee rule/i })).toBeInTheDocument();
  });

  it('(e) calls deactivateFeeRule and badge updates to DEACTIVATED on confirm', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    mockDeactivateFeeRule.mockResolvedValueOnce(undefined);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(screen.getByText('ACTIVE')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /deactivate this fee rule/i }));
    await user.click(screen.getByRole('button', { name: /confirm deactivation/i }));
    await waitFor(() => expect(mockDeactivateFeeRule).toHaveBeenCalledWith(MOCK_CLIENT, 'fee-rule-001'));
    expect(screen.getByText('DEACTIVATED')).toBeInTheDocument();
  });

  it('(f) shows success toast after deactivation', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    mockDeactivateFeeRule.mockResolvedValueOnce(undefined);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /deactivate this fee rule/i }));
    await user.click(screen.getByRole('button', { name: /confirm deactivation/i }));
    await waitFor(() =>
      expect(screen.getByText(/fee rule deactivated successfully/i)).toBeInTheDocument()
    );
  });

  it('(g) shows error toast when API call fails', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    mockDeactivateFeeRule.mockRejectedValueOnce(new Error('Network error'));
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /deactivate this fee rule/i }));
    await user.click(screen.getByRole('button', { name: /confirm deactivation/i }));
    await waitFor(() =>
      expect(screen.getByText(/failed to deactivate rule/i)).toBeInTheDocument()
    );
    expect(screen.getByText('ACTIVE')).toBeInTheDocument();
  });

  it('(h) closes dialog without calling API on Cancel', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /deactivate this fee rule/i }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^cancel$/i }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(mockDeactivateFeeRule).not.toHaveBeenCalled();
  });
});

// =============================================================================
// US-P1-014-003: Simulate Fee Impact
// =============================================================================

describe('US-P1-014-003: Simulate Fee Impact', () => {
  it('(a) simulation panel is collapsed by default', async () => {
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    expect(screen.queryByLabelText(/transaction amount/i)).not.toBeInTheDocument();
  });

  it('(b) panel opens on Simulate Fee Impact button click', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /toggle fee impact simulation/i }));
    expect(screen.getByRole('spinbutton', { name: /transaction amount/i })).toBeInTheDocument();
  });

  it('(c) calls calculateFee with correct args when Calculate clicked with valid amount', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    mockCalculateFee.mockResolvedValueOnce(FEE_RESULT_NORMAL);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /toggle fee impact simulation/i }));
    await user.type(screen.getByRole('spinbutton', { name: /transaction amount/i }), '1000');
    await user.click(screen.getByRole('button', { name: /calculate fee/i }));
    await waitFor(() => expect(mockCalculateFee).toHaveBeenCalledWith(MOCK_CLIENT, {
      transaction_type: 'transfer',
      amount_minor: 100000,
      currency: 'BWP',
      subject_type: 'global',
    }));
  });

  it('(d) displays fee amount and net amount from result', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    mockCalculateFee.mockResolvedValueOnce(FEE_RESULT_NORMAL);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /toggle fee impact simulation/i }));
    await user.type(screen.getByRole('spinbutton', { name: /transaction amount/i }), '1000');
    await user.click(screen.getByRole('button', { name: /calculate fee/i }));
    await waitFor(() => expect(screen.getByText('BWP 5.00')).toBeInTheDocument());
    expect(screen.getByText('BWP 995.00')).toBeInTheDocument();
  });

  it('(e) shows minimum fee applied notice when min_fee_applied = true', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    mockCalculateFee.mockResolvedValueOnce(FEE_RESULT_MIN_APPLIED);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /toggle fee impact simulation/i }));
    await user.type(screen.getByRole('spinbutton', { name: /transaction amount/i }), '100');
    await user.click(screen.getByRole('button', { name: /calculate fee/i }));
    await waitFor(() => expect(screen.getByText(/minimum fee applied/i)).toBeInTheDocument());
  });

  it('(f) shows no-rule message when has_applicable_rule = false', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    mockCalculateFee.mockResolvedValueOnce(FEE_RESULT_NO_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /toggle fee impact simulation/i }));
    await user.type(screen.getByRole('spinbutton', { name: /transaction amount/i }), '500');
    await user.click(screen.getByRole('button', { name: /calculate fee/i }));
    await waitFor(() =>
      expect(screen.getByText(/no fee rule found/i)).toBeInTheDocument()
    );
  });

  it('(g) shows inline error for invalid (non-numeric) amount without calling API', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /toggle fee impact simulation/i }));
    await user.type(screen.getByRole('spinbutton', { name: /transaction amount/i }), 'abc');
    await user.click(screen.getByRole('button', { name: /calculate fee/i }));
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText(/enter a valid amount/i)).toBeInTheDocument();
    expect(mockCalculateFee).not.toHaveBeenCalled();
  });

  it('(h) shows simError when calculateFee rejects', async () => {
    const user = userEvent.setup();
    mockGetFeeRule.mockResolvedValueOnce(ACTIVE_RULE);
    mockCalculateFee.mockRejectedValueOnce(new Error('Server error'));
    render(<FeeRuleDetailPage ruleId="fee-rule-001" />);
    await waitFor(() => expect(mockGetFeeRule).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /toggle fee impact simulation/i }));
    await user.type(screen.getByRole('spinbutton', { name: /transaction amount/i }), '500');
    await user.click(screen.getByRole('button', { name: /calculate fee/i }));
    await waitFor(() =>
      expect(screen.getByText(/fee calculation failed/i)).toBeInTheDocument()
    );
  });
});
