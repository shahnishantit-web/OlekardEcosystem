import type { Metadata } from 'next';
import { KycDetailPage } from '@/src/modules/kyc/pages/kyc-detail';

export const metadata: Metadata = {
  title: 'KYC Submission Detail — OleKard Admin',
};

export default function KycDetailRoute({ params }: { params: { id: string } }) {
  return <KycDetailPage submissionId={params.id} />;
}
