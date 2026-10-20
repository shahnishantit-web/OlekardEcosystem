'use client';

import { Suspense } from 'react';
import { MfaEnrollmentPage } from '@/src/modules/auth/pages/mfa-enrollment';

export default function MfaEnrollmentRoute() {
  return (
    <Suspense>
      <MfaEnrollmentPage />
    </Suspense>
  );
}
