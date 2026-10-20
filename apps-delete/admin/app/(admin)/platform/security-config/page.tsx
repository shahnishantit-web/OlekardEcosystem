import type { Metadata } from 'next';
import { AdminSecurityConfigPage } from '@/src/modules/configuration/pages/security-config';

export const metadata: Metadata = {
  title: 'Security Configuration — OleKard Admin',
};

export default function SecurityConfigRoute() {
  return <AdminSecurityConfigPage />;
}
