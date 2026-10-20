'use client';

import { useCallback, useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';

interface DeadLetterItem {
  id: string;
  operation_type: string;
  source_id: string;
  failure_reason: string;
  retry_count: number;
  status: 'pending' | 'replayed' | 'dismissed';
  created_at: string;
}

interface ApiResponse {
  data: {
    items: DeadLetterItem[];
    pagination: { total: number; current_page: number; per_page: number; last_page: number };
  };
}

function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'error' | 'info' {
  switch (status) {
    case 'pending':   return 'warning';
    case 'replayed':  return 'success';
    case 'dismissed': return 'neutral';
    default:          return 'neutral';
  }
}

export function DeadLetterPage() {
  const { authenticatedClient } = useAuth();

  const [items, setItems]           = useState<DeadLetterItem[]>([]);
  const [loading, setLoading]       = useState(false);
  const [error, setError]           = useState<string | null>(null);
  const [statusFilter, setStatus]   = useState('');
  const [typeFilter, setType]       = useState('');

  // Confirm-dialog state
  const [replayTarget, setReplayTarget]     = useState<DeadLetterItem | null>(null);
  const [dismissTarget, setDismissTarget]   = useState<DeadLetterItem | null>(null);
  const [dismissReason, setDismissReason]   = useState('');
  const [actionError, setActionError]       = useState<string | null>(null);
  const [actionLoading, setActionLoading]   = useState(false);

  const fetchItems = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (statusFilter) params.set('status', statusFilter);
      if (typeFilter)   params.set('operation_type', typeFilter);
      const res = await authenticatedClient.get<ApiResponse>(`/v1/operations/dead-letter?${params.toString()}`);
      setItems(res.data.data.items);
    } catch {
      setError('Failed to load dead-letter items.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, statusFilter, typeFilter]);

  useEffect(() => { void fetchItems(); }, [fetchItems]);

  const handleReplay = async () => {
    if (!authenticatedClient || !replayTarget) return;
    setActionLoading(true);
    setActionError(null);
    try {
      await authenticatedClient.post(`/v1/operations/dead-letter/${replayTarget.id}/replay`, {});
      setReplayTarget(null);
      void fetchItems();
    } catch {
      setActionError('Replay failed. The item may already be replayed or dismissed.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleDismiss = async () => {
    if (!authenticatedClient || !dismissTarget) return;
    if (dismissReason.trim().length < 10) {
      setActionError('Reason must be at least 10 characters.');
      return;
    }
    setActionLoading(true);
    setActionError(null);
    try {
      await authenticatedClient.post(`/v1/operations/dead-letter/${dismissTarget.id}/dismiss`, {
        reason: dismissReason,
      });
      setDismissTarget(null);
      setDismissReason('');
      void fetchItems();
    } catch {
      setActionError('Dismiss failed. The item may already be replayed or dismissed.');
    } finally {
      setActionLoading(false);
    }
  };

  return (
    <PageContainer>
        <PageHeader
          title="Dead-Letter Items"
          actions={
            <Button variant="secondary" onClick={() => void fetchItems()} aria-label="Refresh dead-letter items">
              Refresh
            </Button>
          }
        />

      {/* Filter bar */}
      <div className="mb-4 flex gap-4 flex-wrap" role="search" aria-label="Filter dead-letter items">
        <label className="flex flex-col gap-1 text-sm">
          <span>Status</span>
          <select
            className="border rounded px-2 py-1"
            value={statusFilter}
            onChange={(e) => setStatus(e.target.value)}
            aria-label="Filter by status"
          >
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="replayed">Replayed</option>
            <option value="dismissed">Dismissed</option>
          </select>
        </label>
        <label className="flex flex-col gap-1 text-sm">
          <span>Operation Type</span>
          <input
            type="text"
            className="border rounded px-2 py-1"
            placeholder="e.g. funding.initiation"
            value={typeFilter}
            onChange={(e) => setType(e.target.value)}
            aria-label="Filter by operation type"
          />
        </label>
      </div>

      {loading && <Loading label="Loading dead-letter items…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchItems()} />}

      {!loading && !error && (
        <Card>
          <Table
            caption="Dead-letter items"
            columns={[
              { key: 'operation_type', label: 'Operation Type' },
              { key: 'source_id',      label: 'Source ID' },
              { key: 'failure_reason', label: 'Failure Reason' },
              { key: 'retry_count',    label: 'Retries' },
              { key: 'status',         label: 'Status' },
              { key: 'created_at',     label: 'Created At' },
              { key: 'actions',        label: 'Actions' },
            ]}
            rows={items.map((item) => ({
              id: item.id,
              operation_type: item.operation_type,
              source_id: <span className="font-mono text-xs">{item.source_id}</span>,
              failure_reason: <span className="text-xs truncate max-w-xs">{item.failure_reason}</span>,
              retry_count: item.retry_count,
              status: <Badge label={item.status.toUpperCase()} variant={statusVariant(item.status)} />,
              created_at: new Date(item.created_at).toLocaleString(),
              actions: item.status === 'pending' ? (
                <span className="flex gap-2">
                  <Button
                    size="sm"
                    variant="primary"
                    onClick={() => { setReplayTarget(item); setActionError(null); }}
                    aria-label={`Replay dead-letter item ${item.id}`}
                  >
                    Replay
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => { setDismissTarget(item); setDismissReason(''); setActionError(null); }}
                    aria-label={`Dismiss dead-letter item ${item.id}`}
                  >
                    Dismiss
                  </Button>
                </span>
              ) : null,
            }))}
          />
        </Card>
      )}

      {/* Replay confirm dialog */}
      {replayTarget !== null && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="replay-dialog-title"
          className="fixed inset-0 bg-black/40 flex items-center justify-center z-50"
        >
          <div className="bg-white rounded-lg p-6 max-w-md w-full shadow-xl">
            <h2 id="replay-dialog-title" className="text-lg font-semibold mb-2">Confirm Replay</h2>
            <p className="text-sm text-gray-600 mb-4">
              Replay operation <strong>{replayTarget.operation_type}</strong> for source{' '}
              <code className="text-xs">{replayTarget.source_id}</code>?
            </p>
            {actionError && <p role="alert" className="text-sm text-red-600 mb-3">{actionError}</p>}
            <div className="flex gap-3 justify-end">
              <Button variant="secondary" onClick={() => setReplayTarget(null)} disabled={actionLoading}>
                Cancel
              </Button>
              <Button variant="primary" onClick={() => void handleReplay()} disabled={actionLoading}>
                {actionLoading ? 'Replaying…' : 'Confirm Replay'}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Dismiss dialog */}
      {dismissTarget !== null && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="dismiss-dialog-title"
          className="fixed inset-0 bg-black/40 flex items-center justify-center z-50"
        >
          <div className="bg-white rounded-lg p-6 max-w-md w-full shadow-xl">
            <h2 id="dismiss-dialog-title" className="text-lg font-semibold mb-2">Dismiss Dead-Letter Item</h2>
            <p className="text-sm text-gray-600 mb-3">
              Dismiss operation <strong>{dismissTarget.operation_type}</strong>. Provide a justification reason
              (minimum 10 characters).
            </p>
            <label className="block mb-4">
              <span className="text-sm font-medium">Reason</span>
              <textarea
                className="mt-1 block w-full border rounded px-2 py-1 text-sm"
                rows={3}
                value={dismissReason}
                onChange={(e) => setDismissReason(e.target.value)}
                aria-label="Dismissal reason"
                minLength={10}
                maxLength={1000}
                required
              />
            </label>
            {actionError && <p role="alert" className="text-sm text-red-600 mb-3">{actionError}</p>}
            <div className="flex gap-3 justify-end">
              <Button variant="secondary" onClick={() => setDismissTarget(null)} disabled={actionLoading}>
                Cancel
              </Button>
              <Button variant="primary" onClick={() => void handleDismiss()} disabled={actionLoading || dismissReason.trim().length < 10}>
                {actionLoading ? 'Dismissing…' : 'Confirm Dismiss'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </PageContainer>
  );
}
