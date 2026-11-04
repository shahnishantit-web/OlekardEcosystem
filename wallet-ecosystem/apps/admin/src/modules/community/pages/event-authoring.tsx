'use client';

import { useRef, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/lib/auth/context';

type EventDraft = {
  id: string;
  organisation_id: string;
  version: number;
  title: string;
  status: string;
  sales_state: string;
  lifecycle_state?: string;
  [key: string]: unknown;
};

type CancellationRequest = {
  id: string;
  event_version: number;
  requested_by_actor_id: string;
  reviewed_by_actor_id: string | null;
  status: string;
  reason: string;
  review_reason: string | null;
};

type ComplimentaryApproval = {
  approval_id: string;
  version: number;
  category_id: string;
  requested_by_actor_id: string;
  approved_by_actor_id: string | null;
  approved_quota: number;
  valid_from: string;
  expires_at: string;
  status: string;
};

type IssuedAuthorityAssignment = {
  id: string;
  actor_id: string;
  permission_slug: string;
  revoked_at: string | null;
};

type AttendanceCategory = {
  category: string;
  free_registrations: number;
  paid_sales: number;
  unique_admissions: number;
  admitted_count: number;
  no_shows: number | null;
  no_shows_finalized: boolean;
};

export function EventAuthoringPage() {
  const { authenticatedClient, hasPermission, user } = useAuth();
  const [organisationId, setOrganisationId] = useState('');
  const [eventId, setEventId] = useState('');
  const [newDraftJson, setNewDraftJson] = useState('');
  const [draft, setDraft] = useState<EventDraft | null>(null);
  const [editor, setEditor] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [attendance, setAttendance] = useState<AttendanceCategory[] | null>(null);
  const [cancellationRequests, setCancellationRequests] = useState<CancellationRequest[]>([]);
  const [cancellationReason, setCancellationReason] = useState('');
  const [complimentaryApprovals, setComplimentaryApprovals] = useState<ComplimentaryApproval[]>([]);
  const [complimentaryAssignments, setComplimentaryAssignments] = useState<IssuedAuthorityAssignment[]>([]);
  const [complimentaryAssigneeId, setComplimentaryAssigneeId] = useState('');
  const [complimentaryAssignmentReason, setComplimentaryAssignmentReason] = useState('');
  const [approvalCategoryId, setApprovalCategoryId] = useState('');
  const [approvalQuota, setApprovalQuota] = useState('');
  const [approvalValidFrom, setApprovalValidFrom] = useState('');
  const [approvalExpiresAt, setApprovalExpiresAt] = useState('');
  const [invitationCategoryId, setInvitationCategoryId] = useState('');
  const [invitationRecipientId, setInvitationRecipientId] = useState('');
  const [invitationExpiry, setInvitationExpiry] = useState('');
  const [invitationUses, setInvitationUses] = useState('1');
  const [issuedInvitation, setIssuedInvitation] = useState<string | null>(null);
  const [issuedInvitationId, setIssuedInvitationId] = useState<string | null>(null);
  const invitationIdempotencyKey = useRef<string | null>(null);
  const invitationRevocationIdempotencyKey = useRef<string | null>(null);

  const showError = (error: unknown) => {
    const candidate = error as { response?: { data?: { message?: string } }; message?: string };
    setMessage(candidate.response?.data?.message ?? candidate.message ?? 'Request failed. Check your event assignment and permission.');
  };

  const acceptDraft = (value: EventDraft) => {
    setDraft(value);
    setEventId(value.id);
    setOrganisationId(value.organisation_id);
    const { id: _id, organisation_id: _organisationId, version: _version, status: _status, sales_state: _salesState, published_snapshot: _snapshot, ...editable } = value;
    setEditor(JSON.stringify(editable, null, 2));
    setMessage(`Loaded ${value.title} · version ${value.version} · ${value.status}`);
  };

  const createDraft = async () => {
    if (!authenticatedClient) return;
    setBusy(true); setMessage('');
    try {
      const fields = JSON.parse(newDraftJson) as Record<string, unknown>;
      const response = await authenticatedClient.post<EventDraft>(`/v1/events/organisations/${organisationId}/drafts`, {
        ...fields,
      });
      acceptDraft(response.data);
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const loadDraft = async () => {
    if (!authenticatedClient) return;
    setBusy(true); setMessage('');
    try {
      const response = await authenticatedClient.get<EventDraft>(`/v1/events/${eventId}`);
      acceptDraft(response.data);
      const requests = await authenticatedClient.get<CancellationRequest[]>(`/v1/events/${eventId}/cancellation-requests`);
      setCancellationRequests(requests.data);
      const approvals = await authenticatedClient.get<ComplimentaryApproval[]>(`/v1/events/${eventId}/complimentary-approvals`);
      setComplimentaryApprovals(approvals.data);
      if (user && hasPermission('events.assignment.revoke')) {
        const assignments = await authenticatedClient.get<IssuedAuthorityAssignment[]>(
          `/v1/events/organisations/${response.data.organisation_id}/assignments/issued-by/${user.id}?authority_type=organisation`,
        );
        setComplimentaryAssignments(assignments.data.filter(item => item.permission_slug === 'events.complimentary.approve'));
      }
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const loadCancellationRequests = async (targetEventId: string) => {
    if (!authenticatedClient) return;
    try {
      const response = await authenticatedClient.get<CancellationRequest[]>(`/v1/events/${targetEventId}/cancellation-requests`);
      setCancellationRequests(response.data);
    } catch (error) { showError(error); }
  };

  const loadComplimentaryApprovals = async (targetEventId: string) => {
    if (!authenticatedClient) return;
    try {
      const response = await authenticatedClient.get<ComplimentaryApproval[]>(`/v1/events/${targetEventId}/complimentary-approvals`);
      setComplimentaryApprovals(response.data);
    } catch (error) { showError(error); }
  };

  const grantComplimentaryApprover = async () => {
    if (!authenticatedClient || !organisationId) return;
    setBusy(true); setMessage('');
    try {
      const response = await authenticatedClient.post<IssuedAuthorityAssignment>(`/v1/events/organisations/${organisationId}/assignments`, {
        target_actor_id: complimentaryAssigneeId,
        permission_slug: 'events.complimentary.approve',
        reason_code: complimentaryAssignmentReason,
      }, { idempotencyKey: crypto.randomUUID() });
      setComplimentaryAssignments(items => [...items.filter(item => item.id !== response.data.id), response.data]);
      setMessage('Organisation-scoped approval assignment granted by the authorized assignment service.');
      setComplimentaryAssigneeId(''); setComplimentaryAssignmentReason('');
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const revokeComplimentaryApprover = async (assignmentId: string) => {
    if (!authenticatedClient) return;
    setBusy(true); setMessage('');
    try {
      await authenticatedClient.delete(`/v1/events/assignments/${assignmentId}`, {
        body: { reason_code: 'complimentary_authority_revoked' }, idempotencyKey: crypto.randomUUID(),
      });
      setComplimentaryAssignments(items => items.map(item => item.id === assignmentId ? { ...item, revoked_at: new Date().toISOString() } : item));
      setMessage('Approver assignment revoked. Previously committed registrations and tickets are preserved.');
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const requestComplimentaryApproval = async () => {
    if (!authenticatedClient || !draft) return;
    setBusy(true); setMessage('');
    try {
      await authenticatedClient.post(`/v1/events/${draft.id}/complimentary-approval-requests`, {
        category_id: approvalCategoryId, quota: Number(approvalQuota), valid_from: new Date(approvalValidFrom).toISOString(),
        expires_at: new Date(approvalExpiresAt).toISOString(), expected_version: draft.version,
      }, { idempotencyKey: crypto.randomUUID() });
      setMessage('Complimentary approval request recorded. A different actor with the organisation-scoped approval assignment must approve it.');
      await loadComplimentaryApprovals(draft.id);
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const actOnComplimentaryApproval = async (approval: ComplimentaryApproval, action: 'approve' | 'revoke') => {
    if (!authenticatedClient || !draft) return;
    const reasonCode = action === 'revoke' ? window.prompt('Revocation reason code') : null;
    if (action === 'revoke' && (reasonCode === null || reasonCode.trim() === '')) return;
    setBusy(true); setMessage('');
    try {
      const url = `/v1/events/${draft.id}/complimentary-approvals/${approval.approval_id}`;
      if (action === 'approve') {
        await authenticatedClient.post(`${url}/approve`, { expected_version: draft.version }, { idempotencyKey: crypto.randomUUID() });
      } else {
        await authenticatedClient.delete(url, { body: { reason_code: reasonCode }, idempotencyKey: crypto.randomUUID() });
      }
      setMessage(action === 'approve' ? 'Complimentary approval recorded.' : 'Complimentary approval revoked. Existing registrations remain valid.');
      await loadComplimentaryApprovals(draft.id);
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const requestCancellation = async () => {
    if (!authenticatedClient || !draft) return;
    setBusy(true); setMessage('');
    try {
      await authenticatedClient.post(`/v1/events/${draft.id}/cancellation-requests`, {
        expected_version: draft.version, reason: cancellationReason,
      }, { idempotencyKey: crypto.randomUUID() });
      setCancellationReason('');
      setMessage('Cancellation request recorded. The event remains active until a different authorised reviewer approves it.');
      await loadCancellationRequests(draft.id);
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const reviewCancellation = async (request: CancellationRequest, approve: boolean) => {
    if (!authenticatedClient || !draft) return;
    let reason: string | undefined;
    if (!approve) {
      const response = window.prompt('Reason for rejecting cancellation');
      if (response === null || response.trim() === '') return;
      reason = response;
    }
    setBusy(true); setMessage('');
    try {
      await authenticatedClient.post(`/v1/events/${draft.id}/cancellation-requests/${request.id}/${approve ? 'approve' : 'reject'}`, {
        expected_version: draft.version, ...(reason === undefined ? {} : { reason }),
      }, { idempotencyKey: crypto.randomUUID() });
      setMessage(approve ? 'Cancellation is effective. Financial recovery remains separately pending where paid orders exist.' : 'Cancellation request rejected.');
      await loadDraft();
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const saveDraft = async () => {
    if (!authenticatedClient || !draft) return;
    setBusy(true); setMessage('');
    try {
      const fields = JSON.parse(editor) as Record<string, unknown>;
      const response = await authenticatedClient.put<EventDraft>(`/v1/events/${draft.id}`, { ...fields, expected_version: draft.version });
      acceptDraft(response.data);
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const transition = async (action: string, extra: Record<string, string> = {}) => {
    if (!authenticatedClient || !draft) return;
    setBusy(true); setMessage('');
    try {
      const response = await authenticatedClient.post<EventDraft>(`/v1/events/${draft.id}/${action}`, { expected_version: draft.version, ...extra });
      acceptDraft(response.data);
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const loadAttendance = async () => {
    if (!authenticatedClient || !draft) return;
    setBusy(true); setMessage('');
    try {
      const response = await authenticatedClient.get<AttendanceCategory[]>(`/v1/events/${draft.id}/attendance`);
      setAttendance(response.data);
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const createInvitation = async () => {
    if (!authenticatedClient || !draft) return;
    setBusy(true); setMessage('');
    try {
      const response = await authenticatedClient.post<{ invitation_id: string; token: string }>(
        `/v1/events/${draft.id}/invitations`, {
          category_id: invitationCategoryId,
          recipient_actor_id: invitationRecipientId,
          expires_at: invitationExpiry,
          max_uses: Number(invitationUses),
        }, { idempotencyKey: invitationIdempotencyKey.current ??= crypto.randomUUID() },
      );
      invitationIdempotencyKey.current = null;
      setIssuedInvitationId(response.data.invitation_id);
      setIssuedInvitation(response.data.token);
      setMessage(`Invitation ${response.data.invitation_id} created. Copy the token securely; it is sensitive access material.`);
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const revokeInvitation = async () => {
    if (!authenticatedClient || issuedInvitationId === null) return;
    setBusy(true); setMessage('');
    try {
      await authenticatedClient.delete(`/v1/events/invitations/${issuedInvitationId}`, {
        idempotencyKey: invitationRevocationIdempotencyKey.current ??= crypto.randomUUID(),
      });
      invitationRevocationIdempotencyKey.current = null;
      setIssuedInvitation(null);
      setIssuedInvitationId(null);
      setMessage('Invitation revoked. Previously issued tickets remain valid.');
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  return (
    <PageContainer>
      <PageHeader title="Events · authoring and review" />
      <p role="status" className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
        Draft and review tools only. Publication, ticket sales, checkout, admission and payout are unavailable.
      </p>
      <Card className="mb-4 p-4">
        <h2 className="mb-3 text-lg font-semibold">Open or create a draft</h2>
        <div className="flex flex-wrap gap-3">
          <label className="flex min-w-72 flex-1 flex-col gap-1 text-sm">Organisation ID
            <input value={organisationId} onChange={(event) => setOrganisationId(event.target.value)} className="rounded border border-border px-3 py-2" />
          </label>
          <label className="flex min-w-full flex-col gap-1 text-sm">New draft data (JSON; include `event_format`, physical venue `venue_country_code`, approved policy text and category details)
            <textarea aria-label="New event draft data" value={newDraftJson} onChange={(event) => setNewDraftJson(event.target.value)} rows={8} className="w-full rounded border border-border px-3 py-2 font-mono text-xs" />
          </label>
          <Button onClick={createDraft} disabled={busy || !organisationId || !newDraftJson || !hasPermission('events.event.create')}>Create draft</Button>
          <label className="flex min-w-72 flex-1 flex-col gap-1 text-sm">Event ID
            <input value={eventId} onChange={(event) => setEventId(event.target.value)} className="rounded border border-border px-3 py-2" />
          </label>
          <Button variant="secondary" onClick={loadDraft} disabled={busy || !eventId || !hasPermission('events.event.read')}>Load draft</Button>
        </div>
      </Card>
      {draft && <Card className="p-4">
        <h2 className="mb-2 text-lg font-semibold">{draft.title} · version {draft.version}</h2>
        <p className="mb-3 text-sm">State: {draft.status} · lifecycle: {draft.lifecycle_state ?? 'active'} · sales: {draft.sales_state}</p>
        <label className="flex flex-col gap-1 text-sm">Editable draft fields (JSON; country changes invalidate review and are locked after the first hold/order)
          <textarea aria-label="Editable event draft fields" value={editor} onChange={(event) => setEditor(event.target.value)} rows={20} className="w-full rounded border border-border px-3 py-2 font-mono text-xs" />
        </label>
        <div className="mt-3 flex flex-wrap gap-2">
          <Button onClick={saveDraft} disabled={busy || !hasPermission('events.event.update')}>Save new version</Button>
          <Button variant="secondary" onClick={() => transition('submit-review')} disabled={busy || !hasPermission('events.event.submit')}>Submit for review</Button>
          <Button variant="secondary" onClick={() => transition('approve-prices')} disabled={busy || !hasPermission('events.price.approve')}>Finance: approve prices</Button>
          <Button variant="secondary" onClick={() => transition('approve-review')} disabled={busy || !hasPermission('events.event.review')}>Approve event review</Button>
          <Button variant="secondary" onClick={() => {
            const reason = window.prompt('Reason for rejection');
            if (reason !== null && reason.trim() !== '') void transition('reject', { reason });
          }} disabled={busy || !hasPermission('events.event.review')}>Reject</Button>
          {hasPermission('events.attendance.read') && <Button variant="secondary" onClick={loadAttendance} disabled={busy}>Load aggregate attendance</Button>}
        </div>
        <section className="mt-6 border-t border-border pt-4" aria-label="Event cancellation">
          <h3 className="mb-2 font-semibold">Event cancellation</h3>
          <p className="mb-3 text-sm text-muted-foreground">A request does not pause registration or admission. Effectiveness requires a different authorised event reviewer. Paid financial recovery remains separate and unresolved where orders exist.</p>
          {draft.lifecycle_state !== 'cancelled' && <div className="flex flex-wrap items-end gap-3">
            <label className="flex min-w-72 flex-1 flex-col gap-1 text-sm">Reason
              <textarea value={cancellationReason} onChange={event => setCancellationReason(event.target.value)} maxLength={1000} rows={2} className="rounded border border-border px-3 py-2" />
            </label>
            <Button variant="secondary" onClick={requestCancellation} disabled={busy || !cancellationReason.trim() || !hasPermission('events.event.cancel.request')}>Request cancellation</Button>
          </div>}
          {cancellationRequests.length > 0 && <ul className="mt-3 space-y-3">{cancellationRequests.map(request => <li key={request.id} className="rounded border border-border p-3">
            <p className="text-sm">{request.status} · {request.reason}</p>
            {request.status === 'pending' && draft.lifecycle_state !== 'cancelled' && <div className="mt-2 flex gap-2">
              <Button variant="secondary" onClick={() => void reviewCancellation(request, true)} disabled={busy || !hasPermission('events.event.cancel.approve')}>Approve (different reviewer)</Button>
              <Button variant="secondary" onClick={() => void reviewCancellation(request, false)} disabled={busy || !hasPermission('events.event.cancel.approve')}>Reject</Button>
            </div>}
            {request.review_reason !== null && request.review_reason !== '' && <p className="mt-1 text-sm">Review: {request.review_reason}</p>}
          </li>)}</ul>}
        </section>
        <section className="mt-6 border-t border-border pt-4" aria-label="Complimentary allocation approval">
          <h3 className="mb-2 font-semibold">Complimentary allocation approval</h3>
          {hasPermission('events.assignment.grant') && <div className="mb-4 rounded border border-border p-3">
            <h4 className="mb-2 font-medium">Approver assignments</h4>
            <p className="mb-3 text-sm text-muted-foreground">Assignments are organisation-scoped and server-authorized. No global role or membership automatically grants this authority.</p>
            <div className="grid gap-3 md:grid-cols-2">
              <label className="flex flex-col gap-1 text-sm">Approver actor ID<input value={complimentaryAssigneeId} onChange={event => setComplimentaryAssigneeId(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
              <label className="flex flex-col gap-1 text-sm">Assignment reason<input value={complimentaryAssignmentReason} onChange={event => setComplimentaryAssignmentReason(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
            </div>
            <Button className="mt-3" variant="secondary" onClick={grantComplimentaryApprover} disabled={busy || !complimentaryAssigneeId.trim() || !complimentaryAssignmentReason.trim()}>Grant organisation approver</Button>
            {complimentaryAssignments.length > 0 && <ul className="mt-3 space-y-2">{complimentaryAssignments.map(assignment => <li key={assignment.id} className="flex items-center justify-between gap-2 rounded border border-border p-2 text-sm">
              <span>{assignment.actor_id} · {assignment.revoked_at !== null ? 'revoked' : 'active'}</span>
              {assignment.revoked_at === null && hasPermission('events.assignment.revoke') && <Button variant="secondary" onClick={() => void revokeComplimentaryApprover(assignment.id)} disabled={busy}>Revoke</Button>}
            </li>)}</ul>}
          </div>}
          <p className="mb-3 text-sm text-muted-foreground">Requests are scoped to this event and category. Approval requires a separately assigned organisation approver; requester and approver must differ. No authority is granted automatically.</p>
          <div className="grid gap-3 md:grid-cols-2">
            <label className="flex flex-col gap-1 text-sm">Complimentary category ID<input value={approvalCategoryId} onChange={event => setApprovalCategoryId(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
            <label className="flex flex-col gap-1 text-sm">Approved quota<input type="number" min={1} value={approvalQuota} onChange={event => setApprovalQuota(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
            <label className="flex flex-col gap-1 text-sm">Valid from<input type="datetime-local" value={approvalValidFrom} onChange={event => setApprovalValidFrom(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
            <label className="flex flex-col gap-1 text-sm">Expires at<input type="datetime-local" value={approvalExpiresAt} onChange={event => setApprovalExpiresAt(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
          </div>
          <Button className="mt-3" variant="secondary" onClick={requestComplimentaryApproval} disabled={busy || !approvalCategoryId || !approvalQuota || !approvalValidFrom || !approvalExpiresAt || !hasPermission('events.event.update')}>Request complimentary approval</Button>
          {complimentaryApprovals.length > 0 && <ul className="mt-3 space-y-2">{complimentaryApprovals.map(approval => <li key={approval.approval_id} className="rounded border border-border p-3 text-sm">
            <p>Category {approval.category_id} · quota {approval.approved_quota} · {approval.status} · version {approval.version}</p>
            <p>Requester {approval.requested_by_actor_id}{approval.approved_by_actor_id !== null ? ` · approver ${approval.approved_by_actor_id}` : ''}</p>
            {approval.status === 'requested' && <Button className="mt-2" variant="secondary" onClick={() => void actOnComplimentaryApproval(approval, 'approve')} disabled={busy || !hasPermission('events.complimentary.approve')}>Approve (different assigned actor)</Button>}
            {approval.status === 'approved' && <Button className="mt-2" variant="secondary" onClick={() => void actOnComplimentaryApproval(approval, 'revoke')} disabled={busy || !hasPermission('events.complimentary.approve')}>Revoke approval</Button>}
          </li>)}</ul>}
        </section>
        {attendance !== null && <section aria-label="Aggregate attendance" className="mt-5">
          <h3 className="mb-2 font-semibold">Aggregate event outcomes by ticket category</h3>
          {attendance.length === 0 ? <p className="text-sm">No admissions recorded.</p> : <table className="w-full text-left text-sm">
            <thead><tr><th scope="col">Category</th><th scope="col">Free registrations</th><th scope="col">Paid sales</th><th scope="col">Unique admissions</th><th scope="col">No-shows</th></tr></thead>
            <tbody>{attendance.map((row) => <tr key={row.category}><td>{row.category}</td><td>{row.free_registrations}</td><td>{row.paid_sales}</td><td>{row.unique_admissions ?? row.admitted_count}</td><td>{row.no_shows_finalized ? row.no_shows : 'Event not ended'}</td></tr>)}</tbody>
          </table>}
          <p className="mt-2 text-xs text-muted-foreground">Aggregate counts only. No attendee names, ticket identifiers or scan records are available here.</p>
        </section>}
        <section className="mt-6 border-t border-border pt-4" aria-label="Complimentary invitation management">
          <h3 className="mb-2 font-semibold">Issue a free or complimentary invitation</h3>
          <p className="mb-3 text-sm text-muted-foreground">Development and event-scoped controls only. Complimentary approval authority and production publication remain pending. The token is shown once in this response and must be shared securely.</p>
          <div className="grid gap-3 md:grid-cols-2">
            <label className="flex flex-col gap-1 text-sm">Free/complimentary category ID
              <input value={invitationCategoryId} onChange={(event) => { invitationIdempotencyKey.current = null; setInvitationCategoryId(event.target.value); }} className="rounded border border-border px-3 py-2" />
            </label>
            <label className="flex flex-col gap-1 text-sm">Recipient customer actor ID
              <input value={invitationRecipientId} onChange={(event) => { invitationIdempotencyKey.current = null; setInvitationRecipientId(event.target.value); }} className="rounded border border-border px-3 py-2" />
            </label>
            <label className="flex flex-col gap-1 text-sm">Expiry
              <input type="datetime-local" value={invitationExpiry} onChange={(event) => { invitationIdempotencyKey.current = null; setInvitationExpiry(event.target.value); }} className="rounded border border-border px-3 py-2" />
            </label>
            <label className="flex flex-col gap-1 text-sm">Maximum uses
              <input type="number" min={1} max={10000} value={invitationUses} onChange={(event) => { invitationIdempotencyKey.current = null; setInvitationUses(event.target.value); }} className="rounded border border-border px-3 py-2" />
            </label>
          </div>
          <Button className="mt-3" onClick={createInvitation} disabled={busy || !invitationCategoryId || !invitationRecipientId || !invitationExpiry || !hasPermission('events.event.update')}>Create invitation</Button>
          {issuedInvitation !== null && <label className="mt-3 flex flex-col gap-1 text-sm">Invitation token — handle as a secret
            <textarea readOnly aria-label="New invitation token" value={issuedInvitation} rows={2} className="rounded border border-border px-3 py-2 font-mono" />
          </label>}
          {issuedInvitationId !== null && <Button variant="secondary" onClick={revokeInvitation} disabled={busy || !hasPermission('events.event.update')}>Revoke this invitation</Button>}
        </section>
      </Card>}
      {message && <p role="status" className="mt-4 text-sm">{message}</p>}
    </PageContainer>
  );
}
