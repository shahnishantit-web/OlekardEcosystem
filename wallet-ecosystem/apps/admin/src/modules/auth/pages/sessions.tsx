'use client';

import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Table } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { Confirmation } from '@/components/ui/Confirmation';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import { listAdminSessions, revokeAdminSession } from '@/src/modules/auth/api/sessions';
import type { AdminSessionItem } from '@/src/modules/auth/api/sessions';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import { PageContainer } from '@/components/layout/PageContainer';

const PAGE_SIZE = 20;

interface RevokeTarget {
  sessionId: string;
}

export function AdminSessionsPage() {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const headingId = useId();

  const [sessions, setSessions] = useState<AdminSessionItem[]>([]);
  const [pagination, setPagination] = useState({ page: 1, total: 0, per_page: PAGE_SIZE });
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [revokeTarget, setRevokeTarget] = useState<RevokeTarget | null>(null);
  const [_revoking, setRevoking] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const fetchSessions = useCallback(
    async (searchTerm: string, page: number) => {
      if (!authenticatedClient) return;
      setLoading(true);
      setFetchError(null);
      try {
        const data = await listAdminSessions(authenticatedClient, {
          ...(searchTerm ? { search: searchTerm } : {}),
          page,
        });
        setSessions(data.sessions);
        setPagination({ page: data.pagination.page, total: data.pagination.total, per_page: data.pagination.per_page });
      } catch (err) {
        if (isApiError(err) && ((err as ApiError).error.code === 'IDENTITY_022' || (err as ApiError).error.code === 'IDENTITY_013')) {
          setForbidden(true);
        } else {
          setFetchError('Failed to load sessions. Please try again.');
        }
      } finally {
        setLoading(false);
      }
    },
    [authenticatedClient],
  );

  useEffect(() => {
    void fetchSessions(debouncedSearch, pagination.page);
  }, [fetchSessions, debouncedSearch, pagination.page]);

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => setDebouncedSearch(search), 400);
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [search]);

  async function handleRevoke() {
    if (!revokeTarget || !authenticatedClient) return;
    setRevoking(true);
    try {
      await revokeAdminSession(authenticatedClient, revokeTarget.sessionId);
      setSessions((prev) => prev.filter((s) => s.session_id !== revokeTarget.sessionId));
      setToastMessage('Session revoked successfully.');
    } catch (err) {
      const msg = isApiError(err) ? (err as ApiError).error.message : 'Failed to revoke session.';
      setToastMessage(`Error: ${msg}`);
    } finally {
      setRevoking(false);
      setRevokeTarget(null);
    }
  }

  const columns = [
    {
      key: 'user',
      header: 'User',
      render: (row: AdminSessionItem) => (
        <span className="text-sm text-text-primary">
          {row.user_email ?? row.user_id}
        </span>
      ),
    },
    {
      key: 'device',
      header: 'Device',
      render: (row: AdminSessionItem) => (
        <span className="text-sm text-text-secondary">
          {row.device_name ?? '—'}{row.platform ? ` · ${row.platform}` : ''}
        </span>
      ),
    },
    {
      key: 'actor_type',
      header: 'Actor type',
      render: (row: AdminSessionItem) => (
        <Badge
          label={row.actor_type}
          variant={row.actor_type === 'ADMIN' ? 'error' : 'neutral'}
        />
      ),
    },
    {
      key: 'last_activity',
      header: 'Last activity',
      render: (row: AdminSessionItem) => (
        <time
          dateTime={row.last_activity_at}
          className="text-sm text-text-secondary"
        >
          {new Date(row.last_activity_at).toLocaleString()}
        </time>
      ),
    },
    {
      key: 'expires',
      header: 'Expires',
      render: (row: AdminSessionItem) => (
        <time
          dateTime={row.expires_at}
          className="text-sm text-text-secondary"
        >
          {new Date(row.expires_at).toLocaleString()}
        </time>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      render: (row: AdminSessionItem) => (
        <Button
          variant="danger"
          className="text-xs px-3"
          style={{ minHeight: '32px' }}
          onClick={() => setRevokeTarget({ sessionId: row.session_id })}
          aria-label={`Revoke session for ${row.user_email ?? row.user_id}`}
        >
          Revoke
        </Button>
      ),
    },
  ];

  const totalPages = Math.max(1, Math.ceil(pagination.total / pagination.per_page));

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to view sessions."
      />
    );
  }

  if (forbidden) {
    return (
      <ErrorState
        title="Access denied"
        message="You do not have permission to manage sessions."
      />
    );
  }

  return (
    <PageContainer>
      <div className="mb-6 flex items-center justify-between gap-4">
        <h1
          id={headingId}
          className="text-xl font-semibold text-text-primary"
        >
          Session Management
        </h1>
      </div>

      <div className="mb-4 max-w-sm">
        <Input
          label="Search by user email or ID"
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="user@example.com"
        />
      </div>

      {loading && <Loading label="Loading sessions…" />}

      {!loading && fetchError && (
        <ErrorState
          message={fetchError}
          onRetry={() => void fetchSessions(debouncedSearch, pagination.page)}
        />
      )}

      {!loading && !fetchError && sessions.length === 0 && (
        <Empty
          title="No sessions found"
          description={search ? 'No sessions match your search.' : 'There are no active sessions.'}
        />
      )}

      {!loading && !fetchError && sessions.length > 0 && (
        <>
          <Table
            caption="Active user sessions"
            columns={columns}
            data={sessions}
            getRowKey={(row) => row.session_id}
          />

          <nav
            aria-label="Session list pagination"
            className="mt-4 flex items-center gap-2"
          >
            <Button
              variant="secondary"
              onClick={() => setPagination((p) => ({ ...p, page: Math.max(1, p.page - 1) }))}
              disabled={pagination.page <= 1}
              aria-label="Previous page"
            >
              Previous
            </Button>
            <span className="text-sm text-text-secondary" aria-live="polite">
              Page {pagination.page} of {totalPages}
            </span>
            <Button
              variant="secondary"
              onClick={() => setPagination((p) => ({ ...p, page: Math.min(totalPages, p.page + 1) }))}
              disabled={pagination.page >= totalPages}
              aria-label="Next page"
            >
              Next
            </Button>
          </nav>
        </>
      )}

      <Confirmation
        open={revokeTarget !== null}
        onClose={() => setRevokeTarget(null)}
        onConfirm={() => { void handleRevoke(); }}
        title="Revoke session"
        message="This will immediately terminate the user's session. They will need to sign in again. This action cannot be undone."
        destructive
      />

      {toastMessage && (
        <Toast
          message={toastMessage}
          onDismiss={() => setToastMessage(null)}
        />
      )}
    </PageContainer>
  );
}
