/**
 * ADM-P1-015 — Reports & Analytics
 * US-P1-015-001: Admin generates an on-demand report
 * US-P1-015-002: Admin manages scheduled reports
 * US-P1-015-003: Admin downloads a completed report
 * US-P1-015-004: Admin views the reconciliation report
 */
import React from 'react';
import { render, screen, waitFor, act, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// ── Navigation mock ───────────────────────────────────────────────────────────

const mockRouterPush = jest.fn();

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: mockRouterPush, replace: jest.fn(), back: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

// ── Reports API mocks (must precede component imports) ────────────────────────

const mockRequestExport              = jest.fn();
const mockGetExport                  = jest.fn();
const mockSubmitReportJob            = jest.fn();
const mockGetReportJob               = jest.fn();
const mockUpdateSchedule             = jest.fn();
const mockDeriveReconciliationSummary = jest.fn();
const mockNormaliseJobStatus         = jest.fn((s: string) => s);

jest.mock('@/src/modules/reports/api/reports', () => ({
  requestExport:              (...args: unknown[]) => mockRequestExport(...args),
  getExport:                  (...args: unknown[]) => mockGetExport(...args),
  submitReportJob:            (...args: unknown[]) => mockSubmitReportJob(...args),
  getReportJob:               (...args: unknown[]) => mockGetReportJob(...args),
  updateSchedule:             (...args: unknown[]) => mockUpdateSchedule(...args),
  deriveReconciliationSummary:(...args: unknown[]) => mockDeriveReconciliationSummary(...args),
  normaliseJobStatus:         (s: string) => mockNormaliseJobStatus(s),
}));

// ── AdminAppClient mock ───────────────────────────────────────────────────────

const mockCreateReport      = jest.fn();
const mockGetReport         = jest.fn();
const mockListReconciliation = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => ({
  AdminAppClient: jest.fn().mockImplementation(() => ({
    createReport:       (...args: unknown[]) => mockCreateReport(...args),
    getReport:          (...args: unknown[]) => mockGetReport(...args),
    listReconciliation: (...args: unknown[]) => mockListReconciliation(...args),
  })),
}));

// ── Auth mock ─────────────────────────────────────────────────────────────────

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports (after mocks) ─────────────────────────────────────────────────────

import { ReportListPage, statusVariant }    from '@/src/modules/reports/pages/report-list';
import { ReportJobDetailPage }              from '@/src/modules/reports/pages/report-job-detail';
import { ReportScheduleListPage }           from '@/src/modules/reports/pages/report-schedule-list';
import { ReconciliationReportPage }         from '@/src/modules/reports/pages/reconciliation-report';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

// ── Fixtures ──────────────────────────────────────────────────────────────────

const MOCK_JOB_COMPLETE: import('@/src/modules/reports/api/reports').ReportJob = {
  id:             'job-001',
  report_type:    'transaction_summary',
  status:         'complete',
  row_count:      42,
  failure_reason: null,
  filters:        { date_range_start: '2026-10-01', date_range_end: '2026-10-07' },
  result_data:    null,
  as_of_at:       null,
  created_at:     '2026-10-07T10:00:00Z',
  started_at:     '2026-10-07T10:00:05Z',
  completed_at:   '2026-10-07T10:01:00Z',
};

const MOCK_JOB_FAILED: import('@/src/modules/reports/api/reports').ReportJob = { ...MOCK_JOB_COMPLETE, id: 'job-002', status: 'failed', failure_reason: 'Timeout processing data', completed_at: null };
const MOCK_JOB_PROCESSING: import('@/src/modules/reports/api/reports').ReportJob = { ...MOCK_JOB_COMPLETE, id: 'job-003', status: 'processing', completed_at: null };

const ACTIVE_SCHEDULE = {
  id:                         'sched-001',
  name:                       'Daily Ops',
  report_type:                'transaction-report',
  cadence:                    'daily' as const,
  cadence_time:               '06:00',
  cadence_day_of_week:        null,
  cadence_day_of_month:       null,
  next_run_at:                '2026-10-15T06:00:00Z',
  status:                     'active' as const,
  recipients:                 [],
  email_notification_enabled: false,
};

