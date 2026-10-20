'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import Link from 'next/link';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { useAuth } from '@/lib/auth/context';
import { getAdminDashboardSummary } from '@/src/modules/dashboard/api/dashboard';
import type { AdminDashboardSummary } from '@/src/modules/dashboard/api/dashboard';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

interface KpiCardProps {
  label: string;
  value: number;
  variant: 'neutral' | 'success' | 'warning' | 'error' | 'info';
  description: string;
  href?: string;
  linkLabel?: string;
}

function KpiCard({ label, value, variant, description, href, linkLabel }: KpiCardProps) {
  return (
    <Card
      as="article"
      semanticLabel={label}
      className="flex flex-col gap-3"
    >
      <div className="flex items-start justify-between gap-2">
        <span className="text-sm font-medium text-text-secondary">{label}</span>
        <Badge label={variant === 'neutral' ? 'INFO' : variant.toUpperCase()} variant={variant} />
      </div>
      <span
        className="text-3xl font-bold text-text-primary tabular-nums"
        aria-label={`${label}: ${value}`}
      >
        {value.toLocaleString()}
      </span>
      <p className="text-xs text-text-secondary">{description}</p>
      {href && linkLabel && (
        <Link
          href={href}
          className="text-xs font-medium text-brand-primary underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-primary rounded"
          aria-label={linkLabel}
        >
          {linkLabel} →
        </Link>
      )}
    </Card>
  );
}

export function AdminDashboardPage() {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const headingId = useId();

  const [summary, setSummary] = useState<AdminDashboardSummary | null>(null);
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const fetchSummary = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setFetchError(null);
    try {
      const data = await getAdminDashboardSummary(authenticatedClient);
      setSummary(data);
    } catch (err) {
      if (isApiError(err) && (err as ApiError).error.code === 'FORBIDDEN') {
        setForbidden(true);
      } else {
        setFetchError('Failed to load dashboard summary. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void fetchSummary();
  }, [fetchSummary]);

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to view the dashboard."
      />
    );
  }

  if (forbidden) {
    return (
      <ErrorState
        title="Access denied"
        message="You do not have permission to view the admin dashboard."
      />
    );
  }

  const isAllZero =
    summary !== null &&
    summary.total_users === 0 &&
    summary.active_accounts === 0 &&
    summary.suspended_accounts === 0 &&
    summary.pending_closure_requests === 0 &&
    summary.recent_contact_changes === 0;

  return (
    <main aria-labelledby={headingId} className="px-6 py-8 max-w-6xl mx-auto">
      <div className="mb-6 flex items-center justify-between gap-4 flex-wrap">
        <h1 id={headingId} className="text-xl font-semibold text-text-primary">
          Profile Dashboard
        </h1>
        <Button
          variant="secondary"
          onClick={() => void fetchSummary()}
          aria-label="Refresh dashboard summary"
        >
          Refresh
        </Button>
      </div>

      {loading && <Loading label="Loading dashboard summary…" />}

      {!loading && fetchError && (
        <ErrorState message={fetchError} onRetry={() => void fetchSummary()} />
      )}

      {!loading && !fetchError && summary && isAllZero && (
        <Empty
          title="No profile data yet"
          description="No user profiles have been created. Data will appear here once users register."
        />
      )}

      {!loading && !fetchError && summary && !isAllZero && (
        <section aria-label="Profile health KPIs">
          <div
            className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
            role="list"
            aria-label="Key profile metrics"
          >
            <div role="listitem">
              <KpiCard
                label="Total Users"
                value={summary.total_users}
                variant="neutral"
                description="All registered user profiles in the system."
              />
            </div>
            <div role="listitem">
              <KpiCard
                label="Active Accounts"
                value={summary.active_accounts}
                variant="success"
                description="Accounts currently in active status."
              />
            </div>
            <div role="listitem">
              <KpiCard
                label="Suspended Accounts"
                value={summary.suspended_accounts}
                variant="error"
                description="Accounts currently suspended by an admin."
                href="/users"
                linkLabel="Review suspended accounts"
              />
            </div>
            <div role="listitem">
              <KpiCard
                label="Pending Closure Requests"
                value={summary.pending_closure_requests}
                variant="warning"
                description="User accounts awaiting permanent closure confirmation."
                href="/users"
                linkLabel="Review pending closures"
              />
            </div>
            <div role="listitem">
              <KpiCard
                label="Contact Changes in Progress"
                value={summary.recent_contact_changes}
                variant="info"
                description="Accounts with a pending email or mobile number change awaiting OTP verification."
              />
            </div>
          </div>
        </section>
      )}
    </main>
  );
}
