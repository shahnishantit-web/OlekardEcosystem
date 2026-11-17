'use client';

import { useCallback, useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/lib/auth/context';

type Organisation = { id: string; display_name: string; registry_reference: string; status: string };
type Membership = {
  id: string; organisation_id: string; user_id: string; status: string; evidence_reference: string;
  requested_by: string; reviewed_by: string | null; created_at: string; organisation_name: string;
  registry_reference: string; user_email: string; requester_email: string | null; reviewer_email: string | null;
};
type Stage = 'mine' | 'review';

export function OrganisationMembershipsPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const canRequest = hasPermission('organisation.registry.request');
  const canReview = hasPermission('organisation.registry.review');
  const canRead = hasPermission('organisation.registry.read');
  const [organisations, setOrganisations] = useState<Organisation[]>([]);
  const [organisationId, setOrganisationId] = useState('');
  const [userEmail, setUserEmail] = useState('');
  const [evidenceReference, setEvidenceReference] = useState('');
  const [stage, setStage] = useState<Stage>(canReview ? 'review' : 'mine');
  const [memberships, setMemberships] = useState<Membership[]>([]);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const loadMemberships = useCallback(async () => {
    if (!authenticatedClient || !canRead) return;
    try {
      const response = await authenticatedClient.get<Membership[]>(`/v1/identity/organisations/memberships?stage=${stage}`);
      setMemberships(response.data);
    } catch (cause) { setError(readError(cause)); }
  }, [authenticatedClient, canRead, stage]);

  useEffect(() => {
    if (!authenticatedClient) return;
    void authenticatedClient.get<Organisation[]>('/v1/identity/organisations?stage=active')
      .then(response => setOrganisations(response.data))
      .catch(cause => setError(readError(cause)));
  }, [authenticatedClient]);
  useEffect(() => { void loadMemberships(); }, [loadMemberships]);

  async function submitRequest(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!authenticatedClient || !organisationId) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const response = await authenticatedClient.post<{ id: string; status: string }>(
        `/v1/identity/organisations/${encodeURIComponent(organisationId)}/memberships`,
        { user_email: userEmail.trim(), evidence_reference: evidenceReference.trim() },
        { idempotencyKey: crypto.randomUUID() },
      );
      setNotice(`Membership request ${response.data.id} submitted for reviewer approval.`);
      setUserEmail(''); setEvidenceReference(''); await loadMemberships();
    } catch (cause) { setError(readError(cause)); }
    finally { setBusy(false); }
  }

  async function reviewMembership(membership: Membership, decision: 'active' | 'rejected') {
    if (!authenticatedClient) return;
    setBusy(true); setError(''); setNotice('');
    try {
      await authenticatedClient.post(
        `/v1/identity/organisations/memberships/${encodeURIComponent(membership.id)}/review`,
        { decision }, { idempotencyKey: crypto.randomUUID() },
      );
      setNotice(decision === 'active' ? `Membership for ${membership.user_email} is active.` : `Membership request for ${membership.user_email} was rejected.`);
      await loadMemberships();
    } catch (cause) { setError(readError(cause)); }
    finally { setBusy(false); }
  }

  return <PageContainer>
    <PageHeader title="Organisation Memberships" />
    <p className="mb-6 max-w-3xl text-sm text-text-secondary">Request a person’s membership in an active organisation. A different reviewer must approve the request before it becomes active.</p>
    {notice && <p role="status" className="mb-4 rounded border border-ok-border bg-ok-surface p-3 text-sm">{notice}</p>}
    {error && <p role="alert" className="mb-4 rounded border border-error p-3 text-sm text-error">{error}</p>}

    {canRequest && <Card className="mb-6 p-5">
      <h2 className="mb-1 text-base font-semibold">Request membership</h2>
      <p className="mb-4 text-sm text-text-secondary">Choose the organisation and enter the target user’s email. Membership remains pending until a reviewer activates it.</p>
      <form onSubmit={event => void submitRequest(event)} className="grid max-w-2xl gap-4">
        <label className="grid gap-1 text-sm">Organisation<select required value={organisationId} onChange={event => setOrganisationId(event.target.value)} className="rounded border border-border px-3 py-2"><option value="">Choose an active organisation</option>{organisations.map(org => <option key={org.id} value={org.id}>{org.display_name} · {org.registry_reference}</option>)}</select></label>
        <label className="grid gap-1 text-sm">User email<input required type="email" maxLength={255} value={userEmail} onChange={event => setUserEmail(event.target.value)} placeholder="admin@olekard.local" className="rounded border border-border px-3 py-2" /></label>
        <label className="grid gap-1 text-sm">Evidence reference<input required maxLength={255} value={evidenceReference} onChange={event => setEvidenceReference(event.target.value)} placeholder="local-demo://membership-request" className="rounded border border-border px-3 py-2" /></label>
        <Button type="submit" disabled={busy || !organisationId}>Submit membership request</Button>
      </form>
      {organisations.length === 0 && <p className="mt-3 text-sm text-text-secondary">There are no active organisations available for membership requests.</p>}
    </Card>}

    {canRead && <Card className="overflow-x-auto p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-base font-semibold">{stage === 'review' ? 'Memberships pending review' : 'My membership requests'}</h2>
        <div className="flex gap-2">
          {canRequest && canReview && <><Button type="button" variant="secondary" onClick={() => setStage('review')}>Reviewer queue</Button><Button type="button" variant="secondary" onClick={() => setStage('mine')}>My requests</Button></>}
          <Button type="button" variant="secondary" onClick={() => void loadMemberships()}>Refresh list</Button>
        </div>
      </div>
      {memberships.length === 0 ? <p className="text-sm text-text-secondary">No membership requests in this list.</p> : <table className="w-full min-w-[900px] border-collapse text-left text-sm">
        <thead><tr className="border-b border-border"><th className="p-2">Organisation</th><th className="p-2">Target user</th><th className="p-2">Evidence reference</th><th className="p-2">Requested by</th><th className="p-2">Requested</th><th className="p-2">Status</th>{stage === 'review' && <th className="p-2">Review</th>}</tr></thead>
        <tbody>{memberships.map(item => <tr key={item.id} className="border-b border-border"><td className="p-2">{item.organisation_name}<span className="block text-xs text-text-secondary">{item.registry_reference}</span></td><td className="p-2">{item.user_email}<span className="block text-xs text-text-secondary">{item.user_id}</span></td><td className="p-2">{item.evidence_reference}</td><td className="p-2">{item.requester_email ?? item.requested_by}</td><td className="p-2">{new Date(item.created_at).toLocaleString()}</td><td className="p-2">{item.status.replaceAll('_',' ')}</td>{stage === 'review' && <td className="p-2"><div className="flex gap-2"><Button type="button" disabled={busy || !canReview} onClick={() => void reviewMembership(item,'active')}>Activate</Button><Button type="button" variant="secondary" disabled={busy || !canReview} onClick={() => void reviewMembership(item,'rejected')}>Reject</Button></div></td>}</tr>)}</tbody>
      </table>}
    </Card>}
  </PageContainer>;
}

function readError(cause: unknown): string {
  const error = cause as { error?: { message?: string; details?: Array<{ message?: string }> }; message?: string };
  return error.error?.details?.map(detail => detail.message).filter(Boolean).join(' ') || error.error?.message || error.message || 'Membership request failed.';
}
