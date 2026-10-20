'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorState } from '@/components/ui/ErrorState';
import { Loading } from '@/components/ui/Loading';
import { Select } from '@/components/ui/Select';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

interface ReportJob {
  id: string;
  report_type: string;
  status: string;
  row_count: number | null;
  created_at: string;
  completed_at: string | null;
}

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'requested', label: 'Requested' },
  { value: 'processing', label: 'Processing' },
  { value: 'complete', label: 'Complete' },
  { value: 'failed', label: 'Failed' },
];

const TYPE_OPTIONS = [
  { value: '', label: 'All types' },
  { value: 'wallet-report', label: 'Wallet Report' },
  { value: 'transaction-report', label: 'Transaction Report' },
  { value: 'user-report', label: 'User Report' },
  { value: 'agent-report', label: 'Agent Report' },
  { value: 'commission-report', label: 'Commission Report' },
  { value: 'kyc-report', label: 'KYC Report' },
  { value: 'reconciliation-report', label: 'Reconciliation Report' },
  { value: 'settlement-report', label: 'Settlement Report' },
  { value: 'risk-report', label: 'Risk Report' },
  { value: 'financial-summary', label: 'Financial Summary' },
];

const REPORT_TYPE_FORM_OPTIONS = [
  { value: 'transaction_summary', label: 'Transaction Summary' },
  { value: 'wallet_balances',     label: 'Wallet Balances' },
  { value: 'agent_commissions',   label: 'Agent Commissions' },
  { value: 'kyc_status',          label: 'KYC Status' },
  { value: 'reconciliation',      label: 'Reconciliation' },
  { value: 'user_report',         label: 'User Report' },
  { value: 'agent_report',        label: 'Agent Report' },
  { value: 'settlement_report',   label: 'Settlement Report' },
  { value: 'risk_report',         label: 'Risk Report' },
  { value: 'financial_summary',   label: 'Financial Summary' },
];

export function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'error' | 'info' {
  switch (status) {
    case 'complete':   return 'success';
    case 'ready':      return 'success';
    case 'processing': return 'warning';
    case 'failed':     return 'error';
    case 'requested':  return 'info';
    default:           return 'neutral';
  }
}

