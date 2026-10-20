import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ConfigurationApprovalsPage } from '@/src/modules/configuration/pages/configuration-approvals';

export const metadata: Metadata = {
  title: 'Pending Approvals — Configuration Management — OleKard Admin',
};

export default function ConfigurationApprovalsRoute() {
  return (
    <Suspense fallback={<div className="p-8 text-muted-foreground">Loading approvals...</div>}>
      <ConfigurationApprovalsPage />
    </Suspense>
  );
}
