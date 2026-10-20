/**
 * T066 — Admin layout accessibility tests.
 * Mounts AdminShell and asserts zero axe violations on nav, header, breadcrumbs, buttons, links.
 */
import React from 'react';
import { render } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';

expect.extend(toHaveNoViolations);

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
    {
      title: 'Identity & Access',
      requiredPermission: undefined,
      items: [
        { label: 'Users', href: '/users', requiredPermission: undefined },
      ],
    },
  ],
}));

import { AdminShell } from '@/components/layout/AdminShell';

describe('AdminShell layout — axe accessibility', () => {
  it('has no axe violations on nav, header, buttons and links', async () => {
    const { container } = render(
      <AdminShell>
        <div>
          <h1>Dashboard</h1>
          <p>Content area</p>
        </div>
      </AdminShell>
    );

    const results = await axe(container, {
      rules: {
        'color-contrast': { enabled: false },
      },
    });
    expect(results).toHaveNoViolations();
  });

  it('nav landmark is present', async () => {
    const { container } = render(
      <AdminShell>
        <div>content</div>
      </AdminShell>
    );
    expect(container.querySelector('nav')).not.toBeNull();
  });

  it('breadcrumb nav has aria-label', async () => {
    const { container } = render(
      <AdminShell>
        <div>content</div>
      </AdminShell>
    );
    const breadcrumbNav = container.querySelector('nav[aria-label="Breadcrumb"]');
    expect(breadcrumbNav).not.toBeNull();
  });

  it('all buttons have accessible names', async () => {
    const { container } = render(
      <AdminShell>
        <div>content</div>
      </AdminShell>
    );
    const buttons = container.querySelectorAll('button');
    buttons.forEach((btn) => {
      const name = btn.getAttribute('aria-label') ?? btn.textContent;
      expect(name?.trim().length).toBeGreaterThan(0);
    });
  });

  it('all anchor elements have href', async () => {
    const { container } = render(
      <AdminShell>
        <div>content</div>
      </AdminShell>
    );
    const links = container.querySelectorAll('a');
    links.forEach((link) => {
      expect(link.getAttribute('href')).toBeTruthy();
    });
  });
});
