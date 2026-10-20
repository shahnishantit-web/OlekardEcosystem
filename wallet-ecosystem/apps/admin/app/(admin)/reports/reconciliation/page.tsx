import type { Metadata } from 'next';
import { ReconciliationReportPage } from '@/src/modules/reports/pages/reconciliation-report';

export const metadata: Metadata = {
  title: 'Reconciliation Batches — OleKard Admin',
};

export default function ReconciliationReportRoute() {
  return <ReconciliationReportPage />;
}
