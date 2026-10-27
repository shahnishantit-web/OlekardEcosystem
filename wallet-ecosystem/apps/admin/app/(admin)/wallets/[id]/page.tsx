import type { Metadata } from 'next';
import { WalletDetailPage } from '@/src/modules/wallets/pages/wallet-detail';

export const metadata: Metadata = {
  title: 'Wallet Detail — OleKard Admin',
};

export default function WalletDetailRoute({ params }: { params: { id: string } }) {
  return <WalletDetailPage walletId={params.id} />;
}
