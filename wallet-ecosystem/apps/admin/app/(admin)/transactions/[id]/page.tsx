import type { Metadata } from 'next';
import { TransactionDetailPage } from '@/src/modules/transactions/pages/transaction-detail';

export const metadata: Metadata = {
  title: 'Transaction Detail — OleKard Admin',
};

export default function TransactionDetailRoute({ params }: { params: { id: string } }) {
  return <TransactionDetailPage transactionId={params.id} />;
}
