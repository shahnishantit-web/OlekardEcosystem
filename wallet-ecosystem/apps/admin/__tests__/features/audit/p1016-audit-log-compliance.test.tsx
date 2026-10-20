import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// --- Mocks (before imports) ---
const mockListAuditEvents        = jest.fn();
const mockGetAuditEventDetail    = jest.fn();
const mockGetComplianceDashboard = jest.fn();
const mockListRetentionPolicies  = jest.fn();
const mockUpdateRetentionPolicy  = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    listAuditEvents:        (...args: unknown[]) => mockListAuditEvents(...args),
    getAuditEventDetail:    (...args: unknown[]) => mockGetAuditEventDetail(...args),
    getComplianceDashboard: (...args: unknown[]) => mockGetComplianceDashboard(...args),
    listRetentionPolicies:  (...args: unknown[]) => mockListRetentionPolicies(...args),
    updateRetentionPolicy:  (...args: unknown[]) => mockUpdateRetentionPolicy(...args),
  })),
}));

const mockHasPermission = jest.fn<boolean, [string]>();

// useAuth returns a stable authenticatedClient reference to avoid infinite useEffect loops
jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

import { useAuth } from '@/lib/auth/context';
import { AuditEventsPage }       from '@/src/modules/audit/pages/audit-events';
import { ComplianceStatusPage }  from '@/src/modules/audit/pages/compliance-status';
import { RetentionPoliciesPage } from '@/src/modules/audit/pages/retention-policies';

// Stable client reference — prevents useEffect dep-array churn
const STABLE_CLIENT = {} as never;

// --- Fixtures ---
const MOCK_EVENT = {
  id: 'evt-001',
  action: 'wallet.freeze.applied',
  actor_id: 'admin-001',
  actor_type: 'admin',
  resource_type: 'wallet',
  resource_id: 'wallet-001',
  outcome: 'success' as const,
  correlation_id: 'corr-abc123',
  occurred_at: '2026-10-14T08:00:00Z',
  before_state: { status: 'active' },
  after_state: { status: 'frozen' },
};

const MOCK_EVENT_DETAIL = {
  ...MOCK_EVENT,
  metadata:     { reason: 'suspicious' },
};

const EMPTY_AUDIT_RESPONSE = {
  data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 20 },
};

const AUDIT_RESPONSE_ONE = {
  data: [MOCK_EVENT], meta: { total: 1, current_page: 1, last_page: 1, per_page: 20 },
};

const MOCK_DASHBOARD = {
  regulatory_checklist: [
    { label: 'KYC Tier-1 Completion', status: 'ok' as const,       detail: '98% complete' },
    { label: 'AML Screening',          status: 'warning' as const,  detail: '3 pending' },
    { label: 'Sanctions Sync',         status: 'critical' as const, detail: 'Overdue' },
  ],
  open_items: [
    { id: 'item-1', description: 'KYC docs expired',  due_date: '2026-10-15', urgency: 'high' as const,   category: 'kyc' },
    { id: 'item-2', description: 'AML policy review', due_date: '2026-10-21', urgency: 'medium' as const, category: 'aml' },
  ],
  retention_deadlines: [
    { policy_id: 'pol-1', data_category: 'audit.authentication', deadline_at: '2026-10-20', urgency: 'high' as const },
    { policy_id: 'pol-2', data_category: 'transaction.records',  deadline_at: '2026-11-05', urgency: 'low' as const },
  ],
};

const MOCK_POLICIES: import('@/lib/api/admin-app-client').RetentionPolicy[] = [
  {
    id: 'pol-001',
    data_category: 'audit.authentication',
    minimum_retention_days: 365,
    post_retention_action: 'archive',
    effective_from: '2026-01-01',
    enforcement_hold_until: null,
    configured_by_actor_id: 'admin-001',
    updated_at: '2026-10-01T10:00:00Z',
  },
  {
    id: 'pol-002',
    data_category: 'transaction.records',
    minimum_retention_days: 730,
    post_retention_action: 'archive',
    effective_from: '2026-01-01',
    enforcement_hold_until: '2099-12-31T00:00:00Z',
    configured_by_actor_id: 'admin-001',
    updated_at: '2026-10-01T10:00:00Z',
  },
];

afterEach(() => {
  jest.clearAllMocks();
  jest.useRealTimers();
});

