'use client';

import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { getQaDashboard, triggerQaRun } from '@/src/modules/qa/api/qa';
import type { QaCategoryRow, QaDashboard } from '@/src/modules/qa/api/qa';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';
import Link from 'next/link';

type RunStatus = 'passed' | 'failed' | 'running' | 'pending' | null;

function statusBadgeVariant(status: RunStatus): 'success' | 'error' | 'warning' | 'neutral' | 'info' {
  switch (status) {
    case 'passed':  return 'success';
    case 'failed':  return 'error';
    case 'running': return 'info';
    case 'pending': return 'warning';
    default:        return 'neutral';
  }
}

function statusLabel(status: RunStatus): string {
  if (!status) return 'NEVER RUN';
  return status.toUpperCase();
}

function formatTimestamp(iso: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
}

interface CategoryCardProps {
  row: QaCategoryRow;
  onRunNow: (category: string) => void;
  isTriggering: boolean;
}

function CategoryCard({ row, onRunNow, isTriggering }: CategoryCardProps) {
  const [confirming, setConfirming] = useState(false);
  const confirmBtnRef = useRef<HTMLButtonElement>(null);
  const runBtnRef = useRef<HTMLButtonElement>(null);

  const status = row.latest_status as RunStatus;

  const handleRunNowClick = () => {
    setConfirming(true);
  };

  const handleConfirm = () => {
    setConfirming(false);
    onRunNow(row.category);
  };

  const handleCancel = () => {
    setConfirming(false);
    runBtnRef.current?.focus();
  };

  useEffect(() => {
    if (confirming) confirmBtnRef.current?.focus();
  }, [confirming]);

  return (
    <Card as="article" semanticLabel={`QA category: ${row.label}`} className="flex flex-col gap-3 p-4">
      <div className="flex items-start justify-between gap-2">
        <span className="text-sm font-semibold text-text-primary truncate">{row.label}</span>
        <span role="status" aria-label={`Category status: ${statusLabel(status)}`}>
          <Badge label={statusLabel(status)} variant={statusBadgeVariant(status)} />
        </span>
      </div>

      {row.latest_run_id && (
        <div className="text-xs text-text-secondary space-y-1">
          <div>
            Last run: <time dateTime={row.last_run_at ?? ''}>{formatTimestamp(row.last_run_at)}</time>
          </div>
          {row.total !== null && (
            <div aria-label={`${row.passed ?? 0} passed, ${row.failed ?? 0} failed of ${row.total} total`}>
              <span className="text-green-600 font-medium">{row.passed ?? 0}</span>
              {' / '}
              <span className="text-red-600 font-medium">{row.failed ?? 0}</span>
              {' / '}
              <span className="text-gray-500">{row.total}</span>
              {' '}
              <span className="text-gray-400">pass / fail / total</span>
            </div>
          )}
          <Link
            href={`/qa/runs/${row.latest_run_id}`}
            className="text-brand-primary text-xs underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary rounded"
            aria-label={`View latest run for ${row.label}`}
          >
            View last run →
          </Link>
        </div>
      )}

      {!row.latest_run_id && (
        <p className="text-xs text-text-secondary">No runs yet.</p>
      )}

      {!confirming ? (
        <Button
          ref={runBtnRef}
          variant="secondary"
                   onClick={handleRunNowClick}
          disabled={isTriggering || status === 'running'}
          aria-label={`Trigger QA run for ${row.label}`}
        >
          {status === 'running' ? 'Running…' : 'Run Now'}
        </Button>
      ) : (
        <div role="group" aria-label={`Confirm QA run for ${row.label}`} className="flex gap-2">
          <Button
            ref={confirmBtnRef}
            variant="primary"
                       onClick={handleConfirm}
            disabled={isTriggering}
            aria-label={`Confirm run for ${row.label}`}
          >
            Confirm
          </Button>
          <Button
            variant="secondary"
                       onClick={handleCancel}
            aria-label="Cancel"
          >
            Cancel
          </Button>
        </div>
      )}
    </Card>
  );
}

