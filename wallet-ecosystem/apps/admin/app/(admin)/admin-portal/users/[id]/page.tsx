import type { Metadata } from 'next';
import { UserDetailPage } from '@/src/modules/users/pages/user-detail';

export const metadata: Metadata = {
  title: 'User Detail — OleKard Admin',
};

export default function UserDetailRoute({ params }: { params: { id: string } }) {
  return <UserDetailPage userId={params.id} />;
}
