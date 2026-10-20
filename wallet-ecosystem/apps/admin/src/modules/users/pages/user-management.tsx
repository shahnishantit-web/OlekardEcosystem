'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import {
  searchAdminUsers,
  exportAdminUsers,
} from '@/src/modules/users/api/admin-portal';
import type { AdminUserProfile, PaginatedResponse } from '@/src/modules/users/api/admin-portal';

type StatusFilter = 'all' | 'active' | 'suspended' | 'closed';
type KycStatusFilter = 'all' | 'verified' | 'pending' | 'failed';

function UserStatusBadge({ status }: { status: string }) {
  const variant =
    status === 'active'    ? 'success'
    : status === 'suspended' ? 'error'
    : status === 'closed'    ? 'neutral'
    : 'neutral';
  return <Badge label={status.toUpperCase()} variant={variant} />;
}

function KycStatusBadge({ status }: { status: string | undefined }) {
  if (!status) return <span className="text-sm text-text-secondary">—</span>;
  const variant =
    status === 'verified' ? 'success'
    : status === 'pending'  ? 'warning'
    : status === 'failed'   ? 'error'
    : 'neutral';
  return <Badge label={status.toUpperCase()} variant={variant} />;
}

function fullName(user: AdminUserProfile): string {
  const first = user.profile?.first_name ?? '';
  const last  = user.profile?.last_name ?? '';
  const name  = `${first} ${last}`.trim();
  return name || '—';
}

function UserRow({
  user,
}: {
  user: AdminUserProfile;
}) {
  return (
    <tr className="border-b border-ok-border hover:bg-ok-hover-bg transition-colors">
      <td className="py-3 px-4 text-sm text-text-primary">{fullName(user)}</td>
      <td className="py-3 px-4 text-sm text-text-primary">{user.email}</td>
      <td className="py-3 px-4 text-sm text-text-secondary">{user.profile?.phone_number ?? '—'}</td>
      <td className="py-3 px-4">
        <KycStatusBadge status={user.kyc?.status} />
      </td>
      <td className="py-3 px-4">
        <UserStatusBadge status={user.status} />
      </td>
      <td className="py-3 px-4 text-sm text-text-secondary">
        {new Date(user.created_at).toLocaleDateString()}
      </td>
      <td className="py-3 px-4">
        <Link
          href={`/admin-portal/users/${user.id}`}
          aria-label={`View profile for ${user.email}`}
          className="inline-flex min-h-[44px] items-center justify-center rounded-lg border border-ok-border-strong bg-ok-surface px-3 text-sm font-medium text-text-primary transition-colors hover:bg-ok-hover-bg focus-visible:outline-2 sm:min-h-[35px]"
        >
          View
        </Link>
      </td>
    </tr>
  );
}

