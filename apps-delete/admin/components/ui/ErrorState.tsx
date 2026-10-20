interface ErrorStateProps {
  title?: string;
  message: string;
  onRetry?: () => void;
  retryLabel?: string;
}

export function ErrorState({
  title = 'Something went wrong',
  message,
  onRetry,
  retryLabel = 'Try again',
}: ErrorStateProps) {
  return (
    <div role="alert" className="flex flex-col items-center justify-center py-12 text-center px-4">
      <div aria-hidden="true" className="mb-4 text-error-primary text-4xl">⚠</div>
      <h3 className="text-base font-semibold text-text-primary mb-2">{title}</h3>
      <p className="text-sm text-text-secondary max-w-xs mb-6">{message}</p>
      {onRetry && (
        <button
          onClick={onRetry}
          className="px-4 py-2 border border-neutral-300 rounded-md text-sm font-medium text-text-primary hover:bg-neutral-50 focus-visible:outline-2"
          style={{ minHeight: '44px' }}
        >
          {retryLabel}
        </button>
      )}
    </div>
  );
}
