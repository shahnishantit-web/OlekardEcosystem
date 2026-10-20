import type { Metadata } from 'next';
import { ReportScheduleListPage } from '@/src/modules/reports/pages/report-schedule-list';

export const metadata: Metadata = {
  title: 'Report Schedules — OleKard Admin',
};

export default function ReportSchedulesRoute() {
  return <ReportScheduleListPage />;
}