export function UserManagementPage() {
  const { authenticatedClient } = useAuth();
  const [users, setUsers] = useState<PaginatedResponse<AdminUserProfile> | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [kycStatusFilter, setKycStatusFilter] = useState<KycStatusFilter>('all');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);

  const [exporting, setExporting] = useState(false);
  const [exportToast, setExportToast] = useState<string | null>(null);

  const fetchUsers = useCallback(async (targetPage = page) => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params: Parameters<typeof searchAdminUsers>[1] = { page: targetPage, per_page: 20 };
      if (query) params.q = query;
      if (statusFilter !== 'all') params.status = statusFilter;
      if (kycStatusFilter !== 'all') params.kyc_status = kycStatusFilter;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;
      const data = await searchAdminUsers(authenticatedClient, params);
      setUsers(data);
    } catch {
      setError('Failed to load users. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, query, statusFilter, kycStatusFilter, dateFrom, dateTo, page]);

  useEffect(() => {
    void fetchUsers();
  }, [fetchUsers]);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    void fetchUsers(1);
  };

  const handleExport = async () => {
    if (!authenticatedClient) return;
    setExporting(true);
    try {
      const params: Parameters<typeof exportAdminUsers>[1] = {};
      if (query) params.q = query;
      if (statusFilter !== 'all') params.status = statusFilter;
      if (kycStatusFilter !== 'all') params.kyc_status = kycStatusFilter;
      await exportAdminUsers(authenticatedClient, { ...params, format: 'csv' });
      setExportToast('Export queued. You will be notified when the file is ready.');
    } catch {
      setExportToast('Export failed. Please try again.');
    } finally {
      setExporting(false);
    }
  };

  return (
    <PageContainer>
      <PageHeader
        title="User Management"
        actions={
          <Button
            variant="secondary"
            onClick={() => void handleExport()}
            disabled={exporting}
          >
            {exporting ? 'Exporting…' : 'Export CSV'}
          </Button>
        }
      />

      <Card className="mb-6 p-4">
        <form onSubmit={handleSearch} className="flex flex-wrap gap-3 items-end">
          {/* Search */}
          <div className="flex-1 min-w-[200px]">
            <label htmlFor="user-search" className="block text-sm font-medium text-text-secondary mb-1">
              Search
            </label>
            <input
              id="user-search"
              type="search"
              value={query}
              onChange={e => setQuery(e.target.value)}
              placeholder="Name, email, or phone…"
              className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-ok-primary"
            />
          </div>

          {/* Account status filter */}
          <div>
            <label htmlFor="status-filter" className="block text-sm font-medium text-text-secondary mb-1">
              Status
            </label>
            <select
              id="status-filter"
              value={statusFilter}
              onChange={e => setStatusFilter(e.target.value as StatusFilter)}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-ok-primary"
            >
              <option value="all">All</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="closed">Closed</option>
            </select>
          </div>

          {/* KYC status filter */}
          <div>
            <label htmlFor="kyc-status-filter" className="block text-sm font-medium text-text-secondary mb-1">
              KYC
            </label>
            <select
              id="kyc-status-filter"
              value={kycStatusFilter}
              onChange={e => setKycStatusFilter(e.target.value as KycStatusFilter)}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-ok-primary"
            >
              <option value="all">All</option>
              <option value="verified">Verified</option>
              <option value="pending">Pending</option>
              <option value="failed">Failed</option>
            </select>
          </div>

          {/* Date range filters */}
          <div>
            <label htmlFor="date-from" className="block text-sm font-medium text-text-secondary mb-1">
              Created from
            </label>
            <input
              id="date-from"
              type="date"
              value={dateFrom}
              onChange={e => setDateFrom(e.target.value)}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-ok-primary"
            />
          </div>
          <div>
            <label htmlFor="date-to" className="block text-sm font-medium text-text-secondary mb-1">
              Created to
            </label>
            <input
              id="date-to"
              type="date"
              value={dateTo}
              onChange={e => setDateTo(e.target.value)}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-ok-primary"
            />
          </div>

          <Button type="submit" variant="primary">
            Search
          </Button>
        </form>
      </Card>

      {loading && <Loading label="Loading users…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchUsers()} />}
      {!loading && !error && users?.data.length === 0 && (
        <Empty title="No users found" description="Try adjusting your search or filter." />
      )}

      {!loading && !error && users && users.data.length > 0 && (
        <>
          <div className="overflow-x-auto rounded border border-ok-border">
            <table className="min-w-full" aria-label="Users table">
              <thead className="bg-ok-surface">
                <tr>
                  {['Name', 'Email', 'Phone', 'KYC Status', 'Account Status', 'Created', 'Actions'].map(h => (
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
                {users.data.map(user => (
                  <UserRow key={user.id} user={user} />
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center justify-between text-sm text-text-secondary">
            <span>
              Page {users.meta.current_page} of {users.meta.last_page} · {users.meta.total} total
            </span>
            <div className="flex gap-2">
              <Button
                variant="secondary"
                onClick={() => { setPage(p => Math.max(1, p - 1)); }}
                disabled={page <= 1}
                aria-label="Previous page"
              >
                Previous
              </Button>
              <Button
                variant="secondary"
                onClick={() => { setPage(p => p + 1); }}
                disabled={page >= users.meta.last_page}
                aria-label="Next page"
              >
                Next
              </Button>
            </div>
          </div>
        </>
      )}

      {exportToast && (
        <Toast message={exportToast} onDismiss={() => setExportToast(null)} />
      )}
    </PageContainer>
  );
}