export function QaDashboardPage() {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const statusRegionId = useId();

  const [dashboard, setDashboard] = useState<QaDashboard | null>(null);
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [triggeringCategory, setTriggeringCategory] = useState<string | null>(null);
  const [statusMessage, setStatusMessage] = useState<string>('');

  const fetchDashboard = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setFetchError(null);
    try {
      const data = await getQaDashboard(authenticatedClient);
      setDashboard(data);
    } catch {
      setFetchError('Failed to load QA dashboard. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void fetchDashboard();
  }, [fetchDashboard]);

  const handleRunNow = useCallback(async (category: string) => {
    if (!authenticatedClient) return;
    setTriggeringCategory(category);
    setStatusMessage(`Triggering QA run for ${category}…`);
    try {
      const run = await triggerQaRun(authenticatedClient, category, generateIdempotencyKey());
      setStatusMessage(`Run ${run.id.slice(0, 8)} queued for ${category}.`);
      await fetchDashboard();
    } catch {
      setStatusMessage(`Failed to trigger run for ${category}. Please try again.`);
    } finally {
      setTriggeringCategory(null);
    }
  }, [authenticatedClient, fetchDashboard]);

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to access QA."
      />
    );
  }

  const passedCategories = dashboard?.categories.filter(c => c.latest_status === 'passed').length ?? 0;
  const failedCategories = dashboard?.categories.filter(c => c.latest_status === 'failed').length ?? 0;
  const neverRun = dashboard?.categories.filter(c => !c.latest_run_id).length ?? 0;

  return (
    <PageContainer>
        <PageHeader
          title="QA & Validation Dashboard"
          actions={
            <Button
              variant="secondary"
              onClick={() => void fetchDashboard()}
              disabled={loading}
              aria-label="Refresh QA dashboard"
            >
              Refresh
            </Button>
          }
        />

      {/* Live region for run-status updates (T078 accessibility) */}
      <div
        id={statusRegionId}
        role="status"
        aria-live="polite"
        aria-atomic="true"
        className="sr-only"
      >
        {statusMessage}
      </div>

      {/* Summary strip */}
      {dashboard && (
        <div
          className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6"
          role="list"
          aria-label="QA summary metrics"
        >
          <div role="listitem">
            <Card className="p-3 text-center">
              <p className="text-xs text-text-secondary">Categories</p>
              <p className="text-2xl font-bold tabular-nums">{dashboard.summary.total_categories}</p>
            </Card>
          </div>
          <div role="listitem">
            <Card className="p-3 text-center">
              <p className="text-xs text-text-secondary">Ever Run</p>
              <p className="text-2xl font-bold tabular-nums">{dashboard.summary.categories_with_run}</p>
            </Card>
          </div>
          <div role="listitem">
            <Card className="p-3 text-center">
              <p className="text-xs text-green-600">Passing</p>
              <p className="text-2xl font-bold tabular-nums text-green-600">{passedCategories}</p>
            </Card>
          </div>
          <div role="listitem">
            <Card className="p-3 text-center">
              <p className="text-xs text-red-600">Failing</p>
              <p className="text-2xl font-bold tabular-nums text-red-600">{failedCategories}</p>
            </Card>
          </div>
        </div>
      )}

      {loading && !dashboard && <Loading label="Loading QA dashboard…" />}
      {fetchError && <ErrorState message={fetchError} onRetry={() => void fetchDashboard()} />}

      {dashboard && (
        <section aria-label="QA categories">
          <h2 className="text-base font-semibold text-text-primary mb-3">
            Test Categories
            {neverRun > 0 && (
              <span className="ml-2 text-xs font-normal text-text-secondary">
                ({neverRun} never run)
              </span>
            )}
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {dashboard.categories.map((row: QaCategoryRow) => (
              <CategoryCard
                key={row.category}
                row={row}
                onRunNow={(cat) => void handleRunNow(cat)}
                isTriggering={triggeringCategory === row.category}
              />
            ))}
          </div>
        </section>
      )}
    </PageContainer>
  );
}
