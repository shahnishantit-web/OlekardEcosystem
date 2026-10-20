'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Table } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { useAuth } from '@/lib/auth/context';
import { getDeviceSecurityEvents } from '@/src/modules/devices/api/devices';
import type { DeviceSecurityEvent } from '@/src/modules/devices/types/device';
import { isApiError } from '@/lib/utils/errors';

interface DeviceSecurityEventsLogProps {
  deviceId: string;
}

const COLUMNS = [
  {
    key: 'event_type',
    header: 'Event',
    render: (row: DeviceSecurityEvent) => (
      <Badge
        label={row.event_type.replace(/_/g, ' ')}
        variant="neutral"
      />
    ),
  },
  {
    key: 'created_at',
    header: 'Detected at',
    render: (row: DeviceSecurityEvent) => (
      <time dateTime={row.created_at} className="text-sm text-text-secondary">
        {new Date(row.created_at).toLocaleString()}
      </time>
    ),
  },
  {
    key: 'resolution',
    header: 'Resolution',
    render: (row: DeviceSecurityEvent) => (
      <span className="text-sm text-text-secondary">
        {row.resolution ?? '—'}
      </span>
    ),
  },
  {
    key: 'resolved_at',
    header: 'Resolved at',
    render: (row: DeviceSecurityEvent) => (
      row.resolved_at ? (
        <time dateTime={row.resolved_at} className="text-sm text-text-secondary">
          {new Date(row.resolved_at).toLocaleString()}
        </time>
      ) : (
        <span className="text-sm text-text-secondary">—</span>
      )
    ),
  },
  {
    key: 'expires',
    header: 'Ack. expires',
    render: (row: DeviceSecurityEvent) => (
      row.acknowledgment_expires_at ? (
        <time dateTime={row.acknowledgment_expires_at} className="text-sm text-text-secondary">
          {new Date(row.acknowledgment_expires_at).toLocaleString()}
        </time>
      ) : (
        <span className="text-sm text-text-secondary">—</span>
      )
    ),
  },
];

export function DeviceSecurityEventsLog({ deviceId }: DeviceSecurityEventsLogProps) {
  const { authenticatedClient } = useAuth();
  const headingId = useId();

  const [events, setEvents] = useState<DeviceSecurityEvent[]>([]);
  const [meta, setMeta] = useState({ total: 0, per_page: 20, current_page: 1, last_page: 1 });
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);

  const fetchEvents = useCallback(
    async (page: number) => {
      if (!authenticatedClient) return;
      setLoading(true);
      setFetchError(null);
      try {
        const data = await getDeviceSecurityEvents(authenticatedClient, deviceId, page, 20);
        setEvents(data.data);
        setMeta(data.meta);
      } catch (err) {
        if (!isApiError(err)) {
          setFetchError('Failed to load security events. Please try again.');
        }
      } finally {
        setLoading(false);
      }
    },
    [authenticatedClient, deviceId],
  );

  useEffect(() => {
    void fetchEvents(1);
  }, [fetchEvents]);

  return (
    <section aria-labelledby={headingId} className="mt-8">
      <h2 id={headingId} className="text-base font-semibold text-text-primary mb-3">
        Security Events
      </h2>

      {loading && <Loading label="Loading security events…" />}

      {!loading && fetchError && (
        <ErrorState
          message={fetchError}
          onRetry={() => void fetchEvents(meta.current_page)}
        />
      )}

      {!loading && !fetchError && events.length === 0 && (
        <Empty
          title="No security events"
          description="No security events have been recorded for this device."
        />
      )}

      {!loading && !fetchError && events.length > 0 && (
        <>
          <Table
            caption="Device security events"
            columns={COLUMNS}
            data={events}
            getRowKey={(row) => row.id}
          />

          {meta.last_page > 1 && (
            <nav
              aria-label="Security events pagination"
              className="mt-4 flex items-center gap-2"
            >
              <Button
                variant="secondary"
                onClick={() => void fetchEvents(meta.current_page - 1)}
                disabled={meta.current_page <= 1}
                aria-label="Previous page"
              >
                Previous
              </Button>
              <span className="text-sm text-text-secondary" aria-live="polite">
                Page {meta.current_page} of {meta.last_page}
              </span>
              <Button
                variant="secondary"
                onClick={() => void fetchEvents(meta.current_page + 1)}
                disabled={meta.current_page >= meta.last_page}
                aria-label="Next page"
              >
                Next
              </Button>
            </nav>
          )}
        </>
      )}
    </section>
  );
}
