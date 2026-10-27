'use client';

import { Dialog } from '@/components/ui/Dialog';

import { Suspense, useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import {
  AdminAppClient,
  ALL_PERMISSION_SLUGS,
  SYSTEM_ROLE_NAMES,
} from '@/lib/api/admin-app-client';
import type { AdminRole } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

function isSystemRole(name: string): boolean {
  return (SYSTEM_ROLE_NAMES as readonly string[]).includes(name);
}

function PermissionMatrix({
  selected,
  onChange,
}: {
  selected: Set<string>;
  onChange: (slug: string, checked: boolean) => void;
}) {
  const groups = ALL_PERMISSION_SLUGS.reduce<Record<string, typeof ALL_PERMISSION_SLUGS>>(
    (acc, p) => {
      (acc[p.group] ??= []).push(p);
      return acc;
    },
    {},
  );

  return (
    <div className="space-y-4 max-h-80 overflow-y-auto pr-1">
      {Object.entries(groups).map(([group, perms]) => (
        <fieldset key={group}>
          <legend className="text-xs font-semibold uppercase text-text-secondary mb-1">{group}</legend>
          <div className="flex flex-col gap-1">
            {perms.map(p => (
              <label key={p.slug} className="flex items-center gap-2 cursor-pointer text-sm text-text-primary">
                <input
                  type="checkbox"
                  checked={selected.has(p.slug)}
                  onChange={e => onChange(p.slug, e.target.checked)}
                  className="h-4 w-4"
                />
                {p.label}
              </label>
            ))}
          </div>
        </fieldset>
      ))}
    </div>
  );
}

interface CreateRoleDialog {
  open: boolean;
  name: string;
  selectedSlugs: Set<string>;
}

function RoleManagementInner() {
  const { authenticatedClient } = useAuth();
  const searchParams = useSearchParams();
  const page = parseInt(searchParams.get('page') ?? '1', 10);

  const [roles, setRoles]         = useState<AdminRole[]>([]);
  const [loading, setLoading]     = useState(false);
  const [error, setError]         = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [dialog, setDialog] = useState<CreateRoleDialog>({
    open: false,
    name: '',
    selectedSlugs: new Set(),
  });

  const fetchRoles = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listRoles({ page: String(page), per_page: '50' });
      setRoles(Array.isArray(res.data) ? res.data.map(role => ({
        ...role,
        permission_slugs: Array.isArray(role.permission_slugs) ? role.permission_slugs : [],
      })) : []);
    } catch {
      setError('Failed to load roles.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, page]);

  useEffect(() => { void fetchRoles(); }, [fetchRoles]);

  const openCreateDialog = () => {
    setSuccessMsg(null);
    setDialog({ open: true, name: '', selectedSlugs: new Set() });
  };

  const closeDialog = () =>
    setDialog({ open: false, name: '', selectedSlugs: new Set() });

  const togglePerm = (slug: string, checked: boolean) => {
    setDialog(d => {
      const next = new Set(d.selectedSlugs);
      if (checked) next.add(slug); else next.delete(slug);
      return { ...d, selectedSlugs: next };
    });
  };

  const handleCreate = async () => {
    if (!authenticatedClient || !dialog.name.trim()) return;
    setActionLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const idemKey = generateIdempotencyKey(`create-role-${dialog.name.trim()}`);
      await client.createRole(dialog.name.trim(), [...dialog.selectedSlugs], idemKey);
      setSuccessMsg(`Role "${dialog.name.trim()}" created.`);
      closeDialog();
      void fetchRoles();
    } catch {
      setError('Failed to create role.');
    } finally {
      setActionLoading(false);
    }
  };

  return (
    <PageContainer>
      <PageHeader
        title="Roles & Permissions"
        actions={
          <Button variant="primary" onClick={openCreateDialog} aria-label="Create new role">
            Create Role
          </Button>
        }
      />

      {loading && <Loading label="Loading roles…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchRoles()} />}

      {successMsg && (
        <div role="status" className="mb-4 rounded bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
          {successMsg}
        </div>
      )}

      {!loading && !error && (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full" aria-label="Roles table">
              <thead className="bg-ok-surface">
                <tr>
                  {['Name', 'Type', 'Permissions', 'Actions'].map(h => (
                    <th
                      key={h}
                      className="py-3 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {roles.length === 0 && (
                  <tr>
                    <td colSpan={4} className="py-8 text-center text-sm text-text-secondary">
                      No roles found.
                    </td>
                  </tr>
                )}
                {roles.map(role => {
                  const type = isSystemRole(role.role_name) ? 'system' : 'custom';
                  return (
                    <tr key={role.role_id} className="border-t border-ok-border hover:bg-ok-hover-bg">
                      <td className="py-3 px-4 text-sm font-medium text-text-primary">
                        {role.role_name}
                      </td>
                      <td className="py-3 px-4">
                        <Badge
                          label={type.toUpperCase()}
                          variant={type === 'system' ? 'neutral' : 'warning'}
                        />
                      </td>
                      <td className="py-3 px-4 text-sm text-text-primary tabular-nums">
                        {role.permission_slugs.length}
                      </td>
                      <td className="py-3 px-4">
                        <Link
                          href={`/roles/${role.role_id}`}
                          aria-label={`Manage role ${role.role_name}`}
                          className="inline-flex min-h-[44px] sm:min-h-[35px] items-center justify-center rounded-lg border border-ok-border-strong bg-ok-surface px-3 text-sm font-medium text-text-primary transition-colors hover:bg-ok-hover-bg focus-visible:outline-2"
                        >
                          Manage
                        </Link>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* Create Role dialog */}
      {dialog.open && (
        <Dialog open showCloseButton={false} onClose={closeDialog} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 p-6" labelledBy="create-role-title">
            <h2 id="create-role-title" className="text-lg font-semibold text-text-primary mb-4">
              Create Custom Role
            </h2>

            <div className="mb-4">
              <label htmlFor="role-name" className="block text-sm font-medium text-text-secondary mb-1">
                Role Name <span aria-hidden="true">*</span>
              </label>
              <input
                id="role-name"
                type="text"
                value={dialog.name}
                onChange={e => setDialog(d => ({ ...d, name: e.target.value }))}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="e.g. support_agent"
              />
            </div>

            <div className="mb-4">
              <p className="text-sm font-medium text-text-secondary mb-2">Permissions</p>
              <PermissionMatrix selected={dialog.selectedSlugs} onChange={togglePerm} />
            </div>

            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={closeDialog} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={() => void handleCreate()}
                disabled={!dialog.name.trim() || actionLoading}
                loading={actionLoading}
                aria-label="Confirm create role"
              >
                Create Role
              </Button>
            </div>
          </Dialog>
      )}
    </PageContainer>
  );
}

export function RoleManagementPage() {
  return (
    <Suspense fallback={<Loading label="Loading roles…" />}>
      <RoleManagementInner />
    </Suspense>
  );
}
