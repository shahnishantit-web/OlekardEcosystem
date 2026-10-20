import type { Metadata } from 'next';
import { ComplianceStatusPage } from '@/src/modules/audit/pages/compliance-status';

export const metadata: Metadata = {
  title: 'Compliance Status — OleKard Admin',
};

export default function ComplianceStatusRoute() {
  return <ComplianceStatusPage />;
}
