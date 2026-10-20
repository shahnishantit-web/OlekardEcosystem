import type { Metadata } from 'next';
import { UserProfilePage } from '@/src/modules/profile/pages/user-profile';

export const metadata: Metadata = {
  title: 'User Profile — OleKard Admin',
};

interface UserProfileRouteProps {
  params: { id: string };
}

export default function UserProfileRoute({ params }: UserProfileRouteProps) {
  return <UserProfilePage userId={params.id} />;
}
