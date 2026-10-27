'use client';

type ExportPhaseStatus = 'idle' | 'pending' | 'processing' | 'downloadable' | 'error';

interface Props {
  status: ExportPhaseStatus;
  downloadUrl?: string;
  rowCount?: number;
  onRetry?: () => void;
}

export function ExportProgressBar({ status, downloadUrl, rowCount, onRetry }: Props) {
  if (status === 'idle') return null;

  if (status === 'pending' || status === 'processing') {
    return (
      <div role="status" aria-live="polite" className="flex items-center gap-3 rounded-lg border border-ok-info-border bg-ok-info-bg px-4 py-3">
        <div className="h-4 w-4 animate-spin rounded-full border-2 border-ok-info-fg border-t-transparent" aria-hidden="true" />
        <span className="text-sm text-ok-info-fg">
          Exporting{rowCount != null ? ` ${rowCount.toLocaleString()} rows` : ''}…
        </span>
      </div>
    );
  }

  if (status === 'downloadable' && downloadUrl) {
    return (
      <div role="status" className="flex items-center gap-3 rounded-lg border border-ok-success-border bg-ok-success-bg px-4 py-3">
        <span className="text-sm text-ok-success-fg">Export ready.</span>
        <a
          href={downloadUrl}
          className="text-sm font-medium text-ok-success-fg underline hover:no-underline"
          download
        >
          Download CSV
        </a>
      </div>
    );
  }

  if (status === 'error') {
    return (
      <div role="alert" className="flex items-center justify-between rounded-lg border border-ok-error-border bg-ok-error-bg px-4 py-3">
        <span className="text-sm text-ok-error-fg">Export failed.</span>
        {onRetry && (
          <button
            type="button"
            onClick={onRetry}
            className="text-sm font-medium text-ok-error-fg underline hover:no-underline"
          >
            Retry
          </button>
        )}
      </div>
    );
  }

  return null;
}
