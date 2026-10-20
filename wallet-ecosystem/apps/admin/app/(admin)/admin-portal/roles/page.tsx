import type { Metadata } from 'next';
import { RoleManagementPage } from '@/src/modules/roles/pages/role-management';

export const metadata: Metadata = {
  title: 'Roles & Permissions — OleKard Admin',
};

export default function AdminRolesRoute() {
  return <RoleManagementPage />;
}