// =============================================================================
// US1 — View Immutable Audit Log
// =============================================================================
describe('US1 — Audit Events Page', () => {
  function setupAudit(hasPerm = true) {
    mockHasPermission.mockImplementation((slug: string) =>
      slug === 'adminapp.audit.read' ? hasPerm : false,
    );
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    mockListAuditEvents.mockResolvedValue(AUDIT_RESPONSE_ONE);
    return render(<AuditEventsPage />);
  }

  it('(a) renders audit events table with required columns', async () => {
    setupAudit();
    await waitFor(() => expect(screen.getByRole('table', { name: /audit events/i })).toBeInTheDocument());
    const headers = screen.getAllByRole('columnheader').map(h => h.textContent ?? '');
    expect(headers).toEqual(expect.arrayContaining([
      expect.stringMatching(/occurred at/i),
      expect.stringMatching(/action/i),
      expect.stringMatching(/actor/i),
      expect.stringMatching(/resource/i),
      expect.stringMatching(/correlation id/i),
      expect.stringMatching(/outcome/i),
    ]));
  });

  it('(b) renders event data in table row', async () => {
    setupAudit();
    await waitFor(() => expect(screen.getByText('wallet.freeze.applied')).toBeInTheDocument());
    expect(screen.getByText(/wallet \/ wallet-0…/i)).toBeInTheDocument();
    expect(screen.getByText('corr-abc123')).toBeInTheDocument();
  });

  it('(c) shows access-denied card when permission missing', () => {
    mockHasPermission.mockReturnValue(false);
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    render(<AuditEventsPage />);
    expect(screen.getByRole('alert')).toHaveTextContent(/access denied/i);
    expect(mockListAuditEvents).not.toHaveBeenCalled();
  });

  it('(d) Actor and Entity Type filter controls are present', async () => {
    setupAudit();
    await waitFor(() => expect(screen.getByLabelText('Event Type')).toBeInTheDocument());
    expect(screen.getByLabelText('Actor')).toBeInTheDocument();
    expect(screen.getByLabelText('Entity Type')).toBeInTheDocument();
  });

  it('(e) date range > 90 days shows validation error without API call', async () => {
    setupAudit();
    await waitFor(() => expect(screen.getByLabelText('From')).toBeInTheDocument());

    mockListAuditEvents.mockClear();
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-01-01T00:00' } });
    fireEvent.change(screen.getByLabelText('To'),   { target: { value: '2026-04-30T00:00' } });
    fireEvent.click(screen.getByRole('button', { name: /apply filters/i }));

    expect(screen.getByRole('alert')).toHaveTextContent(/90 days/i);
    expect(mockListAuditEvents).not.toHaveBeenCalled();
  });

  it('(f) date range within 90 days triggers API call', async () => {
    setupAudit();
    await waitFor(() => expect(screen.getByLabelText('From')).toBeInTheDocument());

    mockListAuditEvents.mockClear();
    mockListAuditEvents.mockResolvedValue(EMPTY_AUDIT_RESPONSE);
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01T00:00' } });
    fireEvent.change(screen.getByLabelText('To'),   { target: { value: '2026-10-14T00:00' } });
    fireEvent.click(screen.getByRole('button', { name: /apply filters/i }));

    await waitFor(() => expect(mockListAuditEvents).toHaveBeenCalledWith(
      expect.objectContaining({ from_date: '2026-10-01T00:00', to_date: '2026-10-14T00:00' }),
    ));
  });

  it('(g) View button opens detail drawer', async () => {
    setupAudit();
    await waitFor(() => expect(screen.getByRole('button', { name: /view/i })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /view/i }));
    await waitFor(() => expect(screen.getByRole('dialog', { name: /audit event detail/i })).toBeInTheDocument());
    expect(screen.getByText(/status.*active/i)).toBeInTheDocument();
  });

  it('(g2) Load More requests the next backend page and appends its events', async () => {
    const nextEvent = { ...MOCK_EVENT, id: 'evt-002', action: 'wallet.unfreeze.applied' };
    mockListAuditEvents
      .mockResolvedValueOnce({ data: [MOCK_EVENT], meta: { total: 21, current_page: 1, last_page: 2, per_page: 20 } })
      .mockResolvedValueOnce({ data: [nextEvent], meta: { total: 21, current_page: 2, last_page: 2, per_page: 20 } });
    setupAudit();
    const loadMore = await screen.findByRole('button', { name: /load more/i });
    fireEvent.click(loadMore);
    await waitFor(() => expect(mockListAuditEvents).toHaveBeenLastCalledWith(
      expect.objectContaining({ page: '2' }),
    ));
    expect(await screen.findByText('wallet.unfreeze.applied')).toBeInTheDocument();
    expect(screen.getByText('wallet.freeze.applied')).toBeInTheDocument();
  });

  it('(h) detail drawer contains NO edit or delete buttons (FR-005 / SC-002)', async () => {
    setupAudit();
    await waitFor(() => expect(screen.getByRole('button', { name: /view/i })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /view/i }));
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());

    const buttons = screen.getAllByRole('button').map(b => b.textContent?.toLowerCase() ?? '');
    expect(buttons.some(t => t.includes('edit'))).toBe(false);
    expect(buttons.some(t => t.includes('delete'))).toBe(false);
  });

  it('(i) zero edit/delete buttons on main table (SC-002)', async () => {
    setupAudit();
    await waitFor(() => expect(screen.getByText('wallet.freeze.applied')).toBeInTheDocument());
    const buttons = screen.getAllByRole('button').map(b => b.textContent?.toLowerCase() ?? '');
    expect(buttons.some(t => t.includes('edit'))).toBe(false);
    expect(buttons.some(t => t.includes('delete'))).toBe(false);
  });
});

