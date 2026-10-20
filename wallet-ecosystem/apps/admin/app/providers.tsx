'use client';

import { useState } from 'react';
import { ThemeProvider } from 'next-themes';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider } from '@/lib/auth/context';
import type { ActorDTO } from '@/lib/auth/context';

interface ProvidersProps {
  children: React.ReactNode;
  initialToken?: string;
  initialUser?: ActorDTO | null;
  initialSessionId?: string;
}

export function Providers({
  children,
  initialToken,
  initialUser,
  initialSessionId,
}: ProvidersProps) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 60_000,
            retry: 1,
          },
        },
      }),
  );

  return (
    <ThemeProvider attribute="class" storageKey="admin-theme" enableSystem>
      <QueryClientProvider client={queryClient}>
        <AuthProvider
          {...(initialToken     ? { initialAccessToken: initialToken     } : {})}
          {...(initialUser      ? { initialUser:         initialUser      } : {})}
          {...(initialSessionId ? { initialSessionId:    initialSessionId } : {})}
        >
          {children}
        </AuthProvider>
      </QueryClientProvider>
    </ThemeProvider>
  );
}
