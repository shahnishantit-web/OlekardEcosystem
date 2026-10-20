import type { Metadata } from 'next';
import { AdminDashboardPage } from '@/src/modules/dashboard/pages/admin-dashboard';

export const metadata: Metadata = {
  title: 'Dashboard — OleKard Admin',
};

export default function DashboardRoute() {
  return <AdminDashboardPage />;
}
