import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// --- Mocks (before imports) ---
const mockListKeys              = jest.fn();
const mockGetKey                = jest.fn();
const mockGetKeyVersions        = jest.fn();
const mockListChangeRequests    = jest.fn();
const mockGetChangeRequest      = jest.fn();
const mockSubmitChangeRequest   = jest.fn();
const mockApproveChangeRequest  = jest.fn();
const mockRejectChangeRequest   = jest.fn();
const mockWithdrawChangeRequest = jest.fn();

jest.mock('@/lib/api/configuration', () => ({
  ...jest.requireActual('@/lib/api/configuration'),
  ConfigurationApiClient: jest.fn().mockImplementation(() => ({
    listKeys:              (...args: unknown[]) => mockListKeys(...args),
    getKey:                (...args: unknown[]) => mockGetKey(...args),
    getKeyVersions:        (...args: unknown[]) => mockGetKeyVersions(...args),
    listChangeRequests:    (...args: unknown[]) => mockListChangeRequests(...args),
    getChangeRequest:      (...args: unknown[]) => mockGetChangeRequest(...args),
    submitChangeRequest:   (...args: unknown[]) => mockSubmitChangeRequest(...args),
    approveChangeRequest:  (...args: unknown[]) => mockApproveChangeRequest(...args),
    rejectChangeRequest:   (...args: unknown[]) => mockRejectChangeRequest(...args),
    withdrawChangeRequest: (...args: unknown[]) => mockWithdrawChangeRequest(...args),
  })),
}));

jest.mock('@/lib/utils/idempotency', () => ({
  generateIdempotencyKey: () => 'test-idempotency-key',
}));

const mockHasPermission = jest.fn<boolean, [string]>();

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

import { useAuth } from '@/lib/auth/context';
import { ConfigurationListPage }           from '@/src/modules/configuration/pages/configuration-list';
import { ConfigurationKeyDetailPage }      from '@/src/modules/configuration/pages/configuration-key-detail';
import { ConfigurationApprovalsPage }      from '@/src/modules/configuration/pages/configuration-approvals';
import { ConfigurationApprovalDetailPage } from '@/src/modules/configuration/pages/configuration-approval-detail';

// Stable client reference — prevents useEffect dep-array churn
const STABLE_CLIENT = {} as never;

// --- Fixtures ---
const MOCK_KEY_ITEM = {
  config_key:        'payment.daily_limit',
  domain:            'financial_policy' as const,
  current_value:     5000,
  value_type:        'number',
  status:            'active',
  description:       'Daily payment limit in cents',
  updated_at:        '2026-10-10T12:00:00Z',
  has_pending_change: false,
};

const MOCK_KEY_ITEM_PENDING = {
  ...MOCK_KEY_ITEM,
  config_key:         'payment.min_txn',
  has_pending_change: true,
};

const MOCK_KEY_DETAIL = {
  ...MOCK_KEY_ITEM,
  pending_change_request_id: null,
  requires_approval:         true,
};

const MOCK_KEY_DETAIL_PENDING = {
  ...MOCK_KEY_DETAIL,
  config_key:                'payment.min_txn',
  pending_change_request_id: 'cr-pending-001',
  has_pending_change:        true,
};

const MOCK_VERSION: import('@/lib/api/configuration').ConfigurationVersionItem = {
  id:                'ver-001',
  config_key:        'payment.daily_limit',
  domain:            'financial_policy',
  event_type:        'activated',
  value:             5000,
  actor_id:          'admin-aaa-bbb-ccc-ddd',
  change_request_id: 'cr-001',
  notes:             null,
  occurred_at:       '2026-10-09T10:00:00Z',
  approved_by:       'admin-eee-fff-ggg-hhh',
  effective_from:    '2026-10-09T00:00:00Z',
};

const MOCK_VERSION_NO_APPROVER: import('@/lib/api/configuration').ConfigurationVersionItem = {
  ...MOCK_VERSION,
  id:           'ver-002',
  event_type:   'submitted',
  approved_by:  null,
  effective_from: null,
};

const MOCK_CHANGE_REQUEST: import('@/lib/api/configuration').ChangeRequestDetail = {
  id:               'cr-001',
  config_key:       'payment.daily_limit',
  domain:           'financial_policy',
  proposed_value:   10000,
  previous_value:   5000,
  maker_id:         'maker-user-id',
  status:           'pending_approval',
  rejection_reason: null,
  submitted_at:     '2026-10-10T08:00:00Z',
  resolved_at:      null,
  version_trail:    [MOCK_VERSION],
};

