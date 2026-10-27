import type { Metadata } from 'next';
import { FloatManagementAdminPortalPage } from '@/src/modules/float/pages/float-management';

export const metadata: Metadata = {
  title: 'Agent Float Management — OleKard Admin',
};

export default function FloatRoute() {
  return <FloatManagementAdminPortalPage />;
}
