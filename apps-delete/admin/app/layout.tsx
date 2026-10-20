import type { Metadata } from 'next';
import { Providers } from './providers';
import '../styles/globals.css';

export const metadata: Metadata = {
  title: 'OleKard Admin',
  description: 'OleKard platform administration',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en" data-theme="admin" suppressHydrationWarning>
      <body className="bg-bg-primary text-text-primary min-h-screen">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
