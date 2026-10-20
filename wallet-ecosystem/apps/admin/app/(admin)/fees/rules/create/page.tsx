import type { Metadata } from 'next';
import { FeeRuleCreatePage } from '@/src/modules/fees/pages/fee-rule-create';

export const metadata: Metadata = {
  title: 'Create Fee Rule — OleKard Admin',
};

export default function FeeRuleCreateRoute() {
  return <FeeRuleCreatePage />;
}
