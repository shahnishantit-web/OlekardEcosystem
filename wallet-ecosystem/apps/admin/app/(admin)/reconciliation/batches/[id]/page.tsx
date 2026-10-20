import type { Metadata } from 'next';
import { ReconciliationBatchDetailPage } from '@/src/modules/reconciliation/pages/batch-detail';

export const metadata: Metadata = {
  title: 'Reconciliation Batch Detail — OleKard Admin',
};

interface Props {
  params: { id: string };
}

export default function ReconciliationBatchDetailRoute({ params }: Props) {
  return <ReconciliationBatchDetailPage batchId={params.id} />;
}