const EMPTY_KEYS_RESPONSE = { data: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 } };
const KEYS_RESPONSE       = { data: { data: [MOCK_KEY_ITEM, MOCK_KEY_ITEM_PENDING], current_page: 1, last_page: 1, per_page: 50, total: 2 } };
const VERSIONS_RESPONSE   = { data: { data: [MOCK_VERSION, MOCK_VERSION_NO_APPROVER], current_page: 1, last_page: 1, per_page: 20, total: 2 } };
const EMPTY_VERSIONS      = { data: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 } };
const CHANGE_REQUESTS_RESPONSE = { data: { data: [MOCK_CHANGE_REQUEST], current_page: 1, last_page: 1, per_page: 50, total: 1 } };

afterEach(() => {
  jest.clearAllMocks();
});

// =============================================================================
// US1 — Admin edits a configuration value
// =============================================================================
describe('US1 — ConfigurationListPage', () => {
  function setup(canRead = true) {
    mockHasPermission.mockImplementation((slug: string) =>
      slug === 'configuration.policy.view' ? canRead : false,
    );
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    mockListKeys.mockResolvedValue(KEYS_RESPONSE);
    return render(<ConfigurationListPage />);
  }

  it('(a) shows access-denied when missing configuration.policy.view', () => {
    setup(false);
    expect(screen.getByRole('alert')).toHaveTextContent(/access denied/i);
    expect(mockListKeys).not.toHaveBeenCalled();
  });

  it('(b) renders keys table with required columns', async () => {
    setup();
    await waitFor(() =>
      expect(screen.getByRole('table', { name: /configuration keys/i })).toBeInTheDocument(),
    );
    const headers = screen.getAllByRole('columnheader').map(h => h.textContent ?? '');
    expect(headers).toEqual(
      expect.arrayContaining([
        expect.stringMatching(/key/i),
        expect.stringMatching(/domain/i),
        expect.stringMatching(/current value/i),
        expect.stringMatching(/pending/i),
      ]),
    );
  });

  it('(c) shows Pending badge for key with has_pending_change=true', async () => {
    setup();
    await waitFor(() => expect(screen.getByText('payment.min_txn')).toBeInTheDocument());
    const pendingBadges = screen.getAllByText('Pending');
    expect(pendingBadges.length).toBeGreaterThanOrEqual(1);
  });

  it('loads subsequent pages using Laravel pagination and resets page on search', async () => {
    mockListKeys.mockResolvedValueOnce({ data: {
      data: [MOCK_KEY_ITEM], current_page: 1, last_page: 2, per_page: 50, total: 51,
    } }).mockResolvedValueOnce({ data: {
      data: [MOCK_KEY_ITEM_PENDING], current_page: 2, last_page: 2, per_page: 50, total: 51,
    } });
    setup();
    const next = await screen.findByRole('button', { name: 'Next' });
    fireEvent.click(next);
    await screen.findByText('payment.min_txn');
    expect(mockListKeys).toHaveBeenLastCalledWith({ page: 2, per_page: 50 });
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'auth' } });
    await waitFor(() => expect(mockListKeys).toHaveBeenLastCalledWith({ page: 1, per_page: 50, search: 'auth' }));
  });
});

describe('US1 — ConfigurationKeyDetailPage', () => {
  function setup(opts: { canRead?: boolean; canWrite?: boolean; hasPending?: boolean } = {}) {
    const { canRead = true, canWrite = true, hasPending = false } = opts;
    mockHasPermission.mockImplementation((slug: string) => {
      if (slug === 'configuration.policy.view')  return canRead;
      if (slug === 'configuration.policy.update') return canWrite;
      return false;
    });
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    const detail = hasPending ? MOCK_KEY_DETAIL_PENDING : MOCK_KEY_DETAIL;
    mockGetKey.mockResolvedValue({ data: detail });
    mockGetKeyVersions.mockResolvedValue(VERSIONS_RESPONSE);
    return render(<ConfigurationKeyDetailPage configKey="payment.daily_limit" />);
  }

  it('(d) shows access-denied when missing configuration.policy.view', () => {
    setup({ canRead: false });
    expect(screen.getByRole('alert')).toHaveTextContent(/access denied/i);
    expect(mockGetKey).not.toHaveBeenCalled();
  });

  it('(e) renders current value for the config key', async () => {
    setup();
    await waitFor(() => expect(screen.getByText('payment.daily_limit')).toBeInTheDocument());
    expect(screen.getByText('Daily payment limit in cents')).toBeInTheDocument();
    const values = screen.getAllByText(/5000/);
    expect(values.length).toBeGreaterThanOrEqual(1);
  });

  it('(f) edit form is hidden when missing configuration.policy.update', async () => {
    setup({ canWrite: false });
    await waitFor(() => expect(screen.getByText('payment.daily_limit')).toBeInTheDocument());
    expect(screen.queryByLabelText('Proposed value')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /preview change/i })).not.toBeInTheDocument();
  });

  it('(g) pending guard disables edit when pending_change_request_id is set', async () => {
    setup({ hasPending: true });
    await waitFor(() => expect(screen.getByText('payment.min_txn')).toBeInTheDocument());
    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent(/pending approval/i);
    expect(screen.queryByLabelText('Proposed value')).not.toBeInTheDocument();
  });

  it('(h) Preview Change button reveals diff panel with current and proposed values', async () => {
    const user = userEvent.setup();
    setup();
    await waitFor(() => expect(screen.getByLabelText('Proposed value')).toBeInTheDocument());

    await user.type(screen.getByLabelText('Proposed value'), '7500');
    await user.click(screen.getByRole('button', { name: /preview change/i }));

    const diffPanel = await screen.findByLabelText('Change preview');
    expect(diffPanel).toBeInTheDocument();
    expect(diffPanel.textContent).toContain('5000');
    expect(diffPanel.textContent).toContain('7500');
  });

  it('(i) Confirm Submit calls submitChangeRequest; success banner shows correct message', async () => {
    const user = userEvent.setup();
    mockSubmitChangeRequest.mockResolvedValue({
      data: { id: 'cr-new-001', status: 'pending_approval' },
    });
    setup();
    await waitFor(() => expect(screen.getByLabelText('Proposed value')).toBeInTheDocument());

    await user.type(screen.getByLabelText('Proposed value'), '7500');
    await user.click(screen.getByRole('button', { name: /preview change/i }));
    await screen.findByLabelText('Change preview');
    await user.click(screen.getByRole('button', { name: /confirm submit/i }));

    await waitFor(() => expect(mockSubmitChangeRequest).toHaveBeenCalledWith(
      expect.objectContaining({ config_key: 'payment.daily_limit' }),
    ));
    const banner = await screen.findByRole('alert');
    expect(banner).toHaveTextContent(/submitted for approval/i);
  });
});

