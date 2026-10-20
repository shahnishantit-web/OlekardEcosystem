import type { Metadata } from 'next';
import { FeeRuleDetailPage } from '@/src/modules/fees/pages/fee-rule-detail';

export const metadata: Metadata = {
  title: 'Fee Rule Detail — OleKard Admin',
};

export default function Page({ params }: { params: { id: string } }) {
  return <FeeRuleDetailPage ruleId={params.id} />;
}
