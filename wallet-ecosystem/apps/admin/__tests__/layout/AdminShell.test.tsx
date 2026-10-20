/**
 * T024 — AdminShell layout tests.
 * Verifies: renders children; sidebar toggle; sessionStorage persistence; breadcrumbs render.
 */
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  usePathname: jest.fn(() => '/dashboard'),
  useRouter: jest.fn(() => ({ replace: jest.fn(), push: jest.fn(), back: jest.fn() })),
}));

jest.mock('next/link', () => {
  const MockLink = ({ children, href, ...props }: { children: React.ReactNode; href: string; [key: string]: unknown }) => (
    <a href={href} {...props}>{children}</a>
  );
  MockLink.displayName = 'MockLink';
  return MockLink;
});

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(() => ({ isAuthenticated: true, hasPermission: jest.fn(() => true), permissions: [] })),
}));

jest.mock('@/src/lib/navigation/nav-items', () => ({
  NAV_SECTIONS: [
    {
      title: 'Overview',
      requiredPermission: undefined,
      items: [{ label: 'Dashboard', href: '/dashboard', requiredPermission: undefined }],
    },
  ],
}));

import { AdminShell } from '@/components/layout/AdminShell';

const sessionStorageMock = (() => {
  let store: Record<string, string> = {};
  return {
    getItem: (key: string) => store[key] ?? null,
    setItem: (key: string, value: string) => { store[key] = value; },
    clear: () => { store = {}; },
  };
})();
Object.defineProperty(window, 'sessionStorage', { value: sessionStorageMock });

beforeEach(() => {
  sessionStorageMock.clear();
});

describe('AdminShell', () => {
  it('renders children', () => {
    render(<AdminShell><div>page content</div></AdminShell>);
    expect(screen.getByText('page content')).toBeInTheDocument();
  });

  it('renders main navigation landmark', () => {
    render(<AdminShell><span /></AdminShell>);
    expect(screen.getByRole('navigation', { name: 'Admin navigation' })).toBeInTheDocument();
  });

  it('renders breadcrumb navigation', () => {
    render(<AdminShell><span /></AdminShell>);
    expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toBeInTheDocument();
  });

  it('renders sidebar toggle button with aria-expanded', () => {
    render(<AdminShell><span /></AdminShell>);
    const btn = screen.getByRole('button', { name: /collapse navigation/i });
    expect(btn).toHaveAttribute('aria-expanded', 'true');
  });

  it('sidebar starts expanded when no sessionStorage key set', () => {
    render(<AdminShell><span /></AdminShell>);
    const btn = screen.getByRole('button', { name: /collapse navigation/i });
    expect(btn).toBeInTheDocument();
  });

  it('reads persisted collapsed state from sessionStorage', () => {
    sessionStorageMock.setItem('ok-sidebar-collapsed', 'true');
    render(<AdminShell><span /></AdminShell>);
    expect(screen.getByRole('button', { name: /expand navigation/i })).toBeInTheDocument();
  });

  it('active nav item has aria-current=page', () => {
    render(<AdminShell><span /></AdminShell>);
    const dashLink = screen.getByRole('link', { name: /dashboard/i });
    expect(dashLink).toHaveAttribute('aria-current', 'page');
  });
});
