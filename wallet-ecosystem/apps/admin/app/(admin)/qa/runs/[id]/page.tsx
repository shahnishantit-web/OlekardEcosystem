import type { Metadata } from 'next';
import { QaRunDetailPage } from '@/src/modules/qa/pages/qa-run-detail';

export const metadata: Metadata = {
  title: 'QA Run Detail — OleKard Admin',
};

export default function QaRunDetailRoute({ params }: { params: { id: string } }) {
  return <QaRunDetailPage runId={params.id} />;
}
