import type { Metadata } from 'next';
import { TransactionManagementPage } from '@/src/modules/transactions/pages/transaction-management';

export const metadata: Metadata = {
  title: 'Transaction Management — OleKard Admin',
};

export default function TransactionsRoute() {
  return <TransactionManagementPage />;
}
