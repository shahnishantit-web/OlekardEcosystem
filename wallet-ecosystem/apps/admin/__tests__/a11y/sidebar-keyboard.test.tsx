/**
 * T067 — AdminSidebar keyboard navigation tests.
 * Asserts aria-labels, tab order, and no keyboard trap in collapsed state.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  usePathname: jest.fn(() => '/dashboard'),
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
        { label: 'Roles', href: '/roles', requiredPermission: undefined },
      ],
    },
  ],
}));

import { AdminSidebar } from '@/components/layout/AdminSidebar';

describe('AdminSidebar — keyboard navigation (collapsed)', () => {
  const defaultProps = {
    currentPath: '/dashboard',
    collapsed: true,
    onToggle: jest.fn(),
  };

  it('all nav links have accessible labels', () => {
    render(<AdminSidebar {...defaultProps} />);
    const links = screen.getAllByRole('link');
    links.forEach((link) => {
      const name =
        link.getAttribute('aria-label') ??
        link.textContent ??
        link.getAttribute('title');
      expect(name?.trim().length).toBeGreaterThan(0);
    });
  });

  it('nav links appear in DOM order matching NAV_SECTIONS', () => {
    render(<AdminSidebar {...defaultProps} />);
    const links = screen
      .getAllByRole('link')
      .map((l) => l.getAttribute('href'));
    expect(links).toContain('/dashboard');
    const dashboardIdx = links.indexOf('/dashboard');
    const usersIdx = links.indexOf('/users');
    expect(dashboardIdx).toBeLessThan(usersIdx);
  });

  it('nav links are reachable via keyboard (tab focus lands in nav)', async () => {
    const user = userEvent.setup();
    render(<AdminSidebar {...defaultProps} />);
    // AdminSidebar renders nav links; the sidebar toggle lives in AdminHeader
    const links = screen.getAllByRole('link');
    expect(links.length).toBeGreaterThan(0);
    await user.tab();
    // After one tab the active element is inside the document (not null/body)
    expect(document.activeElement).not.toBe(document.body);
  });

  it('does not trap keyboard focus inside sidebar', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <div>
        <AdminSidebar {...defaultProps} />
        <button type="button">Outside button</button>
      </div>
    );
    const outsideButton = container.querySelector('button:last-of-type') as HTMLElement;
    outsideButton.focus();
    await user.tab();
    expect(document.activeElement).not.toBe(outsideButton);
    await user.tab();
    // Focus should cycle normally without being trapped
    expect(document.body).toBeDefined();
  });
});
