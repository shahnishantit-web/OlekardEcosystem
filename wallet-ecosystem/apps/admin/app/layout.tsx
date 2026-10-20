import type { Metadata } from 'next';
import { isUnsupportedBrowser } from '@/lib/system/browser-support';
import { UnsupportedBrowserContent } from '@/components/system/UnsupportedBrowserContent';
import { headers } from 'next/headers';
import { cookies } from 'next/headers';
import { readTokenCookie } from '@/lib/auth/token-cookies';
import { PublicStatusBoundary } from '@/components/system/PublicStatusBoundary';
import { Providers } from './providers';
import type { ActorDTO } from '@/lib/auth/context';
import '../styles/globals.css';
export const metadata: Metadata = {
    title: 'OleKard Admin',
    description: 'OleKard platform administration',
};
export default function RootLayout({ children, }: {
    children: React.ReactNode;
}) {
    // Read auth session from cookies server-side so the initial server render and
    // client hydration agree on the authenticated state (no hydration mismatch,
    // no extra /me API call on every page load).
    if (isUnsupportedBrowser(headers().get('user-agent') ?? ''))
        return <html lang="en"><body><UnsupportedBrowserContent /></body></html>;
    const cookieStore = cookies();
    const token = readTokenCookie(cookieStore);
    const sessionId = cookieStore.get('admin_sid')?.value;
    let user: ActorDTO | undefined;
    const userRaw = cookieStore.get('admin_user')?.value;
    if (userRaw !== undefined && userRaw !== '') {
        try {
            user = JSON.parse(decodeURIComponent(userRaw)) as ActorDTO;
        }
        catch {
            // Corrupt cookie — silently ignore; middleware will redirect to /login
            // when the missing token cookie is detected on the next navigation.
        }
    }
    return (<html lang="en" data-theme="admin" suppressHydrationWarning>
      <body className="bg-bg-primary text-text-primary min-h-screen">
        <Providers {...(token !== undefined && token !== '' ? { initialToken: token } : {})} {...(user ? { initialUser: user } : {})} {...(sessionId !== undefined && sessionId !== '' ? { initialSessionId: sessionId } : {})}>
          <PublicStatusBoundary>{children}</PublicStatusBoundary>
        </Providers>
      </body>
    </html>);
}
