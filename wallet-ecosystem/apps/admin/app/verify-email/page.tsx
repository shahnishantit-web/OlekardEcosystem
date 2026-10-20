'use client';

import { Suspense } from 'react';
import { useSearchParams } from 'next/navigation';
import { VerifyEmailPage } from '@/src/modules/auth/pages/verify-email';

function VerifyEmailInner() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token');
  const email = searchParams.get('email');
  return <VerifyEmailPage token={token} email={email} />;
}

export default function VerifyEmailRoute() {
  return (
    <Suspense>
      <VerifyEmailInner />
    </Suspense>
  );
}
