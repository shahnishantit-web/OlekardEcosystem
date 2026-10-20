'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
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

interface Props {
  roleId: string;
}

function isSystemRole(name: string): boolean {
  return (SYSTEM_ROLE_NAMES as readonly string[]).includes(name);
}

export function RoleDetailPage({ roleId }: Props) {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [role, setRole]               = useState<AdminRole | null>(null);
  const [loading, setLoading]         = useState(false);
  const [error, setError]             = useState<string | null>(null);
  const [saving, setSaving]           = useState(false);
  const [successMsg, setSuccessMsg]   = useState<string | null>(null);
  const [selectedSlugs, setSelectedSlugs] = useState<Set<string>>(new Set());
  const [dirty, setDirty]             = useState(false);

  const fetchRole = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getRole(roleId);
      const found = res.data ?? null;
      if (!found) {
        setError('Role not found.');
        return;
      }
      setRole(found);
      setSelectedSlugs(new Set(Array.isArray(found.permission_slugs) ? found.permission_slugs : []));
    } catch {
      setError('Failed to load role.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, roleId]);

  useEffect(() => { void fetchRole(); }, [fetchRole]);

  const toggle = (slug: string, checked: boolean) => {
    setSelectedSlugs(prev => {
      const next = new Set(prev);
      if (checked) next.add(slug); else next.delete(slug);
      return next;
    });
    setDirty(true);
    setSuccessMsg(null);
  };

  const handleSave = async () => {
    if (!authenticatedClient || !role) return;
    setSaving(true);
    setError(null);
    setSuccessMsg(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const idemKey = generateIdempotencyKey(`role-perms-${roleId}-${Date.now()}`);
      await client.updateRolePermissions(roleId, [...selectedSlugs], idemKey);
      setSuccessMsg('Permissions updated.');
      setDirty(false);
      void fetchRole();
    } catch {
      setError('Failed to save permissions.');
    } finally {
      setSaving(false);
    }
  };

  const groups = ALL_PERMISSION_SLUGS.reduce<Record<string, typeof ALL_PERMISSION_SLUGS>>(
    (acc, p) => {
      (acc[p.group] ??= []).push(p);
      return acc;
    },
    {},
  );

  const type = role ? (isSystemRole(role.role_name) ? 'system' : 'custom') : '';

  return (
    <PageContainer>
      <PageHeader
        title={role ? `Role: ${role.role_name}` : 'Role Detail'}
        actions={
          <Button variant="secondary" onClick={() => router.back()} aria-label="Back to roles list">
            ← Back
          </Button>
        }
      />

      {loading && <Loading label="Loading role…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchRole()} />}

      {successMsg && (
        <div role="status" className="mb-4 rounded bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
          {successMsg}
        </div>
      )}

      {!loading && !error && role && (
        <div className="space-y-6">
          {/* Role info */}
          <Card className="p-6">
            <div className="flex items-center gap-4 flex-wrap">
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Role Name</dt>
                <dd className="mt-1 text-sm font-medium text-text-primary">{role.role_name}</dd>
              </div>
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Type</dt>
                <dd className="mt-1">
                  <Badge
                    label={type.toUpperCase()}
                    variant={type === 'system' ? 'neutral' : 'warning'}
                    aria-label={`Role type: ${type}`}
                  />
                </dd>
              </div>
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Permissions</dt>
                <dd className="mt-1 text-sm tabular-nums text-text-primary">{selectedSlugs.size}</dd>
              </div>
            </div>
          </Card>

          {/* Permission matrix */}
          <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-sm font-semibold text-text-primary">Permission Matrix</h2>
              <Button
                variant="primary"
                onClick={() => void handleSave()}
                disabled={!dirty || saving}
                loading={saving}
                aria-label="Save role permissions"
              >
                Save Permissions
              </Button>
            </div>

            <div className="space-y-5">
              {Object.entries(groups).map(([group, perms]) => (
                <fieldset key={group}>
                  <legend className="text-xs font-semibold uppercase text-text-secondary mb-2">
                    {group}
                  </legend>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    {perms.map(p => (
                      <label
                        key={p.slug}
                        className="flex items-center gap-2 cursor-pointer text-sm text-text-primary"
                      >
                        <input
                          type="checkbox"
                          checked={selectedSlugs.has(p.slug)}
                          onChange={e => toggle(p.slug, e.target.checked)}
                          className="h-4 w-4"
                          aria-label={p.label}
                        />
                        {p.label}
                      </label>
                    ))}
                  </div>
                </fieldset>
              ))}
            </div>
          </Card>
        </div>
      )}
    </PageContainer>
  );
}
