import type { Metadata } from 'next';
import { RoleDetailPage } from '@/src/modules/roles/pages/role-detail';

export const metadata: Metadata = {
  title: 'Role Detail — OleKard Admin',
};

export default function AdminRoleDetailRoute({ params }: { params: { id: string } }) {
  return <RoleDetailPage roleId={params.id} />;
}
