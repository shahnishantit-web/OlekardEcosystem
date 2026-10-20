import type { Metadata } from 'next';
import { AccountStatusPage } from '@/src/modules/profile/pages/account-status';

export const metadata: Metadata = {
  title: 'Account Status — OleKard Admin',
};

interface AccountStatusRouteProps {
  params: { id: string };
}

export default function AccountStatusRoute({ params }: AccountStatusRouteProps) {
  return <AccountStatusPage userId={params.id} />;
}
