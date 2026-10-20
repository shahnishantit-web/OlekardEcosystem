'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import Link from 'next/link';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';
import { getQaRun } from '@/src/modules/qa/api/qa';
import type { QaTestResult, QaTestRunDetail } from '@/src/modules/qa/api/qa';
import { PageContainer } from '@/components/layout/PageContainer';

type Outcome = 'passed' | 'failed' | 'skipped' | 'error';
type RunStatus = 'pending' | 'running' | 'completed' | 'failed';

function outcomeVariant(outcome: Outcome): 'success' | 'error' | 'warning' | 'neutral' {
  switch (outcome) {
    case 'passed':  return 'success';
    case 'failed':  return 'error';
    case 'error':   return 'error';
    case 'skipped': return 'warning';
    default:        return 'neutral';
  }
}

function statusVariant(status: RunStatus): 'success' | 'error' | 'warning' | 'info' | 'neutral' {
  switch (status) {
    case 'completed': return 'success';
    case 'failed':    return 'error';
    case 'running':   return 'info';
    case 'pending':   return 'warning';
    default:          return 'neutral';
  }
}

function formatMs(ms: number | null): string {
  if (ms === null) return '—';
  return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(2)}s`;
}

function formatTimestamp(iso: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
}

function durationMs(startedAt: string | null, completedAt: string | null): string {
  if (!startedAt || !completedAt) return '—';
  const diff = new Date(completedAt).getTime() - new Date(startedAt).getTime();
  return formatMs(diff);
}

interface EvidencePanelProps {
  evidence: Record<string, unknown> | null;
  testCaseName: string;
}

function EvidencePanel({ evidence, testCaseName }: EvidencePanelProps) {
  const [expanded, setExpanded] = useState(false);
  const id = useId();

  if (!evidence || Object.keys(evidence).length === 0) return null;

  return (
    <div className="mt-1">
      <Button
        variant="secondary"
               onClick={() => setExpanded(v => !v)}
        aria-expanded={expanded}
        aria-controls={id}
        aria-label={`${expanded ? 'Collapse' : 'Expand'} evidence for ${testCaseName}`}
      >
        {expanded ? 'Hide' : 'Evidence'}
      </Button>
      {expanded && (
        <pre
          id={id}
          className="mt-2 text-xs bg-gray-50 p-2 rounded overflow-auto max-h-48 border border-gray-200"
          aria-label={`Evidence for ${testCaseName}`}
        >
          {JSON.stringify(evidence, null, 2)}
        </pre>
      )}
    </div>
  );
}

interface QaRunDetailPageProps {
  runId: string;
}

export function QaRunDetailPage({ runId }: QaRunDetailPageProps) {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const headingId = useId();

  const [run, setRun] = useState<QaTestRunDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [fetchError, setFetchError] = useState<string | null>(null);

  const fetchRun = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setFetchError(null);
    try {
      const data = await getQaRun(authenticatedClient, runId);
      setRun(data);
    } catch (err: unknown) {
      const errObj = err as { error?: { code?: string; message?: string } };
      if (errObj?.error?.code === 'QA_RUN_NOT_FOUND') {
        setFetchError('QA run not found.');
      } else {
        setFetchError('Failed to load QA run. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, runId]);

  useEffect(() => {
    void fetchRun();
  }, [fetchRun]);

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to access QA."
      />
    );
  }

  if (loading && !run) return <Loading label="Loading QA run…" />;
  if (fetchError) return <ErrorState message={fetchError} onRetry={() => void fetchRun()} />;
  if (!run) return null;

  const passedCount = run.counts?.passed ?? 0;
  const failedCount = run.counts?.failed ?? 0;
  const skippedCount = run.counts?.skipped ?? 0;
  const totalCount = run.counts?.total ?? run.results.length;

  return (
    <PageContainer>
      <nav aria-label="Breadcrumb" className="mb-4 text-sm text-text-secondary">
        <Link href="/qa" className="hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary rounded">
          QA Dashboard
        </Link>
        {' / '}
        <span aria-current="page">Run {run.id.slice(0, 8)}</span>
      </nav>

      <div className="mb-6 flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 id={headingId} className="text-xl font-semibold text-text-primary">
            QA Run: {run.category}
          </h1>
          <p className="text-xs text-text-secondary mt-1 font-mono">{run.id}</p>
        </div>
        <Button
          variant="secondary"
          onClick={() => void fetchRun()}
          disabled={loading}
          aria-label="Refresh run details"
        >
          Refresh
        </Button>
      </div>

      {/* Run metadata */}
      <Card className="mb-6 p-4">
        <dl className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
          <div>
            <dt className="text-xs font-medium text-text-secondary">Status</dt>
            <dd className="mt-1">
              <span role="status" aria-label={`Run status: ${run.status}`}>
                <Badge
                  label={run.status.toUpperCase()}
                  variant={statusVariant(run.status as RunStatus)}
                />
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-text-secondary">Category</dt>
            <dd className="mt-1 font-medium capitalize">{run.category.replace(/-/g, ' ')}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-text-secondary">Duration</dt>
            <dd className="mt-1">{durationMs(run.started_at, run.completed_at)}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-text-secondary">Started</dt>
            <dd className="mt-1">{formatTimestamp(run.started_at)}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-text-secondary">Triggered By</dt>
            <dd className="mt-1 font-mono text-xs truncate">{run.triggered_by}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-text-secondary">Completed</dt>
            <dd className="mt-1">{formatTimestamp(run.completed_at)}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-text-secondary">Results</dt>
            <dd className="mt-1">
              <span className="text-green-600 font-medium">{passedCount}</span>
              {' passed · '}
              <span className="text-red-600 font-medium">{failedCount}</span>
              {' failed · '}
              <span className="text-gray-500">{skippedCount}</span>
              {' skipped · '}
              <span className="font-medium">{totalCount}</span>
              {' total'}
            </dd>
          </div>
        </dl>
      </Card>

      {/* Results table */}
      <section aria-label="Test results">
        <h2 className="text-base font-semibold text-text-primary mb-3">Test Results</h2>
        <Table aria-label="QA test results">
          <thead>
            <tr>
              <th scope="col">Test Case</th>
              <th scope="col">Scenario Type</th>
              <th scope="col">Outcome</th>
              <th scope="col">Duration</th>
              <th scope="col">Details</th>
            </tr>
          </thead>
          <tbody>
            {run.results.length === 0 && (
              <tr>
                <td colSpan={5} className="text-center text-sm text-gray-500 py-6">
                  No results yet. The run may still be in progress.
                </td>
              </tr>
            )}
            {run.results.map((result: QaTestResult) => (
              <tr key={result.id}>
                <td>
                  <span className="font-medium text-sm">{result.test_case_name}</span>
                  <span className="block text-xs font-mono text-text-secondary">{result.test_case_code}</span>
                </td>
                <td>
                  <Badge
                    label={result.scenario_type.replace(/_/g, ' ')}
                    variant="neutral"
                  />
                </td>
                <td>
                  <span role="status" aria-label={`Outcome: ${result.outcome}`}>
                    <Badge
                      label={result.outcome.toUpperCase()}
                      variant={outcomeVariant(result.outcome as Outcome)}
                    />
                  </span>
                </td>
                <td className="tabular-nums">{formatMs(result.duration_ms)}</td>
                <td>
                  {result.error_message && (
                    <p className="text-xs text-red-600 max-w-xs truncate" title={result.error_message}>
                      {result.error_message}
                    </p>
                  )}
                  <EvidencePanel evidence={result.evidence} testCaseName={result.test_case_name} />
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      </section>
    </PageContainer>
  );
}
