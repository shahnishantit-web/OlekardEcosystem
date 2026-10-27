/**
 * ADM-P1-012 — Role & Permission Management
 * US-P1-012-001: Super-admin views role catalogue
 * US-P1-012-002: Super-admin assigns a role to an admin user
 * US-P1-012-003: Super-admin creates a custom role
 */
import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ back: jest.fn(), push: jest.fn(), replace: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ children, href, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

// ── API mocks ────────────────────────────────────────────────────────────────

const mockListRoles             = jest.fn();
const mockGetRole               = jest.fn();
const mockUpdateRolePermissions = jest.fn();
const mockCreateRole            = jest.fn();
const mockAssignUserRole        = jest.fn();
const mockGetUser               = jest.fn();
const mockListWallets           = jest.fn();
const mockListTransactions      = jest.fn();
const mockUpdateUserStatus      = jest.fn();

jest.mock('@/lib/api/admin-app-client', () => {
  const SYSTEM_ROLE_NAMES = ['admin', 'operations', 'finance', 'compliance'];
  const ALL_PERMISSION_SLUGS = [
    { slug: 'adminapp.dashboard.operational', label: 'View Operational Dashboard', group: 'Dashboard' },
    { slug: 'adminapp.dashboard.financial',   label: 'View Financial Dashboard',   group: 'Dashboard' },
    { slug: 'adminapp.dashboard.risk',        label: 'View Risk Dashboard',        group: 'Dashboard' },
    { slug: 'adminapp.users.read',            label: 'Read Users',                 group: 'Users' },
    { slug: 'adminapp.users.write',           label: 'Manage Users',               group: 'Users' },
    { slug: 'adminapp.roles.manage',          label: 'Manage Roles',               group: 'Roles' },
    { slug: 'adminapp.kyc.review',            label: 'Review KYC',                 group: 'KYC' },
    { slug: 'adminapp.kyc.approve',           label: 'Approve KYC',                group: 'KYC' },
    { slug: 'adminapp.kyc.reject',            label: 'Reject KYC',                 group: 'KYC' },
    { slug: 'adminapp.wallets.read',          label: 'Read Wallets',               group: 'Wallets' },
    { slug: 'adminapp.wallets.freeze',        label: 'Freeze Wallets',             group: 'Wallets' },
    { slug: 'adminapp.wallets.unfreeze',      label: 'Unfreeze Wallets',           group: 'Wallets' },
    { slug: 'adminapp.transactions.read',        label: 'Read Transactions',        group: 'Transactions' },
    { slug: 'adminapp.transactions.investigate', label: 'Investigate Transactions', group: 'Transactions' },
    { slug: 'adminapp.transactions.reverse',     label: 'Reverse Transactions',     group: 'Transactions' },
    { slug: 'adminapp.agents.approve',        label: 'Approve Agents',             group: 'Agents' },
    { slug: 'adminapp.agents.manage',         label: 'Manage Agents',              group: 'Agents' },
    { slug: 'adminapp.float.manage',          label: 'Manage Agent Float',         group: 'Float' },
    { slug: 'adminapp.reconciliation.read',   label: 'View Reconciliation',        group: 'Reconciliation' },
    { slug: 'adminapp.settlement.read',       label: 'View Settlement',            group: 'Settlement' },
    { slug: 'adminapp.audit.read',            label: 'View Audit Log',             group: 'Audit' },
    { slug: 'adminapp.reports.read',          label: 'View Reports',               group: 'Reports' },
  ];
  return {
    AdminAppClient: jest.fn().mockImplementation(() => ({
      listRoles:             mockListRoles,
      getRole:               mockGetRole,
      updateRolePermissions: mockUpdateRolePermissions,
      createRole:            mockCreateRole,
      assignUserRole:        mockAssignUserRole,
      getUser:               mockGetUser,
      listWallets:           mockListWallets,
      listTransactions:      mockListTransactions,
      updateUserStatus:      mockUpdateUserStatus,
    })),
    SYSTEM_ROLE_NAMES,
    ALL_PERMISSION_SLUGS,
  };
});

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

