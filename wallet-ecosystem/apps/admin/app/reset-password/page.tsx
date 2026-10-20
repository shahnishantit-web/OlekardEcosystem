'use client';

import { Suspense } from 'react';
import { useSearchParams } from 'next/navigation';
import { ResetPasswordPage } from '@/src/modules/auth/pages/reset-password';

function ResetPasswordInner() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token');
  return <ResetPasswordPage token={token} />;
}

export default function ResetPasswordRoute() {
  return (
    <Suspense>
      <ResetPasswordInner />
    </Suspense>
  );
}
