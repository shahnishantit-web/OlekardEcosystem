'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth/context';

type MembershipRole =
  | 'organiser_admin'
  | 'community_representative'
  | 'finance_approver'
  | 'gate_operator'
  | 'support_officer';

type MembershipStatus = 'active' | 'revoked' | 'pending';

interface StaffMembership {
  id: string;
  person_id: string;
  person_name: string;
  person_email: string;
  role: MembershipRole;
  status: MembershipStatus;
  invited_at: string;
  accepted_at: string | null;
  revoked_at: string | null;
  revoked_by_name: string | null;
  organisation_id: string;
}

interface StaffListPageProps {
  organisationId: string;
  organisationName?: string;
}

const ROLE_LABELS: Record<MembershipRole, string> = {
  organiser_admin: 'Organiser Admin',
  community_representative: 'Community Rep',
  finance_approver: 'Finance Approver',
  gate_operator: 'Gate Operator',
  support_officer: 'Support Officer',
};

const ROLE_OPTIONS: MembershipRole[] = [
  'organiser_admin',
  'community_representative',
  'finance_approver',
  'gate_operator',
  'support_officer',
];

function StatusBadge({ status }: { status: MembershipStatus }) {
  const colours: Record<MembershipStatus, string> = {
    active: 'bg-green-100 text-green-800',
    revoked: 'bg-red-100 text-red-800',
    pending: 'bg-yellow-100 text-yellow-800',
  };
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${colours[status]}`}
      aria-label={`Status: ${status}`}
    >
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  );
}

export function StaffListPage({ organisationId, organisationName }: StaffListPageProps) {
  const { authenticatedClient: client } = useAuth();
  const [roleFilter, setRoleFilter] = useState<MembershipRole | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<MembershipStatus | 'all'>('active');
  const [loading, setLoading] = useState(false);
  const [members, setMembers] = useState<StaffMembership[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [revokeConfirm, setRevokeConfirm] = useState<string | null>(null);
  const [revoking, setRevoking] = useState(false);

  async function loadMembers() {
    if (!client) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams({ organisation_id: organisationId });
      if (roleFilter !== 'all') params.set('role', roleFilter);
      if (statusFilter !== 'all') params.set('status', statusFilter);
      const resp = await client.get(`/v1/community/admin/organisations/${organisationId}/staff?${params}`);
      const data = resp.data as { data: StaffMembership[] };
      setMembers(data.data ?? []);
    } catch {
      setError('Failed to load staff members.');
    } finally {
      setLoading(false);
    }
  }

  async function handleRevoke(membershipId: string) {
    if (!client) return;
    setRevoking(true);
    try {
      await client.post(
        `/v1/community/admin/organisations/${organisationId}/staff/${membershipId}/revoke`,
        {},
      );
      setRevokeConfirm(null);
      await loadMembers();
    } catch {
      setError('Failed to revoke membership.');
    } finally {
      setRevoking(false);
    }
  }

  return (
    <main className="p-6 max-w-5xl mx-auto">
      <h1 className="text-2xl font-semibold text-gray-900 mb-1">
        Staff Members
      </h1>
      {organisationName && (
        <p className="text-sm text-gray-500 mb-6">{organisationName}</p>
      )}

      {/* Filters */}
      <section aria-label="Filter staff" className="flex flex-wrap gap-4 mb-6">
        <div>
          <label htmlFor="role-filter" className="block text-xs font-medium text-gray-700 mb-1">
            Role
          </label>
          <select
            id="role-filter"
            value={roleFilter}
            onChange={(e) => setRoleFilter(e.target.value as MembershipRole | 'all')}
            className="rounded border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="all">All roles</option>
            {ROLE_OPTIONS.map((r) => (
              <option key={r} value={r}>{ROLE_LABELS[r]}</option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="status-filter" className="block text-xs font-medium text-gray-700 mb-1">
            Status
          </label>
          <select
            id="status-filter"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as MembershipStatus | 'all')}
            className="rounded border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="all">All statuses</option>
            <option value="active">Active</option>
            <option value="pending">Pending</option>
            <option value="revoked">Revoked</option>
          </select>
        </div>

        <div className="flex items-end">
          <button
            type="button"
            onClick={loadMembers}
            disabled={loading || !client}
            className="rounded bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
            aria-label="Apply filters and load staff"
          >
            {loading ? 'Loading…' : 'Load Staff'}
          </button>
        </div>
      </section>

      {error && (
        <div role="alert" className="mb-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {/* Table */}
      <div className="overflow-x-auto rounded-lg border border-gray-200">
        <table className="min-w-full divide-y divide-gray-200 text-sm">
          <thead className="bg-gray-50">
            <tr>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Name</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Email</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Role</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Invited</th>
              <th scope="col" className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 bg-white">
            {members.length === 0 && !loading && (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-gray-400">
                  No staff members found. Apply filters and click Load Staff.
                </td>
              </tr>
            )}
            {members.map((m) => (
              <tr key={m.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-900">{m.person_name}</td>
                <td className="px-4 py-3 text-gray-600">{m.person_email}</td>
                <td className="px-4 py-3 text-gray-700">{ROLE_LABELS[m.role]}</td>
                <td className="px-4 py-3"><StatusBadge status={m.status} /></td>
                <td className="px-4 py-3 text-gray-500">
                  {new Date(m.invited_at).toLocaleDateString()}
                </td>
                <td className="px-4 py-3 text-right">
                  {m.status === 'active' && (
                    <button
                      type="button"
                      onClick={() => setRevokeConfirm(m.id)}
                      className="rounded border border-red-300 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                      aria-label={`Revoke membership for ${m.person_name}`}
                    >
                      Revoke
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Revoke confirmation dialog */}
      {revokeConfirm && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="revoke-dialog-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
          <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <h2 id="revoke-dialog-title" className="text-lg font-semibold text-gray-900 mb-2">
              Revoke Membership
            </h2>
            <p className="text-sm text-gray-600 mb-6">
              Revoking this membership will immediately block access on the next
              authenticated request. This action cannot be undone.
            </p>
            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => setRevokeConfirm(null)}
                disabled={revoking}
                className="rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={() => handleRevoke(revokeConfirm)}
                disabled={revoking}
                className="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                aria-label="Confirm revoke membership"
              >
                {revoking ? 'Revoking…' : 'Revoke'}
              </button>
            </div>
          </div>
        </div>
      )}
    </main>
  );
}

export default StaffListPage;
