'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface LogEntry {
  id: string;
  severity: string;
  service_name: string;
  message: string;
  correlation_id: string | null;
  created_at: string;
}

const severityClass: Record<string, string> = {
  debug:    'bg-neutral-100 text-neutral-600',
  info:     'bg-ok-info-bg text-ok-info-fg',
  warning:  'bg-ok-warning-bg text-ok-warning-fg',
  error:    'bg-ok-error-bg text-ok-error-fg',
  critical: 'bg-ok-error-bg text-ok-error-fg',
};

const SEVERITIES = ['debug', 'info', 'warning', 'error', 'critical'];

export default function OpsLogsPage() {
  const { authenticatedClient } = useAuth();
  const [correlationId, setCorrelationId] = useState('');
  const [severity,      setSeverity]      = useState('');
  const [serviceName,   setServiceName]   = useState('');
  const [cursor,        setCursor]        = useState<string | null>(null);

  const params = new URLSearchParams();
  if (correlationId) params.set('correlation_id', correlationId);
  if (severity)      params.set('severity', severity);
  if (serviceName)   params.set('service_name', serviceName);
  if (cursor)        params.set('cursor', cursor);
  params.set('per_page', '50');

  const { data, isLoading, isError } = useQuery<{ data: LogEntry[]; meta?: { has_more: boolean; next_cursor: string } | null }>({
    queryKey: ['ops-logs', correlationId, severity, serviceName, cursor],
    enabled:  !!authenticatedClient,
    queryFn:  () =>
      authenticatedClient!
        .get<LogEntry[]>(`/v1/admin/ops/logs?${params.toString()}`)
        .then((r) => ({
          data: Array.isArray(r.data) ? r.data : [],
          meta: r.meta as { has_more: boolean; next_cursor: string } | null,
        })),
  });

  const inputCls  = 'rounded-lg border border-ok-input-border bg-ok-input-bg px-3 h-[35px] text-sm text-text-primary placeholder:text-text-secondary/70 focus:outline-none focus:border-ok-primary focus:ring-1 focus:ring-ok-focus-ring';
  const selectCls = inputCls + ' pr-8';

  return (
    <PageContainer>
      <PageHeader title="Application Logs" description="Search structured log entries across all services." />

      <div className="flex flex-wrap gap-3 mb-4">
        <input
          type="search"
          placeholder="Correlation ID"
          value={correlationId}
          onChange={(e) => { setCorrelationId(e.target.value); setCursor(null); }}
          className={`${inputCls} w-64`}
          aria-label="Filter by correlation ID"
        />
        <select
          value={severity}
          onChange={(e) => { setSeverity(e.target.value === 'all' ? '' : e.target.value); setCursor(null); }}
          aria-label="Filter by severity"
          className={selectCls}
        >
          <option value="all">All severities</option>
          {SEVERITIES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <input
          type="search"
          placeholder="Service name"
          value={serviceName}
          onChange={(e) => { setServiceName(e.target.value); setCursor(null); }}
          className={`${inputCls} w-40`}
          aria-label="Filter by service name"
        />
      </div>

      {isLoading && <p className="text-sm text-text-secondary">Loading…</p>}
      {isError   && <p className="text-sm text-ok-error-fg">Failed to load logs.</p>}

      {data && (
        <>
          <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
            <table className="min-w-full text-sm" aria-label="Application logs">
              <thead>
                <tr className="bg-neutral-50 border-b border-ok-border">
                  {['Time', 'Severity', 'Service', 'Message', 'Correlation ID'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.data.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-4 py-6 text-center text-text-secondary">No entries found.</td>
                  </tr>
                )}
                {data.data.map((entry) => (
                  <tr key={entry.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                    <td className="px-4 py-3 whitespace-nowrap text-xs text-text-secondary">{entry.created_at}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${severityClass[entry.severity] ?? 'bg-neutral-100 text-neutral-600'}`}>
                        {entry.severity}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-text-primary">{entry.service_name}</td>
                    <td className="px-4 py-3 max-w-xs truncate text-sm text-text-primary">{entry.message}</td>
                    <td className="px-4 py-3 font-mono text-xs text-text-secondary">{entry.correlation_id ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {data.meta?.has_more && (
            <div className="flex justify-end mt-3">
              <button
                type="button"
                onClick={() => setCursor(data.meta!.next_cursor)}
                className="px-3 h-[35px] rounded-lg border border-ok-border-strong bg-ok-surface text-sm text-text-primary hover:bg-ok-hover-bg transition-colors"
              >
                Next page
              </button>
            </div>
          )}
        </>
      )}
    </PageContainer>
  );
}
