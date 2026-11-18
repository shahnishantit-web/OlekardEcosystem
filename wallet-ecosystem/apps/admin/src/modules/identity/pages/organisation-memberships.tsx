'use client';

import { useCallback, useEffect, useMemo, useState, type ChangeEvent, type FormEvent, type KeyboardEvent } from 'react';
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
type DirectoryMember = Membership & {
  user_mobile_number: string | null; user_status: string; user_actor_type: string;
};
type Stage = 'mine' | 'review';

const EMAIL_PATTERN = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/;

export function OrganisationMembershipsPage() {
  const { authenticatedClient, hasPermission, user } = useAuth();
  const canRequest = hasPermission('organisation.registry.request');
  const canReview = hasPermission('organisation.registry.review');
  const canRead = hasPermission('organisation.registry.read');
  const [organisations, setOrganisations] = useState<Organisation[]>([]);
  const [organisationId, setOrganisationId] = useState('');
  const [emailInput, setEmailInput] = useState('');
  const [emailList, setEmailList] = useState<string[]>([]);
  const [evidenceReference, setEvidenceReference] = useState('');
  const [stage, setStage] = useState<Stage>(canReview ? 'review' : 'mine');
  const [memberships, setMemberships] = useState<Membership[]>([]);
  const [directoryMembers, setDirectoryMembers] = useState<Record<string, DirectoryMember[]>>({});
  const [openDirectoryId, setOpenDirectoryId] = useState<string | null>(null);
  const [directoryLoadingId, setDirectoryLoadingId] = useState<string | null>(null);
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const loadMemberships = useCallback(async (queueStage: Stage = stage) => {
    if (!authenticatedClient || (!canReview && !canRequest)) return;
    try {
      const response = await authenticatedClient.get<Membership[]>(`/v1/identity/organisations/memberships?stage=${queueStage}`);
      setMemberships(response.data);
      setSelectedIds([]);
    } catch (cause) { setError(readError(cause)); }
  }, [authenticatedClient, canRequest, canReview, stage]);

  useEffect(() => {
    if (!authenticatedClient || !canRead) return;
    void authenticatedClient.get<Organisation[]>('/v1/identity/organisations?stage=active')
      .then(response => setOrganisations(response.data))
      .catch(cause => setError(readError(cause)));
  }, [authenticatedClient, canRead]);
  useEffect(() => { void loadMemberships(); }, [loadMemberships]);

  const reviewableMemberships = useMemo(
    () => memberships.filter(item => item.status === 'pending_review' && item.requested_by !== user?.id && item.user_id !== user?.id),
    [memberships, user?.id],
  );
  const allReviewableSelected = reviewableMemberships.length > 0
    && reviewableMemberships.every(item => selectedIds.includes(item.id));
  const selectedCount = reviewableMemberships.filter(item => selectedIds.includes(item.id)).length;

  function parseEmailInput(raw: string): { emails: string[]; invalid: string[]; duplicates: string[] } {
    const emails: string[] = [];
    const invalid: string[] = [];
    const duplicates: string[] = [];
    const seen = new Set<string>();
    for (const part of raw.split(/[\s,;]+/)) {
      const email = part.trim().replace(/^['"]|['"]$/g, '').toLocaleLowerCase();
      if (!email) continue;
      if (!EMAIL_PATTERN.test(email)) { invalid.push(email); continue; }
      const key = email.toLocaleLowerCase();
      if (seen.has(key)) { duplicates.push(email); continue; }
      seen.add(key);
      emails.push(email);
    }
    return { emails, invalid, duplicates };
  }

  function addEmails(raw: string) {
    const parsed = parseEmailInput(raw);
    const existing = new Set(emailList.map(email => email.toLocaleLowerCase()));
    const additions = parsed.emails.filter(email => {
      const key = email.toLocaleLowerCase();
      if (existing.has(key)) return false;
      existing.add(key);
      return true;
    });
    const repeated = parsed.duplicates.length + parsed.emails.length - additions.length;
    setEmailList(current => [...current, ...additions]);
    setEmailInput('');
    setError(parsed.invalid.length ? `Invalid email address${parsed.invalid.length > 1 ? 'es' : ''}: ${parsed.invalid.join(', ')}` : '');
    setNotice(`${additions.length} email${additions.length === 1 ? '' : 's'} added${repeated ? `; ${repeated} duplicate${repeated === 1 ? '' : 's'} skipped` : ''}.`);
  }

  async function importCsv(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;
    setError('');
    try {
      const content = await file.text();
      const csvRows = content.replace(/^\uFEFF/, '').split(/\r?\n/).filter(line => line.trim() !== '');
      const firstRow = csvRows[0]?.split(',').map(value => value.trim().replace(/^['"]|['"]$/g, '').toLocaleLowerCase()) ?? [];
      const emailColumn = firstRow.findIndex(value => ['email', 'email_address', 'user_email'].includes(value));
      const values = emailColumn >= 0
        ? csvRows.slice(1).map(line => line.split(',')[emailColumn] ?? '')
        : csvRows.flatMap(line => line.split(','));
      const parsed = parseEmailInput(values.join('\n'));
      const existing = new Set(emailList.map(email => email.toLocaleLowerCase()));
      const additions = parsed.emails.filter(email => {
        const key = email.toLocaleLowerCase();
        if (existing.has(key)) return false;
        existing.add(key);
        return true;
      });
      const repeated = parsed.duplicates.length + parsed.emails.length - additions.length;
      setEmailList(current => [...current, ...additions]);
      setNotice(`${file.name}: ${additions.length} unique email${additions.length === 1 ? '' : 's'} imported${repeated ? `; ${repeated} duplicate${repeated === 1 ? '' : 's'} skipped` : ''}.`);
      if (parsed.invalid.length) {
        setError(`Skipped invalid CSV value${parsed.invalid.length > 1 ? 's' : ''}: ${parsed.invalid.slice(0, 8).join(', ')}${parsed.invalid.length > 8 ? ', …' : ''}`);
      }
    } catch {
      setError('Could not read that CSV file. Upload a UTF-8 CSV containing email addresses.');
    } finally {
      event.target.value = '';
    }
  }

  async function submitRequest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!authenticatedClient || !organisationId) return;

    const parsed = parseEmailInput(emailInput);
    const combined = [...emailList];
    const seen = new Set(combined.map(email => email.toLocaleLowerCase()));
    for (const email of parsed.emails) {
      const key = email.toLocaleLowerCase();
      if (!seen.has(key)) { seen.add(key); combined.push(email); }
    }
    if (parsed.invalid.length) {
      setError(`Invalid email address${parsed.invalid.length > 1 ? 'es' : ''}: ${parsed.invalid.join(', ')}`);
      return;
    }
    if (combined.length === 0) {
      setError('Add at least one unique user email or import a CSV file.');
      return;
    }
    if (!evidenceReference.trim()) {
      setError('Enter an evidence reference for these membership requests.');
      return;
    }

    setBusy(true); setError(''); setNotice('');
    let submitted = 0;
    const failures: string[] = [];
    try {
      for (const userEmail of combined) {
        try {
          await authenticatedClient.post(
            `/v1/identity/organisations/${encodeURIComponent(organisationId)}/memberships`,
            { user_email: userEmail, evidence_reference: evidenceReference.trim() },
            { idempotencyKey: crypto.randomUUID() },
          );
          submitted++;
        } catch (cause) {
          failures.push(`${userEmail}: ${readError(cause)}`);
        }
      }

      setEmailList(failures.map(item => item.slice(0, item.indexOf(':'))));
      setEmailInput('');
      const nextStage: Stage = canReview ? 'review' : 'mine';
      setStage(nextStage);
      await loadMemberships(nextStage);
      if (submitted > 0) {
        setNotice(`${submitted} individual membership request${submitted === 1 ? '' : 's'} submitted to the pending-review list${failures.length ? `; ${failures.length} could not be submitted` : ''}.`);
      }
      if (failures.length) setError(failures.slice(0, 5).join(' · ') + (failures.length > 5 ? ` · and ${failures.length - 5} more` : ''));
      if (submitted === 0 && failures.length === 0) setError('No membership requests were submitted.');
      if (submitted > 0) setEvidenceReference('');
    } finally { setBusy(false); }
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
      setSelectedIds(current => current.filter(id => id !== membership.id));
      await loadMemberships();
    } catch (cause) { setError(readError(cause)); }
    finally { setBusy(false); }
  }

  async function activateSelected() {
    if (!authenticatedClient || selectedCount === 0) return;
    setBusy(true); setError(''); setNotice('');
    let activated = 0;
    const failures: string[] = [];
    try {
      for (const membership of reviewableMemberships.filter(item => selectedIds.includes(item.id))) {
        try {
          await authenticatedClient.post(
            `/v1/identity/organisations/memberships/${encodeURIComponent(membership.id)}/review`,
            { decision: 'active' }, { idempotencyKey: crypto.randomUUID() },
          );
          activated++;
        } catch (cause) {
          failures.push(`${membership.user_email}: ${readError(cause)}`);
        }
      }
      setSelectedIds([]);
      await loadMemberships();
      setNotice(`${activated} membership${activated === 1 ? '' : 's'} activated${failures.length ? `; ${failures.length} failed` : ''}.`);
      if (failures.length) setError(failures.slice(0, 5).join(' · ') + (failures.length > 5 ? ` · and ${failures.length - 5} more` : ''));
    } finally { setBusy(false); }
  }

  async function toggleOrganisationMembers(org: Organisation) {
    if (openDirectoryId === org.id) { setOpenDirectoryId(null); return; }
    setOpenDirectoryId(org.id);
    if (!authenticatedClient || !canRead || directoryMembers[org.id]) return;
    setDirectoryLoadingId(org.id);
    setError('');
    try {
      const params = new URLSearchParams({ stage: 'directory', organisation_id: org.id });
      const response = await authenticatedClient.get<DirectoryMember[]>(`/v1/identity/organisations/memberships?${params.toString()}`);
      setDirectoryMembers(current => ({ ...current, [org.id]: response.data }));
    } catch (cause) { setError(readError(cause)); }
    finally { setDirectoryLoadingId(null); }
  }

  function toggleMembership(id: string) {
    setSelectedIds(current => current.includes(id) ? current.filter(item => item !== id) : [...current, id]);
  }

  function toggleAllReviewable() {
    setSelectedIds(allReviewableSelected ? [] : reviewableMemberships.map(item => item.id));
  }

  function handleEmailKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if ((event.key === 'Enter' && !event.shiftKey) || (event.key === 'Tab' && emailInput.trim())) {
      event.preventDefault();
      addEmails(emailInput);
    }
  }

  return <PageContainer>
    <PageHeader title="Organisation Memberships" />
    <p className="mb-6 max-w-3xl text-sm text-text-secondary">Submit one or more user emails for an active organisation. Each unique email creates its own pending-review record. A different reviewer must activate each request.</p>
    {notice && <p role="status" className="mb-4 rounded border border-ok-border bg-ok-surface p-3 text-sm">{notice}</p>}
    {error && <p role="alert" className="mb-4 rounded border border-error p-3 text-sm text-error">{error}</p>}

    {canRequest ? <Card className="mb-6 p-5">
      <h2 className="mb-1 text-base font-semibold">Request membership</h2>
      <p className="mb-4 text-sm text-text-secondary">Add multiple unique email addresses, paste a list separated by commas or new lines, or import a CSV with an email column. Each address is submitted as a separate membership request.</p>
      {!canRead && <p className="rounded border border-border bg-neutral-50 p-3 text-sm text-text-secondary">Your account also needs organisation registry read permission to load the organisation list.</p>}
      <form onSubmit={event => void submitRequest(event)} className="grid max-w-3xl gap-4">
        <label className="grid gap-1 text-sm">Organisation
          <select required disabled={!canRead} value={organisationId} onChange={event => setOrganisationId(event.target.value)} className="rounded border border-border px-3 py-2">
            <option value="">Choose an active organisation</option>
            {organisations.map(org => <option key={org.id} value={org.id}>{org.display_name} · {org.registry_reference}</option>)}
          </select>
        </label>
        <label className="grid gap-1 text-sm">User emails
          <textarea rows={4} value={emailInput} onKeyDown={handleEmailKeyDown} onChange={event => setEmailInput(event.target.value)} placeholder={'person.one@example.com\nperson.two@example.com'} className="rounded border border-border px-3 py-2" aria-describedby="membership-email-help" />
        </label>
        <p id="membership-email-help" className="-mt-3 text-xs text-text-secondary">Separate addresses with commas, semicolons, or new lines. Press Enter to add them to the list.</p>
        <div className="flex flex-wrap items-center gap-3">
          <label className="inline-flex min-h-[40px] cursor-pointer items-center rounded-lg border border-ok-border-strong bg-ok-surface px-3 text-sm font-medium text-text-primary hover:bg-ok-hover-bg">
            Import CSV
            <input type="file" accept=".csv,text/csv" onChange={event => void importCsv(event)} className="sr-only" />
          </label>
          <span className="text-xs text-text-secondary">CSV can be one email per row or contain an email header.</span>
        </div>
        {(emailList.length > 0 || emailInput.trim()) && <div className="rounded-lg border border-border bg-neutral-50 p-3">
          <div className="mb-2 flex items-center justify-between gap-2">
            <p className="text-sm font-medium">{emailList.length + parseEmailInput(emailInput).emails.filter(email => !emailList.some(item => item.toLocaleLowerCase() === email.toLocaleLowerCase())).length} unique email addresses</p>
            {emailList.length > 0 && <button type="button" className="text-xs text-ok-primary underline" onClick={() => setEmailList([])}>Clear list</button>}
          </div>
          {emailList.length > 0 && <div className="flex flex-wrap gap-2">
            {emailList.map(email => <span key={email.toLocaleLowerCase()} className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs text-text-primary ring-1 ring-border">
              {email}<button type="button" aria-label={`Remove ${email}`} onClick={() => setEmailList(current => current.filter(item => item.toLocaleLowerCase() !== email.toLocaleLowerCase()))} className="font-bold text-text-secondary hover:text-error">×</button>
            </span>)}
          </div>}
        </div>}
        <label className="grid gap-1 text-sm">Evidence reference
          <input required maxLength={255} value={evidenceReference} onChange={event => setEvidenceReference(event.target.value)} placeholder="local-demo://membership-request" className="rounded border border-border px-3 py-2" />
        </label>
        <Button type="submit" disabled={busy || !canRead || !organisationId || (emailList.length === 0 && !emailInput.trim()) || !evidenceReference.trim()} loading={busy}>Submit membership request</Button>
      </form>
      {organisations.length === 0 && <p className="mt-3 text-sm text-text-secondary">There are no active organisations available for membership requests.</p>}
    </Card> : <Card className="mb-6 p-5"><h2 className="mb-1 text-base font-semibold">Request membership</h2><p className="text-sm text-text-secondary">Your account does not have permission to submit organisation membership requests.</p></Card>}

    <Card className="overflow-x-auto p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><h2 className="text-base font-semibold">Memberships pending review</h2><p className="mt-1 text-sm text-text-secondary">Review and activate each submitted membership request.</p></div>
        <div className="flex flex-wrap gap-2">
          {canReview && <Button type="button" variant="secondary" onClick={() => { setStage('review'); setSelectedIds([]); void loadMemberships('review'); }}>Refresh list</Button>}
        </div>
      </div>
      {!canReview && <p className="rounded border border-border bg-neutral-50 p-3 text-sm text-text-secondary">This section is available to users with the organisation membership review permission. Ask an administrator to assign that permission to your account.</p>}
      {canReview && <>
      {reviewableMemberships.length === 0 && memberships.length > 0 && <p className="mb-3 rounded border border-border bg-neutral-50 p-3 text-sm text-text-secondary">These pending requests cannot be reviewed by the current account because it submitted a request or is the target member. Sign in as a different user with organisation membership review permission to activate them.</p>}
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <Button type="button" variant="secondary" onClick={toggleAllReviewable} disabled={busy || reviewableMemberships.length === 0}>
          {allReviewableSelected ? 'Clear selection' : 'Select all reviewable'}
        </Button>
        <Button type="button" onClick={() => void activateSelected()} disabled={busy || selectedCount === 0} loading={busy}>
          Activate selected{selectedCount ? ` (${selectedCount})` : ''}
        </Button>
        {selectedCount > 0 && <span className="text-xs text-text-secondary">Each request is activated separately; failed records stay pending.</span>}
      </div>
      {memberships.length === 0 ? <p className="text-sm text-text-secondary">No membership requests are pending review.</p> : <table className="w-full min-w-[980px] border-collapse text-left text-sm">
        <thead><tr className="border-b border-border">
          <th className="w-10 p-2"><input type="checkbox" aria-label="Select all reviewable membership requests" checked={allReviewableSelected} onChange={toggleAllReviewable} disabled={busy || reviewableMemberships.length === 0} /></th>
          <th className="p-2">Organisation</th><th className="p-2">Target user</th><th className="p-2">Evidence reference</th><th className="p-2">Requested by</th><th className="p-2">Requested</th><th className="p-2">Status</th><th className="p-2">Review</th>
        </tr></thead>
        <tbody>{memberships.map(item => {
          const canReviewThis = item.status === 'pending_review' && item.requested_by !== user?.id && item.user_id !== user?.id;
          const reviewBlockReason = item.status !== 'pending_review'
            ? 'This request is no longer pending.'
            : item.requested_by === user?.id
              ? 'Submitted by you; a different reviewer must activate it.'
              : item.user_id === user?.id
                ? 'You are the target member; a different reviewer must activate it.'
                : !canReview
                  ? 'Your account lacks organisation membership review permission.'
                  : null;
          return <tr key={item.id} className="border-b border-border">
            <td className="p-2"><input type="checkbox" aria-label={`Select membership for ${item.user_email}`} checked={selectedIds.includes(item.id)} onChange={() => toggleMembership(item.id)} disabled={busy || !canReviewThis} /></td>
            <td className="p-2">{item.organisation_name}<span className="block text-xs text-text-secondary">{item.registry_reference}</span></td>
            <td className="p-2">{item.user_email}</td>
            <td className="p-2">{item.evidence_reference}</td>
            <td className="p-2">{item.requester_email ?? item.requested_by}</td>
            <td className="p-2">{new Date(item.created_at).toLocaleString()}</td>
            <td className="p-2 capitalize">{item.status.replaceAll('_', ' ')}</td>
            <td className="p-2"><div className="flex gap-2">
              {item.status === 'pending_review' && <>
                <Button type="button" disabled={busy || !canReview || !canReviewThis} onClick={() => void reviewMembership(item, 'active')}>Activate</Button>
                <Button type="button" variant="secondary" disabled={busy || !canReview || !canReviewThis} onClick={() => void reviewMembership(item, 'rejected')}>Reject</Button>
              </>}
              {!canReviewThis && item.status === 'pending_review' && <span className="self-center text-xs text-text-secondary">{reviewBlockReason}</span>}
            </div></td>
          </tr>;
        })}</tbody>
      </table>}
      </>}
    </Card>

    <Card className="mt-6 overflow-x-auto p-5">
      <div className="mb-4">
        <h2 className="text-base font-semibold">Organisation membership directory</h2>
        <p className="mt-1 text-sm text-text-secondary">Browse organisations and view their active members, account details, and membership review history.</p>
      </div>
      {!canRead && <p className="rounded border border-border bg-neutral-50 p-3 text-sm text-text-secondary">Your account needs organisation registry read permission to view the organisation directory.</p>}
      {canRead && organisations.length === 0 && <p className="text-sm text-text-secondary">No active organisations are available.</p>}
      {canRead && organisations.length > 0 && <div className="divide-y divide-border">
        {organisations.map(org => <div key={org.id} className="py-3 first:pt-0 last:pb-0">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div><h3 className="font-medium">{org.display_name}</h3><p className="text-xs text-text-secondary">{org.registry_reference} · {org.status}</p></div>
            <Button type="button" variant="secondary" onClick={() => void toggleOrganisationMembers(org)} disabled={directoryLoadingId === org.id}>
              {directoryLoadingId === org.id ? 'Loading members…' : openDirectoryId === org.id ? 'Hide members' : 'View members'}
            </Button>
          </div>
          {openDirectoryId === org.id && <div className="mt-3">
            {directoryLoadingId === org.id ? <p className="text-sm text-text-secondary">Loading organisation members…</p>
              : (directoryMembers[org.id]?.length ?? 0) === 0 ? <p className="rounded bg-neutral-50 p-3 text-sm text-text-secondary">This organisation has no active members.</p>
                : <table className="w-full min-w-[900px] border-collapse text-left text-sm">
                  <thead><tr className="border-b border-border"><th className="p-2">Member</th><th className="p-2">Phone</th><th className="p-2">Account status</th><th className="p-2">Actor type</th><th className="p-2">Membership</th><th className="p-2">Requested</th><th className="p-2">Reviewed by</th><th className="p-2">Evidence reference</th></tr></thead>
                  <tbody>{directoryMembers[org.id]?.map(member => <tr key={member.id} className="border-b border-border last:border-0">
                    <td className="p-2">{member.user_email}</td><td className="p-2">{member.user_mobile_number ?? '—'}</td>
                    <td className="p-2 capitalize">{member.user_status.replaceAll('_', ' ')}</td><td className="p-2">{member.user_actor_type}</td>
                    <td className="p-2 capitalize">{member.status.replaceAll('_', ' ')}</td><td className="p-2">{new Date(member.created_at).toLocaleString()}</td>
                    <td className="p-2">{member.reviewer_email ?? '—'}</td><td className="p-2">{member.evidence_reference}</td>
                  </tr>)}</tbody>
                </table>}
          </div>}
        </div>)}
      </div>}
    </Card>
  </PageContainer>;
}

function readError(cause: unknown): string {
  const error = cause as { error?: { message?: string; details?: Array<{ message?: string }> }; message?: string };
  const detailMessage = error.error?.details
    ?.map(detail => detail.message)
    .filter((message): message is string => typeof message === 'string' && message.length > 0)
    .join(' ');
  return detailMessage ?? error.error?.message ?? error.message ?? 'Membership request failed.';
}
