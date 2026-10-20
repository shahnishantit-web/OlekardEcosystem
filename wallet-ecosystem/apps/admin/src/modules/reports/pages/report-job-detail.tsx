'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorState } from '@/components/ui/ErrorState';
import { Loading } from '@/components/ui/Loading';
import { useAuth } from '@/lib/auth/context';
import {
  normaliseJobStatus,
  requestExport,
  getExport,
  submitReportJob,
  type ReportJob,
  type ReportExport,
} from '@/src/modules/reports/api/reports';

interface Props {
  jobId: string;
}

const POLL_INTERVAL_MS   = 5000;
const MAX_JOB_POLLS      = 36;   // ~3 minutes at 5s
const EXPORT_POLL_MS     = 3000;
const MAX_EXPORT_POLLS   = 20;   // ~60s at 3s

function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'error' | 'info' {
  switch (status) {
    case 'complete':   return 'success';
    case 'ready':      return 'success';
    case 'processing': return 'warning';
    case 'failed':     return 'error';
    case 'requested':  return 'info';
    default:           return 'neutral';
  }
}

export function ReportJobDetailPage({ jobId }: Props) {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [job, setJob]         = useState<ReportJob | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState<string | null>(null);
  const [pollingTimedOut, setPollingTimedOut] = useState(false);

  const [exportState, setExportState]   = useState<'idle' | 'pending' | 'ready' | 'failed'>('idle');
  const [retrying, setRetrying]         = useState(false);

  const jobPollCountRef    = useRef(0);
  const exportIntervalRef  = useRef<ReturnType<typeof setInterval> | null>(null);

  const fetchJob = useCallback(async () => {
    if (!authenticatedClient) return;
    try {
      const res = await authenticatedClient.get<ReportJob>(`/v1/reports/jobs/${jobId}`);
      setJob(res.data);
      setError(null);
    } catch {
      setError('Failed to load report job.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, jobId]);

  // Initial load
  useEffect(() => { void fetchJob(); }, [fetchJob]);

  // Job-status polling with 36-iteration cap (FR-012)
  useEffect(() => {
    if (!job || pollingTimedOut) return;
    const normalised = normaliseJobStatus(job.status);
    if (normalised !== 'requested' && normalised !== 'processing') {
      jobPollCountRef.current = 0;
      return;
    }
    const interval = setInterval(() => {
      if (jobPollCountRef.current >= MAX_JOB_POLLS) {
        clearInterval(interval);
        setPollingTimedOut(true);
        return;
      }
      jobPollCountRef.current += 1;
      void fetchJob();
    }, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [job, fetchJob, pollingTimedOut]);

  // Cleanup export interval on unmount
  useEffect(() => () => {
    if (exportIntervalRef.current) clearInterval(exportIntervalRef.current);
  }, []);

  const handleExport = useCallback(async (format: 'csv' | 'excel') => {
    if (!authenticatedClient || !job) return;
    setExportState('pending');

    let exp: ReportExport;
    try {
      exp = await requestExport(authenticatedClient, job.id, format);
    } catch {
      setExportState('failed');
      return;
    }

    let pollCount = 0;
    exportIntervalRef.current = setInterval(async () => {
      try {
        const updated = await getExport(authenticatedClient, exp.id);
        if (updated.status === 'ready') {
          clearInterval(exportIntervalRef.current!);
          setExportState('ready');
          window.open(updated.download_url!, '_blank');
          return;
        }
        if (updated.status === 'purged') {
          clearInterval(exportIntervalRef.current!);
          setExportState('failed');
          return;
        }
        pollCount += 1;
        if (pollCount >= MAX_EXPORT_POLLS) {
          clearInterval(exportIntervalRef.current!);
          setExportState('failed');
        }
      } catch {
        clearInterval(exportIntervalRef.current!);
        setExportState('failed');
      }
    }, EXPORT_POLL_MS);
  }, [authenticatedClient, job]);

  const handleRetry = useCallback(async () => {
    if (!authenticatedClient || !job) return;
    setRetrying(true);
    try {
      const newJob = await submitReportJob(authenticatedClient, {
        report_type: job.report_type,
        filters: job.filters as { date_range_start: string; date_range_end: string },
      });
      router.push(`/reports/${newJob.id}`);
    } catch {
      setError('Failed to retry report. Please try again.');
    } finally {
      setRetrying(false);
    }
  }, [authenticatedClient, job, router]);

  if (loading) return <Loading aria-label="Loading report job" />;
  if (error && !job) return <ErrorState message={error} />;
  if (!job) return <ErrorState message="Report not found." />;

  const normalised  = normaliseJobStatus(job.status);
  const isComplete  = normalised === 'complete';
  const isFailed    = normalised === 'failed';
  const isPolling   = normalised === 'requested' || normalised === 'processing';

  return (
    <PageContainer>
      <PageHeader title="Report Job" />

      <Card>
        <dl>
          <dt>Type</dt><dd>{job.report_type}</dd>
          <dt>Status</dt>
          <dd>
            <Badge
              variant={statusVariant(job.status)}
              label={normalised.toUpperCase()}
            />
          </dd>
          <dt>Row Count</dt><dd>{job.row_count ?? '—'}</dd>
          {isFailed && job.failure_reason && (
            <><dt>Failure Reason</dt><dd><p role="alert" className="text-error">{job.failure_reason}</p></dd></>
          )}
          <dt>Filters</dt><dd><code>{JSON.stringify(job.filters)}</code></dd>
          <dt>Created</dt><dd>{new Date(job.created_at).toLocaleString()}</dd>
          {job.as_of_at && <><dt>As Of</dt><dd>{new Date(job.as_of_at).toLocaleString()}</dd></>}
          {job.completed_at && <><dt>Completed</dt><dd>{new Date(job.completed_at).toLocaleString()}</dd></>}
        </dl>

        {isPolling && !pollingTimedOut && (
          <p role="status" aria-live="polite" className="mt-3 text-sm text-text-secondary">
            Checking status…
          </p>
        )}

        {pollingTimedOut && (
          <p role="status" aria-live="polite" className="mt-3 text-sm text-text-secondary">
            This report is taking longer than expected — check back later.{' '}
            <button className="underline" onClick={() => { jobPollCountRef.current = 0; setPollingTimedOut(false); void fetchJob(); }}>
              Refresh
            </button>
          </p>
        )}

        {error && (
          <p role="alert" className="mt-2 text-sm text-error">{error}</p>
        )}

        <div id="export" style={{ marginTop: '1rem', display: 'flex', gap: '0.75rem', alignItems: 'center' }}>
          <Button
            onClick={() => void handleExport('csv')}
            disabled={!isComplete || exportState === 'pending'}
            aria-disabled={!isComplete}
            aria-label="Export as CSV"
          >
            Export CSV
          </Button>
          <Button
            onClick={() => void handleExport('excel')}
            disabled={!isComplete || exportState === 'pending'}
            aria-disabled={!isComplete}
            aria-label="Export as Excel"
          >
            Export Excel
          </Button>

          {exportState === 'pending' && (
            <p role="status" aria-live="polite" className="text-sm text-text-secondary">Generating export…</p>
          )}
          {exportState === 'ready' && (
            <p role="status" aria-live="polite" className="text-sm text-success">Download started.</p>
          )}
          {exportState === 'failed' && (
            <p role="alert" className="text-sm text-error">Export failed. Try again.</p>
          )}
        </div>

        {isFailed && (
          <div style={{ marginTop: '1rem' }}>
            <Button
              onClick={() => void handleRetry()}
              disabled={retrying}
              aria-label="Retry this report"
            >
              {retrying ? 'Retrying…' : 'Retry Report'}
            </Button>
          </div>
        )}
      </Card>
    </PageContainer>
  );
}
