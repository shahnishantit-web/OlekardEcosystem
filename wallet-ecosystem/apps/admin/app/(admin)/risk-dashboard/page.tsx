import type { Metadata } from 'next';
import { RiskDashboardPage } from '@/src/modules/dashboard/pages/risk-dashboard';

export const metadata: Metadata = {
  title: 'Risk Dashboard — OleKard Admin',
};

export default function RiskDashboardRoute() {
  return <RiskDashboardPage />;
}
