'use client';

import { useEffect } from 'react';
import { ErrorPage } from '@/components/ui/ErrorPage';

export default function RootError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // Surface to an error monitoring service when available.
    console.error('[RootError]', error);
  }, [error]);

  return (
    <ErrorPage
      code="500"
      title="Something went wrong"
      message="An unexpected error occurred. The team has been notified. Try refreshing the page, or return to the dashboard."
      variant="error"
      {...(error.digest ? { correlationId: error.digest } : {})}
      primaryAction={{ label: 'Try again', onClick: reset }}
      secondaryAction={{ label: 'Go to Dashboard', href: '/dashboard' }}
    />
  );
}
