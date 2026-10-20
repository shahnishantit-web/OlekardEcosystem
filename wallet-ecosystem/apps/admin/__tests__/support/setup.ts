import '@testing-library/jest-dom';
import { toHaveNoViolations } from 'jest-axe';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';
import { render, RenderOptions } from '@testing-library/react';
import { TextEncoder, TextDecoder } from 'util';

// jsdom doesn't ship TextEncoder/TextDecoder; polyfill them so WebAuthn and
// other browser APIs that encode strings work correctly in tests.
if (typeof globalThis.TextEncoder === 'undefined') {
  globalThis.TextEncoder = TextEncoder as unknown as typeof globalThis.TextEncoder;
  globalThis.TextDecoder = TextDecoder as unknown as typeof globalThis.TextDecoder;
}

expect.extend(toHaveNoViolations);

// Mock next/router for tests
jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn(), replace: jest.fn() }),
  usePathname: () => '/',
}));

export function renderWithProviders(
  ui: React.ReactElement,
  options?: Omit<RenderOptions, 'wrapper'>,
) {
  // Lazy require so test-level jest.mock() calls for auth dependencies take effect
  // before context.tsx binds to them. A top-level import here would pre-cache
  // context.tsx with the real auth module before the test's mock is applied.
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const { AuthProvider } = require('@/lib/auth/context') as typeof import('@/lib/auth/context');

  function Providers({ children }: { children: React.ReactNode }) {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });

    return React.createElement(
      QueryClientProvider,
      { client: queryClient },
      React.createElement(AuthProvider, null, children),
    );
  }

  return render(ui, { wrapper: Providers, ...options });
}
