'use client';

import { FormEvent, useCallback, useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/lib/auth/context';

type Organisation = {
  id: string; registry_reference: string; display_name: string; status: string;
  evidence_reference: string; requested_by: string; creator_email?: string;
  reviewed_by?: string | null; reviewer_email?: string | null; reviewer_comments?: string | null;
  approved_by?: string | null; approver_email?: string | null; approver_comments?: string | null;
  created_at: string; updated_at: string;
};
type Stage = 'mine' | 'review' | 'approval';

export function OrganisationOnboardingPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const [registryReference, setRegistryReference] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [evidenceReference, setEvidenceReference] = useState('');
  const [queue, setQueue] = useState<Organisation[]>([]);
  const [selected, setSelected] = useState<Organisation | null>(null);
  const [queueView, setQueueView] = useState<Stage | null>(null);
  const [comments, setComments] = useState('');
  const [decision, setDecision] = useState<'approve' | 'reject'>('approve');
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const canRequest = hasPermission('organisation.registry.request');
  const canReview = hasPermission('organisation.registry.review');
  const canApprove = hasPermission('organisation.registry.approve');
  const canRead = hasPermission('organisation.registry.read');
  const defaultStage: Stage | null = canReview ? 'review' : canApprove ? 'approval' : canRequest ? 'mine' : null;
  const stage = queueView ?? defaultStage;

  const loadQueue = useCallback(async () => {
    if (!authenticatedClient || !stage) return;
    try {
      const response = await authenticatedClient.get<Organisation[]>(`/v1/identity/organisations?stage=${stage}`);
      setQueue(response.data);
    } catch (cause) { setError(readError(cause)); }
  }, [authenticatedClient, stage]);
  useEffect(() => { void loadQueue(); }, [loadQueue]);

  async function openRequest(id: string) {
    if (!authenticatedClient) return;
    setError('');
    try {
      const response = await authenticatedClient.get<Organisation>(`/v1/identity/organisations/${encodeURIComponent(id)}`);
      setSelected(response.data);
      setComments('');
      setDecision('approve');
    } catch (cause) { setError(readError(cause)); }
  }

  async function requestOrganisation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!authenticatedClient) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const response = await authenticatedClient.post<{ id: string; status: string }>('/v1/identity/organisations/', {
        registry_reference: registryReference.trim(), display_name: displayName.trim(), evidence_reference: evidenceReference.trim(),
      }, { idempotencyKey: crypto.randomUUID() });
      setNotice(`Organisation request ${response.data.id} submitted for review.`);
      setRegistryReference(''); setDisplayName(''); setEvidenceReference(''); await loadQueue();
    } catch (cause) { setError(readError(cause)); } finally { setBusy(false); }
  }

  async function processRequest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!authenticatedClient || !selected) return;
    setBusy(true); setError(''); setNotice('');
    const reviewerDecision = canReview && selected.status === 'pending_review';
    const endpoint = reviewerDecision ? 'review' : 'approve';
    const apiDecision = decision === 'reject' ? 'rejected' : reviewerDecision ? 'pending_approval' : 'active';
    try {
      await authenticatedClient.post(`/v1/identity/organisations/${encodeURIComponent(selected.id)}/${endpoint}`, {
        decision: apiDecision, comments: comments.trim() || null,
      }, { idempotencyKey: crypto.randomUUID() });
      setNotice(reviewerDecision ? `Review recorded. Request moved to ${apiDecision === 'rejected' ? 'rejected' : 'approver queue'}.` : `Organisation ${apiDecision === 'active' ? 'approved and activated' : 'rejected'}.`);
      setSelected(null); setComments(''); await loadQueue();
    } catch (cause) { setError(readError(cause)); } finally { setBusy(false); }
  }

  return <PageContainer>
    <PageHeader title="Organisation Onboarding" />
    <p className="mb-6 max-w-3xl text-sm text-text-secondary">Organisations move through creator submission, independent reviewer assessment, then separate approver decision. Each role sees its own queue and the complete submitted record.</p>
    {notice && <p role="status" className="mb-4 rounded border border-ok-border bg-ok-surface p-3 text-sm">{notice}</p>}
    {error && <p role="alert" className="mb-4 rounded border border-error p-3 text-sm text-error">{error}</p>}

    {canRequest && <Card className="mb-6 p-5">
      <h2 className="mb-1 text-base font-semibold">1. Create organisation</h2>
      <p className="mb-4 text-sm text-text-secondary">Provide the registry reference, organisation name and evidence reference for review.</p>
      <form onSubmit={(event) => void requestOrganisation(event)} className="grid max-w-2xl gap-4">
        <label className="grid gap-1 text-sm">Registry reference<input required maxLength={120} value={registryReference} onChange={e => setRegistryReference(e.target.value)} className="rounded border border-border px-3 py-2" /></label>
        <label className="grid gap-1 text-sm">Organisation name<input required maxLength={200} value={displayName} onChange={e => setDisplayName(e.target.value)} className="rounded border border-border px-3 py-2" /></label>
        <label className="grid gap-1 text-sm">Evidence reference<input required maxLength={255} value={evidenceReference} onChange={e => setEvidenceReference(e.target.value)} className="rounded border border-border px-3 py-2" /></label>
        <Button type="submit" disabled={busy}>Submit organisation</Button>
      </form>
    </Card>}

    {canRead && stage && <Card className="mb-6 overflow-x-auto p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 className="text-base font-semibold">{stage === 'review' ? '2. Organisations pending review' : stage === 'approval' ? '3. Organisations pending approval' : 'My organisation requests'}</h2><div className="flex gap-2">{canReview && canApprove && <><Button type="button" variant="secondary" onClick={() => setQueueView('review')}>Reviewer queue</Button><Button type="button" variant="secondary" onClick={() => setQueueView('approval')}>Approver queue</Button></>}<Button type="button" variant="secondary" onClick={() => void loadQueue()}>Refresh list</Button></div></div>
      {queue.length === 0 ? <p className="text-sm text-text-secondary">No organisations in this queue.</p> : <table className="w-full min-w-[760px] border-collapse text-left text-sm">
        <thead><tr className="border-b border-border"><th className="p-2">Organisation</th><th className="p-2">Registry reference</th><th className="p-2">Submitted by</th><th className="p-2">Submitted</th><th className="p-2">Status</th><th className="p-2">Action</th></tr></thead>
        <tbody>{queue.map(org => <tr key={org.id} className="border-b border-border"><td className="p-2">{org.display_name}</td><td className="p-2">{org.registry_reference}</td><td className="p-2">{org.creator_email ?? org.requested_by}</td><td className="p-2">{new Date(org.created_at).toLocaleString()}</td><td className="p-2">{org.status.replaceAll('_', ' ')}</td><td className="p-2"><Button type="button" variant="secondary" onClick={() => void openRequest(org.id)}>View details</Button></td></tr>)}</tbody>
      </table>}
    </Card>}

    {selected && <Card className="p-5">
      <div className="mb-4 flex items-center justify-between"><h2 className="text-base font-semibold">Organisation details</h2><Button type="button" variant="secondary" onClick={() => setSelected(null)}>Close</Button></div>
      <dl className="mb-6 grid gap-3 sm:grid-cols-2">{[
        ['Request ID', selected.id], ['Organisation name', selected.display_name], ['Registry reference', selected.registry_reference], ['Status', selected.status.replaceAll('_', ' ')], ['Evidence reference', selected.evidence_reference], ['Creator', selected.creator_email ?? selected.requested_by], ['Submitted', new Date(selected.created_at).toLocaleString()], ['Reviewer', selected.reviewer_email ?? selected.reviewed_by ?? 'Not reviewed'], ['Reviewer comments', selected.reviewer_comments || 'No reviewer comments'], ['Approver', selected.approver_email ?? selected.approved_by ?? 'Not approved'], ['Approver comments', selected.approver_comments || 'No approver comments'],
      ].map(([label,value]) => <div key={label}><dt className="text-xs font-medium text-text-secondary">{label}</dt><dd className="break-words text-sm">{value}</dd></div>)}</dl>
      {((canReview && selected.status === 'pending_review') || (canApprove && selected.status === 'pending_approval')) && <form onSubmit={(event) => void processRequest(event)} className="grid max-w-2xl gap-4 border-t border-border pt-4">
        <h3 className="font-semibold">{selected.status === 'pending_review' ? 'Reviewer decision' : 'Approver decision'}</h3>
        {selected.status === 'pending_review' && <p className="text-sm text-text-secondary">Approving this review sends the request to the approver queue. It does not activate the organisation yet.</p>}
        {selected.status === 'pending_approval' && <p className="text-sm text-text-secondary">The reviewer’s comments above are included in your decision.</p>}
        <label className="grid gap-1 text-sm">Decision<select value={decision} onChange={e => setDecision(e.target.value as 'approve' | 'reject')} className="rounded border border-border px-3 py-2"><option value="approve">{selected.status === 'pending_review' ? 'Approve review and send to approver' : 'Approve and activate'}</option><option value="reject">Reject</option></select></label>
        <label className="grid gap-1 text-sm">Comments<textarea maxLength={2000} value={comments} onChange={e => setComments(e.target.value)} rows={4} className="rounded border border-border px-3 py-2" /></label>
        <Button type="submit" disabled={busy}>{decision === 'reject' ? 'Submit rejection' : selected.status === 'pending_review' ? 'Approve review and send onward' : 'Approve and activate'}</Button>
      </form>}
    </Card>}
    {!canRead && <Card className="p-5"><p role="alert" className="text-sm">You do not have permission to view organisation requests.</p></Card>}
  </PageContainer>;
}

function readError(cause: unknown): string {
  const error = cause as { response?: { data?: { message?: string; error?: { message?: string } } }; message?: string };
  return error.response?.data?.error?.message ?? error.response?.data?.message ?? error.message ?? 'The organisation request could not be completed.';
}
