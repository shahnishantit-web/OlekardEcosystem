import type { Metadata } from 'next';
import { SettlementReportPage } from '@/src/modules/reports/pages/settlement-report';

export const metadata: Metadata = {
  title: 'Settlement Records — OleKard Admin',
};

export default function SettlementReportRoute() {
  return <SettlementReportPage />;
}
