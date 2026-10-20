import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ConfigurationApprovalDetailPage } from '@/src/modules/configuration/pages/configuration-approval-detail';

export const metadata: Metadata = {
  title: 'Change Request Detail — Configuration Management — OleKard Admin',
};

interface Props {
  params: { id: string };
}

export default function ConfigurationApprovalDetailRoute({ params }: Props) {
  return (
    <Suspense fallback={<div className="p-8 text-muted-foreground">Loading change request...</div>}>
      <ConfigurationApprovalDetailPage changeRequestId={params.id} />
    </Suspense>
  );
}