// =============================================================================
// US2 — Compliance Dashboard
// =============================================================================
describe('US2 — Compliance Dashboard', () => {
  function setupDashboard(hasPerm = true) {
    mockHasPermission.mockImplementation((slug: string) =>
      slug === 'compliance.read' ? hasPerm : false,
    );
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    mockGetComplianceDashboard.mockResolvedValue({ data: MOCK_DASHBOARD });
    return render(<ComplianceStatusPage />);
  }

  it('(a) shows access-denied card when permission missing', () => {
    mockHasPermission.mockReturnValue(false);
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    render(<ComplianceStatusPage />);
    expect(screen.getByRole('alert')).toHaveTextContent(/access denied/i);
    expect(mockGetComplianceDashboard).not.toHaveBeenCalled();
  });

  it('(b) renders regulatory checklist section with urgency badges', async () => {
    setupDashboard();
    await waitFor(() => expect(screen.getByText('KYC Tier-1 Completion')).toBeInTheDocument());
    expect(screen.getByText('AML Screening')).toBeInTheDocument();
    expect(screen.getByText('Sanctions Sync')).toBeInTheDocument();
  });

  it('(c) checklist items have correct urgency badge text', async () => {
    setupDashboard();
    await waitFor(() => expect(screen.getByText('OK')).toBeInTheDocument());
    expect(screen.getByText('WARNING')).toBeInTheDocument();
    expect(screen.getByText('CRITICAL')).toBeInTheDocument();
  });

  it('(d) renders open compliance items with urgency badges', async () => {
    setupDashboard();
    await waitFor(() => expect(screen.getByText('KYC docs expired')).toBeInTheDocument());
    expect(screen.getByText('AML policy review')).toBeInTheDocument();
    const highBadges = screen.getAllByText('HIGH');
    expect(highBadges.length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('MEDIUM')).toBeInTheDocument();
  });

  it('(e) renders retention deadlines sorted by earliest first', async () => {
    setupDashboard();
    await waitFor(() => expect(screen.getByText('audit.authentication')).toBeInTheDocument());
    expect(screen.getByText('transaction.records')).toBeInTheDocument();
    // audit.authentication deadline (Oct 20) comes before transaction.records (Nov 05)
    expect(document.body.innerHTML.indexOf('audit.authentication'))
      .toBeLessThan(document.body.innerHTML.indexOf('transaction.records'));
  });

  it('(f) shows LOW badge for low-urgency retention deadline', async () => {
    setupDashboard();
    await waitFor(() => expect(screen.getByText('transaction.records')).toBeInTheDocument());
    expect(screen.getByText('LOW')).toBeInTheDocument();
  });
});

