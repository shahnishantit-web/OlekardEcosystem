import type { Metadata } from 'next';
import { AdminSessionsPage } from '@/src/modules/auth/pages/sessions';

export const metadata: Metadata = {
  title: 'Session Management — OleKard Admin',
};

export default function SessionsRoute() {
  return <AdminSessionsPage />;
}
