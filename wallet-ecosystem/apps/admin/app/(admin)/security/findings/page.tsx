import type { Metadata } from 'next';
import { SecurityFindingsPage } from '@/src/modules/security/pages/findings';

export const metadata: Metadata = {
  title: 'Security Findings — OleKard Admin',
};

export default function SecurityFindingsRoute() {
  return <SecurityFindingsPage />;
}
