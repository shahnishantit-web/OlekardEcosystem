import type { Metadata } from 'next';
import { FinancialDashboardPage } from '@/src/modules/dashboard/pages/financial-dashboard';

export const metadata: Metadata = {
  title: 'Financial Dashboard — OleKard Admin',
};

export default function FinancialDashboardRoute() {
  return <FinancialDashboardPage />;
}
