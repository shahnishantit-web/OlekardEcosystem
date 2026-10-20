import type { Metadata } from 'next';
import { FeeRulesListPage } from '@/src/modules/fees/pages/fee-rules-list';

export const metadata: Metadata = {
  title: 'Fee Rules — OleKard Admin',
};

export default function FeeRulesRoute() {
  return <FeeRulesListPage />;
}
