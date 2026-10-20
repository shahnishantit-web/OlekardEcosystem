'use client';

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <div role="alert" aria-live="assertive">
      <h2>Something went wrong</h2>
      {error.digest && (
        <p>
          Correlation ID: <code>{error.digest}</code>
        </p>
      )}
      <button type="button" onClick={reset}>
        Try again
      </button>
    </div>
  );
}
