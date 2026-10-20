/**
 * ADM-P1-007 — KYC Review & Decisions
 * US-P1-007-001: KYC reviewer views the review queue
 * US-P1-007-002: KYC reviewer inspects a submission
 * US-P1-007-003: KYC reviewer approves a submission
 * US-P1-007-004: KYC reviewer rejects a submission
 */
import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ back: jest.fn(), push: jest.fn(), replace: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

// ── API mocks ─────────────────────────────────────────────────────────────────

const mockListKyc      = jest.fn();
const mockGetKyc       = jest.fn();
const mockApproveKyc   = jest.fn();
const mockRejectKyc    = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    listKyc:    mockListKyc,
    getKyc:     mockGetKyc,
    approveKyc: mockApproveKyc,
    rejectKyc:  mockRejectKyc,
  })),
}));

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports ───────────────────────────────────────────────────────────────────

import { KycReviewPage } from '@/src/modules/kyc/pages/kyc-review';
import { KycDetailPage } from '@/src/modules/kyc/pages/kyc-detail';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

// ── Fixtures ──────────────────────────────────────────────────────────────────

const SUBMISSION_PENDING = {
  id: 'kyc-001',
  user_id: 'user-001',
  user_name: 'Alice Modise',
  user_phone_number: '+26771000001',
  tier_applied: 2,
  current_tier: 1,
  status: 'pending_review',
  document_type: 'national_id',
  submitted_at: '2026-10-10T09:00:00Z',
  document_urls: ['https://storage.example.com/doc1.jpg'],
  risk_score: 35,
  kyc_type: 'identity',
  rejection_reason: null,
  reviewed_at: null,
  personal_info: {
    full_legal_name: 'Alice B. Modise',   // different from user_name to avoid duplicate text
    date_of_birth: '1990-03-15',
    nationality: 'BW',
    address: { line_1: '1 Main St', city: 'Gaborone', country_code: 'BW' },
  },
  verification_results: [
    {
      provider_name: 'null_provider',
      outcome: 'manual_review_required',
      confidence_score: null,
      created_at: '2026-10-10T09:05:00Z',
    },
  ],
};

const SUBMISSION_UNDER_REVIEW = {
  ...SUBMISSION_PENDING,
  id: 'kyc-002',
  user_name: 'Bob Kgosi',
  user_phone_number: '+26771000002',   // distinct phone to avoid duplicate text in queue
  status: 'under_review',
  risk_score: 72,
};

const SUBMISSION_APPROVED = {
  ...SUBMISSION_PENDING,
  id: 'kyc-003',
  user_name: 'Carol Tau',
  user_phone_number: '+26771000003',
  status: 'approved',
  reviewed_at: '2026-10-11T10:00:00Z',
};

const PAGINATED_QUEUE = {
  data: [SUBMISSION_PENDING, SUBMISSION_UNDER_REVIEW],
  meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
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
    permissions: ['adminapp.kyc.review'],
    hasPermission: jest.fn((p: string) => p === 'adminapp.kyc.review'),
    login: jest.fn(),
    completeMfa: jest.fn(),
    signOut: jest.fn(),
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  setupAuth();
  mockListKyc.mockResolvedValue({ data: PAGINATED_QUEUE });
  mockGetKyc.mockResolvedValue({ data: SUBMISSION_PENDING });
  mockApproveKyc.mockResolvedValue({ data: { approval_id: 'appr-001', action_type: 'adminapp.kyc.approve', status: 'pending_approval', expires_at: '' } });
  mockRejectKyc.mockResolvedValue({ data: { approval_id: 'rej-001', action_type: 'adminapp.kyc.reject', status: 'pending_approval', expires_at: '' } });
});

// ── US-P1-007-001: Review queue ───────────────────────────────────────────────

