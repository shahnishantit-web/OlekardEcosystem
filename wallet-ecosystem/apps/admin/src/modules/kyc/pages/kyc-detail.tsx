'use client';

import { Dialog } from '@/components/ui/Dialog';

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
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { KycSubmission } from '@/lib/api/admin-app-client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

interface Props {
  submissionId: string;
}

const REJECTION_REASON_CODES: { value: string; label: string }[] = [
  { value: 'expired_document',   label: 'Document is expired' },
  { value: 'poor_quality',       label: 'Image quality too poor to verify' },
  { value: 'document_mismatch',  label: 'Details do not match document' },
  { value: 'selfie_mismatch',    label: 'Selfie does not match identity document' },
  { value: 'suspected_fraud',    label: 'Suspected fraudulent document' },
  { value: 'other',              label: 'Other' },
];

function statusVariant(status: string): 'success' | 'error' | 'warning' | 'neutral' {
  if (status === 'approved')       return 'success';
  if (status === 'rejected')       return 'error';
  if (status === 'escalated')      return 'error';
  if (status === 'under_review')   return 'warning';
  if (status === 'pending_review') return 'warning';
  return 'neutral';
}

function outcomeVariant(outcome: string): 'success' | 'error' | 'warning' | 'neutral' {
  if (outcome === 'approved' || outcome === 'pass') return 'success';
  if (outcome === 'rejected' || outcome === 'fail') return 'error';
  if (outcome === 'manual_review_required')         return 'warning';
  return 'neutral';
}

function DocumentCard({ url, index }: { url: string; index: number }) {
  const [enlarged, setEnlarged] = useState(false);
  const isImage = /\.(jpg|jpeg|png|webp|gif)(\?|$)/i.test(url);

  return (
    <>
      <div className="border border-ok-border rounded-lg overflow-hidden">
        {isImage ? (
          <button
            type="button"
            className="w-full text-left"
            onClick={() => setEnlarged(true)}
            aria-label={`View document ${index + 1} full size`}
          >
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={url}
              alt={`Identity document ${index + 1}`}
              className="w-full h-48 object-cover"
            />
            <p className="px-3 py-2 text-xs text-text-secondary">
              Document {index + 1} — click to zoom
            </p>
          </button>
        ) : (
          <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-2 px-4 py-3 text-sm text-ok-primary hover:underline"
            aria-label={`Download document ${index + 1}`}
          >
            <span>📄</span> Document {index + 1}
          </a>
        )}
      </div>

      {/* Full-size modal */}
      {enlarged && isImage && (
        <Dialog open showCloseButton={false} onClose={() => setEnlarged(false)} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/80" className="relative max-w-4xl max-h-screen p-4" label={`Document ${index + 1} viewer`}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={url}
              alt={`Identity document ${index + 1} full size`}
              className="max-w-full max-h-[85vh] object-contain rounded"
            />
            <Button
              variant="secondary"
              className="absolute top-2 right-2"
              onClick={() => setEnlarged(false)}
              aria-label="Close document viewer"
            >
              ✕ Close
            </Button>
          </Dialog>
      )}
    </>
  );
}

