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
  [key: string]: unknown;
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
  const { authenticatedClient, hasPermission } = useAuth();
  const [organisationId, setOrganisationId] = useState('');
  const [eventId, setEventId] = useState('');
  const [newDraftJson, setNewDraftJson] = useState('');
  const [draft, setDraft] = useState<EventDraft | null>(null);
  const [editor, setEditor] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [attendance, setAttendance] = useState<AttendanceCategory[] | null>(null);
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
        <p className="mb-3 text-sm">State: {draft.status} · sales: {draft.sales_state}</p>
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