// ── Imports (after mocks) ─────────────────────────────────────────────────────

import { RoleManagementPage } from '@/src/modules/roles/pages/role-management';
import { RoleDetailPage }     from '@/src/modules/roles/pages/role-detail';
import { UserDetailPage }     from '@/src/modules/users/pages/user-detail';
import * as authContext from '@/lib/auth/context';

const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;
const MOCK_CLIENT = {} as never;

// ── Fixtures ──────────────────────────────────────────────────────────────────

const ROLE_ADMIN = {
  role_id:          'role-001',
  role_name:        'admin',
  permission_slugs: ['adminapp.users.read', 'adminapp.users.write', 'adminapp.roles.manage'],
  user_count:       0,
  created_at:       '2026-01-01T00:00:00Z',
};

const ROLE_OPERATIONS = {
  role_id:          'role-002',
  role_name:        'operations',
  permission_slugs: ['adminapp.dashboard.operational', 'adminapp.users.read'],
  user_count:       0,
  created_at:       '2026-01-01T00:00:00Z',
};

const ROLE_CUSTOM = {
  role_id:          'role-003',
  role_name:        'support_agent',
  permission_slugs: ['adminapp.users.read'],
  user_count:       0,
  created_at:       '2026-09-01T00:00:00Z',
};

const ROLES_LIST = { data: [ROLE_ADMIN, ROLE_OPERATIONS, ROLE_CUSTOM], meta: { total: 3, per_page: 50, current_page: 1, last_page: 1 } };

const MOCK_USER = {
  id: 'usr-001',
  full_name: 'Kefilwe Mokoena',
  email: 'kefilwe@example.com',
  phone_number: '+267 71 123 456',
  status: 'active',
  kyc_tier: 2,
  wallet_count: 1,
  actor_type: 'CUSTOMER',
  created_at: '2026-01-15T00:00:00Z',
};

// ── Setup helpers ─────────────────────────────────────────────────────────────

beforeEach(() => {
  mockUseAuth.mockReturnValue({ authenticatedClient: MOCK_CLIENT } as never);
  mockListRoles.mockResolvedValue({ data: ROLES_LIST.data, meta: ROLES_LIST.meta });
  mockGetRole.mockImplementation(async (roleId: string) => ({
    data: roleId === ROLE_ADMIN.role_id ? ROLE_ADMIN : roleId === ROLE_CUSTOM.role_id ? ROLE_CUSTOM : null,
  }));
  mockUpdateRolePermissions.mockResolvedValue({ data: ROLE_ADMIN });
  mockCreateRole.mockResolvedValue({ data: ROLE_CUSTOM });
  mockAssignUserRole.mockResolvedValue({ data: { user_id: 'usr-001', role_id: 'role-001', assigned_at: '2026-10-13T00:00:00Z' } });
  mockGetUser.mockResolvedValue({ data: MOCK_USER });
  mockListWallets.mockResolvedValue({ data: [], meta: { total: 0, per_page: 10, current_page: 1, last_page: 1 } });
  mockListTransactions.mockResolvedValue({ data: [], meta: { total: 0, per_page: 10, current_page: 1, last_page: 1 } });
});

afterEach(() => jest.clearAllMocks());

// =============================================================================
// US-P1-012-001 — Super-admin views role catalogue
// =============================================================================

