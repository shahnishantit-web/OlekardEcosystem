import type { Metadata } from 'next';
import { ReportListPage } from '@/src/modules/reports/pages/report-list';

export const metadata: Metadata = {
  title: 'Reports — OleKard Admin',
};

export default function ReportsRoute() {
  return <ReportListPage />;
}