const INACTIVE_SCHEDULE = { ...ACTIVE_SCHEDULE, id: 'sched-002', name: 'Weekly Summary', status: 'inactive' as const, next_run_at: null };

const BATCH_1 = {
  batch_id:                        'batch-001',
  period_start:                    '2026-10-01',
  period_end:                      '2026-10-07',
  status:                          'completed',
  total_transactions:              100,
  matched_count:                   95,
  unmatched_count:                 5,
  discrepancy_amount_minor_units:  25000,
  currency_code:                   'BWP',
  run_at:                          null,
  completed_at:                    null,
};

const BATCH_2 = { ...BATCH_1, batch_id: 'batch-002', total_transactions: 50, matched_count: 48, unmatched_count: 2, discrepancy_amount_minor_units: 5000 };

const MOCK_SUMMARY = {
  total_transactions:  150,
  matched_count:       143,
  unmatched_count:     7,
  exception_amount_bwp: 300.00,
  matched_pct:         95.3,
};

const MOCK_EXPORT_PENDING: import('@/src/modules/reports/api/reports').ReportExport = {
  id: 'exp-001', job_id: 'job-001', format: 'csv', status: 'pending',
  file_size_bytes: null, row_count: null, download_url: null,
  token_expires_at: null, file_retention_expires_at: null,
};

const MOCK_EXPORT_READY = { ...MOCK_EXPORT_PENDING, status: 'ready' as const, download_url: 'https://dl.example.com/report.csv' };

// ── Setup helpers ─────────────────────────────────────────────────────────────

function setupAuth(overrides: Partial<{ hasPermissionResult: boolean; permissions: string[] }> = {}) {
  const permissions = overrides.permissions ?? ['adminapp.reports.read', 'admin.read', 'reports.schedule'];
  mockUseAuth.mockReturnValue({
    authenticatedClient: MOCK_CLIENT,
    hasPermission: (slug: string) => permissions.includes(slug),
    permissions,
    phase: 'authenticated',
    user: { id: 'admin-001', email: 'admin@olekard.local', name: 'Admin' },
    mfaMethods: null,
    isAuthenticated: true,
    authError: null,
    authErrorCode: null,
    login: jest.fn(),
    completeMfa: jest.fn(),
    signOut: jest.fn(),
  } as never);
}

beforeEach(() => {
  jest.clearAllMocks();
  mockRouterPush.mockClear();
  mockNormaliseJobStatus.mockImplementation((s: string) => {
    if (s === 'pending') return 'requested';
    if (s === 'ready')   return 'complete';
    return s;
  });
  setupAuth();
});

afterEach(() => {
  jest.useRealTimers();
});

// =============================================================================
// US-P1-015-001: Generate On-Demand Report
// =============================================================================

