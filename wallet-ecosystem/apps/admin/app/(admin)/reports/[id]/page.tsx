import type { Metadata } from 'next';
import { ReportJobDetailPage } from '@/src/modules/reports/pages/report-job-detail';

export const metadata: Metadata = {
  title: 'Report Job — OleKard Admin',
};

interface Props {
  params: { id: string };
}

export default function ReportJobDetailRoute({ params }: Props) {
  return <ReportJobDetailPage jobId={params.id} />;
}
