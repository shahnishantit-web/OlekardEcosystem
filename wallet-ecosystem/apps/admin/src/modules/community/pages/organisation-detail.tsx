'use client';

import { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';

interface Organisation {
  id: string;
  name: string;
  type: string;
  status: string;
  registration_number: string | null;
  operating_countries: string[];
  suspension_reason: string | null;
  verified_at: string | null;
  created_at: string;
  evidence: Array<{ id: string; document_type: string; scan_status: string; uploaded_at: string }>;
  status_history: Array<{ from_status: string; to_status: string; reason: string | null; actor_id: string; occurred_at: string }>;
}

type StatusVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

function statusVariant(status: string): StatusVariant {
  switch (status) {
    case 'approved':          return 'success';
    case 'submitted':         return 'info';
    case 'under_review':      return 'info';
    case 'changes_requested': return 'warning';
    case 'suspended':         return 'error';
    case 'archived':          return 'neutral';
    case 'rejected':          return 'error';
    default:                  return 'neutral';
  }
}

export function OrganisationDetailPage() {
  const { authenticatedClient } = useAuth();
  const params = useParams<{ id: string }>();
  const router = useRouter();

  const [org, setOrg] = useState<Organisation | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionLoading, setActionLoading] = useState(false);

  const fetchOrg = useCallback(async () => {
    if (!authenticatedClient || !params.id) return;
    setLoading(true);
    setError(null);
    try {
      const res = await authenticatedClient.get<Organisation>(
        `/v1/community/admin/organisations/${params.id}`
      );
      setOrg(res.data);
    } catch {
      setError('Failed to load organisation.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, params.id]);

  useEffect(() => { void fetchOrg(); }, [fetchOrg]);

  const handleTransition = async (toStatus: string, reason?: string) => {
    if (!authenticatedClient || !params.id) return;
    setActionLoading(true);
    setActionError(null);
    try {
      await authenticatedClient.post(
        `/v1/community/admin/organisations/${params.id}/transition`,
        { status: toStatus, reason }
      );
      await fetchOrg();
    } catch {
      setActionError(`Failed to transition to ${toStatus}.`);
    } finally {
      setActionLoading(false);
    }
  };

  if (loading) return <PageContainer><Loading label="Loading organisation…" /></PageContainer>;
  if (error || !org) return (
    <PageContainer>
      <ErrorState message={error ?? 'Organisation not found.'} onRetry={() => void fetchOrg()} />
    </PageContainer>
  );

  const canApprove   = org.status === 'under_review';
  const canSuspend   = org.status === 'approved';
  const canArchive   = ['approved', 'suspended', 'rejected'].includes(org.status);
  const canSubmit    = org.status === 'draft' || org.status === 'changes_requested';
  const canReview    = org.status === 'submitted';

  return (
    <PageContainer>
      <PageHeader
        title={org.name}
        actions={
          <Button variant="secondary" onClick={() => router.back()}>
            Back
          </Button>
        }
      />

      {actionError && (
        <div role="alert" className="mb-4 rounded bg-error-bg border border-error px-4 py-3 text-sm text-error">
          {actionError}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          <Card>
            <h2 className="text-base font-semibold text-text-primary mb-4">Organisation Details</h2>
            <dl className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <dt className="text-text-secondary">Type</dt>
                <dd className="text-text-primary mt-1">{org.type}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Status</dt>
                <dd className="mt-1">
                  <Badge label={org.status.replace(/_/g, ' ').toUpperCase()} variant={statusVariant(org.status)} />
                </dd>
              </div>
              <div>
                <dt className="text-text-secondary">Registration Number</dt>
                <dd className="text-text-primary mt-1">{org.registration_number ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-text-secondary">Operating Countries</dt>
                <dd className="text-text-primary mt-1">{org.operating_countries.join(', ') || '—'}</dd>
              </div>
              {org.suspension_reason && (
                <div className="col-span-2">
                  <dt className="text-text-secondary">Suspension Reason</dt>
                  <dd className="text-error mt-1">{org.suspension_reason}</dd>
                </div>
              )}
            </dl>
          </Card>

          <Card>
            <h2 className="text-base font-semibold text-text-primary mb-4">Evidence Documents</h2>
            {org.evidence.length === 0 ? (
              <p className="text-sm text-text-secondary">No evidence submitted.</p>
            ) : (
              <ul className="space-y-2">
                {org.evidence.map((ev) => (
                  <li key={ev.id} className="flex items-center justify-between text-sm border rounded px-3 py-2">
                    <span>{ev.document_type}</span>
                    <div className="flex items-center gap-3">
                      <Badge
                        label={ev.scan_status.toUpperCase()}
                        variant={ev.scan_status === 'clean' ? 'success' : ev.scan_status === 'malicious' ? 'error' : 'warning'}
                      />
                      <span className="text-text-secondary">{new Date(ev.uploaded_at).toLocaleDateString()}</span>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <h2 className="text-base font-semibold text-text-primary mb-4">Actions</h2>
            <div className="flex flex-col gap-2">
              {canSubmit && (
                <Button
                  variant="primary"
                  onClick={() => void handleTransition('submitted')}
                  disabled={actionLoading}
                >
                  Submit for Review
                </Button>
              )}
              {canReview && (
                <Button
                  variant="secondary"
                  onClick={() => void handleTransition('under_review')}
                  disabled={actionLoading}
                >
                  Mark Under Review
                </Button>
              )}
              {canApprove && (
                <Button
                  variant="primary"
                  onClick={() => void handleTransition('approved')}
                  disabled={actionLoading}
                >
                  Approve Organisation
                </Button>
              )}
              {canSuspend && (
                <Button
                  variant="danger"
                  onClick={() => {
                    const reason = window.prompt('Enter suspension reason (required):');
                    if (reason) void handleTransition('suspended', reason);
                  }}
                  disabled={actionLoading}
                >
                  Suspend
                </Button>
              )}
              {canArchive && (
                <Button
                  variant="secondary"
                  onClick={() => {
                    const reason = window.prompt('Enter archive reason (required):');
                    if (reason) void handleTransition('archived', reason);
                  }}
                  disabled={actionLoading}
                >
                  Archive
                </Button>
              )}
            </div>
          </Card>

          <Card>
            <h2 className="text-base font-semibold text-text-primary mb-4">Status History</h2>
            {org.status_history.length === 0 ? (
              <p className="text-sm text-text-secondary">No transitions yet.</p>
            ) : (
              <ol className="space-y-3" aria-label="Status history">
                {org.status_history.map((h, i) => (
                  <li key={i} className="text-sm border-l-2 border-ok-border pl-3">
                    <p className="font-medium text-text-primary">
                      {h.from_status.replace(/_/g, ' ')} → {h.to_status.replace(/_/g, ' ')}
                    </p>
                    {h.reason && <p className="text-text-secondary">{h.reason}</p>}
                    <p className="text-text-secondary text-xs">{new Date(h.occurred_at).toLocaleString()}</p>
                  </li>
                ))}
              </ol>
            )}
          </Card>
        </div>
      </div>
    </PageContainer>
  );
}
