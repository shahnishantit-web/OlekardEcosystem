import type { Metadata } from 'next';
import { ErrorPage } from '@/components/ui/ErrorPage';

export const metadata: Metadata = {
  title: 'Account Disabled — OleKard Admin',
};

/**
 * Shown when the login API returns ACCOUNT_DISABLED (account suspended by a super-admin).
 * No backend call required — purely informational.
 */
export default function AccountDisabledPage() {
  return (
    <ErrorPage
      title="Account disabled"
      message="Your admin account has been disabled. Please contact your system administrator for assistance."
      variant="warning"
      primaryAction={{ label: 'Back to sign in', href: '/login' }}
    />
  );
}
