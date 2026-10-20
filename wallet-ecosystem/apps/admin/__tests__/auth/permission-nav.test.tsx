/**
 * US-FOUND-001 / US-FOUND-002 — Permission-filtered navigation tests.
 *
 * AC: Nav items are shown/hidden based on the authenticated user's permissions.
 * AC: Items with requiredPermission are hidden when the user lacks that permission.
 * AC: Items without requiredPermission are always shown to authenticated users.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';

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

const mockHasPermission = jest.fn();

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(() => ({
    isAuthenticated: true,
    user: { id: 'admin-1', email: 'admin@olekard.local', name: 'Admin User' },
    hasPermission: mockHasPermission,
    permissions: [],
  })),
}));

jest.mock('@/src/lib/navigation/nav-items', () => ({
  NAV_SECTIONS: [
    {
      title: 'Workspace',
      items: [
        { href: '/dashboard', label: 'Dashboard' },
        { href: '/financial-dashboard', label: 'Financial Dashboard', requiredPermission: 'adminapp.dashboard.financial' },
        { href: '/risk-dashboard', label: 'Risk Dashboard', requiredPermission: 'adminapp.dashboard.risk' },
      ],
    },
    {
      title: 'Management',
      items: [
        { href: '/audit', label: 'Audit Events' },
        {
          href: '/security/findings',
          label: 'Vulnerabilities',
          requiredPermission: 'security.findings.read',
        },
      ],
    },
  ],
}));

import { AdminSidebar } from '@/components/layout/AdminSidebar';

describe('AdminSidebar — permission-filtered navigation (US-FOUND-001/002)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('shows unrestricted nav items to all authenticated users', () => {
    mockHasPermission.mockReturnValue(false);
    render(<AdminSidebar currentPath="/dashboard" collapsed={false} onToggle={jest.fn()} />);
    expect(screen.getByRole('link', { name: /dashboard/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /audit events/i })).toBeInTheDocument();
  });

  it('hides permission-gated items when user lacks the permission', () => {
    mockHasPermission.mockReturnValue(false);
    render(<AdminSidebar currentPath="/dashboard" collapsed={false} onToggle={jest.fn()} />);
    expect(screen.queryByRole('link', { name: /financial dashboard/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /risk dashboard/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /vulnerabilities/i })).not.toBeInTheDocument();
  });

  it('shows permission-gated items when user has the required permission', () => {
    mockHasPermission.mockImplementation((slug: string) =>
      slug === 'adminapp.dashboard.financial' || slug === 'security.findings.read',
    );
    render(<AdminSidebar currentPath="/dashboard" collapsed={false} onToggle={jest.fn()} />);
    expect(screen.getByRole('link', { name: /financial dashboard/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /vulnerabilities/i })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /risk dashboard/i })).not.toBeInTheDocument();
  });

  it('Vulnerabilities nav item (US-FOUND-002): hidden without security.findings.read', () => {
    mockHasPermission.mockReturnValue(false);
    render(<AdminSidebar currentPath="/dashboard" collapsed={false} onToggle={jest.fn()} />);
    expect(screen.queryByRole('link', { name: /vulnerabilities/i })).not.toBeInTheDocument();
  });

  it('Vulnerabilities nav item (US-FOUND-002): visible with security.findings.read', () => {
    mockHasPermission.mockImplementation((slug: string) => slug === 'security.findings.read');
    render(<AdminSidebar currentPath="/dashboard" collapsed={false} onToggle={jest.fn()} />);
    expect(screen.getByRole('link', { name: /vulnerabilities/i })).toBeInTheDocument();
  });

  it('shows no nav items for unauthenticated users', () => {
    const { useAuth } = jest.requireMock('@/lib/auth/context') as { useAuth: jest.Mock };
    useAuth.mockReturnValueOnce({
      isAuthenticated: false,
      user: null,
      hasPermission: jest.fn(() => false),
      permissions: [],
    });
    render(<AdminSidebar currentPath="/dashboard" collapsed={false} onToggle={jest.fn()} />);
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('renders user avatar initials in the account area', () => {
    mockHasPermission.mockReturnValue(false);
    render(<AdminSidebar currentPath="/dashboard" collapsed={false} onToggle={jest.fn()} />);
    // "Admin User" → initials "AU"
    expect(screen.getByText('AU')).toBeInTheDocument();
  });
});
