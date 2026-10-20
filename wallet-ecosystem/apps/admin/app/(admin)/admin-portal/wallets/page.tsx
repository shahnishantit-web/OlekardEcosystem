import type { Metadata } from 'next';
import { WalletManagementPage } from '@/src/modules/wallets/pages/wallet-management';

export const metadata: Metadata = {
  title: 'Wallet Management — OleKard Admin',
};

export default function AdminWalletsRoute() {
  return <WalletManagementPage />;
}