describe('US-P1-015-001 Generate On-Demand Report', () => {
  beforeEach(() => {
    // List returns empty by default so the table renders quickly
    (MOCK_CLIENT as Record<string, unknown>).get = jest.fn().mockResolvedValue({ data: [] });
  });

  it('(a) renders generate form when user has admin.read permission', async () => {
    render(<ReportListPage />);
    await waitFor(() => {
      expect(screen.getByText('Request New Report')).toBeInTheDocument();
    });
  });

  it('New Report focuses the existing request form without opening a record URL', async () => {
    render(<ReportListPage />);
    const type = await screen.findByLabelText('Report Type');
    await userEvent.click(screen.getByRole('button', { name: 'New Report' }));
    expect(type).toHaveFocus();
    expect(mockRouterPush).not.toHaveBeenCalled();
  });

  it('(b) hides generate form when user lacks admin.read permission', async () => {
    setupAuth({ permissions: ['adminapp.reports.read'] });
    render(<ReportListPage />);
    await waitFor(() => {
      expect(screen.queryByText('Request New Report')).not.toBeInTheDocument();
    });
  });

  it('(c) shows date validation error when period end is before period start', async () => {
    const user = userEvent.setup();
    render(<ReportListPage />);
    await waitFor(() => screen.getByText('Request New Report'));

    fireEvent.change(screen.getByLabelText('Period Start'), { target: { value: '2026-10-10' } });
    fireEvent.change(screen.getByLabelText('Period End'),   { target: { value: '2026-10-01' } });
    await user.click(screen.getByRole('button', { name: 'Request report' }));

    expect(screen.getByRole('alert')).toHaveTextContent('Period end must be on or after period start');
    expect(mockCreateReport).not.toHaveBeenCalled();
  });

  it('(d) calls createReport with correct payload on valid form submit', async () => {
    const user = userEvent.setup();
    mockCreateReport.mockResolvedValueOnce({ data: { report_id: 'job-new-01', status: 'requested' } });
    render(<ReportListPage />);
    await waitFor(() => screen.getByText('Request New Report'));

    fireEvent.change(screen.getByLabelText('Period Start'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('Period End'),   { target: { value: '2026-10-07' } });
    await user.click(screen.getByRole('button', { name: 'Request report' }));

    await waitFor(() => expect(mockCreateReport).toHaveBeenCalled());
    const [reportType, start, end] = mockCreateReport.mock.calls[0] as [string, string, string];
    expect(reportType).toBe('transaction_summary');
    expect(start).toBe('2026-10-01');
    expect(end).toBe('2026-10-07');
  });

  it('(e) redirects to /reports/{report_id} on successful submission', async () => {
    const user = userEvent.setup();
    mockCreateReport.mockResolvedValueOnce({ data: { report_id: 'job-new-01', status: 'requested' } });
    render(<ReportListPage />);
    await waitFor(() => screen.getByText('Request New Report'));

    fireEvent.change(screen.getByLabelText('Period Start'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('Period End'),   { target: { value: '2026-10-07' } });
    await user.click(screen.getByRole('button', { name: 'Request report' }));

    await waitFor(() => expect(mockRouterPush).toHaveBeenCalledWith('/reports/job-new-01'));
  });

  it('(f) shows 429-specific error message when concurrency limit exceeded', async () => {
    const user = userEvent.setup();
    mockCreateReport.mockRejectedValueOnce({ status: 429 });
    render(<ReportListPage />);
    await waitFor(() => screen.getByText('Request New Report'));

    fireEvent.change(screen.getByLabelText('Period Start'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('Period End'),   { target: { value: '2026-10-07' } });
    await user.click(screen.getByRole('button', { name: 'Request report' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('maximum number of active report jobs');
    });
  });

  it('(g) statusVariant returns success for both complete and ready', () => {
    expect(statusVariant('complete')).toBe('success');
    expect(statusVariant('ready')).toBe('success');
    expect(statusVariant('processing')).toBe('warning');
    expect(statusVariant('failed')).toBe('error');
    expect(statusVariant('requested')).toBe('info');
  });
});

// =============================================================================
// US-P1-015-003: Download Completed Report
// =============================================================================

describe('US-P1-015-003 Download Completed Report', () => {
  function setupDetailPage(job = MOCK_JOB_COMPLETE) {
    (MOCK_CLIENT as Record<string, unknown>).get = jest.fn().mockResolvedValue({ data: job });
    return render(<ReportJobDetailPage jobId={job.id} />);
  }

  it('(a) Export CSV button is enabled when job status is complete', async () => {
    setupDetailPage(MOCK_JOB_COMPLETE);
    const btn = await screen.findByRole('button', { name: 'Export as CSV' });
    expect(btn).not.toBeDisabled();
  });

  it('(b) Export CSV button is disabled when job status is processing', async () => {
    setupDetailPage(MOCK_JOB_PROCESSING);
    const btn = await screen.findByRole('button', { name: 'Export as CSV' });
    expect(btn).toBeDisabled();
  });

  it('(c) calls requestExport with csv format and correct jobId on click', async () => {
    const user = userEvent.setup();
    mockRequestExport.mockResolvedValueOnce(MOCK_EXPORT_PENDING);
    mockGetExport.mockResolvedValue(MOCK_EXPORT_READY);
    setupDetailPage(MOCK_JOB_COMPLETE);

    await user.click(await screen.findByRole('button', { name: 'Export as CSV' }));
    await waitFor(() => expect(mockRequestExport).toHaveBeenCalledWith(MOCK_CLIENT, 'job-001', 'csv'));
  });

  it('(d) shows Generating export… status while export is pending', async () => {
    const user = userEvent.setup();
    mockRequestExport.mockResolvedValueOnce(MOCK_EXPORT_PENDING);
    mockGetExport.mockReturnValue(new Promise(() => {})); // never resolves
    setupDetailPage(MOCK_JOB_COMPLETE);

    await user.click(await screen.findByRole('button', { name: 'Export as CSV' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Generating export'));
  });

  it('(e) shows failure reason when job status is failed', async () => {
    setupDetailPage(MOCK_JOB_FAILED);
    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('Timeout processing data');
    });
  });

  it('(f) shows Retry button when job status is failed', async () => {
    setupDetailPage(MOCK_JOB_FAILED);
    expect(await screen.findByRole('button', { name: 'Retry this report' })).toBeInTheDocument();
  });

  it('(g) calls submitReportJob with original report_type and filters on Retry click', async () => {
    const user = userEvent.setup();
    mockSubmitReportJob.mockResolvedValueOnce({ ...MOCK_JOB_COMPLETE, id: 'job-retry-01' });
    setupDetailPage(MOCK_JOB_FAILED);

    await user.click(await screen.findByRole('button', { name: 'Retry this report' }));
    await waitFor(() => expect(mockSubmitReportJob).toHaveBeenCalledWith(
      MOCK_CLIENT,
      expect.objectContaining({ report_type: 'transaction_summary' }),
    ));
    expect(mockRouterPush).toHaveBeenCalledWith('/reports/job-retry-01');
  });

  it('(h) job-status polling stops after 36 iterations and shows timeout message', async () => {
    jest.useFakeTimers();
    try {
      (MOCK_CLIENT as Record<string, unknown>).get = jest.fn().mockResolvedValue({ data: MOCK_JOB_PROCESSING });

      // Wrap render in act so initial fetchJob effect completes
      await act(async () => {
        render(<ReportJobDetailPage jobId="job-003" />);
        await Promise.resolve(); // flush promise microtasks
      });

      // 37 ticks needed: 36 increments (count → 36) then 1 final check (36 ≥ 36 → timeout)
      for (let i = 0; i < 37; i++) {
        await act(async () => {
          jest.advanceTimersByTime(5000);
          await Promise.resolve();
        });
      }

      expect(screen.getByRole('status')).toHaveTextContent('taking longer than expected');
    } finally {
      jest.useRealTimers();
    }
  }, 15000);

  it('(i) clicking Export when download_url is null creates a new export via requestExport', async () => {
    const user = userEvent.setup();
    const jobNoUrl = { ...MOCK_JOB_COMPLETE, download_url: null };
    mockRequestExport.mockResolvedValueOnce(MOCK_EXPORT_PENDING);
    mockGetExport.mockResolvedValue(MOCK_EXPORT_READY);
    setupDetailPage(jobNoUrl);

    await user.click(await screen.findByRole('button', { name: 'Export as CSV' }));
    await waitFor(() => expect(mockRequestExport).toHaveBeenCalledWith(MOCK_CLIENT, 'job-001', 'csv'));
  });
});

// =============================================================================
// US-P1-015-002: Manage Scheduled Reports
// =============================================================================

describe('US-P1-015-002 Manage Scheduled Reports', () => {
  function setupSchedulePage() {
    (MOCK_CLIENT as Record<string, unknown>).get    = jest.fn().mockResolvedValue({ data: [ACTIVE_SCHEDULE, INACTIVE_SCHEDULE] });
    (MOCK_CLIENT as Record<string, unknown>).delete = jest.fn().mockResolvedValue(undefined);
    (MOCK_CLIENT as Record<string, unknown>).post   = jest.fn().mockResolvedValue({ data: { ...ACTIVE_SCHEDULE, id: 'sched-new' } });
    return render(<ReportScheduleListPage />);
  }

  it('(a) renders schedule list with active and inactive schedules', async () => {
    setupSchedulePage();
    await waitFor(() => {
      expect(screen.getByText('Daily Ops')).toBeInTheDocument();
      expect(screen.getByText('Weekly Summary')).toBeInTheDocument();
    });
  });

  it('(b) shows Deactivate button only for active schedules', async () => {
    setupSchedulePage();
    await waitFor(() => screen.getByText('Daily Ops'));
    const deactivateBtns = screen.getAllByRole('button', { name: /Deactivate schedule/i });
    expect(deactivateBtns).toHaveLength(1);
    expect(deactivateBtns[0]).toHaveAccessibleName('Deactivate schedule Daily Ops');
  });

  it('(c) shows Re-activate button only for inactive schedules', async () => {
    setupSchedulePage();
    await waitFor(() => screen.getByText('Weekly Summary'));
    const reactivateBtns = screen.getAllByRole('button', { name: /Re-activate schedule/i });
    expect(reactivateBtns).toHaveLength(1);
    expect(reactivateBtns[0]).toHaveAccessibleName('Re-activate schedule Weekly Summary');
  });

  it('(d) calls DELETE /v1/reports/schedules/{id} on Deactivate click', async () => {
    const user = userEvent.setup();
    setupSchedulePage();
    await user.click(await screen.findByRole('button', { name: 'Deactivate schedule Daily Ops' }));
    await waitFor(() => {
      expect((MOCK_CLIENT as Record<string, jest.Mock>).delete).toHaveBeenCalledWith('/v1/reports/schedules/sched-001');
    });
  });

  it('(e) calls updateSchedule with status: active on Re-activate click', async () => {
    const user = userEvent.setup();
    mockUpdateSchedule.mockResolvedValueOnce({ ...INACTIVE_SCHEDULE, status: 'active' });
    setupSchedulePage();
    await user.click(await screen.findByRole('button', { name: 'Re-activate schedule Weekly Summary' }));
    await waitFor(() => {
      expect(mockUpdateSchedule).toHaveBeenCalledWith(
        MOCK_CLIENT,
        'sched-002',
        expect.objectContaining({ status: 'active' }),
      );
    });
  });

  it('(f) shows Create Schedule button only when hasPermission is true', async () => {
    setupSchedulePage();
    expect(await screen.findByRole('button', { name: 'Create Schedule' })).toBeInTheDocument();
  });

  it('(g) hides Create/Deactivate/Re-activate when user lacks schedule permission', async () => {
    setupAuth({ permissions: ['adminapp.reports.read'] });
    setupSchedulePage();
    await waitFor(() => screen.getByText('Daily Ops'));
    expect(screen.queryByRole('button', { name: 'Create Schedule' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Deactivate/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Re-activate/ })).not.toBeInTheDocument();
  });

  it('(h) weekly cadence shows day-of-week field; monthly shows day-of-month field', async () => {
    const user = userEvent.setup();
    setupSchedulePage();
    await user.click(await screen.findByRole('button', { name: 'Create Schedule' }));

    // Wait for Sheet contents to render
    const cadenceSelect = await screen.findByLabelText('Cadence');

    // Switch to weekly
    await user.selectOptions(cadenceSelect, 'weekly');
    await waitFor(() => expect(screen.getByLabelText(/Day of Week/i)).toBeInTheDocument());
    expect(screen.queryByLabelText(/Day of Month/i)).not.toBeInTheDocument();

    // Switch to monthly
    await user.selectOptions(cadenceSelect, 'monthly');
    await waitFor(() => expect(screen.getByLabelText(/Day of Month/i)).toBeInTheDocument());
    expect(screen.queryByLabelText(/Day of Week/i)).not.toBeInTheDocument();
  });
});

// =============================================================================
// US-P1-015-004: Reconciliation Report
// =============================================================================

describe('US-P1-015-004 Reconciliation Report', () => {
  function setupReconPage() {
    mockDeriveReconciliationSummary.mockReturnValue(MOCK_SUMMARY);
    mockListReconciliation.mockResolvedValue({ data: [BATCH_1, BATCH_2], meta: { current_page: 1, last_page: 1 } });
    return render(<ReconciliationReportPage />);
  }

  it('(a) date range form shows on load; table and KPI cards NOT shown before search', () => {
    setupReconPage();
    expect(screen.getByLabelText('From')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Search reconciliation batches' })).toBeInTheDocument();
    expect(screen.queryByText('Total Transactions')).not.toBeInTheDocument();
    expect(screen.queryByText('batch-001')).not.toBeInTheDocument();
  });

  it('(b) shows date validation error when to-date is before from-date; no API call made', async () => {
    const user = userEvent.setup();
    setupReconPage();
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-10' } });
    fireEvent.change(screen.getByLabelText('To'),   { target: { value: '2026-10-01' } });
    await user.click(screen.getByRole('button', { name: 'Search reconciliation batches' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Period end must be on or after period start');
    expect(mockListReconciliation).not.toHaveBeenCalled();
  });

  it('(c) calls listReconciliation with correct date params on Search click', async () => {
    const user = userEvent.setup();
    setupReconPage();
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('To'),   { target: { value: '2026-10-07' } });
    await user.click(screen.getByRole('button', { name: 'Search reconciliation batches' }));
    await waitFor(() => expect(mockListReconciliation).toHaveBeenCalledWith(
      expect.objectContaining({ date_from: '2026-10-01', date_to: '2026-10-07' }),
    ));
  });

  it('(d) renders four KPI cards after successful search', async () => {
    const user = userEvent.setup();
    setupReconPage();
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('To'),   { target: { value: '2026-10-07' } });
    await user.click(screen.getByRole('button', { name: 'Search reconciliation batches' }));
    await waitFor(() => {
      expect(screen.getByText('Total Transactions')).toBeInTheDocument();
      expect(screen.getByText('Matched %')).toBeInTheDocument();
      expect(screen.getByText('Unmatched Count')).toBeInTheDocument();
      expect(screen.getByText('Exception Amount')).toBeInTheDocument();
    });
  });

  it('(e) Matched % KPI shows computed percentage from summary', async () => {
    const user = userEvent.setup();
    setupReconPage();
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('To'),   { target: { value: '2026-10-07' } });
    await user.click(screen.getByRole('button', { name: 'Search reconciliation batches' }));
    await waitFor(() => {
      expect(screen.getByText(/95\.3%/)).toBeInTheDocument();
    });
  });

  it('(f) shows empty state when API returns empty array after search', async () => {
    const user = userEvent.setup();
    mockDeriveReconciliationSummary.mockReturnValue({ total_transactions: 0, matched_count: 0, unmatched_count: 0, exception_amount_bwp: 0, matched_pct: 0 });
    mockListReconciliation.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1 } });
    render(<ReconciliationReportPage />);
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2030-01-01' } });
    fireEvent.change(screen.getByLabelText('To'),   { target: { value: '2030-01-31' } });
    await user.click(screen.getByRole('button', { name: 'Search reconciliation batches' }));
    await waitFor(() => {
      expect(screen.getByText('No reconciliation batches found for the selected period.')).toBeInTheDocument();
    });
  });

  it('(g) each batch row has a Detail link pointing to /reconciliation/batches/{batch_id}', async () => {
    const user = userEvent.setup();
    setupReconPage();
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('To'),   { target: { value: '2026-10-07' } });
    await user.click(screen.getByRole('button', { name: 'Search reconciliation batches' }));
    await waitFor(() => {
      const link = screen.getByRole('link', { name: `View batch ${BATCH_1.batch_id}` });
      expect(link).toHaveAttribute('href', `/reconciliation/batches/${BATCH_1.batch_id}`);
    });
  });
});