function AdminAppReportForm({ client }: { client: AuthenticatedApiClient }) {
  const formId = useId();
  const router = useRouter();

  const [reportType, setReportType]   = useState('transaction_summary');
  const [periodStart, setPeriodStart] = useState('');
  const [periodEnd, setPeriodEnd]     = useState('');
  const [format, setFormat]           = useState<'csv' | 'xlsx'>('csv');
  const [submitting, setSubmitting]   = useState(false);
  const [formError, setFormError]     = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);

    if (periodStart && periodEnd && periodEnd < periodStart) {
      setFormError('Period end must be on or after period start.');
      return;
    }

    setSubmitting(true);
    try {
      const adminClient = new AdminAppClient(client);
      const idempotencyKey = generateIdempotencyKey(`report-${reportType}-${periodStart}-${periodEnd}`);
      const res = await adminClient.createReport(reportType, periodStart, periodEnd, format, idempotencyKey);
      router.push(`/reports/${res.data.report_id}`);
    } catch (err) {
      const status = (err as { status?: number }).status;
      if (status === 429) {
        setFormError('You already have the maximum number of active report jobs. Wait for one to complete first.');
      } else {
        setFormError('Failed to request report. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Card className="mb-6 p-4">
      <h2 className="text-sm font-semibold text-text-primary mb-4" id={formId}>Request New Report</h2>
      <form id="request-new-report" onSubmit={e => void handleSubmit(e)} aria-labelledby={formId} className="flex flex-wrap gap-3 items-end">
        <div>
          <label htmlFor="report-type" className="block text-xs font-medium text-text-secondary mb-1">Report Type</label>
          <select
            id="report-type"
            value={reportType}
            onChange={e => setReportType(e.target.value)}
            className="rounded border border-ok-border px-2 py-1.5 text-sm"
          >
            {REPORT_TYPE_FORM_OPTIONS.map(o => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="period-start" className="block text-xs font-medium text-text-secondary mb-1">Period Start</label>
          <input
            id="period-start"
            type="date"
            value={periodStart}
            onChange={e => setPeriodStart(e.target.value)}
            required
            className="rounded border border-ok-border px-2 py-1.5 text-sm"
          />
        </div>
        <div>
          <label htmlFor="period-end" className="block text-xs font-medium text-text-secondary mb-1">Period End</label>
          <input
            id="period-end"
            type="date"
            value={periodEnd}
            onChange={e => setPeriodEnd(e.target.value)}
            required
            className="rounded border border-ok-border px-2 py-1.5 text-sm"
          />
        </div>
        <div>
          <label htmlFor="report-format" className="block text-xs font-medium text-text-secondary mb-1">Format</label>
          <select
            id="report-format"
            value={format}
            onChange={e => setFormat(e.target.value as 'csv' | 'xlsx')}
            className="rounded border border-ok-border px-2 py-1.5 text-sm"
          >
            <option value="csv">CSV</option>
            <option value="xlsx">XLSX</option>
          </select>
        </div>
        <Button type="submit" variant="primary" disabled={submitting} aria-label="Request report">
          {submitting ? 'Requesting…' : 'Request Report'}
        </Button>
      </form>

      {formError && (
        <p role="alert" className="mt-2 text-sm text-error">{formError}</p>
      )}
    </Card>
  );
}

export function ReportListPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const router = useRouter();
  const headingId = useId();

  const [jobs, setJobs]             = useState<ReportJob[]>([]);
  const [loading, setLoading]       = useState(false);
  const [error, setError]           = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter]     = useState('');

  const fetchJobs = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (statusFilter) params.set('status', statusFilter);
      if (typeFilter)   params.set('report_type', typeFilter);
      const res = await authenticatedClient.get<ReportJob[]>(`/v1/reports/jobs?${params.toString()}`);
      setJobs(Array.isArray(res.data) ? res.data : []);
    } catch {
      setError('Failed to load report jobs.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, statusFilter, typeFilter]);

  useEffect(() => { void fetchJobs(); }, [fetchJobs]);

  return (
    <PageContainer>
      <PageHeader
        title="Reports"
        actions={
          <Button onClick={() => document.getElementById('report-type')?.focus()} aria-label="New Report">
            New Report
          </Button>
        }
      />

      {authenticatedClient && (hasPermission('admin.read') || hasPermission('finance.reports') || hasPermission('compliance.reports')) && (
        <AdminAppReportForm client={authenticatedClient} />
      )}

      <Card>
        <div style={{ display: 'flex', gap: '1rem', marginBottom: '1rem' }} role="search" aria-label="Filter reports">
          <Select
            id="type-filter"
            aria-label="Filter by report type"
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
          >
            {TYPE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
          <Select
            id="status-filter"
            aria-label="Filter by status"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
          >
            {STATUS_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </div>

        {loading && <Loading aria-label="Loading report jobs" />}
        {error && <ErrorState message={error} onRetry={() => void fetchJobs()} />}
        {!loading && !error && (
          <Table aria-labelledby={headingId}>
            <thead>
              <tr>
                <th scope="col">Type</th>
                <th scope="col">Status</th>
                <th scope="col">Row Count</th>
                <th scope="col">Created</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              {jobs.length === 0 && (
                <tr><td colSpan={5}>No reports found.</td></tr>
              )}
              {jobs.map((job) => (
                <tr key={job.id}>
                  <td>{job.report_type}</td>
                  <td>
                    <Badge
                      variant={statusVariant(job.status)}
                      label={job.status.toUpperCase()}
                    />
                  </td>
                  <td>{job.row_count ?? '—'}</td>
                  <td>{new Date(job.created_at).toLocaleString()}</td>
                  <td>
                    <Button
                      onClick={() => router.push(`/reports/${job.id}`)}
                      aria-label={`View report job ${job.id}`}
                    >
                      View
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </PageContainer>
  );
}
