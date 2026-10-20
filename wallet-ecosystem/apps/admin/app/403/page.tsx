'use client';

import { useRouter } from 'next/navigation';
import { ErrorPage } from '@/components/ui/ErrorPage';

/**
 * Middleware redirects to /403 when the authenticated user lacks the required
 * permission slug for a route (see middleware.ts ADMINAPP_PERMISSION_MAP).
 */
export default function ForbiddenPage() {
  const router = useRouter();
  return (
    <ErrorPage
      code="403"
      title="Access denied"
      message="You don't have permission to view this page. If you believe this is a mistake, contact your system administrator."
      variant="warning"
      primaryAction={{ label: 'Go to Dashboard', href: '/dashboard' }}
      secondaryAction={{ label: 'Go back', onClick: () => router.back() }}
    />
  );
}
