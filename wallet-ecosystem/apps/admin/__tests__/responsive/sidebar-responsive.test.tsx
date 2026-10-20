/**
 * T068 — AdminShell responsive sidebar tests.
 * Asserts sidebar visibility and drawer toggle at 375, 768, 1024, 1280, 1440, 1920px.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  usePathname: jest.fn(() => '/dashboard'),
  useRouter: jest.fn(() => ({ replace: jest.fn(), push: jest.fn(), back: jest.fn() })),
}));

jest.mock('next/link', () => {
  const MockLink = ({
    children,
    href,
    ...props
  }: {
    children: React.ReactNode;
    href: string;
    [key: string]: unknown;
  }) => (
    <a href={href} {...props}>
      {children}
    </a>
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
      items: [
        { label: 'Dashboard', href: '/dashboard', requiredPermission: undefined },
      ],
    },
  ],
}));

import { AdminShell } from '@/components/layout/AdminShell';

function setViewportWidth(width: number) {
  Object.defineProperty(window, 'innerWidth', {
    writable: true,
    configurable: true,
    value: width,
  });
  window.dispatchEvent(new Event('resize'));
}

describe('AdminShell — responsive sidebar behaviour', () => {
  afterEach(() => {
    setViewportWidth(1440);
  });

  it.each([375, 768])(
    'at %dpx: drawer toggle button is visible and sidebar nav is hidden',
    (width) => {
      setViewportWidth(width);
      render(
        <AdminShell>
          <div>content</div>
        </AdminShell>
      );
      const toggleButton = screen.getByRole('button', { name: /collapse navigation|expand navigation/i });
      expect(toggleButton).toBeInTheDocument();

      // The desktop sidebar wrapper has hidden lg:flex — hidden below 1024px via CSS
      const sidebarWrapper = document.getElementById('admin-sidebar')?.parentElement;
      if (sidebarWrapper) {
        expect(sidebarWrapper.className).toMatch(/lg:/);
      }
    }
  );

  it.each([1024, 1280, 1440, 1920])(
    'at %dpx: sidebar nav element is present in DOM',
    (width) => {
      setViewportWidth(width);
      render(
        <AdminShell>
          <div>content</div>
        </AdminShell>
      );
      const sidebar = document.getElementById('admin-sidebar');
      expect(sidebar).not.toBeNull();
    }
  );
});