describe('KycReviewPage — US-P1-007-001', () => {
  it('renders the page heading', async () => {
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getByText('KYC Review Queue')).toBeInTheDocument());
  });

  it('shows required queue columns', async () => {
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    const table = screen.getByRole('table', { name: /kyc submissions/i });
    const headers = within(table).getAllByRole('columnheader').map(h => h.textContent ?? '');
    const headerText = headers.join('|');
    expect(headerText).toMatch(/User Name/i);
    expect(headerText).toMatch(/KYC Type/i);
    expect(headerText).toMatch(/Risk Score/i);
    expect(headerText).toMatch(/Status/i);
    expect(headerText).toMatch(/Submitted/i);
  });

  it('renders user name and phone for each row', async () => {
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    expect(screen.getByText('Bob Kgosi')).toBeInTheDocument();
    expect(screen.getByText('+26771000001')).toBeInTheDocument();
  });

  it('normalizes the API root array and pagination meta response', async () => {
    mockListKyc.mockResolvedValueOnce({
      data: [SUBMISSION_PENDING],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getByText('Alice Modise')).toBeInTheDocument());
    expect(screen.getByText(/Page 1 of 1 · 1 total/)).toBeInTheDocument();
  });

  it('renders KYC status badges', async () => {
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getByText('PENDING REVIEW')).toBeInTheDocument());
    expect(screen.getByText('UNDER REVIEW')).toBeInTheDocument();
  });

  it('renders risk score badges', async () => {
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getByText('35')).toBeInTheDocument());
    expect(screen.getByText('72')).toBeInTheDocument();
  });

  it('renders a View button per row', async () => {
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    const viewButtons = screen.getAllByRole('button', { name: /view kyc submission/i });
    expect(viewButtons.length).toBe(2);
  });

  it('has a Status filter with Under Review and Escalated options', async () => {
    render(<KycReviewPage />);
    const statusSelect = screen.getByLabelText(/^Status$/i);
    const options = within(statusSelect as HTMLElement).getAllByRole('option').map(o => o.textContent ?? '');
    expect(options).toContain('Pending');
    expect(options).toContain('Under Review');
    expect(options).toContain('Escalated');
  });

  it('has a Tier filter', async () => {
    render(<KycReviewPage />);
    const tierSelect = screen.getByLabelText(/^Tier$/i);
    const options = within(tierSelect as HTMLElement).getAllByRole('option').map(o => o.textContent ?? '');
    expect(options).toContain('Tier 1');
    expect(options).toContain('Tier 2');
    expect(options).toContain('Tier 3');
  });

  it('has date range filters', async () => {
    render(<KycReviewPage />);
    expect(screen.getByLabelText(/^From$/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/^To$/i)).toBeInTheDocument();
  });

  it('has sortable Submitted and Risk Score columns', async () => {
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    expect(screen.getByRole('button', { name: /sort by submission date/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sort by risk score/i })).toBeInTheDocument();
  });

  it('passes status filter param to API', async () => {
    render(<KycReviewPage />);
    const statusSelect = screen.getByLabelText(/^Status$/i);
    await userEvent.selectOptions(statusSelect, 'under_review');
    await userEvent.click(screen.getByRole('button', { name: /apply filters/i }));
    await waitFor(() =>
      expect(mockListKyc).toHaveBeenCalledWith(
        expect.objectContaining({ status: 'under_review' }),
      ),
    );
  });

  it('shows empty state when queue is empty', async () => {
    mockListKyc.mockResolvedValueOnce({ data: { data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } } });
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getByText(/no kyc submissions/i)).toBeInTheDocument());
  });

  it('shows error state with retry when API fails', async () => {
    mockListKyc.mockRejectedValueOnce(new Error('Network error'));
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getByText(/failed to load kyc queue/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });

  it('shows pagination controls', async () => {
    render(<KycReviewPage />);
    await waitFor(() => expect(screen.getByRole('button', { name: /previous page/i })).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /next page/i })).toBeInTheDocument();
  });
});

// ── US-P1-007-002: Inspect a submission ──────────────────────────────────────

describe('KycDetailPage — US-P1-007-002', () => {
  it('renders the page heading with back button', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getByText('KYC Submission Detail')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /back to kyc queue/i })).toBeInTheDocument();
  });

  it('renders submission metadata', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    expect(screen.getByText('+26771000001')).toBeInTheDocument();
    expect(screen.getByText('Tier 2')).toBeInTheDocument();
    expect(screen.getByText(/national id/i)).toBeInTheDocument();
  });

  it('renders personal information section when available', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getByRole('region', { name: /personal information/i })).toBeInTheDocument());
    // full_legal_name is 'Alice B. Modise' (distinct from user_name 'Alice Modise')
    expect(screen.getByText('Alice B. Modise')).toBeInTheDocument();
    expect(screen.getByText('Gaborone')).toBeInTheDocument();
  });

  it('renders identity documents section', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getByRole('region', { name: /identity documents/i })).toBeInTheDocument());
  });

  it('renders AI pre-screen results section', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getByRole('region', { name: /ai pre-screen results/i })).toBeInTheDocument());
    expect(screen.getByText(/manual review required/i)).toBeInTheDocument();
  });

  it('renders risk score when present', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getByText('35')).toBeInTheDocument());
  });

  it('shows loading state', () => {
    mockGetKyc.mockReturnValue(new Promise(() => undefined));
    render(<KycDetailPage submissionId="kyc-001" />);
    expect(screen.getByLabelText(/loading kyc submission/i)).toBeInTheDocument();
  });

  it('shows error state with retry when fetch fails', async () => {
    mockGetKyc.mockRejectedValueOnce(new Error('Server error'));
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getByText(/failed to load kyc submission/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });
});

// ── US-P1-007-003: Approve a submission ──────────────────────────────────────

