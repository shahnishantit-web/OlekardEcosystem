'use client';

import { Dialog } from '@/components/ui/Dialog';

import { useCallback, useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AuditEvent, AuditEventDetail } from '@/lib/api/admin-app-client';

const ENTITY_TYPE_OPTIONS = [
  'wallet', 'user', 'transaction', 'agent', 'kyc', 'config', 'system',
];

function outcomeVariant(outcome: string): 'success' | 'error' | 'warning' | 'neutral' {
  switch (outcome) {
    case 'success': return 'success';
    case 'failure': return 'error';
    case 'denied':  return 'warning';
    default:        return 'neutral';
  }
}

export function AuditEventsPage() {
  const { authenticatedClient, hasPermission } = useAuth();

  const canRead = hasPermission('adminapp.audit.read');

  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedEvent, setSelectedEvent] = useState<AuditEventDetail | null>(null);

  const [filterEventType, setFilterEventType] = useState('');
  const [filterActor, setFilterActor] = useState('');
  const [filterEntityType, setFilterEntityType] = useState('');
  const [filterDateFrom, setFilterDateFrom] = useState('');
  const [filterDateTo, setFilterDateTo] = useState('');
  const [dateRangeError, setDateRangeError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);

  const fetchEvents = useCallback(async (reset = false, targetPage?: number) => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const requestedPage = targetPage ?? (reset ? 1 : page);
      const params: Record<string, string> = { per_page: '20', page: String(requestedPage) };
      if (filterEventType)  params.action       = filterEventType;
      if (filterActor)      params.actor_id    = filterActor;
      if (filterEntityType) params.resource_type = filterEntityType;
      if (filterDateFrom)   params.from_date     = filterDateFrom;
      if (filterDateTo)     params.to_date       = filterDateTo;

      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listAuditEvents(params);
      const newEvents = reset ? res.data : [...events, ...res.data];
      setEvents(newEvents);
      setPage(requestedPage);
      setHasMore(requestedPage < Number(res.meta?.last_page ?? 1));
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load audit events.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, filterEventType, filterActor, filterEntityType, filterDateFrom, filterDateTo, page, events]);

  useEffect(() => {
    if (canRead) fetchEvents(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleApplyFilters = () => {
    // enforce 90-day max range (FR-003)
    if (filterDateFrom && filterDateTo) {
      const diffDays = (new Date(filterDateTo).getTime() - new Date(filterDateFrom).getTime()) / (1000 * 60 * 60 * 24);
      if (diffDays > 90) {
        setDateRangeError('Date range must be 90 days or less. Please select a narrower range.');
        return;
      }
    }
    setDateRangeError(null);
    setPage(1);
    fetchEvents(true);
  };

  const handleViewDetail = (event: AuditEvent) => {
    setSelectedEvent({ ...event, metadata: {} });
  };

  if (!canRead) {
    return (
      <PageContainer>
        <PageHeader title="Audit Events" />
        <Card className="p-6">
          <p role="alert" className="text-sm text-error">
            Access denied. You do not have permission to view the audit log.
          </p>
        </Card>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Audit Events" />

      {/* Filters */}
      <Card className="mb-4 p-4">
        <div className="flex flex-wrap gap-3">
          <div>
            <label htmlFor="event-type-filter" className="text-sm font-medium block mb-1">Event Type</label>
            <input
              id="event-type-filter"
              type="text"
              value={filterEventType}
              onChange={e => setFilterEventType(e.target.value)}
              placeholder="e.g. wallet.freeze.applied"
              className="border rounded px-2 py-1 text-sm"
            />
          </div>
          <div>
            <label htmlFor="actor-filter" className="text-sm font-medium block mb-1">Actor</label>
            <input
              id="actor-filter"
              type="text"
              value={filterActor}
              onChange={e => setFilterActor(e.target.value)}
              placeholder="Actor ID"
              className="border rounded px-2 py-1 text-sm"
            />
          </div>
          <div>
            <label htmlFor="entity-type-filter" className="text-sm font-medium block mb-1">Entity Type</label>
            <select
              id="entity-type-filter"
              value={filterEntityType}
              onChange={e => setFilterEntityType(e.target.value)}
              className="border rounded px-2 py-1 text-sm"
            >
              <option value="">All</option>
              {ENTITY_TYPE_OPTIONS.map(t => (
                <option key={t} value={t}>{t}</option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="from-filter" className="text-sm font-medium block mb-1">From</label>
            <input
              id="from-filter"
              type="datetime-local"
              value={filterDateFrom}
              onChange={e => setFilterDateFrom(e.target.value)}
              className="border rounded px-2 py-1 text-sm"
            />
          </div>
          <div>
            <label htmlFor="to-filter" className="text-sm font-medium block mb-1">To</label>
            <input
              id="to-filter"
              type="datetime-local"
              value={filterDateTo}
              onChange={e => setFilterDateTo(e.target.value)}
              className="border rounded px-2 py-1 text-sm"
            />
          </div>
          <div className="flex items-end">
            <Button onClick={handleApplyFilters}>Apply Filters</Button>
          </div>
        </div>
        {dateRangeError && (
          <p role="alert" className="mt-2 text-sm text-error">{dateRangeError}</p>
        )}
      </Card>

      {error && <ErrorState message={error} />}
      {loading && events.length === 0 && <Loading />}

      <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
        <table className="min-w-full text-sm" aria-label="Audit events">
          <thead>
            <tr className="bg-neutral-50 border-b border-ok-border">
              {['Occurred At', 'Action', 'Actor', 'Resource', 'Correlation ID', 'Outcome', 'Actions'].map((h) => (
                <th key={h} scope="col" className="px-4 py-3 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {events.map(evt => (
              <tr key={evt.id} className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg">
                <td className="px-4 py-3 text-xs text-text-secondary">{new Date(evt.occurred_at).toLocaleString()}</td>
                <td className="px-4 py-3 font-mono text-xs text-text-primary">{evt.action}</td>
                <td className="px-4 py-3 font-mono text-xs text-text-secondary">{evt.actor_id ?? evt.actor_type}</td>
                <td className="px-4 py-3 text-xs text-text-secondary">
                  {evt.resource_type
                    ? `${evt.resource_type}${evt.resource_id ? ` / ${evt.resource_id.slice(0, 8)}…` : ''}`
                    : '—'}
                </td>
                <td className="px-4 py-3 font-mono text-xs text-text-secondary truncate max-w-xs">{evt.correlation_id}</td>
                <td className="px-4 py-3"><Badge variant={outcomeVariant(evt.outcome)} label={evt.outcome} /></td>
                <td className="px-4 py-3">
              <Button variant="secondary" onClick={() => handleViewDetail(evt)}>
                    View
                  </Button>
                </td>
              </tr>
            ))}
            {events.length === 0 && !loading && (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-sm text-text-secondary">No audit events found.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {hasMore && (
        <div className="mt-4 flex justify-center">
          <Button variant="secondary" onClick={() => { void fetchEvents(false, page + 1); }} disabled={loading}>
            {loading ? 'Loading…' : 'Load More'}
          </Button>
        </div>
      )}

      {/* Detail Drawer — read-only; no edit/delete controls (FR-005) */}
      {selectedEvent && (
        <Dialog open showCloseButton={false} onClose={() => setSelectedEvent(null)} overlayClassName="fixed inset-0 bg-black/30 z-50 flex justify-end" className="bg-white w-full max-w-lg h-full overflow-y-auto p-6 shadow-xl" label="Audit event detail">
            <div className="flex justify-between items-center mb-4">
              <h2 className="text-lg font-semibold">Event Detail</h2>
              <Button variant="secondary" onClick={() => setSelectedEvent(null)}>Close</Button>
            </div>
            <dl className="space-y-3 text-sm">
              <div><dt className="font-medium">ID</dt><dd className="font-mono">{selectedEvent.id}</dd></div>
              <div><dt className="font-medium">Action</dt><dd>{selectedEvent.action}</dd></div>
              <div><dt className="font-medium">Outcome</dt><dd><Badge variant={outcomeVariant(selectedEvent.outcome)} label={selectedEvent.outcome} /></dd></div>
              <div><dt className="font-medium">Occurred At</dt><dd>{new Date(selectedEvent.occurred_at).toLocaleString()}</dd></div>
              <div><dt className="font-medium">Actor</dt><dd>{selectedEvent.actor_id ?? selectedEvent.actor_type}</dd></div>
              {selectedEvent.resource_type && <div><dt className="font-medium">Resource</dt><dd>{selectedEvent.resource_type} {selectedEvent.resource_id ?? ''}</dd></div>}
              {selectedEvent.before_state && <div><dt className="font-medium">Before State</dt><dd><pre className="text-xs bg-gray-50 p-2 rounded overflow-auto">{JSON.stringify(selectedEvent.before_state, null, 2)}</pre></dd></div>}
              {selectedEvent.after_state && <div><dt className="font-medium">After State</dt><dd><pre className="text-xs bg-gray-50 p-2 rounded overflow-auto">{JSON.stringify(selectedEvent.after_state, null, 2)}</pre></dd></div>}
              {selectedEvent.metadata && <div><dt className="font-medium">Metadata</dt><dd><pre className="text-xs bg-gray-50 p-2 rounded overflow-auto">{JSON.stringify(selectedEvent.metadata, null, 2)}</pre></dd></div>}
            </dl>
          </Dialog>
      )}
    </PageContainer>
  );
}
