'use client';

import { Dialog } from '@/components/ui/Dialog';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AdminAgent, PendingAction } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

// ---------------------------------------------------------------------------
// Status badge
// ---------------------------------------------------------------------------
function AgentStatusBadge({ status }: { status: string }) {
  const s = (status ?? '').toLowerCase();
  const variant =
    s === 'active'          ? 'success'  :
    s === 'pending_approval'? 'warning'  :
    s === 'suspended'       ? 'error'    : 'neutral';
  return <Badge label={(status || 'unknown').replace(/_/g, ' ').toUpperCase()} variant={variant} />;
}

// ---------------------------------------------------------------------------
// Detail field helper
// ---------------------------------------------------------------------------
function DetailField({
  label,
  value,
  mono = false,
}: {
  label: string;
  value: React.ReactNode;
  mono?: boolean | undefined;
}) {
  return (
    <div>
      <dt className="text-xs font-medium text-text-secondary uppercase tracking-wide">{label}</dt>
      <dd className={`mt-0.5 text-sm text-text-primary ${mono ? 'font-mono' : ''}`}>{value ?? '—'}</dd>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Metric card
// ---------------------------------------------------------------------------
function MetricCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded border border-ok-border bg-ok-surface p-4">
      <p className="text-xs font-medium text-text-secondary uppercase tracking-wide mb-1">{label}</p>
      <p className="text-xl font-semibold tabular-nums text-text-primary">{value}</p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Dialog state types
// ---------------------------------------------------------------------------
type DialogKind = 'approve' | 'reject' | 'suspend' | 'reactivate';

interface ActionDialog {
  kind: DialogKind | null;
  reason: string;
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------
export function AgentDetailPage({ agentId }: { agentId: string }) {
  const { authenticatedClient } = useAuth();

  const [agent, setAgent]         = useState<AdminAgent | null>(null);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState<string | null>(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [dialog, setDialog]       = useState<ActionDialog>({ kind: null, reason: '' });

  const fetchAgent = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getAgent(agentId);
      setAgent(res.data as unknown as AdminAgent);
    } catch {
      setError('Failed to load agent. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, agentId]);

  useEffect(() => { void fetchAgent(); }, [fetchAgent]);

  const openDialog = (kind: DialogKind) => {
    setSuccessMsg(null);
    setActionError(null);
    setDialog({ kind, reason: '' });
  };

  const closeDialog = () => setDialog({ kind: null, reason: '' });

  const handleConfirm = async () => {
    if (!authenticatedClient || !dialog.kind) return;

    const requiresReason = dialog.kind !== 'approve';
    if (requiresReason && !dialog.reason.trim()) return;

    setActionLoading(true);
    setActionError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const id = agent?.agent_id ?? agentId;

      if (dialog.kind === 'approve') {
        const idemKey = generateIdempotencyKey(`agent-approve-${id}`);
        const res = await client.approveAgent(id, idemKey);
        const result = res.data as unknown as PendingAction;
        setSuccessMsg(`Agent approval submitted for checker review. Approval ID: ${result.approval_id}`);

      } else if (dialog.kind === 'reject') {
        const idemKey = generateIdempotencyKey(`agent-reject-${id}`);
        await client.rejectAgent(id, dialog.reason.trim(), idemKey);
        setSuccessMsg('Agent application rejected.');
        void fetchAgent();

      } else if (dialog.kind === 'suspend' || dialog.kind === 'reactivate') {
        const idemKey = generateIdempotencyKey(`agent-${dialog.kind}-${id}`);
        const response = await client.updateAgentStatus(id, dialog.kind, dialog.reason.trim(), idemKey);
        const updatedStatus = response.data.agent_status ?? response.data.status
          ?? (dialog.kind === 'suspend' ? 'suspended' : 'active');
        setAgent(current => current ? { ...current, agent_status: updatedStatus, status: updatedStatus } : current);
        setSuccessMsg(`Agent ${dialog.kind === 'suspend' ? 'suspended' : 'reactivated'} successfully.`);
      }

      closeDialog();
    } catch (err) {
      const code = (err as { code?: string })?.code;
      if (code === 'AGENT_NOT_FOUND') {
        setActionError('Agent not found.');
      } else if (code === 'AGENT_INVALID_STATUS_TRANSITION') {
        setActionError('This status change is not permitted from the current state.');
      } else {
        setActionError('Action failed. Please try again.');
      }
    } finally {
      setActionLoading(false);
    }
  };

  if (loading) return <PageContainer><Loading label="Loading agent…" /></PageContainer>;
  if (error)   return <PageContainer><ErrorState message={error} onRetry={() => void fetchAgent()} /></PageContainer>;
  if (!agent)  return null;

  const id          = agent.agent_id ?? agent.id;
  const status      = agent.agent_status ?? agent.status;
  const name        = agent.full_name?.trim() || 'Agent';
  const currency    = agent.float_currency ?? '';
  const floatBal    = agent.float_balance_minor_units ?? 0;
  const floatLimit  = agent.float_limit_minor_units ?? 0;
  const utilization = floatLimit > 0 ? ((floatBal / floatLimit) * 100).toFixed(1) : '0.0';
  const cashIn      = agent.total_cash_in_today_minor_units ?? 0;
  const cashOut     = agent.total_cash_out_today_minor_units ?? 0;
  const commRate    = agent.commission_rate ?? 0;

  const isPending   = status === 'pending_approval';
  const isActive    = status === 'active';
  const isSuspended = status === 'suspended';

  const fmt = (minor: number) => `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;

  const dialogTitles: Record<DialogKind, string> = {
    approve:    'Approve Agent Application',
    reject:     'Reject Agent Application',
    suspend:    'Suspend Agent',
    reactivate: 'Reactivate Agent',
  };
  const dialogNeedsReason: Record<DialogKind, boolean> = {
    approve: false, reject: true, suspend: true, reactivate: true,
  };

  return (
    <PageContainer>
      <PageHeader
        title={name}
        breadcrumbs={[
          { label: 'Agents', href: '/agents' },
          { label: name },
        ]}
      />

      {successMsg && (
        <div role="status" className="mb-4 rounded bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
          {successMsg}
        </div>
      )}
      {actionError && (
        <div role="alert" className="mb-4 rounded bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
          {actionError}
        </div>
      )}

      {/* Profile card */}
      <Card className="mb-6 p-6">
        <div className="flex flex-wrap gap-6 items-start justify-between">
          <dl className="grid grid-cols-2 gap-x-12 gap-y-3">
            <DetailField label="Agent ID"      value={id} mono />
            <DetailField label="Status"        value={<AgentStatusBadge status={status} />} />
            <DetailField label="Full Name"     value={name} />
            <DetailField label="Phone"         value={agent.phone_number ?? '—'} mono />
            <DetailField label="Business"      value={agent.business_name ?? '—'} />
            <DetailField label="Region"        value={agent.location ?? '—'} />
            {agent.approved_at && (
              <DetailField label="Approved At" value={new Date(agent.approved_at).toLocaleString()} />
            )}
            {agent.approved_by && (
              <DetailField label="Approved By" value={agent.approved_by} />
            )}
            {agent.user_id && (
              <DetailField
                label="User"
                value={
                  <Link href={`/users/${agent.user_id}`} className="text-ok-primary underline hover:text-ok-primary-hover font-mono">
                    {agent.user_id.substring(0, 8)}…
                  </Link>
                }
              />
            )}
            <DetailField label="Joined" value={new Date(agent.created_at).toLocaleDateString()} />
          </dl>

          {/* Action buttons */}
          <div className="flex flex-col gap-2">
            {isPending && (
              <>
                <Button onClick={() => openDialog('approve')}>
                  Approve Application
                </Button>
                <Button variant="danger" onClick={() => openDialog('reject')}>
                  Reject Application
                </Button>
              </>
            )}
            {isActive && (
              <Button variant="danger" onClick={() => openDialog('suspend')}>
                Suspend Agent
              </Button>
            )}
            {isSuspended && (
              <Button onClick={() => openDialog('reactivate')}>
                Reactivate Agent
              </Button>
            )}
          </div>
        </div>
      </Card>

      {/* Float balance */}
      <section aria-label="Float balance" className="mb-6">
        <h2 className="text-base font-semibold text-text-primary mb-3">Float Balance</h2>
        <div className="grid grid-cols-3 gap-4">
          <MetricCard label="Current Balance" value={fmt(floatBal)} />
          <MetricCard label="Float Limit"     value={fmt(floatLimit)} />
          <MetricCard label="Utilization"     value={`${utilization}%`} />
        </div>
      </section>

      {/* Performance metrics */}
      <section aria-label="Performance metrics" className="mb-6">
        <h2 className="text-base font-semibold text-text-primary mb-3">Performance Metrics</h2>
        <div className="grid grid-cols-4 gap-4">
          <MetricCard label="Commission Rate"    value={`${(commRate * 100).toFixed(2)}%`} />
          <MetricCard label="Cash-In Today"      value={fmt(cashIn)} />
          <MetricCard label="Cash-Out Today"     value={fmt(cashOut)} />
          <MetricCard
            label="Tx Count (30d)"
            value={agent.transactions_30d != null ? String(agent.transactions_30d) : '—'}
          />
        </div>
        {agent.commission_earned_30d_minor_units != null && (
          <div className="grid grid-cols-2 gap-4 mt-4">
            <MetricCard label="Commission Earned (30d)" value={fmt(agent.commission_earned_30d_minor_units)} />
            {agent.cash_in_30d_minor_units != null && (
              <MetricCard label="Cash-In Volume (30d)" value={fmt(agent.cash_in_30d_minor_units)} />
            )}
          </div>
        )}
      </section>

      {/* Rejection/suspension info */}
      {agent.rejection_reason && (
        <Card className="mb-6 p-4 border-red-200 bg-red-50">
          <h2 className="text-sm font-semibold text-red-800 mb-1">Rejection Reason</h2>
          <p className="text-sm text-red-700">{agent.rejection_reason}</p>
        </Card>
      )}
      {agent.suspension_reason && (
        <Card className="mb-6 p-4 border-orange-200 bg-orange-50">
          <h2 className="text-sm font-semibold text-orange-800 mb-1">Suspension Reason</h2>
          <p className="text-sm text-orange-700">{agent.suspension_reason}</p>
          {agent.suspended_at && (
            <p className="text-xs text-orange-600 mt-1">
              Suspended: {new Date(agent.suspended_at).toLocaleString()}
            </p>
          )}
        </Card>
      )}

      {/* Action dialog */}
      {dialog.kind && (
        <Dialog open showCloseButton={false} onClose={closeDialog} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="agent-action-dialog-title">
            <h2 id="agent-action-dialog-title" className="text-lg font-semibold text-text-primary mb-4">
              {dialogTitles[dialog.kind]}
            </h2>

            {dialog.kind === 'approve' && (
              <p className="text-sm text-text-secondary mb-4">
                Approving this agent application requires a second approver. A pending action will be created.
              </p>
            )}

            {dialogNeedsReason[dialog.kind] && (
              <div className="mb-4">
                <label htmlFor="agent-action-reason" className="block text-sm font-medium text-text-secondary mb-1">
                  Reason <span aria-hidden="true">*</span>
                </label>
                <textarea
                  id="agent-action-reason"
                  value={dialog.reason}
                  onChange={e => setDialog(d => ({ ...d, reason: e.target.value }))}
                  rows={3}
                  className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                  placeholder={`Enter reason for ${dialog.kind}…`}
                />
              </div>
            )}

            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={closeDialog} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                variant={dialog.kind === 'reject' || dialog.kind === 'suspend' ? 'danger' : 'primary'}
                onClick={() => void handleConfirm()}
                disabled={
                  (dialogNeedsReason[dialog.kind] && !dialog.reason.trim()) || actionLoading
                }
                loading={actionLoading}
              >
                Confirm
              </Button>
            </div>
          </Dialog>
      )}
    </PageContainer>
  );
}
