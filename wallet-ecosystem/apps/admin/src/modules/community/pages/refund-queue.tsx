'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth/context';

type RefundStatus = 'pending' | 'approved' | 'rejected' | 'settled' | 'failed';

interface RefundRequest {
  id: string;
  order_id: string;
  organisation_id: string;
  organisation_name: string;
  requested_by: string;
  requester_name: string;
  amount_minor: number;
  currency: string;
  reason: string;
  status: RefundStatus;
  requested_at: string;
  approved_by: string | null;
  approver_name: string | null;
  approved_at: string | null;
  rejected_at: string | null;
}

const STATUS_COLOURS: Record<RefundStatus, string> = {
  pending:  'bg-yellow-100 text-yellow-800',
  approved: 'bg-blue-100 text-blue-800',
  rejected: 'bg-red-100 text-red-800',
  settled:  'bg-green-100 text-green-800',
  failed:   'bg-gray-100 text-gray-700',
};

function formatMinor(amount: number, currency: string): string {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
  }).format(amount / 100);
}

export function RefundQueuePage() {
  const { authenticatedClient: client, user } = useAuth();
  const currentUserId = user?.id;
  const [statusFilter, setStatusFilter] = useState<RefundStatus | 'all'>('pending');
  const [loading, setLoading] = useState(false);
  const [refunds, setRefunds] = useState<RefundRequest[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [actionId, setActionId] = useState<string | null>(null);
  const [actionType, setActionType] = useState<'approve' | 'reject' | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function loadRefunds() {
    if (!client) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (statusFilter !== 'all') params.set('status', statusFilter);
      const resp = await client.get(`/v1/community/admin/refunds?${params}`);
      const data = resp.data as { data: RefundRequest[] };
      setRefunds(data.data ?? []);
    } catch {
      setError('Failed to load refunds.');
    } finally {
      setLoading(false);
    }
  }

  async function handleApprove(id: string) {
    if (!client) return;
    setSubmitting(true);
    setError(null);
    try {
      await client.post(`/v1/community/admin/refunds/${id}/approve`, {});
      setActionId(null);
      setActionType(null);
      await loadRefunds();
    } catch {
      setError('Failed to approve refund.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleReject(id: string) {
    if (!client || !rejectReason.trim()) return;
    setSubmitting(true);
    setError(null);
    try {
      await client.post(`/v1/community/admin/refunds/${id}/reject`, { reason: rejectReason });
      setActionId(null);
      setActionType(null);
      setRejectReason('');
      await loadRefunds();
    } catch {
      setError('Failed to reject refund.');
    } finally {
      setSubmitting(false);
    }
  }

  const selectedRefund = refunds.find((r) => r.id === actionId);
  const isSelfApproval = selectedRefund?.requested_by === currentUserId;

  return (
    <main className="p-6 max-w-6xl mx-auto">
      <h1 className="text-2xl font-semibold text-gray-900 mb-1">Refund Queue</h1>
      <p className="text-sm text-gray-500 mb-6">
        Review and action pending refund requests. Finance Approvers cannot approve
        their own requests.
      </p>

      {/* Filters */}
      <section aria-label="Filter refunds" className="flex flex-wrap gap-4 mb-6">
        <div>
          <label htmlFor="status-filter" className="block text-xs font-medium text-gray-700 mb-1">
            Status
          </label>
          <select
            id="status-filter"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as RefundStatus | 'all')}
            className="rounded border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="all">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="settled">Settled</option>
            <option value="failed">Failed</option>
          </select>
        </div>

        <div className="flex items-end">
          <button
            type="button"
            onClick={loadRefunds}
            disabled={loading || !client}
            className="rounded bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
            aria-label="Apply filters and load refunds"
          >
            {loading ? 'Loading…' : 'Load Refunds'}
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
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Organisation</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Requested By</th>
              <th scope="col" className="px-4 py-3 text-right font-medium text-gray-600">Amount</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Reason</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
              <th scope="col" className="px-4 py-3 text-left font-medium text-gray-600">Requested</th>
              <th scope="col" className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 bg-white">
            {refunds.length === 0 && !loading && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-gray-400">
                  No refunds found. Apply filters and click Load Refunds.
                </td>
              </tr>
            )}
            {refunds.map((r) => {
              const isSelf = r.requested_by === currentUserId;
              return (
                <tr key={r.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">{r.organisation_name}</td>
                  <td className="px-4 py-3 text-gray-600">
                    {r.requester_name}
                    {isSelf && (
                      <span className="ml-2 text-xs text-amber-600 font-medium">(you)</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right font-mono text-gray-900">
                    {formatMinor(r.amount_minor, r.currency)}
                  </td>
                  <td className="px-4 py-3 text-gray-600 max-w-xs truncate">{r.reason}</td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_COLOURS[r.status]}`}
                      aria-label={`Status: ${r.status}`}
                    >
                      {r.status.charAt(0).toUpperCase() + r.status.slice(1)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-gray-500">
                    {new Date(r.requested_at).toLocaleDateString()}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {r.status === 'pending' && (
                      <div className="flex items-center justify-end gap-2">
                        <button
                          type="button"
                          onClick={() => {
                            setActionId(r.id);
                            setActionType('approve');
                          }}
                          disabled={isSelf}
                          title={isSelf ? 'Cannot approve your own request' : 'Approve refund'}
                          className="rounded border border-green-300 px-3 py-1 text-xs font-medium text-green-700 hover:bg-green-50 disabled:cursor-not-allowed disabled:opacity-40 focus:outline-none focus:ring-2 focus:ring-green-500"
                          aria-label={isSelf ? 'Self-approval not permitted' : `Approve refund from ${r.requester_name}`}
                          aria-disabled={isSelf}
                        >
                          Approve
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            setActionId(r.id);
                            setActionType('reject');
                          }}
                          className="rounded border border-red-300 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                          aria-label={`Reject refund from ${r.requester_name}`}
                        >
                          Reject
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Action confirmation dialog */}
      {actionId && actionType && selectedRefund && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="action-dialog-title"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
          <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <h2 id="action-dialog-title" className="text-lg font-semibold text-gray-900 mb-2">
              {actionType === 'approve' ? 'Approve Refund' : 'Reject Refund'}
            </h2>

            {actionType === 'approve' && isSelfApproval && (
              <div role="alert" className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                Self-approval is not permitted. You submitted this request.
              </div>
            )}

            {actionType === 'approve' && !isSelfApproval && (
              <p className="text-sm text-gray-600 mb-6">
                Approve {formatMinor(selectedRefund.amount_minor, selectedRefund.currency)} refund
                requested by {selectedRefund.requester_name}?
              </p>
            )}

            {actionType === 'reject' && (
              <div className="mb-4">
                <label htmlFor="reject-reason" className="block text-xs font-medium text-gray-700 mb-1">
                  Rejection Reason <span aria-hidden="true">*</span>
                </label>
                <textarea
                  id="reject-reason"
                  rows={3}
                  required
                  value={rejectReason}
                  onChange={(e) => setRejectReason(e.target.value)}
                  className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Provide a reason for rejection..."
                />
              </div>
            )}

            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => {
                  setActionId(null);
                  setActionType(null);
                  setRejectReason('');
                }}
                disabled={submitting}
                className="rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400"
              >
                Cancel
              </button>
              {actionType === 'approve' && !isSelfApproval && (
                <button
                  type="button"
                  onClick={() => handleApprove(actionId)}
                  disabled={submitting}
                  className="rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                >
                  {submitting ? 'Approving…' : 'Confirm Approve'}
                </button>
              )}
              {actionType === 'reject' && (
                <button
                  type="button"
                  onClick={() => handleReject(actionId)}
                  disabled={submitting || !rejectReason.trim()}
                  className="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                >
                  {submitting ? 'Rejecting…' : 'Confirm Reject'}
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </main>
  );
}

export default RefundQueuePage;