describe('KycDetailPage — US-P1-007-003', () => {
  it('shows Approve and Reject buttons for pending_review submissions', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    expect(screen.getByRole('button', { name: /approve this kyc submission/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /reject this kyc submission/i })).toBeInTheDocument();
  });

  it('shows Approve and Reject buttons for under_review submissions', async () => {
    mockGetKyc.mockResolvedValueOnce({ data: SUBMISSION_UNDER_REVIEW });
    render(<KycDetailPage submissionId="kyc-002" />);
    await waitFor(() => expect(screen.getByText('Bob Kgosi')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /approve this kyc submission/i })).toBeInTheDocument();
  });

  it('does NOT show Approve/Reject buttons for already-decided submissions', async () => {
    mockGetKyc.mockResolvedValueOnce({ data: SUBMISSION_APPROVED });
    render(<KycDetailPage submissionId="kyc-003" />);
    await waitFor(() => expect(screen.getByText('Carol Tau')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /approve this kyc submission/i })).not.toBeInTheDocument();
  });

  it('opens approve confirmation dialog when Approve is clicked', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    await userEvent.click(screen.getByRole('button', { name: /approve this kyc submission/i }));
    const dialog = screen.getByRole('dialog');
    expect(dialog).toBeInTheDocument();
    expect(within(dialog).getByText('Approve KYC Submission')).toBeInTheDocument();
  });

  it('calls approveKyc with idempotency key when Confirm Approval is clicked', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));

    await userEvent.click(screen.getByRole('button', { name: /approve this kyc submission/i }));
    const dialog = screen.getByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: /confirm kyc approval/i }));

    await waitFor(() =>
      expect(mockApproveKyc).toHaveBeenCalledWith(
        'kyc-001',
        expect.any(String),
      ),
    );
  });

  it('shows success message after approval', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));

    await userEvent.click(screen.getByRole('button', { name: /approve this kyc submission/i }));
    await userEvent.click(screen.getByRole('button', { name: /confirm kyc approval/i }));

    await waitFor(() =>
      expect(screen.getByRole('status')).toHaveTextContent(/approval submitted/i),
    );
  });

  it('shows already-decided message when API returns ADMINAPP_KYC_002', async () => {
    const err = Object.assign(new Error('Already decided'), { code: 'ADMINAPP_KYC_002' });
    mockApproveKyc.mockRejectedValueOnce(err);

    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));

    await userEvent.click(screen.getByRole('button', { name: /approve this kyc submission/i }));
    await userEvent.click(screen.getByRole('button', { name: /confirm kyc approval/i }));

    await waitFor(() =>
      expect(screen.getByText(/already been decided/i)).toBeInTheDocument(),
    );
  });
});

// ── US-P1-007-004: Reject a submission ───────────────────────────────────────

describe('KycDetailPage — US-P1-007-004', () => {
  it('opens reject dialog when Reject is clicked', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    await userEvent.click(screen.getByRole('button', { name: /reject this kyc submission/i }));
    const dialog = screen.getByRole('dialog');
    expect(dialog).toBeInTheDocument();
    expect(within(dialog).getByText('Reject KYC Submission')).toBeInTheDocument();
  });

  it('reject dialog has a reason code select', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    await userEvent.click(screen.getByRole('button', { name: /reject this kyc submission/i }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByLabelText(/reason code/i)).toBeInTheDocument();
  });

  it('Confirm Rejection button is disabled until reason code is selected', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));
    await userEvent.click(screen.getByRole('button', { name: /reject this kyc submission/i }));
    const dialog = screen.getByRole('dialog');
    const confirmBtn = within(dialog).getByRole('button', { name: /confirm kyc rejection/i });
    expect(confirmBtn).toBeDisabled();
  });

  it('calls rejectKyc with reason when Confirm Rejection is clicked', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));

    await userEvent.click(screen.getByRole('button', { name: /reject this kyc submission/i }));
    const dialog = screen.getByRole('dialog');
    await userEvent.selectOptions(within(dialog).getByLabelText(/reason code/i), 'expired_document');
    await userEvent.click(within(dialog).getByRole('button', { name: /confirm kyc rejection/i }));

    await waitFor(() =>
      expect(mockRejectKyc).toHaveBeenCalledWith(
        'kyc-001',
        expect.stringContaining('Document is expired'),
        expect.any(String),
      ),
    );
  });

  it('appends notes to reason when notes are filled', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));

    await userEvent.click(screen.getByRole('button', { name: /reject this kyc submission/i }));
    const dialog = screen.getByRole('dialog');
    await userEvent.selectOptions(within(dialog).getByLabelText(/reason code/i), 'other');
    await userEvent.type(within(dialog).getByLabelText(/additional notes/i), 'Missing signature page');
    await userEvent.click(within(dialog).getByRole('button', { name: /confirm kyc rejection/i }));

    await waitFor(() =>
      expect(mockRejectKyc).toHaveBeenCalledWith(
        'kyc-001',
        expect.stringContaining('Missing signature page'),
        expect.any(String),
      ),
    );
  });

  it('shows success message after rejection', async () => {
    render(<KycDetailPage submissionId="kyc-001" />);
    await waitFor(() => expect(screen.getAllByText('Alice Modise').length).toBeGreaterThan(0));

    await userEvent.click(screen.getByRole('button', { name: /reject this kyc submission/i }));
    const dialog = screen.getByRole('dialog');
    await userEvent.selectOptions(within(dialog).getByLabelText(/reason code/i), 'poor_quality');
    await userEvent.click(within(dialog).getByRole('button', { name: /confirm kyc rejection/i }));

    await waitFor(() =>
      expect(screen.getByRole('status')).toHaveTextContent(/rejection submitted/i),
    );
  });
});
