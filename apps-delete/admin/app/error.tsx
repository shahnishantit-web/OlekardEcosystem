'use client';

import { useEffect } from 'react';

interface ErrorProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function Error({ error, reset }: ErrorProps) {
  useEffect(() => {
    console.warn('Unhandled application error:', error);
  }, [error]);

  const correlationId = error.digest;

  return (
    <div role="alert" className="flex min-h-screen items-center justify-center p-8">
      <div className="max-w-md text-center">
        <h1 className="text-2xl font-semibold text-text-primary mb-4">
          Something went wrong
        </h1>
        <p className="text-text-secondary mb-4">
          An unexpected error occurred. Please try again.
        </p>
        {correlationId && (
          <p className="text-xs text-text-secondary mb-6 font-mono">
            Reference: {correlationId}
          </p>
        )}
        <button
          onClick={reset}
          className="px-4 py-2 bg-brand-primary text-white rounded-md hover:opacity-90 focus-visible:outline-2"
          style={{ minHeight: '44px' }}
        >
          Try again
        </button>
      </div>
    </div>
  );
}
