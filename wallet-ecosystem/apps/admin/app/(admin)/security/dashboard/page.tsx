import type { Metadata } from 'next';
import { SecurityDashboardPage } from '@/src/modules/security/pages/dashboard';

export const metadata: Metadata = {
  title: 'Security Dashboard — OleKard Admin',
};

export default function SecurityDashboardRoute() {
  return <SecurityDashboardPage />;
}
