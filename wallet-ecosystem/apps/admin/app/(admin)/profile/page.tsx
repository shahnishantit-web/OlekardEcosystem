'use client';

import { useAuth } from '@/lib/auth/context';
import { UserProfilePage } from '@/src/modules/profile/pages/user-profile';
import { Loading } from '@/components/ui/Loading';

export default function ProfilePage() {
  const { user, isAuthenticated } = useAuth();

  if (!isAuthenticated || !user?.id) {
    return <Loading label="Loading profile…" />;
  }

  return <UserProfilePage userId={user.id} canSuspend={false} />;
}
