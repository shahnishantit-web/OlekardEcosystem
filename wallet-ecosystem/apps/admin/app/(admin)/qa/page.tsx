import type { Metadata } from 'next';
import { QaDashboardPage } from '@/src/modules/qa/pages/qa-dashboard';

export const metadata: Metadata = {
  title: 'QA Dashboard — OleKard Admin',
};

export default function QaDashboardRoute() {
  return <QaDashboardPage />;
}
