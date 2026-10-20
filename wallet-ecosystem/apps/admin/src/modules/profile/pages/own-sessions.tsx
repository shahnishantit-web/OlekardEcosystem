'use client';

import { useCallback, useEffect, useState } from 'react';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import { listOwnSessions, revokeOwnSession } from '@/src/modules/auth/api/sessions';
import type { OwnSessionItem } from '@/src/modules/auth/api/sessions';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

function formatDate(iso: string) {
  try {
    return new Date(iso).toLocaleString(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    });
  } catch {
    return iso;
  }
}

export function OwnSessionsPage() {
  const { authenticatedClient } = useAuth();

  const [sessions, setSessions] = useState<OwnSessionItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [revokingId, setRevokingId] = useState<string | null>(null);
  const [revokeError, setRevokeError] = useState<string | null>(null);
  const [revokeSuccess, setRevokeSuccess] = useState(false);

  const loadSessions = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setFetchError(null);
    try {
      const data = await listOwnSessions(authenticatedClient);
      setSessions(data);
    } catch {
      setFetchError('Failed to load active sessions.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void loadSessions();
  }, [loadSessions]);

  async function handleRevoke(sessionId: string) {
    if (!authenticatedClient) return;
    setRevokingId(sessionId);
    setRevokeError(null);
    setRevokeSuccess(false);
    try {
      await revokeOwnSession(authenticatedClient, sessionId);
      setSessions((prev) => prev.filter((s) => s.id !== sessionId));
      setRevokeSuccess(true);
    } catch (err) {
      setRevokeError(
        isApiError(err) ? (err as ApiError).error.message : 'Failed to revoke session.',
      );
    } finally {
      setRevokingId(null);
    }
  }

  // The current session cookie is stored as admin_sid. We read it client-side
  // to highlight/disable the current session row.
  const currentSessionId =
    typeof document !== 'undefined'
      ? (document.cookie.match(/(?:^|;\s*)admin_sid=([^;]*)/) ?? [])[1] ?? ''
      : '';

  return (
    <PageContainer>
      <PageHeader
        title="Active Sessions"
        description="All active admin sessions for your account."
      />

      {revokeSuccess && (
        <Toast
          message="Session revoked."
          onDismiss={() => setRevokeSuccess(false)}
        />
      )}

      <Card>
        {loading ? (
          <Loading label="Loading sessions…" />
        ) : fetchError ? (
          <p className="text-sm text-red-600" role="alert">{fetchError}</p>
        ) : sessions.length === 0 ? (
          <p className="text-sm text-text-secondary">No active sessions found.</p>
        ) : (
          <>
            {revokeError && (
              <p className="text-sm text-red-600 mb-3" role="alert">{revokeError}</p>
            )}
            <ul className="space-y-3" aria-label="Active sessions">
              {sessions.map((session) => {
                const isCurrent =
                  session.id === currentSessionId ||
                  (currentSessionId === '' && sessions.indexOf(session) === 0);
                return (
                  <li
                    key={session.id}
                    className="flex items-start justify-between py-3 border-b border-neutral-700 last:border-0"
                  >
                    <div className="flex-1 min-w-0 pr-4">
                      <div className="flex items-center gap-2 mb-1">
                        <Badge variant={isCurrent ? 'success' : 'neutral'}>
                          {isCurrent ? 'This session' : 'Active'}
                        </Badge>
                        <span className="text-xs text-text-secondary truncate">
                          {session.device_id ?? 'Unknown device'}
                        </span>
                      </div>
                      <p className="text-xs text-text-secondary">
                        Last active: {formatDate(session.last_activity_at)}
                      </p>
                      <p className="text-xs text-text-secondary">
                        Created: {formatDate(session.created_at)}
                      </p>
                    </div>
                    {!isCurrent && (
                      <Button
                        variant="secondary"
                        loading={revokingId === session.id}
                        onClick={() => { void handleRevoke(session.id); }}
                        aria-label={`Revoke session from ${session.device_id ?? 'unknown device'}`}
                      >
                        Revoke
                      </Button>
                    )}
                  </li>
                );
              })}
            </ul>
          </>
        )}
      </Card>
    </PageContainer>
  );
}
