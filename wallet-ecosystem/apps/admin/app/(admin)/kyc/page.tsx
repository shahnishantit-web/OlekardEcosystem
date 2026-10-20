import type { Metadata } from 'next';
import { KycReviewPage } from '@/src/modules/kyc/pages/kyc-review';

export const metadata: Metadata = {
  title: 'KYC Review Queue — OleKard Admin',
};

export default function KycQueueRoute() {
  return <KycReviewPage />;
}
