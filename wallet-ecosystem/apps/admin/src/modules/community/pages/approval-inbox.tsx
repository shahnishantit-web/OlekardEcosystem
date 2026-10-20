'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth/context';

type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'expired' | 'cancelled';

interface PolicyVersion {
  id: string;
  policy_type: string;
  version: string;
  is_active: boolean;
}

interface ApprovalRequest {
  id: string;
  subject_type: string;
  subject_id: string;
  organisation_id: string;
  organisation_name: string;
  submitted_by: string;
  submitter_name: string;
  subject_payload: Record<string, unknown>;
  notes: string | null;
  status: ApprovalStatus;
  submitted_at: string;
  expires_at: string | null;
  approved_by: string | null;
  approver_name: string | null;
  policy_version: PolicyVersion | null;
}



const STATUS_COLOURS: Record<ApprovalStatus, string> = {
  pending:   'bg-yellow-100 text-yellow-800',
  approved:  'bg-green-100 text-green-800',
  rejected:  'bg-red-100 text-red-800',
  expired:   'bg-gray-100 text-gray-600',
  cancelled: 'bg-gray-100 text-gray-600',
};

export function ApprovalInboxPage() {
  const { authenticatedClient: client, user } = useAuth();
  const currentUserId = user?.id;
  const [statusFilter, setStatusFilter] = useState<ApprovalStatus | 'all'>('pending');
  const [typeFilter, setTypeFilter]     = useState('');
  const [loading, setLoading]           = useState(false);
  const [approvals, setApprovals]       = useState<ApprovalRequest[]>([]);
  const [error, setError]               = useState<string | null>(null);
  const [selected, setSelected]         = useState<ApprovalRequest | null>(null);
  const [actionNotes, setActionNotes]   = useState('');
  const [actionType, setActionType]     = useState<'approve' | 'reject' | null>(null);
  const [submitting, setSubmitting]     = useState(false);

  async function loadApprovals() {
    if (!client) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (statusFilter !== 'all') params.set('status', statusFilter);
      if (typeFilter) params.set('subject_type', typeFilter);
      const resp = await client.get(`/v1/community/admin/approvals?${params}`);
      const data = resp.data as { data: ApprovalRequest[] };
      setApprovals(data.data ?? []);
    } catch {
      setError('Failed to load approvals.');
    } finally {
      setLoading(false);
    }
  }

  async function handleAction(request: ApprovalRequest, type: 'approve' | 'reject') {
    if (!client) return;
    if (type === 'reject' && !actionNotes.trim()) return;

    setSubmitting(true);
    setError(null);
    try {
      const url = `/v1/community/admin/approvals/${request.id}/${type}`;
      await client.post(url, { notes: actionNotes });
      setSelected(null);
      setActionType(null);
      setActionNotes('');
      await loadApprovals();
    } catch {
      setError(`Failed to ${type} approval.`);
    } finally {
      setSubmitting(false);
    }
  }

  const isSelfApproval = selected?.submitted_by === currentUserId;

  return (
    <main className="p-6 max-w-6xl mx-auto">
      <h1 className="text-2xl font-semibold text-gray-900 mb-1">Approval Inbox</h1>
      <p className="text-sm text-gray-500 mb-6">
        Maker-checker queue. Finance Approvers cannot approve their own submissions.
        Policy version is displayed for each subject.
      </p>

      {/* Filters */}
      <section aria-label="Filter approvals" className="flex flex-wrap gap-4 mb-6">
        <div>
          <label htmlFor="status-filter" className="block text-xs font-medium text-gray-700 mb-1">
            Status
          </label>
          <select
            id="status-filter"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as ApprovalStatus | 'all')}
            className="rounded border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="all">All</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="expired">Expired</option>
          </select>
        </div>

        <div>
          <label htmlFor="type-filter" className="block text-xs font-medium text-gray-700 mb-1">
            Subject Type
          </label>
          <input
            id="type-filter"
            type="text"
            placeholder="e.g. refund"
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
            className="rounded border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div className="flex items-end">
          <button
            type="button"
            onClick={loadApprovals}
            disabled={loading || !client}
            className="rounded bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
            aria-label="Apply filters and load approvals"
          >
            {loading ? 'Loading…' : 'Load Approvals'}
          </button>
        </div>
      </section>

      {error && (
        <div role="alert" className="mb-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {error}
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {/* Approval list */}
        <div className="space-y-2">
          {approvals.length === 0 && !loading && (
            <p className="text-sm text-gray-400 p-4 text-center">
              No approvals found. Apply filters and click Load Approvals.
            </p>
          )}
          {approvals.map((a) => (
            <button
              key={a.id}
              type="button"
              onClick={() => { setSelected(a); setActionType(null); setActionNotes(''); }}
              className={`w-full text-left rounded-lg border p-4 hover:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors ${
                selected?.id === a.id ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white'
              }`}
              aria-pressed={selected?.id === a.id}
              aria-label={`Approval request: ${a.subject_type} by ${a.submitter_name}`}
            >
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-medium text-gray-900 capitalize">{a.subject_type.replace('_', ' ')}</p>
                  <p className="text-sm text-gray-500 truncate">{a.submitter_name}</p>
                </div>
                <span className={`flex-shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_COLOURS[a.status]}`}>
                  {a.status.charAt(0).toUpperCase() + a.status.slice(1)}
                </span>
              </div>
              {a.policy_version && (
                <p className="mt-1 text-xs text-gray-400">
                  Policy: {a.policy_version.policy_type} v{a.policy_version.version}
                  {a.policy_version.is_active ? '' : ' (superseded)'}
                </p>
              )}
            </button>
          ))}
        </div>

        {/* Detail panel */}
        {selected && (
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4 capitalize">
              {selected.subject_type.replace('_', ' ')} Review
            </h2>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm mb-6">
              <dt className="text-gray-500">Organisation</dt>
              <dd className="text-gray-900">{selected.organisation_name}</dd>
              <dt className="text-gray-500">Submitted by</dt>
              <dd className="text-gray-900">
                {selected.submitter_name}
                {isSelfApproval && (
                  <span className="ml-1 text-xs text-amber-600 font-medium">(you)</span>
                )}
              </dd>
              <dt className="text-gray-500">Submitted at</dt>
              <dd className="text-gray-900">{new Date(selected.submitted_at).toLocaleString()}</dd>
              {selected.expires_at && (
                <>
                  <dt className="text-gray-500">Expires at</dt>
                  <dd className="text-gray-900">{new Date(selected.expires_at).toLocaleString()}</dd>
                </>
              )}
              {selected.policy_version && (
                <>
                  <dt className="text-gray-500">Policy Version</dt>
                  <dd className="text-gray-900 font-mono text-xs">
                    {selected.policy_version.policy_type} v{selected.policy_version.version}
                    {!selected.policy_version.is_active && (
                      <span className="ml-1 text-amber-600">(superseded)</span>
                    )}
                  </dd>
                </>
              )}
            </dl>

            {/* Subject payload */}
            <div className="mb-6">
              <h3 className="text-sm font-medium text-gray-700 mb-2">Subject Details</h3>
              <pre className="rounded bg-gray-50 border border-gray-200 p-3 text-xs overflow-auto max-h-40">
                {JSON.stringify(selected.subject_payload, null, 2)}
              </pre>
            </div>

            {/* Actions */}
            {selected.status === 'pending' && (
              <div className="space-y-3">
                {isSelfApproval && (
                  <div role="alert" className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                    Self-approval is not permitted. You submitted this request.
                  </div>
                )}

                {actionType === 'reject' && (
                  <div>
                    <label htmlFor="action-notes" className="block text-xs font-medium text-gray-700 mb-1">
                      Rejection Reason <span aria-hidden="true">*</span>
                    </label>
                    <textarea
                      id="action-notes"
                      rows={3}
                      required
                      value={actionNotes}
                      onChange={(e) => setActionNotes(e.target.value)}
                      className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                      placeholder="Reason for rejection..."
                    />
                  </div>
                )}

                <div className="flex gap-3">
                  {actionType !== 'reject' && (
                    <button
                      type="button"
                      onClick={() => handleAction(selected, 'approve')}
                      disabled={submitting || isSelfApproval}
                      title={isSelfApproval ? 'Cannot approve your own submission' : undefined}
                      className="flex-1 rounded bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-40 focus:outline-none focus:ring-2 focus:ring-green-500"
                      aria-disabled={isSelfApproval}
                    >
                      {submitting ? 'Approving…' : 'Approve'}
                    </button>
                  )}

                  {actionType === 'reject' ? (
                    <button
                      type="button"
                      onClick={() => handleAction(selected, 'reject')}
                      disabled={submitting || !actionNotes.trim()}
                      className="flex-1 rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                    >
                      {submitting ? 'Rejecting…' : 'Confirm Reject'}
                    </button>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setActionType('reject')}
                      className="flex-1 rounded border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                    >
                      Reject
                    </button>
                  )}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </main>
  );
}

export default ApprovalInboxPage;
