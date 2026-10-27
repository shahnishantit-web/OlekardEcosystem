import { PayoutRequestDetailPage } from '@/src/modules/payment/pages/payout-request-detail';

export const metadata = { title: 'Payout Request — OleKard Admin' };

interface Props {
  params: { id: string };
}

export default function Page({ params }: Props) {
  return <PayoutRequestDetailPage id={params.id} />;
}
