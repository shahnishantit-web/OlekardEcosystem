import type { Metadata } from 'next';
import { UserManagementPage } from '@/src/modules/users/pages/user-management';

export const metadata: Metadata = {
  title: 'User Management — OleKard Admin',
};

export default function UsersRoute() {
  return <UserManagementPage />;
}
