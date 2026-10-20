import type { Metadata } from 'next';
import { AdminLoginPage } from '@/src/modules/auth/pages/login';

export const metadata: Metadata = {
  title: 'Sign In — OleKard Admin',
};

export default function LoginRoute() {
  return <AdminLoginPage />;
}
