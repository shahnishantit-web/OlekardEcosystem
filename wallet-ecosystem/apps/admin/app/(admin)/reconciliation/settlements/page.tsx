import type { Metadata } from 'next';
import { SettlementListPage } from '@/src/modules/reconciliation/pages/settlement-list';

export const metadata: Metadata = {
  title: 'Settlements — OleKard Admin',
};

export default function SettlementsRoute() {
  return <SettlementListPage />;
}
