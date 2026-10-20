import { redirect } from 'next/navigation';
import { cookies } from 'next/headers';
import { readTokenCookie } from '@/lib/auth/token-cookies';

/** Root index — redirect based on session cookie presence. */
export default function RootPage() {
  const token = readTokenCookie(cookies());
  redirect(token ? '/dashboard' : '/login');
}