describe('US-P1-012-001 — Role list', () => {
  test('renders loading state initially', () => {
    mockListRoles.mockReturnValue(new Promise(() => {}));
    render(<RoleManagementPage />);
    expect(screen.getByText(/loading roles/i)).toBeInTheDocument();
  });

  test('renders roles table with Name, Type, Permissions columns', async () => {
    render(<RoleManagementPage />);
    await waitFor(() => expect(screen.getByRole('table', { name: /roles table/i })).toBeInTheDocument());
    expect(screen.getByText('admin')).toBeInTheDocument();
    expect(screen.getByText('operations')).toBeInTheDocument();
    expect(screen.getByText('support_agent')).toBeInTheDocument();
  });

  test('marks known system roles as SYSTEM type', async () => {
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByText('admin'));
    const rows = screen.getAllByRole('row');
    const adminRow = rows.find(r => within(r).queryByText('admin'));
    expect(within(adminRow!).getByText('SYSTEM')).toBeInTheDocument();
  });

  test('marks custom roles as CUSTOM type', async () => {
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByText('support_agent'));
    const rows = screen.getAllByRole('row');
    const customRow = rows.find(r => within(r).queryByText('support_agent'));
    expect(within(customRow!).getByText('CUSTOM')).toBeInTheDocument();
  });

  test('shows permission count for each role', async () => {
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByText('admin'));
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  test('shows a semantic Manage link to the role detail page', async () => {
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('link', { name: /manage role admin/i }));
    expect(screen.getByRole('link', { name: /manage role admin/i }))
      .toHaveAttribute('href', `/roles/${ROLE_ADMIN.role_id}`);
  });

  test('renders a safe zero permission count when the API omits permission slugs', async () => {
    mockListRoles.mockResolvedValue({ data: [{ ...ROLE_ADMIN, permission_slugs: null as unknown as string[] }], meta: { total: 1, per_page: 50, current_page: 1, last_page: 1 } });
    render(<RoleManagementPage />);
    await waitFor(() => expect(screen.getByText('admin')).toBeInTheDocument());
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  test('shows empty state when no roles', async () => {
    mockListRoles.mockResolvedValue({ data: [], meta: { total: 0, per_page: 50, current_page: 1, last_page: 1 } });
    render(<RoleManagementPage />);
    await waitFor(() => expect(screen.getByText(/no roles found/i)).toBeInTheDocument());
  });

  test('shows error state on fetch failure', async () => {
    mockListRoles.mockRejectedValue(new Error('Network error'));
    render(<RoleManagementPage />);
    await waitFor(() => expect(screen.getByText(/failed to load roles/i)).toBeInTheDocument());
  });

  test('shows Create Role button', async () => {
    render(<RoleManagementPage />);
    expect(screen.getByRole('button', { name: /create new role/i })).toBeInTheDocument();
  });
});

// =============================================================================
// US-P1-012-003 — Super-admin creates a custom role
// =============================================================================

describe('US-P1-012-003 — Create custom role', () => {
  test('opens Create Role dialog on button click', async () => {
    const user = userEvent.setup();
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /create new role/i }));
    expect(screen.getByRole('dialog', { name: /create custom role/i })).toBeInTheDocument();
  });

  test('dialog shows role name field and permission checkboxes', async () => {
    const user = userEvent.setup();
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /create new role/i }));
    expect(screen.getByLabelText(/role name/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/view operational dashboard/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/manage roles/i)).toBeInTheDocument();
  });

  test('Create Role button is disabled when name is empty', async () => {
    const user = userEvent.setup();
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /create new role/i }));
    expect(screen.getByRole('button', { name: /confirm create role/i })).toBeDisabled();
  });

  test('Create Role button enabled after entering name', async () => {
    const user = userEvent.setup();
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /create new role/i }));
    await user.type(screen.getByLabelText(/role name/i), 'support_agent');
    expect(screen.getByRole('button', { name: /confirm create role/i })).not.toBeDisabled();
  });

  test('submits createRole with name and selected permissions', async () => {
    const user = userEvent.setup();
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /create new role/i }));
    await user.type(screen.getByLabelText(/role name/i), 'support_agent');
    await user.click(screen.getByLabelText(/read users/i));
    await user.click(screen.getByRole('button', { name: /confirm create role/i }));
    await waitFor(() => expect(mockCreateRole).toHaveBeenCalledWith(
      'support_agent',
      expect.arrayContaining(['adminapp.users.read']),
      expect.any(String),
    ));
  });

  test('shows success message after role created', async () => {
    const user = userEvent.setup();
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /create new role/i }));
    await user.type(screen.getByLabelText(/role name/i), 'support_agent');
    await user.click(screen.getByRole('button', { name: /confirm create role/i }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/support_agent.*created/i));
  });

  test('closes dialog on Cancel', async () => {
    const user = userEvent.setup();
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /cancel/i }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  test('shows error when createRole fails', async () => {
    mockCreateRole.mockRejectedValue(new Error('Server error'));
    const user = userEvent.setup();
    render(<RoleManagementPage />);
    await waitFor(() => screen.getByRole('button', { name: /create new role/i }));
    await user.click(screen.getByRole('button', { name: /create new role/i }));
    await user.type(screen.getByLabelText(/role name/i), 'test_role');
    await user.click(screen.getByRole('button', { name: /confirm create role/i }));
    await waitFor(() => expect(screen.getByText(/failed to create role/i)).toBeInTheDocument());
  });
});

