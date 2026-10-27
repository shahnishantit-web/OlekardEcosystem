import { DisputeCaseDetailPage } from '@/src/modules/payment/pages/dispute-case-detail';

export const metadata = { title: 'Dispute Case — OleKard Admin' };

interface Props {
  params: { id: string };
}

export default function Page({ params }: Props) {
  return <DisputeCaseDetailPage id={params.id} />;
}