// =============================================================================
// US3 — Data Retention Policies
// =============================================================================
describe('US3 — Retention Policies', () => {
  function setupRetention(configPerm = true, readPerm = true) {
    mockHasPermission.mockImplementation((slug: string) => {
      if (slug === 'compliance.config') return configPerm;
      if (slug === 'compliance.read')   return readPerm;
      return false;
    });
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    mockListRetentionPolicies.mockResolvedValue({ data: MOCK_POLICIES });
    return render(<RetentionPoliciesPage />);
  }

  it('(a) shows access-denied when no permissions', () => {
    mockHasPermission.mockReturnValue(false);
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    render(<RetentionPoliciesPage />);
    expect(screen.getByRole('alert')).toHaveTextContent(/access denied/i);
    expect(mockListRetentionPolicies).not.toHaveBeenCalled();
  });

  it('(b) lists policies with required columns', async () => {
    setupRetention();
    await waitFor(() => expect(screen.getByText('audit.authentication')).toBeInTheDocument());
    expect(screen.getByText('365')).toBeInTheDocument();
  });

  it('(c) Edit button visible with compliance.config permission', async () => {
    setupRetention(true, true);
    const editBtns = await screen.findAllByRole('button', { name: /edit/i });
    expect(editBtns.length).toBeGreaterThanOrEqual(1);
  });

  it('(d) Edit button hidden when only compliance.read (no config)', async () => {
    setupRetention(false, true);
    await waitFor(() => expect(screen.getByText('audit.authentication')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /edit/i })).not.toBeInTheDocument();
  });

  it('(e) clicking Edit shows form; clicking "Update Policy" shows confirmation dialog', async () => {
    const user = userEvent.setup();
    setupRetention();
    const [firstEdit] = await screen.findAllByRole('button', { name: /edit/i });
    await user.click(firstEdit!);
    expect(await screen.findByLabelText(/minimum retention days/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /update policy/i }));
    expect(await screen.findByRole('dialog', { name: /confirm retention policy change/i })).toBeInTheDocument();
  });

  it('(f) confirmation dialog shows before/after values', async () => {
    const user = userEvent.setup();
    setupRetention();
    const [firstEdit] = await screen.findAllByRole('button', { name: /edit/i });
    await user.click(firstEdit!);
    const daysInput = await screen.findByLabelText(/minimum retention days/i);
    fireEvent.change(daysInput, { target: { value: '730' } });
    await user.click(screen.getByRole('button', { name: /update policy/i }));
    const dialog = await screen.findByRole('dialog');
    expect(dialog.textContent).toContain('365');
    expect(dialog.textContent).toContain('730');
  });

  it('(g) cancelling confirmation makes no API call', async () => {
    const user = userEvent.setup();
    setupRetention();
    const [firstEdit] = await screen.findAllByRole('button', { name: /edit/i });
    await user.click(firstEdit!);
    await user.click(await screen.findByRole('button', { name: /update policy/i }));
    await screen.findByRole('dialog');
    mockUpdateRetentionPolicy.mockClear();
    await user.click(screen.getByRole('button', { name: /^cancel$/i }));
    expect(mockUpdateRetentionPolicy).not.toHaveBeenCalled();
    expect(screen.queryByRole('dialog', { name: /confirm/i })).not.toBeInTheDocument();
  });

  it('(h) confirming calls updateRetentionPolicy', async () => {
    mockUpdateRetentionPolicy.mockResolvedValue({ data: { data: MOCK_POLICIES[0] } });
    const user = userEvent.setup();
    setupRetention();
    const [firstEdit] = await screen.findAllByRole('button', { name: /edit/i });
    await user.click(firstEdit!);
    await user.click(await screen.findByRole('button', { name: /update policy/i }));
    await screen.findByRole('dialog');
    await user.click(screen.getByRole('button', { name: /^confirm$/i }));
    await waitFor(() => expect(mockUpdateRetentionPolicy).toHaveBeenCalledWith(
      'pol-001',
      expect.objectContaining({ minimum_retention_days: 365, post_retention_action: 'archive' }),
    ));
  });

  it('(i) Edit button disabled for policy with active hold (FR-016)', async () => {
    setupRetention();
    await waitFor(() => expect(screen.getByText('transaction.records')).toBeInTheDocument());
    const [btn0, btn1] = screen.getAllByRole('button', { name: /edit/i });
    expect(btn0!).not.toBeDisabled();
    expect(btn1!).toBeDisabled();
  });
});
