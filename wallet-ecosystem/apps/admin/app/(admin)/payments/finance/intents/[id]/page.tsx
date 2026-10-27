import { IntentTimelinePage } from '@/src/modules/payment/pages/intent-timeline';

export const metadata = { title: 'Intent Timeline — OleKard Admin' };

interface Props {
  params: { id: string };
}

export default function Page({ params }: Props) {
  return <IntentTimelinePage id={params.id} />;
}
