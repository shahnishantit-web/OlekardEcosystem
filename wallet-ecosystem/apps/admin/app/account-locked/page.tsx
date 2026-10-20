import type { Metadata } from 'next';
import { ErrorPage } from '@/components/ui/ErrorPage';

export const metadata: Metadata = {
  title: 'Account Locked — OleKard Admin',
};

/**
 * Shown when the login API returns ACCOUNT_LOCKED (≥5 consecutive failed logins).
 * No backend call required — purely informational.
 */
export default function AccountLockedPage() {
  return (
    <ErrorPage
      title="Account locked"
      message="Your account has been locked. Contact your administrator."
      variant="warning"
      primaryAction={{ label: 'Back to sign in', href: '/login' }}
    />
  );
}
