interface KpiCardProps {
  label: string;
  value: number | string | null;
  unit?: string;
  trend?: { value: number; label?: string };
  refreshedAt?: Date | null;
  loading?: boolean;
  error?: boolean;
  onRetry?: () => void;
}

export function KpiCard({ label, value, unit, trend, refreshedAt, loading, error, onRetry }: KpiCardProps) {
  if (loading) {
    return (
      <article
        aria-label={`${label} — loading`}
        aria-busy="true"
        className="bg-ok-card-bg border border-ok-card-border rounded-[11px] p-5 min-h-[136px] flex flex-col"
        style={{ boxShadow: 'var(--ok-card-shadow)' }}
      >
        <div className="flex justify-between items-center mb-4">
          <div className="h-3 w-24 rounded bg-neutral-200 animate-pulse" />
          <div className="h-8 w-8 rounded-lg bg-neutral-100 animate-pulse" />
        </div>
        <div className="h-8 w-20 rounded bg-neutral-200 animate-pulse mb-2" />
        <div className="h-2 w-32 rounded bg-neutral-100 animate-pulse" />
      </article>
    );
  }

  if (error) {
    return (
      <article
        aria-label={`${label} — failed to load`}
        className="bg-ok-card-bg border border-ok-card-border rounded-[11px] p-5 min-h-[136px] flex flex-col"
        style={{ boxShadow: 'var(--ok-card-shadow)' }}
      >
        <span className="text-xs font-medium text-text-secondary mb-4">{label}</span>
        <p className="text-sm text-ok-error-fg">Failed to load</p>
        {onRetry && (
          <button
            type="button"
            onClick={onRetry}
            className="self-start text-xs font-medium text-ok-primary hover:underline focus-visible:outline-2 mt-2"
          >
            Retry
          </button>
        )}
      </article>
    );
  }

  const displayValue =
    value === null
      ? '—'
      : typeof value === 'number'
      ? value.toLocaleString()
      : value;

  const trendPositive = trend && trend.value > 0;

  return (
    <article
      aria-label={label}
      className="bg-ok-card-bg border border-ok-card-border rounded-[11px] p-5 min-h-[136px] flex flex-col"
      style={{ boxShadow: 'var(--ok-card-shadow)' }}
    >
      {/* Header row: label + icon placeholder */}
      <div className="flex justify-between items-center mb-4">
        <span className="text-xs font-medium text-[#727d90] tracking-wide">
          {label}
        </span>
        <span
          className="w-8 h-8 border border-[#edf0f4] rounded-lg flex items-center justify-center text-[#7180a2]"
          aria-hidden="true"
        >
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
            <path d="M2 8h12M8 2l6 6-6 6" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
          </svg>
        </span>
      </div>

      {/* Value */}
      <span
        className="text-[29px] font-bold text-text-primary tabular-nums leading-none tracking-tight mb-2"
        aria-label={`${label}: ${displayValue}${unit ? ` ${unit}` : ''}`}
      >
        {displayValue}
        {unit && (
          <span className="text-sm font-normal text-text-secondary ml-1">{unit}</span>
        )}
      </span>

      {/* Trend */}
      {trend ? (
        <div className="flex items-center gap-1.5 text-xs">
          <span className={trendPositive ? 'text-ok-success-fg font-semibold' : 'text-ok-warning-fg font-semibold'}>
            {trendPositive ? '↗' : '●'} {trend.value > 0 ? '+' : ''}{trend.value}%
          </span>
          <span className="text-[#929baa]">{trend.label ?? 'vs previous period'}</span>
        </div>
      ) : refreshedAt ? (
        <time dateTime={refreshedAt.toISOString()} className="text-xs text-text-secondary/60">
          {refreshedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
        </time>
      ) : null}
    </article>
  );
}
