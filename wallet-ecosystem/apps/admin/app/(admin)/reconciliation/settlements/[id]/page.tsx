import { SettlementDetailPage } from '@/src/modules/reconciliation/pages/settlement-detail';

export default function SettlementDetailRoute({ params }: { params: { id: string } }) {
  return <SettlementDetailPage settlementId={params.id} />;
}
