'use client';

import { useEffect, useState } from 'react';

interface Props {
  fetchedAt: Date;
  thresholdSeconds?: number;
  onRefresh?: () => void;
}

const DEFAULT_THRESHOLD =
  typeof process !== 'undefined' && process.env.NEXT_PUBLIC_FINANCE_DASHBOARD_STALE_THRESHOLD_SECONDS
    ? parseInt(process.env.NEXT_PUBLIC_FINANCE_DASHBOARD_STALE_THRESHOLD_SECONDS, 10)
    : 300;

export function StaleDataBanner({ fetchedAt, thresholdSeconds = DEFAULT_THRESHOLD, onRefresh }: Props) {
  const [isStale, setIsStale] = useState(false);

  useEffect(() => {
    const check = () => {
      setIsStale((Date.now() - fetchedAt.getTime()) > thresholdSeconds * 1000);
    };
    check();
    const interval = setInterval(check, 30_000);
    return () => clearInterval(interval);
  }, [fetchedAt, thresholdSeconds]);

  if (!isStale) return null;

  const timeStr = fetchedAt.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });

  return (
    <div
      role="status"
      aria-live="polite"
      className="flex items-center justify-between rounded-lg border border-ok-warning-border bg-ok-warning-bg px-4 py-2.5"
    >
      <p className="text-sm text-ok-warning-fg">
        Data may be outdated — last refreshed at {timeStr}
      </p>
      {onRefresh && (
        <button
          type="button"
          onClick={onRefresh}
          className="ml-4 text-sm font-medium text-ok-warning-fg underline hover:no-underline focus-visible:outline-2"
        >
          Refresh
        </button>
      )}
    </div>
  );
}
