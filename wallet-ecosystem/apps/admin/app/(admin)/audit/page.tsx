import type { Metadata } from 'next';
import { AuditEventsPage } from '@/src/modules/audit/pages/audit-events';

export const metadata: Metadata = {
  title: 'Audit Events — OleKard Admin',
};

export default function AuditEventsRoute() {
  return <AuditEventsPage />;
}
