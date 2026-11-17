'use client';

import { FormEvent, useEffect, useRef, useState } from 'react';
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
  event_format: 'physical' | 'online' | 'hybrid';
  venue_country_code: string | null;
  organiser_name: string;
  sponsors: string[];
  capacity: number;
  timezone: string;
  starts_at: string;
  ends_at: string;
  sales_start_at: string;
  sales_end_at: string;
  max_tickets_per_order: number;
  cancellation_policy: string;
  transfer_policy: string;
  entry_policy: string;
  categories: EventCategory[];
  [key: string]: unknown;
};

type EventCategory = { id?: string; name: string; capacity: number; price_minor: number; allocation_type: 'paid' | 'free' | 'complimentary'; complimentary_quota?: number | null; max_per_person?: number | null };
type OrganisationOption = { id: string; display_name: string; registry_reference: string; status: string };
type ActorOption = { id: string; display_name: string; email: string | null; mobile_number: string | null };
type EventForm = {
  title: string; event_format: 'physical' | 'online' | 'hybrid'; venue_country_code: string;
  organiser_name: string; sponsors: string; capacity: string; timezone: string; starts_at: string;
  ends_at: string; sales_start_at: string; sales_end_at: string; max_tickets_per_order: string;
  cancellation_policy: string; transfer_policy: string; entry_policy: string; categories: Array<{
    name: string; capacity: string; price_minor: string; allocation_type: 'paid' | 'free' | 'complimentary';
    complimentary_quota: string; max_per_person: string;
  }>;
};

