'use client';

import { useEffect, useState, useCallback } from 'react';
import { useAuth } from '@/lib/auth/context';

interface AuditEventItem {
  id: string;
  event_type: string;
  actor_type: string;
  action: string;
  outcome: 'success' | 'failure' | 'denied';
  occurred_at: string;
}

interface AuditEventsResponse {
  data: AuditEventItem[];
}

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60_000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

const OUTCOME_STYLES: Record<string, string> = {
  success: 'text-ok-success',
  failure: 'text-ok-error',
  denied:  'text-ok-warning',
};

export function ActivityFeed() {
  const { authenticatedClient } = useAuth();
  const [items, setItems] = useState<AuditEventItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const fetchActivity = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(false);
    try {
      const result = await authenticatedClient.get<AuditEventsResponse>(
        '/v1/audit/events?per_page=5&sort=occurred_at_desc',
      );
      setItems(result.data?.data ?? []);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void fetchActivity();
  }, [fetchActivity]);

  return (
    <section
      className="bg-ok-card-bg border border-ok-card-border rounded-[11px] p-4"
      aria-label="Recent operational activity"
    >
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-sm font-semibold text-text-primary">Operational Activity</h2>
      </div>

      {loading && (
        <ul aria-busy="true" aria-label="Loading recent activity" className="space-y-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <li key={i} className="flex items-center gap-3">
              <div className="h-3 w-24 rounded bg-neutral-200 animate-pulse" />
              <div className="h-3 w-16 rounded bg-neutral-200 animate-pulse ml-auto" />
            </li>
          ))}
        </ul>
      )}

      {error && (
        <p className="text-sm text-text-secondary py-4 text-center">
          System events unavailable
        </p>
      )}

      {!loading && !error && items.length === 0 && (
        <p className="text-sm text-text-secondary py-4 text-center">No recent system events</p>
      )}

      {!loading && !error && items.length > 0 && (
        <ul className="divide-y divide-ok-border">
          {items.map((event) => (
            <li key={event.id} className="flex items-center gap-3 py-2 min-w-0">
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 min-w-0">
                  <span className="text-xs font-medium text-text-primary capitalize truncate">
                    {event.event_type.replace(/_/g, ' ')}
                  </span>
                  <span
                    className={[
                      'text-[10px] font-semibold uppercase tracking-wide shrink-0',
                      OUTCOME_STYLES[event.outcome] ?? 'text-text-secondary',
                    ].join(' ')}
                  >
                    {event.outcome}
                  </span>
                </div>
                <div className="text-[10px] text-text-secondary truncate mt-0.5">
                  {event.actor_type}
                </div>
              </div>
              <div className="text-[10px] text-text-secondary shrink-0">
                {timeAgo(event.occurred_at)}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