export function KycDetailPage({ submissionId }: Props) {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [submission, setSubmission]   = useState<KycSubmission | null>(null);
  const [loading, setLoading]         = useState(false);
  const [error, setError]             = useState<string | null>(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [successMsg, setSuccessMsg]   = useState<string | null>(null);
  const [showApproveDialog, setShowApproveDialog] = useState(false);
  const [showRejectDialog, setShowRejectDialog]   = useState(false);
  const [rejectReasonCode, setRejectReasonCode]   = useState('');
  const [rejectNotes, setRejectNotes]             = useState('');

  const fetchSubmission = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      const res = await client.getKyc(submissionId);
      setSubmission(res.data);
    } catch {
      setError('Failed to load KYC submission.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, submissionId]);

  useEffect(() => { void fetchSubmission(); }, [fetchSubmission]);

  const handleApprove = async () => {
    if (!authenticatedClient) return;
    setActionLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      await client.approveKyc(submissionId, generateIdempotencyKey(`kyc-approve-${submissionId}`));
      setSuccessMsg('Approval submitted for checker review.');
      setShowApproveDialog(false);
      void fetchSubmission();
    } catch (err: unknown) {
      const code = (err as { code?: string })?.code;
      if (code === 'ADMINAPP_KYC_002' || code === 'INVALID_CASE_STATUS') {
        setError('This submission has already been decided.');
      } else {
        setError('Failed to submit approval.');
      }
      setShowApproveDialog(false);
    } finally {
      setActionLoading(false);
    }
  };

  const handleReject = async () => {
    if (!authenticatedClient || !rejectReasonCode) return;
    const reasonLabel = REJECTION_REASON_CODES.find(r => r.value === rejectReasonCode)?.label ?? rejectReasonCode;
    const reason = rejectNotes.trim()
      ? `${reasonLabel}: ${rejectNotes.trim()}`
      : reasonLabel;
    setActionLoading(true);
    setError(null);
    try {
      const client = new AdminAppClient(authenticatedClient);
      await client.rejectKyc(submissionId, reason, generateIdempotencyKey(`kyc-reject-${submissionId}`));
      setSuccessMsg('Rejection submitted.');
      setShowRejectDialog(false);
      setRejectReasonCode('');
      setRejectNotes('');
      void fetchSubmission();
    } catch (err: unknown) {
      const code = (err as { code?: string })?.code;
      if (code === 'ADMINAPP_KYC_002' || code === 'INVALID_CASE_STATUS') {
        setError('This submission has already been decided.');
      } else {
        setError('Failed to submit rejection.');
      }
      setShowRejectDialog(false);
    } finally {
      setActionLoading(false);
    }
  };

  const canDecide = submission?.status === 'pending_review' || submission?.status === 'under_review';

  return (
    <PageContainer>
      <PageHeader
        title="KYC Submission Detail"
        actions={
          <Button variant="secondary" onClick={() => router.back()} aria-label="Back to KYC queue">
            ← Back
          </Button>
        }
      />

      {loading && <Loading label="Loading KYC submission…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchSubmission()} />}

      {successMsg && (
        <div role="status" className="mb-4 rounded bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
          {successMsg}
        </div>
      )}

      {!loading && !error && submission && (
        <div className="space-y-6">
          {/* Submission summary */}
          <Card className="p-6">
            <div className="flex items-start justify-between gap-4 mb-6">
              <div>
                <h2 className="text-base font-semibold text-text-primary">{submission.user_name}</h2>
                {submission.user_phone_number && (
                  <p className="text-sm text-text-secondary">{submission.user_phone_number}</p>
                )}
              </div>
              <Badge
                label={submission.status.replace(/_/g, ' ').toUpperCase()}
                variant={statusVariant(submission.status)}
              />
            </div>

            <dl className="grid grid-cols-2 gap-4">
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Tier Applied</dt>
                <dd className="mt-1 text-sm text-text-primary">{submission.tier_applied == null ? 'Not recorded' : `Tier ${submission.tier_applied}`}</dd>
              </div>
              {submission.current_tier != null && (
                <div>
                  <dt className="text-xs font-semibold uppercase text-text-secondary">Current Tier</dt>
                  <dd className="mt-1 text-sm text-text-primary">Tier {submission.current_tier}</dd>
                </div>
              )}
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Document Type</dt>
                <dd className="mt-1 text-sm text-text-primary capitalize">
                  {submission.document_type.replace(/_/g, ' ')}
                </dd>
              </div>
              <div>
                <dt className="text-xs font-semibold uppercase text-text-secondary">Submitted</dt>
                <dd className="mt-1 text-sm text-text-primary">
                  {submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : 'Not submitted'}
                </dd>
              </div>
              {submission.reviewed_at && (
                <div>
                  <dt className="text-xs font-semibold uppercase text-text-secondary">Reviewed</dt>
                  <dd className="mt-1 text-sm text-text-primary">
                    {new Date(submission.reviewed_at).toLocaleString()}
                  </dd>
                </div>
              )}
              {submission.risk_score != null && (
                <div>
                  <dt className="text-xs font-semibold uppercase text-text-secondary">Risk Score</dt>
                  <dd className="mt-1 text-sm tabular-nums text-text-primary">{submission.risk_score}</dd>
                </div>
              )}
            </dl>

            {submission.rejection_reason && (
              <div className="mt-4 p-3 rounded bg-red-50 border border-red-200">
                <p className="text-xs font-semibold uppercase text-red-700 mb-1">Rejection Reason</p>
                <p className="text-sm text-red-800">{submission.rejection_reason}</p>
              </div>
            )}
          </Card>

          {/* Personal information */}
          {submission.personal_info && (
            <section aria-label="Personal information">
              <Card className="p-6">
                <h3 className="text-sm font-semibold text-text-primary mb-4">Personal Information</h3>
                <dl className="grid grid-cols-2 gap-4">
                  {submission.personal_info.full_legal_name && (
                    <div>
                      <dt className="text-xs font-semibold uppercase text-text-secondary">Full Legal Name</dt>
                      <dd className="mt-1 text-sm text-text-primary">{submission.personal_info.full_legal_name}</dd>
                    </div>
                  )}
                  {submission.personal_info.date_of_birth && (
                    <div>
                      <dt className="text-xs font-semibold uppercase text-text-secondary">Date of Birth</dt>
                      <dd className="mt-1 text-sm text-text-primary">{submission.personal_info.date_of_birth}</dd>
                    </div>
                  )}
                  {submission.personal_info.nationality && (
                    <div>
                      <dt className="text-xs font-semibold uppercase text-text-secondary">Nationality</dt>
                      <dd className="mt-1 text-sm text-text-primary">{submission.personal_info.nationality}</dd>
                    </div>
                  )}
                  {submission.personal_info.address?.city && (
                    <div>
                      <dt className="text-xs font-semibold uppercase text-text-secondary">City</dt>
                      <dd className="mt-1 text-sm text-text-primary">{submission.personal_info.address.city}</dd>
                    </div>
                  )}
                </dl>
              </Card>
            </section>
          )}

          {/* Documents */}
          {(submission.document_urls ?? []).length > 0 && (
            <section aria-label="Identity documents">
              <h3 className="text-sm font-semibold text-text-primary mb-3">Identity Documents</h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {(submission.document_urls ?? []).map((url, i) => (
                  <DocumentCard key={url} url={url} index={i} />
                ))}
              </div>
            </section>
          )}

          {/* AI pre-screen & verification results */}
          {submission.verification_results && submission.verification_results.length > 0 && (
            <section aria-label="AI pre-screen results">
              <Card className="p-6">
                <h3 className="text-sm font-semibold text-text-primary mb-4">AI Pre-screen Results</h3>
                <div className="space-y-3">
                  {submission.verification_results.map((result, i) => (
                    <div
                      key={i}
                      className="flex items-center justify-between p-3 rounded-lg bg-ok-surface border border-ok-border"
                    >
                      <div>
                        <p className="text-sm font-medium text-text-primary capitalize">
                          {result.provider_name.replace(/_/g, ' ')}
                        </p>
                        {result.confidence_score != null && (
                          <p className="text-xs text-text-secondary">
                            Confidence: {(result.confidence_score * 100).toFixed(0)}%
                          </p>
                        )}
                      </div>
                      <Badge
                        label={result.outcome.replace(/_/g, ' ').toUpperCase()}
                        variant={outcomeVariant(result.outcome)}
                      />
                    </div>
                  ))}
                </div>
              </Card>
            </section>
          )}

          {/* Action buttons */}
          {canDecide && (
            <div className="flex gap-3">
              <Button
                variant="primary"
                onClick={() => setShowApproveDialog(true)}
                disabled={actionLoading}
                aria-label="Approve this KYC submission"
              >
                Approve
              </Button>
              <Button
                variant="danger"
                onClick={() => setShowRejectDialog(true)}
                disabled={actionLoading}
                aria-label="Reject this KYC submission"
              >
                Reject
              </Button>
            </div>
          )}
        </div>
      )}

      {/* Approve confirmation dialog */}
      {showApproveDialog && (
        <Dialog open showCloseButton={false} onClose={() => setShowApproveDialog(false)} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="approve-dialog-title">
            <h2 id="approve-dialog-title" className="text-lg font-semibold text-text-primary mb-3">
              Approve KYC Submission
            </h2>
            <p className="text-sm text-text-secondary mb-6">
              This will approve the submission and submit it for checker review. The customer will be
              notified once the decision is finalised.
            </p>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={() => setShowApproveDialog(false)} disabled={actionLoading}>
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={() => void handleApprove()}
                disabled={actionLoading}
                loading={actionLoading}
                aria-label="Confirm KYC approval"
              >
                Confirm Approval
              </Button>
            </div>
          </Dialog>
      )}

      {/* Reject dialog */}
      {showRejectDialog && (
        <Dialog open showCloseButton={false} onClose={() => setShowRejectDialog(false)} overlayClassName="fixed inset-0 z-50 flex items-center justify-center bg-black/40" className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6" labelledBy="reject-dialog-title">
            <h2 id="reject-dialog-title" className="text-lg font-semibold text-text-primary mb-3">
              Reject KYC Submission
            </h2>

            <div className="mb-4">
              <label htmlFor="reject-reason-code" className="block text-sm font-medium text-text-secondary mb-1">
                Reason code <span aria-hidden="true">*</span>
              </label>
              <select
                id="reject-reason-code"
                value={rejectReasonCode}
                onChange={e => setRejectReasonCode(e.target.value)}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              >
                <option value="">Select a reason…</option>
                {REJECTION_REASON_CODES.map(r => (
                  <option key={r.value} value={r.value}>{r.label}</option>
                ))}
              </select>
            </div>

            <div className="mb-4">
              <label htmlFor="reject-notes" className="block text-sm font-medium text-text-secondary mb-1">
                Additional notes <span className="text-text-tertiary">(optional)</span>
              </label>
              <textarea
                id="reject-notes"
                value={rejectNotes}
                onChange={e => setRejectNotes(e.target.value)}
                rows={3}
                className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
                placeholder="Provide any additional context for the customer…"
              />
            </div>

            <div className="flex justify-end gap-3">
              <Button
                variant="secondary"
                onClick={() => { setShowRejectDialog(false); setRejectReasonCode(''); setRejectNotes(''); }}
                disabled={actionLoading}
              >
                Cancel
              </Button>
              <Button
                variant="danger"
                onClick={() => void handleReject()}
                disabled={!rejectReasonCode || actionLoading}
                loading={actionLoading}
                aria-label="Confirm KYC rejection"
              >
                Confirm Rejection
              </Button>
            </div>
          </Dialog>
      )}
    </PageContainer>
  );
}