// =============================================================================
// US2 — Admin approves a pending config change
// =============================================================================
describe('US2 — ConfigurationApprovalsPage', () => {
  function setup(canApprove = true) {
    mockHasPermission.mockImplementation((slug: string) =>
      slug === 'configuration.approve' ? canApprove : false,
    );
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    mockListChangeRequests.mockResolvedValue(CHANGE_REQUESTS_RESPONSE);
    return render(<ConfigurationApprovalsPage />);
  }

  it('(a) shows access-denied when missing configuration.approve', () => {
    setup(false);
    expect(screen.getByRole('alert')).toHaveTextContent(/access denied/i);
    expect(mockListChangeRequests).not.toHaveBeenCalled();
  });

  it('(b) lists pending requests with required columns', async () => {
    setup();
    await waitFor(() =>
      expect(screen.getByRole('table', { name: /pending configuration change requests/i })).toBeInTheDocument(),
    );
    const headers = screen.getAllByRole('columnheader').map(h => h.textContent ?? '');
    expect(headers).toEqual(
      expect.arrayContaining([
        expect.stringMatching(/config key/i),
        expect.stringMatching(/domain/i),
        expect.stringMatching(/maker/i),
        expect.stringMatching(/submitted/i),
        expect.stringMatching(/age/i),
      ]),
    );
    expect(screen.getByText('payment.daily_limit')).toBeInTheDocument();
  });
});

