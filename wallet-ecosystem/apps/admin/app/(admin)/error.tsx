'use client';

import { useEffect } from 'react';
import Link from 'next/link';
import { Button } from '@/components/ui/Button';

/**
 * Catches render errors within the (admin) route group.
 * Rendered inside AdminShell (sidebar + header remain visible).
 */
export default function AdminError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error('[AdminError]', error);
  }, [error]);

  return (
    <div
      role="alert"
      className="flex flex-col items-center justify-center min-h-[calc(100vh-var(--header-height))] px-6 py-12 text-center"
    >
      <div className="w-full max-w-[400px]">

        {/* Icon */}
        <div
          className="mx-auto mb-6 w-[64px] h-[64px] rounded-full bg-ok-error-bg flex items-center justify-center"
          aria-hidden="true"
        >
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="text-ok-error-fg">
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        </div>

        <h1 className="text-xl font-bold text-text-primary mb-2">Something went wrong</h1>
        <p className="text-sm text-text-secondary leading-relaxed mb-2 max-w-xs mx-auto">
          {error.message && error.message !== 'An error occurred in the Server Components render.'
            ? error.message
            : 'An unexpected error occurred while loading this page.'}
        </p>

        {error.digest && (
          <p className="text-xs text-text-secondary mb-6">
            Reference:{' '}
            <code className="font-mono bg-neutral-100 px-1.5 py-0.5 rounded text-[11px]">
              {error.digest}
            </code>
          </p>
        )}
        {!error.digest && <div className="mb-6" />}

        <div className="flex flex-col sm:flex-row items-center justify-center gap-3">
          <Button onClick={reset}>Try again</Button>
          <Link
            href="/dashboard"
            className="text-sm text-text-secondary hover:text-text-primary transition-colors"
          >
            Go to Dashboard
          </Link>
        </div>
      </div>
    </div>
  );
}