const emptyEventForm: EventForm = {
  title: '', event_format: 'physical', venue_country_code: '', organiser_name: '', sponsors: '', capacity: '',
  timezone: 'Africa/Gaborone', starts_at: '', ends_at: '', sales_start_at: '', sales_end_at: '',
  max_tickets_per_order: '', cancellation_policy: '', transfer_policy: '', entry_policy: '', categories: [],
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
  const [organisations, setOrganisations] = useState<OrganisationOption[]>([]);
  const [organisationMembers, setOrganisationMembers] = useState<ActorOption[]>([]);
  const [eventRows, setEventRows] = useState<EventDraft[]>([]);
  const [eventId, setEventId] = useState('');
  const [draft, setDraft] = useState<EventDraft | null>(null);
  const [eventForm, setEventForm] = useState<EventForm>(emptyEventForm);
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
  const [invitationCustomers, setInvitationCustomers] = useState<ActorOption[]>([]);
  const [invitationSearch, setInvitationSearch] = useState('');
  const [invitationExpiry, setInvitationExpiry] = useState('');
  const [invitationUses, setInvitationUses] = useState('1');
  const [issuedInvitation, setIssuedInvitation] = useState<string | null>(null);
  const [issuedInvitationId, setIssuedInvitationId] = useState<string | null>(null);
  const invitationIdempotencyKey = useRef<string | null>(null);
  const invitationRevocationIdempotencyKey = useRef<string | null>(null);
  const createDraftIdempotencyKey = useRef<string | null>(null);
  const saveDraftIdempotencyKey = useRef<string | null>(null);
  const transitionIdempotencyKeys = useRef<Record<string, string>>({});
  const updateEventForm = <K extends keyof EventForm>(field: K, value: EventForm[K]) => {
    createDraftIdempotencyKey.current = null;
    saveDraftIdempotencyKey.current = null;
    setEventForm(current => ({ ...current, [field]: value }));
  };
  const categoryCapacityTotal = eventForm.categories.reduce((total, category) => total + (Number(category.capacity) || 0), 0);
  const categoryCapacityExceeded = Number(eventForm.capacity) > 0 && categoryCapacityTotal > Number(eventForm.capacity);
  const scheduleErrors = getScheduleErrors(eventForm);

  useEffect(() => {
    if (!authenticatedClient) return;
    void authenticatedClient.get<OrganisationOption[]>('/v1/identity/organisations?stage=active')
      .then(response => setOrganisations(response.data))
      .catch(showError);
  }, [authenticatedClient]);

  useEffect(() => {
    if (!authenticatedClient || !organisationId) { setEventRows([]); setEventId(''); setOrganisationMembers([]); return; }
    void authenticatedClient.get<EventDraft[]>(`/v1/events/organisations/${organisationId}/drafts`)
      .then(response => setEventRows(response.data))
      .catch(showError);
    if (hasPermission('events.assignment.grant')) {
      void authenticatedClient.get<ActorOption[]>(`/v1/events/organisations/${organisationId}/members`)
        .then(response => setOrganisationMembers(response.data))
        .catch(() => setOrganisationMembers([]));
    } else setOrganisationMembers([]);
  }, [authenticatedClient, organisationId, hasPermission]);

  const showError = (error: unknown) => {
    const candidate = error as {
      response?: { data?: { message?: string; error?: { message?: string; details?: Array<{ message?: string }> } } };
      error?: { message?: string; details?: Array<{ message?: string }> };
      message?: string;
    };
    const apiError = candidate.response?.data?.error ?? candidate.error;
    setMessage(apiError?.details?.map(detail => detail.message).filter(Boolean).join(' ') || apiError?.message || candidate.response?.data?.message || candidate.message || 'Request failed. Check your event assignment and permission.');
  };

  const acceptDraft = (value: EventDraft) => {
    setDraft(value);
    setEventId(value.id);
    setOrganisationId(value.organisation_id);
    setEventForm({
      title: value.title, event_format: value.event_format ?? 'physical', venue_country_code: value.venue_country_code ?? '',
      organiser_name: value.organiser_name, sponsors: (value.sponsors ?? []).join(', '), capacity: String(value.capacity),
      timezone: value.timezone, starts_at: toLocalDateTime(value.starts_at, value.timezone), ends_at: toLocalDateTime(value.ends_at, value.timezone),
      sales_start_at: toLocalDateTime(value.sales_start_at, value.timezone), sales_end_at: toLocalDateTime(value.sales_end_at, value.timezone),
      max_tickets_per_order: String(value.max_tickets_per_order ?? ''), cancellation_policy: value.cancellation_policy,
      transfer_policy: value.transfer_policy, entry_policy: value.entry_policy,
      categories: (value.categories ?? []).map(category => ({ name: category.name, capacity: String(category.capacity), price_minor: String(category.price_minor), allocation_type: category.allocation_type ?? 'paid', complimentary_quota: category.complimentary_quota ? String(category.complimentary_quota) : '', max_per_person: category.max_per_person ? String(category.max_per_person) : '' })),
    });
    setMessage(`Loaded ${value.title} · version ${value.version} · ${value.status}`);
  };

  const createDraft = async () => {
    if (!authenticatedClient || !organisationId) return;
    if (categoryCapacityExceeded || scheduleErrors.length > 0) {
      setMessage(scheduleErrors.length > 0 ? scheduleErrors.join(' ') : `Ticket category capacities total ${categoryCapacityTotal}, exceeding the event capacity of ${eventForm.capacity}.`);
      return;
    }
    setBusy(true); setMessage('');
    try {
      const response = await authenticatedClient.post<EventDraft>(
        `/v1/events/organisations/${organisationId}/drafts`,
        buildEventPayload(eventForm),
        { idempotencyKey: createDraftIdempotencyKey.current ??= crypto.randomUUID() },
      );
      createDraftIdempotencyKey.current = null;
      acceptDraft(response.data);
      const rows = await authenticatedClient.get<EventDraft[]>(`/v1/events/organisations/${organisationId}/drafts`);
      setEventRows(rows.data);
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
      try {
        const customers = await authenticatedClient.get<ActorOption[]>(`/v1/events/${eventId}/invitation-customers`);
        setInvitationCustomers(customers.data);
      } catch { setInvitationCustomers([]); }
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
    if (categoryCapacityExceeded || scheduleErrors.length > 0) {
      setMessage(scheduleErrors.length > 0 ? scheduleErrors.join(' ') : `Ticket category capacities total ${categoryCapacityTotal}, exceeding the event capacity of ${eventForm.capacity}.`);
      return;
    }
    setBusy(true); setMessage('');
    try {
      const response = await authenticatedClient.put<EventDraft>(`/v1/events/${draft.id}`, { ...buildEventPayload(eventForm), expected_version: draft.version }, { idempotencyKey: saveDraftIdempotencyKey.current ??= crypto.randomUUID() });
      saveDraftIdempotencyKey.current = null;
      acceptDraft(response.data);
      if (organisationId) {
        const rows = await authenticatedClient.get<EventDraft[]>(`/v1/events/organisations/${organisationId}/drafts`);
        setEventRows(rows.data);
      }
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const transition = async (action: string, extra: Record<string, string> = {}) => {
    if (!authenticatedClient || !draft) return;
    setBusy(true); setMessage('');
    try {
      const response = await authenticatedClient.post<EventDraft>(
        `/v1/events/${draft.id}/${action}`,
        { expected_version: draft.version, ...extra },
        { idempotencyKey: transitionIdempotencyKeys.current[action] ??= crypto.randomUUID() },
      );
      delete transitionIdempotencyKeys.current[action];
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

  const searchInvitationCustomers = async () => {
    if (!authenticatedClient || !draft) return;
    setBusy(true);
    try {
      const params = new URLSearchParams();
      if (invitationSearch.trim()) params.set('search', invitationSearch.trim());
      const query = params.size ? `?${params.toString()}` : '';
      const response = await authenticatedClient.get<ActorOption[]>(`/v1/events/${draft.id}/invitation-customers${query}`);
      setInvitationCustomers(response.data);
      setInvitationRecipientId('');
    } catch (error) { showError(error); }
    finally { setBusy(false); }
  };

  const actorLabel = (actorId: string | null) => {
    if (!actorId) return 'Not assigned';
    const actor = organisationMembers.find(member => member.id === actorId);
    return actor ? `${actor.display_name}${actor.email ? ` · ${actor.email}` : actor.mobile_number ? ` · ${actor.mobile_number}` : ''}` : 'Organisation member';
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
        <h2 className="mb-3 text-lg font-semibold">Select an organisation or event</h2>
        <div className="grid gap-3 md:grid-cols-2">
          <label className="flex flex-col gap-1 text-sm">Organisation
            <select value={organisationId} onChange={event => {
              const selectedOrganisation = organisations.find(org => org.id === event.target.value);
              createDraftIdempotencyKey.current = null;
              setOrganisationId(event.target.value);
              setDraft(null);
              setEventForm({ ...emptyEventForm, organiser_name: selectedOrganisation?.display_name ?? '' });
            }} className="rounded border border-border px-3 py-2">
              <option value="">Choose an active organisation</option>
              {organisations.map(org => <option key={org.id} value={org.id}>{org.display_name} · {org.registry_reference}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-sm">Existing event
            <select value={eventId} onChange={event => setEventId(event.target.value)} disabled={!organisationId} className="rounded border border-border px-3 py-2">
              <option value="">Create a new event or choose an existing draft</option>
              {eventRows.map(row => <option key={row.id} value={row.id}>{row.title} · {row.status} · version {row.version}</option>)}
            </select>
          </label>
        </div>
        {eventId && <Button className="mt-3" variant="secondary" onClick={loadDraft} disabled={busy || !hasPermission('events.event.read')}>Load selected event</Button>}
        {organisations.length === 0 && <p className="mt-3 text-sm text-text-secondary">No active organisations are available to this account. Complete organisation approval and membership setup first, or refresh after signing in with an authorised account.</p>}
      </Card>
      {organisationId && !draft && hasPermission('events.event.create') && <Card className="mb-4 p-4">
        <h2 className="mb-1 text-lg font-semibold">Create event draft</h2>
        <p className="mb-4 text-sm text-text-secondary">Enter event details and ticket categories below. Finance assigns currency separately.</p>
        <form onSubmit={event => { event.preventDefault(); void createDraft(); }}>
          <EventDraftFields value={eventForm} onChange={updateEventForm} />
          <Button type="submit" className="mt-4" disabled={busy || eventForm.categories.length === 0 || categoryCapacityExceeded || scheduleErrors.length > 0}>Create draft</Button>
        </form>
      </Card>}
      {draft && <Card className="p-4">
        <h2 className="mb-2 text-lg font-semibold">{draft.title} · version {draft.version}</h2>
        <p className="mb-3 text-sm">State: {draft.status} · lifecycle: {draft.lifecycle_state ?? 'active'} · sales: {draft.sales_state}</p>
        <form onSubmit={event => { event.preventDefault(); void saveDraft(); }}>
          <EventDraftFields value={eventForm} onChange={updateEventForm} />
          <Button type="submit" className="mt-4" disabled={busy || !hasPermission('events.event.update') || categoryCapacityExceeded || scheduleErrors.length > 0}>Save new version</Button>
        </form>
        <div className="mt-3 flex flex-wrap gap-2">
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
          <label className="flex flex-col gap-1 text-sm">Approver<select value={complimentaryAssigneeId} onChange={event => setComplimentaryAssigneeId(event.target.value)} className="rounded border border-border px-3 py-2"><option value="">Choose an organisation member</option>{organisationMembers.map(member => <option key={member.id} value={member.id}>{member.display_name}{member.email ? ` · ${member.email}` : member.mobile_number ? ` · ${member.mobile_number}` : ''}</option>)}</select></label>
              <label className="flex flex-col gap-1 text-sm">Assignment reason<input value={complimentaryAssignmentReason} onChange={event => setComplimentaryAssignmentReason(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
            </div>
            <Button className="mt-3" variant="secondary" onClick={grantComplimentaryApprover} disabled={busy || !complimentaryAssigneeId.trim() || !complimentaryAssignmentReason.trim()}>Grant organisation approver</Button>
            {complimentaryAssignments.length > 0 && <ul className="mt-3 space-y-2">{complimentaryAssignments.map(assignment => <li key={assignment.id} className="flex items-center justify-between gap-2 rounded border border-border p-2 text-sm">
              <span>{actorLabel(assignment.actor_id)} · {assignment.revoked_at !== null ? 'revoked' : 'active'}</span>
              {assignment.revoked_at === null && hasPermission('events.assignment.revoke') && <Button variant="secondary" onClick={() => void revokeComplimentaryApprover(assignment.id)} disabled={busy}>Revoke</Button>}
            </li>)}</ul>}
          </div>}
          <p className="mb-3 text-sm text-muted-foreground">Requests are scoped to this event and category. Approval requires a separately assigned organisation approver; requester and approver must differ. No authority is granted automatically.</p>
          <div className="grid gap-3 md:grid-cols-2">
            <label className="flex flex-col gap-1 text-sm">Ticket category<select value={approvalCategoryId} onChange={event => setApprovalCategoryId(event.target.value)} className="rounded border border-border px-3 py-2"><option value="">Choose a category</option>{draft.categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
            <label className="flex flex-col gap-1 text-sm">Approved quota<input type="number" min={1} value={approvalQuota} onChange={event => setApprovalQuota(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
            <label className="flex flex-col gap-1 text-sm">Valid from<input type="datetime-local" value={approvalValidFrom} onChange={event => setApprovalValidFrom(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
            <label className="flex flex-col gap-1 text-sm">Expires at<input type="datetime-local" value={approvalExpiresAt} onChange={event => setApprovalExpiresAt(event.target.value)} className="rounded border border-border px-3 py-2" /></label>
          </div>
          <Button className="mt-3" variant="secondary" onClick={requestComplimentaryApproval} disabled={busy || !approvalCategoryId || !approvalQuota || !approvalValidFrom || !approvalExpiresAt || !hasPermission('events.event.update')}>Request complimentary approval</Button>
          {complimentaryApprovals.length > 0 && <ul className="mt-3 space-y-2">{complimentaryApprovals.map(approval => <li key={approval.approval_id} className="rounded border border-border p-3 text-sm">
            <p>Category {approval.category_id} · quota {approval.approved_quota} · {approval.status} · version {approval.version}</p>
            <p>Requester {actorLabel(approval.requested_by_actor_id)}{approval.approved_by_actor_id !== null ? ` · approver ${actorLabel(approval.approved_by_actor_id)}` : ''}</p>
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
            <label className="flex flex-col gap-1 text-sm">Free/complimentary ticket category
              <select value={invitationCategoryId} onChange={(event) => { invitationIdempotencyKey.current = null; setInvitationCategoryId(event.target.value); }} className="rounded border border-border px-3 py-2"><option value="">Choose a category</option>{draft.categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}</select>
            </label>
            <label className="flex flex-col gap-1 text-sm">Recipient customer
              <select value={invitationRecipientId} onChange={(event) => { invitationIdempotencyKey.current = null; setInvitationRecipientId(event.target.value); }} className="rounded border border-border px-3 py-2"><option value="">Choose a customer</option>{invitationCustomers.map(customer => <option key={customer.id} value={customer.id}>{customer.display_name}{customer.email ? ` · ${customer.email}` : customer.mobile_number ? ` · ${customer.mobile_number}` : ''}</option>)}</select>
            </label>
            <label className="flex flex-col gap-1 text-sm">Expiry
              <input type="datetime-local" value={invitationExpiry} onChange={(event) => { invitationIdempotencyKey.current = null; setInvitationExpiry(event.target.value); }} className="rounded border border-border px-3 py-2" />
            </label>
            <label className="flex flex-col gap-1 text-sm">Maximum uses
              <input type="number" min={1} max={10000} value={invitationUses} onChange={(event) => { invitationIdempotencyKey.current = null; setInvitationUses(event.target.value); }} className="rounded border border-border px-3 py-2" />
            </label>
          </div>
          <div className="mt-3 flex flex-wrap items-end gap-2"><label className="flex min-w-72 flex-1 flex-col gap-1 text-sm">Find customer by email or mobile number<input value={invitationSearch} onChange={event => setInvitationSearch(event.target.value)} className="rounded border border-border px-3 py-2" /></label><Button type="button" variant="secondary" onClick={() => void searchInvitationCustomers()} disabled={busy}>Search customers</Button></div>
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

function buildEventPayload(form: EventForm): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    title: form.title.trim(), event_format: form.event_format, organiser_name: form.organiser_name.trim(),
    sponsors: form.sponsors.split(',').map(name => name.trim()).filter(Boolean), capacity: Number(form.capacity),
    timezone: form.timezone.trim(), starts_at: toZonedIso(form.starts_at, form.timezone), ends_at: toZonedIso(form.ends_at, form.timezone),
    sales_start_at: toZonedIso(form.sales_start_at, form.timezone), sales_end_at: toZonedIso(form.sales_end_at, form.timezone),
    max_tickets_per_order: Number(form.max_tickets_per_order), cancellation_policy: form.cancellation_policy.trim(),
    transfer_policy: form.transfer_policy.trim(), entry_policy: form.entry_policy.trim(),
    categories: form.categories.map(category => ({
      name: category.name.trim(), capacity: Number(category.capacity), allocation_type: category.allocation_type,
      price_minor: category.allocation_type === 'paid' ? Number(category.price_minor) : 0,
      ...(category.allocation_type === 'complimentary' ? { complimentary_quota: Number(category.complimentary_quota) } : {}),
      ...(category.allocation_type !== 'paid' ? { max_per_person: Number(category.max_per_person) } : {}),
    })),
  };
  if (form.event_format === 'physical') payload['venue_country_code'] = form.venue_country_code.trim().toUpperCase();
  return payload;
}

function toZonedIso(value: string, timezone: string): string {
  if (!value) return '';
  const [date, time = ''] = value.split('T');
  const [year, month, day] = (date ?? '').split('-').map(Number);
  const [hour, minute] = time.split(':').map(Number);
  const wall = `${date}T${time.length === 5 ? `${time}:00` : time}`;
  const utcGuess = new Date(Date.UTC(year ?? 0, (month ?? 1) - 1, day ?? 1, hour ?? 0, minute ?? 0));
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(utcGuess);
  const part = (type: string) => Number(parts.find(item => item.type === type)?.value ?? 0);
  const observedUtc = Date.UTC(part('year'), part('month') - 1, part('day'), part('hour'), part('minute'), part('second'));
  const offset = Math.round((observedUtc - utcGuess.getTime()) / 60000);
  const sign = offset >= 0 ? '+' : '-';
  const abs = Math.abs(offset);
  return `${wall}${sign}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`;
}

function toLocalDateTime(value: string, timezone: string): string {
  if (!value) return '';
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(value));
  const part = (type: string) => parts.find(item => item.type === type)?.value ?? '';
  return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

function getScheduleErrors(value: EventForm): string[] {
  const errors: string[] = [];
  if (value.starts_at && value.timezone) {
    try {
      if (Date.parse(toZonedIso(value.starts_at, value.timezone)) <= Date.now()) {
        errors.push('Event start time must be in the future. Events cannot be created for past dates or times.');
      }
    } catch {
      // The API reports invalid IANA time zones; avoid hiding the other schedule errors here.
    }
  }
  if (value.starts_at && value.ends_at && value.starts_at >= value.ends_at) {
    errors.push('Event start time must be before event end time.');
  }
  if (value.sales_start_at && value.sales_end_at && value.sales_start_at >= value.sales_end_at) {
    errors.push('Ticket sales start time must be before ticket sales end time.');
  }
  if (value.sales_end_at && value.starts_at && value.sales_end_at >= value.starts_at) {
    errors.push('Ticket sales end time must be before event start time.');
  }
  return errors;
}

function EventDraftFields({ value, onChange }: { value: EventForm; onChange: <K extends keyof EventForm>(field: K, value: EventForm[K]) => void }) {
  const categoryCapacityTotal = value.categories.reduce((total, category) => total + (Number(category.capacity) || 0), 0);
  const capacity = Number(value.capacity) || 0;
  const categoryCapacityExceeded = capacity > 0 && categoryCapacityTotal > capacity;
  const scheduleErrors = getScheduleErrors(value);
  const setCategory = (index: number, patch: Partial<EventForm['categories'][number]>) =>
    onChange('categories', value.categories.map((item, current) => current === index ? { ...item, ...patch } : item));
  return <div className="grid gap-4">
    <div className="grid gap-3 md:grid-cols-2">
      <label className="flex flex-col gap-1 text-sm">Event name<input required maxLength={200} value={value.title} onChange={e => onChange('title', e.target.value)} className="rounded border border-border px-3 py-2" /></label>
      <label className="flex flex-col gap-1 text-sm">Organiser name<input required maxLength={200} value={value.organiser_name} onChange={e => onChange('organiser_name', e.target.value)} className="rounded border border-border px-3 py-2" /></label>
      <label className="flex flex-col gap-1 text-sm">Event format<select value={value.event_format} onChange={e => onChange('event_format', e.target.value as EventForm['event_format'])} className="rounded border border-border px-3 py-2"><option value="physical">Physical</option><option value="online">Online</option><option value="hybrid">Hybrid</option></select></label>
      {value.event_format === 'physical' && <label className="flex flex-col gap-1 text-sm">Venue country code<input required maxLength={2} placeholder="BW" value={value.venue_country_code} onChange={e => onChange('venue_country_code', e.target.value.toUpperCase())} className="rounded border border-border px-3 py-2" /></label>}
      <label className="flex flex-col gap-1 text-sm">Event capacity<input required type="number" min={1} value={value.capacity} onChange={e => onChange('capacity', e.target.value)} className="rounded border border-border px-3 py-2" /></label>
      <label className="flex flex-col gap-1 text-sm">Maximum tickets per order<input required type="number" min={1} value={value.max_tickets_per_order} onChange={e => onChange('max_tickets_per_order', e.target.value)} className="rounded border border-border px-3 py-2" /></label>
      <label className="flex flex-col gap-1 text-sm">Time zone (IANA)<input required placeholder="Africa/Gaborone" value={value.timezone} onChange={e => onChange('timezone', e.target.value)} className="rounded border border-border px-3 py-2" /></label>
      <label className="flex flex-col gap-1 text-sm">Sponsors, separated by commas<input value={value.sponsors} onChange={e => onChange('sponsors', e.target.value)} className="rounded border border-border px-3 py-2" /></label>
      {([['starts_at','Event starts'],['ends_at','Event ends'],['sales_start_at','Ticket sales start'],['sales_end_at','Ticket sales end']] as const).map(([key,label]) => <label key={key} className="flex flex-col gap-1 text-sm">{label}<input required type="datetime-local" value={value[key]} onChange={e => onChange(key, e.target.value)} className="rounded border border-border px-3 py-2" /></label>)}
      {scheduleErrors.length > 0 && <ul role="alert" className="space-y-1 text-sm text-error">{scheduleErrors.map(error => <li key={error}>{error}</li>)}</ul>}
    </div>
    <fieldset className="grid gap-3"><legend className="text-sm font-semibold">Ticket categories</legend>
      {value.categories.map((category,index) => <div key={index} className="grid gap-2 rounded border border-border p-3 md:grid-cols-5">
        <label className="flex flex-col gap-1 text-sm">Category name<input required value={category.name} onChange={e => setCategory(index,{name:e.target.value})} className="rounded border border-border px-3 py-2" /></label>
        <label className="flex flex-col gap-1 text-sm">Capacity<input required type="number" min={1} value={category.capacity} onChange={e => setCategory(index,{capacity:e.target.value})} className="rounded border border-border px-3 py-2" /></label>
        <label className="flex flex-col gap-1 text-sm">Allocation type<select value={category.allocation_type} onChange={e => { const allocation_type=e.target.value as EventForm['categories'][number]['allocation_type']; setCategory(index,{allocation_type,price_minor:allocation_type==='paid'?category.price_minor:'0'}); }} className="rounded border border-border px-3 py-2"><option value="paid">Paid</option><option value="free">Free registration</option><option value="complimentary">Complimentary</option></select></label>
        <label className="flex flex-col gap-1 text-sm">Price in minor units<input required type="number" min={0} disabled={category.allocation_type!=='paid'} value={category.price_minor} onChange={e => setCategory(index,{price_minor:e.target.value})} className="rounded border border-border px-3 py-2" /></label>
        {category.allocation_type === 'complimentary' && <label className="flex flex-col gap-1 text-sm">Complimentary quota<input required type="number" min={1} value={category.complimentary_quota} onChange={e => setCategory(index,{complimentary_quota:e.target.value})} className="rounded border border-border px-3 py-2" /></label>}
        {category.allocation_type !== 'paid' && <label className="flex flex-col gap-1 text-sm">Per-person limit<input required type="number" min={1} value={category.max_per_person} onChange={e => setCategory(index,{max_per_person:e.target.value})} className="rounded border border-border px-3 py-2" /></label>}
        <Button type="button" variant="secondary" onClick={() => onChange('categories',value.categories.filter((_,i)=>i!==index))}>Remove category</Button>
      </div>)}
      <p role={categoryCapacityExceeded ? 'alert' : 'status'} className={`text-sm ${categoryCapacityExceeded ? 'text-error' : 'text-text-secondary'}`}>
        Category capacity total: {categoryCapacityTotal} / {capacity || 'enter event capacity'} tickets.
        {categoryCapacityExceeded && ` Reduce category capacity by ${categoryCapacityTotal - capacity} to continue.`}
      </p>
      <Button type="button" variant="secondary" onClick={() => onChange('categories',[...value.categories,{name:'',capacity:'',price_minor:'',allocation_type:'paid',complimentary_quota:'',max_per_person:''}])}>Add ticket category</Button>
    </fieldset>
    <div className="grid gap-3 md:grid-cols-3">
      <label className="flex flex-col gap-1 text-sm">Entry policy<textarea required value={value.entry_policy} onChange={e=>onChange('entry_policy',e.target.value)} rows={3} className="rounded border border-border px-3 py-2" /></label>
      <label className="flex flex-col gap-1 text-sm">Cancellation policy<textarea required value={value.cancellation_policy} onChange={e=>onChange('cancellation_policy',e.target.value)} rows={3} className="rounded border border-border px-3 py-2" /></label>
      <label className="flex flex-col gap-1 text-sm">Transfer policy<textarea required value={value.transfer_policy} onChange={e=>onChange('transfer_policy',e.target.value)} rows={3} className="rounded border border-border px-3 py-2" /></label>
    </div>
  </div>;
}
