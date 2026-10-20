import type { Metadata } from 'next';
import { ReconciliationBatchListPage } from '@/src/modules/reconciliation/pages/batch-list';

export const metadata: Metadata = {
  title: 'Reconciliation Batches — OleKard Admin',
};

export default function ReconciliationBatchesRoute() {
  return <ReconciliationBatchListPage />;
}
