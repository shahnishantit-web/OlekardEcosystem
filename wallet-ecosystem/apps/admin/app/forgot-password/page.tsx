import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ForgotPasswordPage } from '@/src/modules/auth/pages/forgot-password';

export const metadata: Metadata = {
  title: 'Reset Password — OleKard Admin',
};

export default function ForgotPasswordRoute() {
  return (
    <Suspense>
      <ForgotPasswordPage />
    </Suspense>
  );
}