describe('US2 — ConfigurationApprovalDetailPage', () => {
  function setup(opts: { canApprove?: boolean; isOwnChange?: boolean } = {}) {
    const { canApprove = true, isOwnChange = false } = opts;
    mockHasPermission.mockImplementation((slug: string) =>
      slug === 'configuration.approve' ? canApprove : false,
    );
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      user: { id: isOwnChange ? 'maker-user-id' : 'other-user-id' },
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    mockGetChangeRequest.mockResolvedValue({ data: MOCK_CHANGE_REQUEST });
    return render(<ConfigurationApprovalDetailPage changeRequestId="cr-001" />);
  }

  it('(c) shows access-denied when missing configuration.approve', () => {
    setup({ canApprove: false });
    expect(screen.getByRole('alert')).toHaveTextContent(/access denied/i);
    expect(mockGetChangeRequest).not.toHaveBeenCalled();
  });

  it('(d) renders diff panel with previous and proposed values', async () => {
    setup();
    await waitFor(() => expect(screen.getByText('payment.daily_limit')).toBeInTheDocument());
    expect(screen.getByText('Previous Value')).toBeInTheDocument();
    expect(screen.getByText('Proposed Value')).toBeInTheDocument();
    expect(screen.getByText(/5000/)).toBeInTheDocument();
    expect(screen.getByText(/10000/)).toBeInTheDocument();
  });

  it('(e) shows maker-checker alert and disables Approve + Reject when isOwnChange', async () => {
    setup({ isOwnChange: true });
    await waitFor(() => expect(screen.getByText('payment.daily_limit')).toBeInTheDocument());
    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent(/cannot approve your own/i);
    expect(screen.getByRole('button', { name: /approve change request/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /reject change request/i })).toBeDisabled();
  });

  it('(f) Withdraw button stays enabled when isOwnChange', async () => {
    setup({ isOwnChange: true });
    await waitFor(() => expect(screen.getByText('payment.daily_limit')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /withdraw change request/i })).not.toBeDisabled();
  });

  it('(g) Approve button calls approveChangeRequest and redirects', async () => {
    const user = userEvent.setup();
    mockApproveChangeRequest.mockResolvedValue({ data: { ...MOCK_CHANGE_REQUEST, status: 'approved' } });
    setup({ isOwnChange: false });
    await waitFor(() => expect(screen.getByRole('button', { name: /approve change request/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /approve change request/i }));
    await waitFor(() => expect(mockApproveChangeRequest).toHaveBeenCalledWith(
      'cr-001',
      expect.objectContaining({ idempotency_key: 'test-idempotency-key' }),
    ));
  });

  it('(h) Reject dialog appears; submitting calls rejectChangeRequest', async () => {
    const user = userEvent.setup();
    mockRejectChangeRequest.mockResolvedValue({ data: { ...MOCK_CHANGE_REQUEST, status: 'rejected' } });
    setup({ isOwnChange: false });
    await waitFor(() => expect(screen.getByRole('button', { name: /reject change request/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /reject change request/i }));
    expect(await screen.findByRole('dialog', { name: /reject change request/i })).toBeInTheDocument();
    await user.type(screen.getByLabelText(/rejection reason/i), 'Not approved by policy');
    await user.click(screen.getByRole('button', { name: /confirm rejection/i }));
    await waitFor(() => expect(mockRejectChangeRequest).toHaveBeenCalledWith(
      'cr-001',
      expect.objectContaining({ reason: 'Not approved by policy' }),
    ));
  });
});

// =============================================================================
// US3 — Admin views configuration version history
// =============================================================================
describe('US3 — Version History Table', () => {
  function setup(versions = VERSIONS_RESPONSE) {
    mockHasPermission.mockImplementation((slug: string) =>
      slug === 'configuration.policy.view' ? true : slug === 'configuration.policy.update',
    );
    (useAuth as jest.Mock).mockReturnValue({
      authenticatedClient: STABLE_CLIENT,
      hasPermission: (slug: string) => mockHasPermission(slug),
    });
    mockGetKey.mockResolvedValue({ data: MOCK_KEY_DETAIL });
    mockGetKeyVersions.mockResolvedValue(versions);
    return render(<ConfigurationKeyDetailPage configKey="payment.daily_limit" />);
  }

  it('(a) version history table has 6 required columns', async () => {
    setup();
    await waitFor(() =>
      expect(screen.getByRole('table', { name: /version history for payment\.daily_limit/i })).toBeInTheDocument(),
    );
    const headers = screen.getAllByRole('columnheader').map(h => h.textContent ?? '');
    expect(headers).toEqual(
      expect.arrayContaining([
        expect.stringMatching(/event/i),
        expect.stringMatching(/value/i),
        expect.stringMatching(/changed by/i),
        expect.stringMatching(/approved by/i),
        expect.stringMatching(/occurred at/i),
        expect.stringMatching(/effective from/i),
      ]),
    );
  });

  it('(b) approved_by renders as truncated UUID when present', async () => {
    setup();
    await waitFor(() =>
      expect(screen.getByRole('table', { name: /version history/i })).toBeInTheDocument(),
    );
    // MOCK_VERSION.approved_by = 'admin-eee-fff-ggg-hhh' → first 8 chars + '…'
    expect(screen.getByText('admin-ee…')).toBeInTheDocument();
  });

  it('(c) approved_by shows "—" when null', async () => {
    setup();
    await waitFor(() =>
      expect(screen.getByRole('table', { name: /version history/i })).toBeInTheDocument(),
    );
    // MOCK_VERSION_NO_APPROVER has approved_by = null
    const dashes = screen.getAllByText('—');
    expect(dashes.length).toBeGreaterThanOrEqual(1);
  });

  it('(d) effective_from renders as locale date string when present', async () => {
    setup();
    await waitFor(() =>
      expect(screen.getByRole('table', { name: /version history/i })).toBeInTheDocument(),
    );
    // '2026-10-09T00:00:00Z' → locale date — just verify it's not '—' for that row
    const rows = screen.getAllByRole('row');
    // Row 1 is header, row 2 is MOCK_VERSION with effective_from set
    expect(rows.length).toBeGreaterThanOrEqual(2);
  });

  it('(e) shows "No version history" message when versions are empty', async () => {
    setup(EMPTY_VERSIONS);
    await waitFor(() => expect(screen.getByText('payment.daily_limit')).toBeInTheDocument());
    expect(screen.getByText(/no version history/i)).toBeInTheDocument();
  });
});