// =============================================================================
// Role Detail — permission matrix (part of US-P1-012-001 and US-P1-012-003)
// =============================================================================

describe('Role Detail — permission matrix', () => {
  test('renders loading state initially', () => {
    mockListRoles.mockReturnValue(new Promise(() => {}));
    render(<RoleDetailPage roleId="role-001" />);
    expect(screen.getByText(/loading role/i)).toBeInTheDocument();
  });

  test('finds role by ID from list and displays it', async () => {
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /save role permissions/i })).toBeInTheDocument());
    expect(screen.getByText('admin')).toBeInTheDocument();
  });

  test('shows SYSTEM badge for system roles', async () => {
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    expect(screen.getByText('SYSTEM')).toBeInTheDocument();
  });

  test('shows CUSTOM badge for custom roles', async () => {
    render(<RoleDetailPage roleId="role-003" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    expect(screen.getByText('CUSTOM')).toBeInTheDocument();
  });

  test('pre-checks the role permission slugs', async () => {
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    const readUsersCheck = screen.getByLabelText('Read Users');
    expect(readUsersCheck).toBeChecked();
  });

  test('permissions not on role are unchecked', async () => {
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    const viewReportsCheck = screen.getByLabelText('View Reports');
    expect(viewReportsCheck).not.toBeChecked();
  });

  test('Save Permissions button is disabled when no changes', async () => {
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    expect(screen.getByRole('button', { name: /save role permissions/i })).toBeDisabled();
  });

  test('Save Permissions button enabled after toggling a permission', async () => {
    const user = userEvent.setup();
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    await user.click(screen.getByLabelText('View Reports'));
    expect(screen.getByRole('button', { name: /save role permissions/i })).not.toBeDisabled();
  });

  test('calls updateRolePermissions with full slug set on save', async () => {
    const user = userEvent.setup();
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    await user.click(screen.getByLabelText('View Reports'));
    await user.click(screen.getByRole('button', { name: /save role permissions/i }));
    await waitFor(() => expect(mockUpdateRolePermissions).toHaveBeenCalledWith(
      'role-001',
      expect.arrayContaining(['adminapp.users.read', 'adminapp.reports.read']),
      expect.any(String),
    ));
  });

  test('shows success message after saving', async () => {
    const user = userEvent.setup();
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    await user.click(screen.getByLabelText('View Reports'));
    await user.click(screen.getByRole('button', { name: /save role permissions/i }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/permissions updated/i));
  });

  test('shows error on save failure', async () => {
    mockUpdateRolePermissions.mockRejectedValue(new Error('Server error'));
    const user = userEvent.setup();
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    await user.click(screen.getByLabelText('View Reports'));
    await user.click(screen.getByRole('button', { name: /save role permissions/i }));
    await waitFor(() => expect(screen.getByText(/failed to save permissions/i)).toBeInTheDocument());
  });

  test('shows error when role ID not found', async () => {
    render(<RoleDetailPage roleId="role-nonexistent" />);
    await waitFor(() => expect(screen.getByText(/role not found/i)).toBeInTheDocument());
  });

  test('groups permissions by module category', async () => {
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    expect(screen.getByText('Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Users')).toBeInTheDocument();
    expect(screen.getByText('KYC')).toBeInTheDocument();
    expect(screen.getByText('Transactions')).toBeInTheDocument();
  });

  test('Back button is present', async () => {
    render(<RoleDetailPage roleId="role-001" />);
    await waitFor(() => screen.getByRole('button', { name: /save role permissions/i }));
    expect(screen.getByRole('button', { name: /back to roles list/i })).toBeInTheDocument();
  });
});

