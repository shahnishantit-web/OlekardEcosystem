import type { Metadata } from 'next';
import { Suspense } from 'react';
import { AdminLoginPage } from '@/src/modules/auth/pages/login';

export const metadata: Metadata = {
  title: 'Sign In — OleKard Admin',
};

// Suspense required because AdminLoginPage reads useSearchParams
// (e.g. ?reason=session_expired passed by AdminShell on auto-signout).
export default function LoginRoute() {
  return (
    <Suspense>
      <AdminLoginPage />
    </Suspense>
  );
}