// =============================================================================
// US-P1-012-002 — Super-admin assigns a role to an admin user
// =============================================================================

describe('US-P1-012-002 — Assign role to user', () => {
  test('shows Edit Roles button on user detail page', async () => {
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => expect(screen.getByRole('button', { name: /edit roles for this user/i })).toBeInTheDocument());
  });

  test('opens Assign Role dialog on Edit Roles click', async () => {
    const user = userEvent.setup();
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /edit roles for this user/i }));
    expect(screen.getByRole('dialog', { name: /assign role/i })).toBeInTheDocument();
  });

  test('dialog shows all available roles in select', async () => {
    const user = userEvent.setup();
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /edit roles for this user/i }));
    const select = screen.getByRole('combobox', { name: /role/i });
    expect(select).toBeInTheDocument();
    expect(within(select as HTMLElement).getByRole('option', { name: /admin/ })).toBeInTheDocument();
    expect(within(select as HTMLElement).getByRole('option', { name: /operations/ })).toBeInTheDocument();
  });

  test('Assign Role button disabled until role selected', async () => {
    const user = userEvent.setup();
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /edit roles for this user/i }));
    expect(screen.getByRole('button', { name: /confirm role assignment/i })).toBeDisabled();
  });

  test('Assign Role button enabled after selecting a role', async () => {
    const user = userEvent.setup();
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.selectOptions(screen.getByRole('combobox', { name: /role/i }), 'role-001');
    expect(screen.getByRole('button', { name: /confirm role assignment/i })).not.toBeDisabled();
  });

  test('calls assignUserRole with userId and roleId', async () => {
    const user = userEvent.setup();
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.selectOptions(screen.getByRole('combobox', { name: /role/i }), 'role-001');
    await user.click(screen.getByRole('button', { name: /confirm role assignment/i }));
    await waitFor(() => expect(mockAssignUserRole).toHaveBeenCalledWith(
      'usr-001',
      'role-001',
      expect.any(String),
    ));
  });

  test('shows success message after role assigned', async () => {
    const user = userEvent.setup();
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.selectOptions(screen.getByRole('combobox', { name: /role/i }), 'role-001');
    await user.click(screen.getByRole('button', { name: /confirm role assignment/i }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/role assigned/i));
  });

  test('closes dialog on Cancel', async () => {
    const user = userEvent.setup();
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /cancel/i }));
    expect(screen.queryByRole('dialog', { name: /assign role/i })).not.toBeInTheDocument();
  });

  test('shows error when assignUserRole fails', async () => {
    mockAssignUserRole.mockRejectedValue(new Error('Server error'));
    const user = userEvent.setup();
    render(<UserDetailPage userId="usr-001" />);
    await waitFor(() => screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.click(screen.getByRole('button', { name: /edit roles for this user/i }));
    await user.selectOptions(screen.getByRole('combobox', { name: /role/i }), 'role-001');
    await user.click(screen.getByRole('button', { name: /confirm role assignment/i }));
    await waitFor(() => expect(screen.getByText(/failed to assign role/i)).toBeInTheDocument());
  });
});
