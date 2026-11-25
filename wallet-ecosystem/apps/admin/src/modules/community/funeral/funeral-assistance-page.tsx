'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { useAuth } from '@/lib/auth/context';

type Tab = 'overview' | 'households' | 'collections' | 'finance';
type Readiness = { enabled: boolean; code: string | null; message: string | null };
type Organisation = { id: string; name: string };
type Community = {
  id: string; organisation_id: string; organisation_name: string; display_name: string;
  status: string; policy_version: string; created_by: string;
};
type Staff = { id: string; actor_id: string; role: 'manager' | 'reviewer'; email: string | null; mobile_number: string | null };
type Representative = {
  id: string; actor_id: string; status: string; evidence_reference: string | null;
  email: string | null; mobile_number: string | null; verified_by: string | null; verification_method: string;
};
type Household = {
  id: string; private_reference: string; status: string; exemption_reference: string | null;
  has_consented_contact: boolean; invitation_status: string | null; invitation_sent_at: string | null;
  creator_email: string | null; reviewer_email: string | null; representatives: Representative[];
};
type Collection = {
  id: string; status: 'under_review' | 'approved' | 'active' | 'closed' | 'archived';
  beneficiary_id: string; beneficiary_name: string; target_household_id: string | null; target_household_reference: string | null; evidence_reference: string | null; purpose: string;
  amount_minor: number; currency_code: string; terms_version: string;
  obligation_count: number; unpaid_count: number; fulfilled_count: number;
  net_support_minor: number | null; unresolved_liability_count: number | null;
  carry_forward_reference: string | null; carry_forward_requested_by: string | null;
  carry_forward_approved_by: string | null; payout_status: string | null; settlement_status: string | null;
  disbursement: Disbursement | null;
};
type Disbursement = { id: string; beneficiary_id: string | null; recipient_actor_id: string | null; recipient_email: string | null; destination_type: string; amount_minor: number; currency_code: string; status: string; requested_by: string; requested_at: string; approved_by: string | null; approved_at: string | null; rejected_by: string | null; rejection_reason: string | null; provider_reference: string | null; receipt_reference: string | null; ledger_journal_id: string | null; paid_at: string | null };
type MemberOption = { actor_id: string; email: string | null; mobile_number: string | null; actor_type: string };
type BeneficiaryOption = { id: string; account_holder_name: string; account_type: string; bank_name: string | null; currency_code: string };
type Workspace = { community: Community; staff: Staff[]; households: Household[]; collections: Collection[] };
type Directory = { members: MemberOption[]; beneficiaries: BeneficiaryOption[] };
type Report = {
  collection_id: string; confirmed_gross_minor: number; confirmed_contribution_count: number; fee_total_minor: number;
  digital_gross_minor: number; offline_gross_minor: number; offline_contribution_count: number; digital_payout_available_minor: number;
  pending_offline_contribution_count: number;
  offline_disbursed_minor: number; offline_available_for_disbursement_minor: number; pending_offline_disbursement_count: number;
  offline_disbursements: OfflineDisbursement[];
  confirmed_refunds_minor: number; net_support_minor: number; late_review_gross_minor: number;
  unresolved_liability_count: number; pending_payment_count: number; pending_refund_count: number;
  late_review_count: number; payout_liability_open: number; settlement_status: string; payout_status: string; disbursement: Disbursement | null;
};
type Transaction = { id?: string; obligation_id: string; payment_intent_id: string | null; reference: string; channel: 'in_app' | 'offline'; amount_minor: number; fee_minor: number; currency_code: string; status: string; evidence_reference?: string | null; review_reason: string | null; occurred_at: string };
type OfflineOption = { household_id: string; private_reference: string; amount_minor: number; currency_code: string };
type OfflineDisbursement = { id: string; recipient_actor_id: string; amount_minor: number; currency_code: string; reference: string; evidence_reference: string; status: string; recorded_by: string; reviewed_by: string | null; rejection_reason: string | null; created_at: string };

// Funeral routes are registered under api/v1 in the Laravel API. In local
// Admin configuration NEXT_PUBLIC_API_URL points at the API origin directly.
const API = '/api/v1/community/funeral';
const formatMoney = (minor: number, currency = 'BWP') =>
  `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const personLabel = (person: { email: string | null; mobile_number: string | null }) =>
  [person.email, person.mobile_number].filter(Boolean).join(' · ') || 'Active member';

export function FuneralAssistancePage() {
  const { authenticatedClient, hasPermission, user } = useAuth();
  const canManage = hasPermission('community.funeral.manage');
  const canReview = hasPermission('community.funeral.review');
  const canRequestPayout = hasPermission('community.funeral.payout.request');
  const canApprovePayout = hasPermission('community.funeral.payout.approve');
  const canReport = hasPermission('community.funeral.report');
  const [tab, setTab] = useState<Tab>('overview');
  const [readiness, setReadiness] = useState<Readiness | null>(null);
  const [organisations, setOrganisations] = useState<Organisation[]>([]);
  const [communities, setCommunities] = useState<Community[]>([]);
  const [selectedId, setSelectedId] = useState('');
  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [directory, setDirectory] = useState<Directory>({ members: [], beneficiaries: [] });
  const [report, setReport] = useState<Report | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [offlineOptions, setOfflineOptions] = useState<OfflineOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [organisationId, setOrganisationId] = useState('');
  const [communityName, setCommunityName] = useState('');
  const [staffActorId, setStaffActorId] = useState('');
  const [staffRole, setStaffRole] = useState<'manager' | 'reviewer'>('manager');
  const [householdReference, setHouseholdReference] = useState('');
  const [householdContactPhone, setHouseholdContactPhone] = useState('');
  const [householdContactConsent, setHouseholdContactConsent] = useState(false);
  const [reviewHouseholdId, setReviewHouseholdId] = useState('');
  const [householdDecision, setHouseholdDecision] = useState<'eligible' | 'exempt'>('eligible');
  const [exemptionReference, setExemptionReference] = useState('');
  const [representativeHouseholdId, setRepresentativeHouseholdId] = useState('');
  const [representativeActorId, setRepresentativeActorId] = useState('');
  const [representativeEvidence, setRepresentativeEvidence] = useState('');
  const [beneficiaryId, setBeneficiaryId] = useState('');
  const [targetHouseholdId, setTargetHouseholdId] = useState('');
  const [collectionPurpose, setCollectionPurpose] = useState('');
  const [collectionEvidence, setCollectionEvidence] = useState('');
  const [carryForwardReference, setCarryForwardReference] = useState('');
  const [offlineHouseholdId, setOfflineHouseholdId] = useState('');
  const [offlineReference, setOfflineReference] = useState('');
  const [offlineEvidence, setOfflineEvidence] = useState('');
  const [payoutRecipientActorId, setPayoutRecipientActorId] = useState('');
  const [payoutDestinationType, setPayoutDestinationType] = useState<'wallet' | 'bank_transfer'>('wallet');
  const [payoutBeneficiaryId, setPayoutBeneficiaryId] = useState('');
  const [offlineCashRecipientActorId, setOfflineCashRecipientActorId] = useState('');
  const [offlineCashAmount, setOfflineCashAmount] = useState('');
  const [offlineCashReference, setOfflineCashReference] = useState('');
  const [offlineCashEvidence, setOfflineCashEvidence] = useState('');

  const selectedCommunity = useMemo(
    () => communities.find((community) => community.id === selectedId) ?? null,
    [communities, selectedId],
  );
  const reportCollection = useMemo(
    () => workspace?.collections.find((collection) => collection.id === report?.collection_id) ?? null,
    [workspace, report?.collection_id],
  );
  const messageFor = (cause: unknown, fallback: string) => {
    const candidate = cause as { response?: { status?: number; data?: { error?: { message?: string }; message?: string } }; error?: { message?: string } };
    if (candidate?.response?.status === 503) return candidate.response.data?.error?.message ?? 'Funeral Assistance is unavailable while readiness prerequisites remain closed.';
    return candidate?.response?.data?.error?.message ?? candidate?.response?.data?.message ?? candidate?.error?.message ?? fallback;
  };

  const loadInitial = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true); setError(null);
    try {
      const readyResponse = await authenticatedClient.get<Readiness>(`${API}/readiness`);
      const ready = readyResponse.data;
      setReadiness(ready);
      if (!ready.enabled) {
        setOrganisations([]);
        setCommunities([]);
        setWorkspace(null);
        return;
      }
      const orgResponse = await authenticatedClient.get<Organisation[]>(`${API}/organisations`);
      setOrganisations(orgResponse.data ?? []);
      const communityResponse = await authenticatedClient.get<Community[]>(`${API}/communities`);
      const rows = communityResponse.data ?? [];
      setCommunities(rows);
      setSelectedId((current) => current || rows[0]?.id || '');
    } catch (cause) {
      setReadiness((current) => current ?? { enabled: false, code: 'UNAVAILABLE', message: null });
      setError(messageFor(cause, 'Could not load funeral assistance workspace.'));
    } finally { setLoading(false); }
  }, [authenticatedClient]);

  const loadCommunity = useCallback(async (id: string) => {
    if (!authenticatedClient || !id || !readiness?.enabled) { setWorkspace(null); return; }
    setError(null); setReport(null);
    try {
      const [workspaceResponse, directoryResponse] = await Promise.all([
        authenticatedClient.get<Workspace>(`${API}/communities/${encodeURIComponent(id)}/workspace`),
        authenticatedClient.get<Directory>(`${API}/communities/${encodeURIComponent(id)}/directory`),
      ]);
      setWorkspace(workspaceResponse.data);
      setDirectory(directoryResponse.data);
    } catch (cause) {
      setWorkspace(null);
      setError(messageFor(cause, 'Could not load this community. Check your assignment and access.'));
    }
  }, [authenticatedClient, readiness?.enabled]);

  useEffect(() => { void loadInitial(); }, [loadInitial]);
  useEffect(() => { if (readiness?.enabled && selectedId) void loadCommunity(selectedId); }, [readiness?.enabled, selectedId, loadCommunity]);

  const refresh = async () => {
    await loadInitial();
    if (selectedId) await loadCommunity(selectedId);
  };

  async function mutate<T>(path: string, method: 'post' | 'patch', body: unknown, success: string, after?: () => void) {
    if (!authenticatedClient) return;
    setBusy(true); setError(null); setNotice(null);
    try {
      await authenticatedClient[method]<T>(path, body, { idempotencyKey: crypto.randomUUID() });
      setNotice(success); after?.();
      if (selectedId) await loadCommunity(selectedId);
      await loadInitial();
    } catch (cause) { setError(messageFor(cause, 'The requested action could not be completed.')); }
    finally { setBusy(false); }
  }

  const createCommunity = async (event: React.FormEvent) => {
    event.preventDefault();
    await mutate(`${API}/communities`, 'post', { organisation_id: organisationId, display_name: communityName.trim() }, 'Community created. Assign manager and reviewer roles next.', () => setCommunityName(''));
  };
  const createHousehold = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!selectedId) return;
    const hasPhone = householdContactPhone.trim().length > 0;
    await mutate(`${API}/communities/${selectedId}/households`, 'post', {
      private_reference: householdReference.trim(),
      ...(hasPhone ? { contact_phone: householdContactPhone.trim(), contact_consent: householdContactConsent } : {}),
    }, 'Household added for eligibility review.', () => { setHouseholdReference(''); setHouseholdContactPhone(''); setHouseholdContactConsent(false); });
  };
  const reviewHousehold = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!reviewHouseholdId) return;
    await mutate(`${API}/households/${reviewHouseholdId}/review`, 'post', {
      decision: householdDecision,
      ...(householdDecision === 'exempt' ? { exemption_reference: exemptionReference.trim() } : {}),
    }, `Household marked ${householdDecision}.`, () => { setReviewHouseholdId(''); setExemptionReference(''); });
  };
  const addRepresentative = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!representativeHouseholdId || !representativeActorId) return;
    await mutate(`${API}/households/${representativeHouseholdId}/representatives`, 'post', {
      actor_id: representativeActorId, evidence_reference: representativeEvidence.trim(),
    }, 'Household representative added. Independent verification is still required.', () => setRepresentativeEvidence(''));
  };
  const copyInvitationLink = async (householdId: string) => {
    if (!authenticatedClient) return;
    setError(null); setNotice(null);
    try {
      const response = await authenticatedClient.get<{ url: string }>(`${API}/households/${householdId}/invitation-link`);
      await navigator.clipboard.writeText(response.data.url);
      setNotice('Join link copied. Share it only with the person whose phone received the invitation.');
    } catch (cause) { setError(messageFor(cause, 'Could not retrieve the active household invitation link.')); }
  };
  const createCollection = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!selectedId) return;
    await mutate(`${API}/communities/${selectedId}/collections`, 'post', {
      beneficiary_id: beneficiaryId, target_household_id: targetHouseholdId, purpose: collectionPurpose.trim(), evidence_reference: collectionEvidence.trim(),
    }, 'Collection submitted for independent review.', () => { setCollectionPurpose(''); setCollectionEvidence(''); setTargetHouseholdId(''); });
  };
  const loadReport = async (collectionId: string) => {
    if (!authenticatedClient) return;
    setBusy(true); setError(null);
    try {
      const [response, transactionResponse, optionsResponse] = await Promise.all([
        authenticatedClient.get<Report>(`${API}/collections/${collectionId}/aggregate-report`),
        authenticatedClient.get<Transaction[]>(`${API}/collections/${collectionId}/transactions`),
        canManage
          ? authenticatedClient.get<OfflineOption[]>(`${API}/collections/${collectionId}/offline-contribution-options`)
          : Promise.resolve({ data: [] as OfflineOption[] }),
      ]);
      setReport(response.data);
      setTransactions(transactionResponse.data ?? []);
      setOfflineOptions(optionsResponse.data ?? []);
    } catch (cause) { setError(messageFor(cause, 'Unable to load the collection report.')); }
    finally { setBusy(false); }
  };
  const exportTransactions = async (collectionId: string) => {
    if (!authenticatedClient) return;
    try {
      const blob = await authenticatedClient.getBlob(`${API}/collections/${collectionId}/transactions/export`);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a'); link.href = url; link.download = `funeral-collection-${collectionId}-transactions.csv`; link.click(); URL.revokeObjectURL(url);
    } catch (cause) { setError(messageFor(cause, 'Unable to export collection transactions.')); }
  };
  const recordOfflineContribution = (event: React.FormEvent, collectionId: string) => {
    event.preventDefault();
    const option = offlineOptions.find((item) => item.household_id === offlineHouseholdId);
    if (!option) return;
    void mutate(`${API}/collections/${collectionId}/offline-contributions`, 'post', {
      household_id: option.household_id, amount_minor: option.amount_minor,
      reference: offlineReference.trim(), evidence_reference: offlineEvidence.trim() || null,
    }, 'Offline contribution recorded and submitted for independent review.', () => {
      setOfflineHouseholdId(''); setOfflineReference(''); setOfflineEvidence('');
      void loadReport(collectionId);
    });
  };
  const requestOfflineDisbursement = (event: React.FormEvent, collectionId: string) => {
    event.preventDefault();
    const amountMinor = Math.round(Number(offlineCashAmount) * 100);
    if (!Number.isFinite(amountMinor) || amountMinor < 1) return;
    void mutate(`${API}/collections/${collectionId}/offline-disbursements`, 'post', {
      recipient_actor_id: offlineCashRecipientActorId, amount_minor: amountMinor,
      reference: offlineCashReference.trim(), evidence_reference: offlineCashEvidence.trim(),
    }, 'Offline cash handover submitted for independent review.', () => {
      setOfflineCashAmount(''); setOfflineCashReference(''); setOfflineCashEvidence('');
      void loadReport(collectionId);
    });
  };

  const selectClass = 'w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-text-primary';
  const inputClass = 'w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-text-primary';
  const activeOptions = organisations.map((org) => ({ value: org.id, label: org.name }));
  const verifiedBeneficiaryOptions = directory.beneficiaries.map((item) => ({
    value: item.id,
    label: `${item.account_holder_name} · ${item.account_type.replaceAll('_', ' ')}${item.bank_name ? ` · ${item.bank_name}` : ''} · ${item.currency_code}`,
  }));
  const memberOptions = directory.members.map((item) => ({ value: item.actor_id, label: personLabel(item) }));

  return (
    <PageContainer>
      <PageHeader title="Funeral Assistance" description="Community setup, household eligibility, customer contributions, reconciliation, and beneficiary readiness." />
      {error && <div role="alert" className="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800">{error}</div>}
      {notice && <div role="status" className="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800">{notice}</div>}
      {loading ? <Loading label="Loading funeral assistance workspace…" /> : (
        <>
          {readiness && !readiness.enabled && (
            <Card>
              <div className="flex items-start gap-3">
                <span className="mt-0.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">NOT AVAILABLE</span>
                <div>
                  <h2 className="font-semibold text-text-primary">Readiness prerequisites are still closed</h2>
                  <p className="mt-1 text-sm text-text-secondary">{readiness.message ?? 'The funeral API cannot accept setup or payment actions yet.'} The forms below become active when the API readiness gate is released. Local wallet payout is available only after the collection is closed and all review liabilities are resolved.</p>
                  <div className="mt-3 flex flex-wrap gap-3 text-sm">
                    <Link className="text-ok-primary underline" href="/configuration/operational_policy/community_funeral.http_enabled">Manage availability in Configuration</Link>
                    <Link className="text-ok-primary underline" href="/organisations/onboarding">Organisation onboarding</Link>
                    <Link className="text-ok-primary underline" href="/organisations/memberships">Organisation memberships</Link>
                  </div>
                </div>
              </div>
            </Card>
          )}
          <div className="my-5 flex flex-wrap gap-2" role="tablist" aria-label="Funeral assistance workflow">
            {([
              ['overview', 'Community setup'], ['households', 'Households & representatives'],
              ['collections', 'Collections'], ['finance', 'Reconciliation & payout'],
            ] as const).map(([key, label]) => (
              <button key={key} role="tab" aria-selected={tab === key} onClick={() => setTab(key)}
                className={`rounded-lg px-4 py-2 text-sm font-medium ${tab === key ? 'bg-ok-primary text-white' : 'border border-border bg-surface text-text-primary hover:bg-neutral-50'}`}>
                {label}
              </button>
            ))}
            <Button variant="secondary" onClick={() => void refresh()} disabled={busy}>Refresh</Button>
          </div>

          {(tab !== 'overview' || communities.length > 0) && (
            <div className="mb-4 grid gap-3 md:grid-cols-[minmax(220px,360px)_1fr]">
              <label className="text-sm font-medium text-text-primary">Community
                <select className={`${selectClass} mt-1`} value={selectedId} onChange={(event) => setSelectedId(event.target.value)} disabled={!readiness?.enabled || communities.length === 0}>
                  <option value="">Select a community</option>
                  {communities.map((community) => <option key={community.id} value={community.id}>{community.display_name} · {community.organisation_name}</option>)}
                </select>
              </label>
              {selectedCommunity && <div className="self-end text-sm text-text-secondary">{selectedCommunity.organisation_name} · Policy {selectedCommunity.policy_version}</div>}
            </div>
          )}

          {tab === 'overview' && <>
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              {[
                ['Communities', communities.length], ['Households', workspace?.households.length ?? 0],
                ['Verified representatives', workspace?.households.flatMap((household) => household.representatives).filter((rep) => rep.status === 'verified').length ?? 0],
                ['Open collections', workspace?.collections.filter((collection) => collection.status === 'active').length ?? 0],
              ].map(([label, value]) => <Card key={label}><p className="text-sm text-text-secondary">{label}</p><p className="mt-1 text-2xl font-semibold text-text-primary">{value}</p></Card>)}
            </div>
            <div className="grid gap-4 xl:grid-cols-2">
              <Card>
                <h2 className="text-lg font-semibold text-text-primary">Initialize a community</h2>
                <p className="mb-4 mt-1 text-sm text-text-secondary">Select an approved organisation where your account has active membership. Organisation and member identifiers stay hidden behind the selections.</p>
                <form className="space-y-3" onSubmit={(event) => void createCommunity(event)}>
                  <label className="block text-sm font-medium">Organisation
                    <select required className={`${selectClass} mt-1`} value={organisationId} onChange={(event) => setOrganisationId(event.target.value)} disabled={!readiness?.enabled || !canManage}>
                      <option value="">Choose your organisation</option>{activeOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </select>
                  </label>
                  <label className="block text-sm font-medium">Community name<input required maxLength={200} className={`${inputClass} mt-1`} value={communityName} onChange={(event) => setCommunityName(event.target.value)} placeholder="e.g. Gaborone Community Support" disabled={!readiness?.enabled || !canManage} /></label>
                  <Button variant="primary" disabled={busy || !readiness?.enabled || !canManage || !organisationId || !communityName.trim()}>Create community</Button>
                  {!canManage && <p className="text-xs text-text-secondary">Your account needs the community manager permission to create a community.</p>}
                </form>
              </Card>
              <Card>
                <h2 className="text-lg font-semibold text-text-primary">Lifecycle guide</h2>
                <ol className="mt-3 space-y-2 text-sm text-text-secondary">
                  <li><b>1. Set up:</b> active organisation membership, community, manager and reviewer.</li>
                  <li><b>2. Verify:</b> add households, independently review eligibility, verify household representatives.</li>
                  <li><b>3. Open collection:</b> select a verified beneficiary, submit evidence, review and activate.</li>
                  <li><b>4. Customer payment:</b> the verified representative pays the household obligation in the customer app.</li>
                  <li><b>5. Close and reconcile:</b> review payment liabilities, then submit any eligible beneficiary payout for independent approval.</li>
                </ol>
              </Card>
            </div>
            <Card className="mt-4">
              <h2 className="font-semibold text-text-primary">Your communities</h2>
              {communities.length === 0 ? <p className="py-5 text-sm text-text-secondary">No community is available for your account. Check active organisation membership or create a community above when the service is enabled.</p> : (
                <div className="mt-3 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr className="border-b text-text-secondary"><th className="py-2 pr-3">Community</th><th className="py-2 pr-3">Organisation</th><th className="py-2 pr-3">Status</th><th className="py-2">Policy</th></tr></thead><tbody>{communities.map((community) => <tr key={community.id} className="border-b border-border"><td className="py-3 pr-3"><button className="font-medium text-ok-primary underline" onClick={() => { setSelectedId(community.id); setTab('households'); }}>{community.display_name}</button></td><td className="py-3 pr-3">{community.organisation_name}</td><td className="py-3 pr-3 capitalize">{community.status.replaceAll('_', ' ')}</td><td className="py-3">{community.policy_version}</td></tr>)}</tbody></table></div>
              )}
            </Card>
          </>}

          {tab === 'households' && <>
            {!workspace ? <Card><p className="text-sm text-text-secondary">Select a community to manage household participation.</p></Card> : <>
              <div className="grid gap-4 xl:grid-cols-2">
                <Card>
                  <h2 className="text-lg font-semibold">Community staff</h2>
                  <p className="mb-3 mt-1 text-sm text-text-secondary">Assign an active member by name. Managers maintain records; reviewers perform independent checks.</p>
                  <form className="grid gap-3 sm:grid-cols-[1fr_150px_auto]" onSubmit={(event) => { event.preventDefault(); if (selectedId && staffActorId) void mutate(`${API}/communities/${selectedId}/staff`, 'post', { actor_id: staffActorId, role: staffRole }, `${staffRole === 'manager' ? 'Manager' : 'Reviewer'} assigned.`, () => setStaffActorId('')); }}>
                    <select className={selectClass} value={staffActorId} onChange={(event) => setStaffActorId(event.target.value)} required disabled={!readiness?.enabled || !canManage}><option value="">Select active organisation member</option>{memberOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
                    <select className={selectClass} value={staffRole} onChange={(event) => setStaffRole(event.target.value as 'manager' | 'reviewer')} disabled={!readiness?.enabled || !canManage}><option value="manager">Manager</option><option value="reviewer">Reviewer</option></select>
                    <Button variant="primary" disabled={busy || !readiness?.enabled || !canManage || !staffActorId}>Assign</Button>
                  </form>
                  <div className="mt-4 divide-y divide-border">{workspace.staff.map((person) => <div key={person.id} className="flex justify-between py-2 text-sm"><span>{personLabel(person)}</span><span className="capitalize text-text-secondary">{person.role}</span></div>)}{workspace.staff.length === 0 && <p className="py-3 text-sm text-text-secondary">No additional staff assigned yet.</p>}</div>
                </Card>
                <Card>
                  <h2 className="text-lg font-semibold">Add household</h2>
                  <p className="mb-3 mt-1 text-sm text-text-secondary">Use a private household reference. If the household representative does not yet have an OleKard account, record their phone with consent so an invitation can be sent when a collection opens.</p>
                  <form className="space-y-3" onSubmit={(event) => void createHousehold(event)}>
                    <input className={inputClass} maxLength={200} required value={householdReference} onChange={(event) => setHouseholdReference(event.target.value)} placeholder="Private household reference" disabled={!readiness?.enabled || !canManage} />
                    <input className={inputClass} type="tel" inputMode="tel" pattern="[+][1-9][0-9]{9,14}" value={householdContactPhone} onChange={(event) => setHouseholdContactPhone(event.target.value)} placeholder="Contact phone in international format, e.g. +267… (optional)" disabled={!readiness?.enabled || !canManage} />
                    {householdContactPhone.trim() && <label className="flex items-start gap-2 text-sm text-text-secondary"><input type="checkbox" checked={householdContactConsent} onChange={(event) => setHouseholdContactConsent(event.target.checked)} required disabled={!readiness?.enabled || !canManage} /><span>The household agreed that OleKard may use this number to send funeral collection and account-joining instructions.</span></label>}
                    <Button variant="primary" disabled={busy || !readiness?.enabled || !canManage || !householdReference.trim() || (Boolean(householdContactPhone.trim()) && !householdContactConsent)}>Add household</Button>
                  </form>
                </Card>
                <Card>
                  <h2 className="text-lg font-semibold">Review eligibility</h2>
                  <form className="mt-3 grid gap-3 md:grid-cols-3" onSubmit={(event) => void reviewHousehold(event)}>
                    <select className={selectClass} value={reviewHouseholdId} onChange={(event) => setReviewHouseholdId(event.target.value)} required disabled={!readiness?.enabled || !canReview}><option value="">Choose pending household</option>{workspace.households.filter((household) => household.status === 'pending_review').map((household) => <option key={household.id} value={household.id}>{household.private_reference}</option>)}</select>
                    <select className={selectClass} value={householdDecision} onChange={(event) => setHouseholdDecision(event.target.value as 'eligible' | 'exempt')} disabled={!readiness?.enabled || !canReview}><option value="eligible">Eligible</option><option value="exempt">Exempt</option></select>
                    {householdDecision === 'exempt' && <input className={inputClass} value={exemptionReference} onChange={(event) => setExemptionReference(event.target.value)} placeholder="Approved exemption reference" required disabled={!readiness?.enabled || !canReview} />}
                    <Button variant="primary" disabled={busy || !readiness?.enabled || !canReview || !reviewHouseholdId || (householdDecision === 'exempt' && !exemptionReference.trim())}>Save review</Button>
                  </form>
                </Card>
                <Card>
                  <h2 className="text-lg font-semibold">Link an existing customer</h2>
                  <p className="mb-3 mt-1 text-sm text-text-secondary">Use this for a household representative who already has an OleKard customer account. For someone without an account, use the consented phone above; the collection invitation will guide them to register or sign in and claim their household.</p>
                  <form className="space-y-3" onSubmit={(event) => void addRepresentative(event)}>
                    <select className={selectClass} value={representativeHouseholdId} onChange={(event) => setRepresentativeHouseholdId(event.target.value)} required disabled={!readiness?.enabled || !canManage}><option value="">Choose a household</option>{workspace.households.map((household) => <option key={household.id} value={household.id}>{household.private_reference}</option>)}</select>
                    <select className={selectClass} value={representativeActorId} onChange={(event) => setRepresentativeActorId(event.target.value)} required disabled={!readiness?.enabled || !canManage}><option value="">Choose an active member</option>{memberOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
                    <input className={inputClass} maxLength={500} value={representativeEvidence} onChange={(event) => setRepresentativeEvidence(event.target.value)} placeholder="Representative evidence reference" disabled={!readiness?.enabled || !canManage} />
                    <Button variant="primary" disabled={busy || !readiness?.enabled || !canManage || !representativeHouseholdId || !representativeActorId}>Add representative</Button>
                  </form>
                </Card>
              </div>
              <Card className="mt-4">
                <h2 className="font-semibold">Household directory</h2>
                <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[800px] text-left text-sm"><thead><tr className="border-b text-text-secondary"><th className="py-2 pr-3">Household</th><th className="py-2 pr-3">Eligibility</th><th className="py-2 pr-3">Representative / invitation</th><th className="py-2 pr-3">Review</th><th className="py-2">Actions</th></tr></thead><tbody>{workspace.households.map((household) => <tr key={household.id} className="border-b border-border align-top"><td className="py-3 pr-3 font-medium">{household.private_reference}</td><td className="py-3 pr-3 capitalize">{household.status.replaceAll('_', ' ')}{household.exemption_reference && <div className="text-xs text-text-secondary">Exemption evidence: {household.exemption_reference}</div>}</td><td className="py-3 pr-3">{household.representatives.length ? household.representatives.map((rep) => <div key={rep.id} className="mb-2">{personLabel(rep)}<div className="text-xs capitalize text-text-secondary">{rep.status.replaceAll('_', ' ')}{rep.verification_method === 'phone_invite' ? ' by verified phone invitation' : ''}</div>{rep.status === 'unverified' && rep.evidence_reference && canReview && <Button variant="secondary" disabled={busy || !readiness?.enabled} onClick={() => void mutate(`${API}/households/${household.id}/representatives/${rep.id}/verify`, 'post', {}, 'Representative verified.')}>Verify representative</Button>}</div>) : <span className="text-text-secondary">No OleKard account linked</span>}{!household.representatives.some((rep) => rep.status === 'verified') && household.has_consented_contact && <div className="mt-1 text-xs text-text-secondary">Phone invitation: {household.invitation_status?.replaceAll('_', ' ') ?? 'will send when a collection opens'}{household.invitation_sent_at ? ` · sent ${new Date(household.invitation_sent_at).toLocaleString()}` : ''}</div>}</td><td className="py-3 pr-3 text-text-secondary">{household.reviewer_email ?? 'Awaiting review'}</td><td className="py-3">{household.status === 'pending_review' && canReview && <Button variant="secondary" disabled={busy || !readiness?.enabled} onClick={() => { setReviewHouseholdId(household.id); setHouseholdDecision('eligible'); window.scrollTo({ top: 0, behavior: 'smooth' }); }}>Review</Button>}{household.has_consented_contact && !household.representatives.some((rep) => rep.status === 'verified') && household.invitation_status && <Button className="mt-2" variant="secondary" disabled={busy || !canManage} onClick={() => void copyInvitationLink(household.id)}>Copy join link</Button>}</td></tr>)}</tbody></table>{workspace.households.length === 0 && <p className="py-6 text-center text-sm text-text-secondary">No households have been added.</p>}</div>
              </Card>
            </>}
          </>}

          {tab === 'collections' && <>
            {!workspace ? <Card><p className="text-sm text-text-secondary">Select a community to review collections.</p></Card> : <>
              <Card>
                <h2 className="text-lg font-semibold">Create a funeral collection</h2>
                <p className="mb-3 mt-1 text-sm text-text-secondary">Select the organisation’s verified payment settlement account. The bereaved household is recorded separately and receives any eligible local wallet disbursement through its verified representative.</p>
                <form className="grid gap-3 lg:grid-cols-[1fr_1fr_1.4fr_1fr_auto]" onSubmit={(event) => void createCollection(event)}>
                  <select className={selectClass} value={beneficiaryId} onChange={(event) => setBeneficiaryId(event.target.value)} required disabled={!readiness?.enabled || !canManage}><option value="">Choose organisation settlement account</option>{verifiedBeneficiaryOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
                  <select className={selectClass} value={targetHouseholdId} onChange={(event) => setTargetHouseholdId(event.target.value)} required disabled={!readiness?.enabled || !canManage}><option value="">Choose bereaved household</option>{workspace.households.filter((household) => household.status === 'eligible').map((household) => <option key={household.id} value={household.id}>{household.private_reference}</option>)}</select>
                  <input className={inputClass} maxLength={500} minLength={5} value={collectionPurpose} onChange={(event) => setCollectionPurpose(event.target.value)} placeholder="Purpose of this collection" required disabled={!readiness?.enabled || !canManage} />
                  <input className={inputClass} maxLength={500} value={collectionEvidence} onChange={(event) => setCollectionEvidence(event.target.value)} placeholder="Collection and beneficiary evidence reference" required disabled={!readiness?.enabled || !canManage} />
                  <Button variant="primary" disabled={busy || !readiness?.enabled || !canManage || !beneficiaryId || collectionPurpose.trim().length < 5 || !collectionEvidence.trim()}>Submit for review</Button>
                </form>
                {directory.beneficiaries.length === 0 && <p className="mt-2 text-sm text-amber-800">No verified payout destination is registered for this organisation. Add and verify one before creating a collection.</p>}
              </Card>
              <Card className="mt-4">
                <h2 className="font-semibold">Collection register</h2>
                <div className="mt-3 space-y-3">{workspace.collections.map((collection) => (
                  <div key={collection.id} className="rounded-xl border border-border p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div><div className="font-semibold text-text-primary">Bereaved household: {collection.target_household_reference ?? 'Legacy collection (not linked)'}</div><div className="mt-1 text-sm text-text-secondary">Organisation settlement account: {collection.beneficiary_name} · {collection.purpose} · {formatMoney(collection.amount_minor, collection.currency_code)} · {collection.obligation_count} household obligations · {collection.fulfilled_count} paid</div><div className="mt-1 text-xs text-text-secondary">Terms {collection.terms_version} · {collection.evidence_reference ?? 'No evidence reference'}</div></div>
                      <span className="rounded-full bg-neutral-100 px-3 py-1 text-xs font-semibold capitalize text-text-primary">{collection.status.replaceAll('_', ' ')}</span>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                      {collection.status === 'under_review' && canReview && <Button variant="secondary" disabled={busy || !readiness?.enabled} onClick={() => void mutate(`${API}/collections/${collection.id}/review`, 'post', {}, 'Collection independently approved.')}>Approve collection</Button>}
                      {collection.status === 'approved' && canReview && <Button variant="primary" disabled={busy || !readiness?.enabled} onClick={() => void mutate(`${API}/collections/${collection.id}/activate`, 'post', {}, 'Collection activated. Existing representatives are notified in-app; households without OleKard accounts receive a phone invitation to join.')}>Activate and notify households</Button>}
                      {collection.status === 'active' && canManage && <Button variant="secondary" disabled={busy || !readiness?.enabled} onClick={() => void mutate(`${API}/collections/${collection.id}/close`, 'post', {}, 'Collection closed. Review its reconciliation report.')}>Close collection</Button>}
                      {canReport && <Button variant="secondary" disabled={busy || !readiness?.enabled} onClick={() => { setTab('finance'); void loadReport(collection.id); }}>View contribution history</Button>}
                      {(collection.status === 'closed' || collection.status === 'archived') && canReport && <Button variant="secondary" disabled={busy || !readiness?.enabled} onClick={() => { setTab('finance'); void loadReport(collection.id); }}>Open reconciliation</Button>}
                      {collection.status === 'under_review' && canManage && <label className="sr-only" htmlFor={`beneficiary-${collection.id}`}>Change beneficiary for {collection.beneficiary_name}</label>}
                      {collection.status === 'under_review' && canManage && <><select id={`beneficiary-${collection.id}`} className={`${selectClass} w-auto`} value={beneficiaryId} onChange={(event) => setBeneficiaryId(event.target.value)} disabled={!readiness?.enabled}><option value="">Change beneficiary…</option>{verifiedBeneficiaryOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select><Button variant="secondary" disabled={busy || !readiness?.enabled || !beneficiaryId} onClick={() => void mutate(`${API}/collections/${collection.id}/beneficiary`, 'patch', { beneficiary_id: beneficiaryId }, 'Beneficiary changed; collection returned to independent review.')}>Save beneficiary</Button></>}
                    </div>
                  </div>
                ))}{workspace.collections.length === 0 && <p className="py-6 text-center text-sm text-text-secondary">No collections have been created.</p>}</div>
              </Card>
            </>}
          </>}

          {tab === 'finance' && <>
            <Card>
              <h2 className="text-lg font-semibold">Reconciliation and disbursement</h2>
              <p className="mb-3 mt-1 text-sm text-text-secondary">Select any collection to review contribution attempts and, once closed, its reconciliation totals.</p>
              <div className="flex flex-wrap gap-2">{workspace?.collections.map((collection) => <Button key={collection.id} variant="secondary" disabled={busy || !readiness?.enabled || !canReport} onClick={() => void loadReport(collection.id)}>{collection.target_household_reference ?? collection.beneficiary_name} · {collection.status}</Button>)}</div>
              {workspace && workspace.collections.length === 0 && <p className="py-4 text-sm text-text-secondary">No collections are available for reconciliation.</p>}
            </Card>
            {report && <>
              <div className="my-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                  ['Confirmed total received', formatMoney(report.confirmed_gross_minor)], ['Successful contributions', report.confirmed_contribution_count],
                  ['Digital receipts', formatMoney(report.digital_gross_minor)], ['Offline contributions', `${formatMoney(report.offline_gross_minor)} · ${report.offline_contribution_count}`],
                  ['Offline cash handed over', formatMoney(report.offline_disbursed_minor)],
                  ['Fees', formatMoney(report.fee_total_minor)], ['Refunds', formatMoney(report.confirmed_refunds_minor)], ['Net support', formatMoney(report.net_support_minor)],
                ].map(([label, amount]) => <Card key={label}><p className="text-sm text-text-secondary">{label}</p><p className="mt-1 text-xl font-semibold text-text-primary">{amount}</p></Card>)}
              </div>
              <Card>
                <h3 className="font-semibold">Outstanding reconciliation items</h3>
                <dl className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4 text-sm">
                  <div><dt className="text-text-secondary">Pending payments</dt><dd className="font-semibold">{report.pending_payment_count}</dd></div>
                  <div><dt className="text-text-secondary">Pending refunds</dt><dd className="font-semibold">{report.pending_refund_count}</dd></div>
                  <div><dt className="text-text-secondary">Late review cases</dt><dd className="font-semibold">{report.late_review_count} · {formatMoney(report.late_review_gross_minor)}</dd></div>
                  <div><dt className="text-text-secondary">Offline contributions awaiting review</dt><dd className="font-semibold">{report.pending_offline_contribution_count}</dd></div>
                  <div><dt className="text-text-secondary">Cash handovers awaiting review</dt><dd className="font-semibold">{report.pending_offline_disbursement_count}</dd></div>
                  <div><dt className="text-text-secondary">Unresolved liabilities</dt><dd className="font-semibold">{report.unresolved_liability_count}</dd></div>
                </dl>
                <p className="mt-4 text-sm text-text-secondary">Settlement: <b className="capitalize text-text-primary">{report.settlement_status.replaceAll('_', ' ')}</b> · Payout: <b className="capitalize text-text-primary">{report.payout_status.replaceAll('_', ' ')}</b></p>
              </Card>
              {(() => {
                const selectedCollection = workspace?.collections.find((collection) => collection.id === report.collection_id);
                const targetHousehold = workspace?.households.find((household) => household.id === selectedCollection?.target_household_id);
                const payoutRecipients = targetHousehold?.representatives.filter((rep) => rep.status === 'verified') ?? [];
                const payout = report.disbursement ?? selectedCollection?.disbursement ?? null;
                const payoutRecipient = payoutRecipients.find((rep) => rep.actor_id === payout?.recipient_actor_id);
                const payoutBanks = directory.beneficiaries.filter((item) => item.account_type === 'bank_account' && item.currency_code === reportCollection?.currency_code && item.id !== reportCollection?.beneficiary_id);
                const requestPayout = () => void mutate(`${API}/collections/${report.collection_id}/payout-request`, 'post', { recipient_actor_id: payoutRecipientActorId, destination_type: payoutDestinationType, ...(payoutDestinationType === 'bank_transfer' ? { beneficiary_id: payoutBeneficiaryId } : {}) }, `${payoutDestinationType === 'wallet' ? 'Wallet payout' : 'Bank transfer'} submitted for independent approval.`, () => { void loadReport(report.collection_id); });
                const approvePayout = () => void mutate(`${API}/collections/${report.collection_id}/payout/approve`, 'post', {}, payout?.destination_type === 'bank_transfer' ? 'Local mock bank transfer approved and recorded.' : 'Disbursement approved and settled to the bereaved household representative wallet.', () => { void loadReport(report.collection_id); });
                const rejectPayout = () => {
                  const reason = window.prompt('Reason for rejecting this payout request:')?.trim();
                  if (reason) void mutate(`${API}/collections/${report.collection_id}/payout/reject`, 'post', { reason }, 'Disbursement request rejected.', () => { void loadReport(report.collection_id); });
                };
                return <Card className="mt-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div><h3 className="font-semibold">Beneficiary payout</h3><p className="mt-1 text-sm text-text-secondary">Only digitally settled funds are eligible. Offline contributions are recorded and disbursed separately. Both wallet settlement and bank transfer use independent approval. Bank transfer is a local mock for this POC; it does not send money to a real bank.</p></div>
                    {(!payout || ['rejected', 'failed'].includes(payout.status)) && <Button variant="secondary" disabled={busy || !canRequestPayout || !payoutRecipientActorId || (payoutDestinationType === 'bank_transfer' && !payoutBeneficiaryId) || selectedCollection?.status !== 'closed' || report.digital_payout_available_minor <= 0 || report.pending_payment_count > 0 || report.pending_refund_count > 0 || report.late_review_count > 0 || report.pending_offline_contribution_count > 0} title={selectedCollection?.status !== 'closed' ? 'Close the collection before requesting payout.' : !canRequestPayout ? 'Your account cannot request beneficiary payout.' : undefined} onClick={requestPayout}>{payout ? 'Resubmit' : 'Request'} {formatMoney(report.digital_payout_available_minor)} payout</Button>}
                  </div>
                  {(!payout || ['rejected', 'failed'].includes(payout.status)) && <div className="mt-3 grid max-w-2xl gap-3 sm:grid-cols-2">
                    <label className="block text-sm font-medium">Payout method<select className={`${selectClass} mt-1`} value={payoutDestinationType} onChange={(event) => { setPayoutDestinationType(event.target.value as 'wallet' | 'bank_transfer'); setPayoutBeneficiaryId(''); }} disabled={!canRequestPayout || selectedCollection?.status !== 'closed'}><option value="wallet">OleKard wallet</option><option value="bank_transfer">Bank transfer (local POC mock)</option></select></label>
                    <label className="block text-sm font-medium">Bereaved household representative<select id={`payout-recipient-${report.collection_id}`} className={`${selectClass} mt-1`} value={payoutRecipientActorId} onChange={(event) => setPayoutRecipientActorId(event.target.value)} disabled={!canRequestPayout || selectedCollection?.status !== 'closed'}><option value="">Choose verified representative</option>{payoutRecipients.map((rep) => <option key={rep.actor_id} value={rep.actor_id}>{personLabel(rep)}</option>)}</select>{payoutRecipients.length === 0 && <span className="mt-1 block text-xs text-amber-700">Link and verify a representative for this household first.</span>}</label>
                    {payoutDestinationType === 'bank_transfer' && <label className="block text-sm font-medium sm:col-span-2">Verified bank account<select className={`${selectClass} mt-1`} value={payoutBeneficiaryId} onChange={(event) => setPayoutBeneficiaryId(event.target.value)} disabled={!canRequestPayout || selectedCollection?.status !== 'closed'}><option value="">Choose an organisation verified bank account</option>{payoutBanks.map((bank) => <option key={bank.id} value={bank.id}>{bank.account_holder_name}{bank.bank_name ? ` · ${bank.bank_name}` : ''} · ${bank.currency_code}</option>)}</select>{payoutBanks.length === 0 && <span className="mt-1 block text-xs text-amber-700">Add and verify a bank account in Payment Beneficiaries. The collection receiving account cannot be reused for payout.</span>}</label>}
                  </div>}
                  {payout && <div className="mt-3 rounded-lg border border-border p-3 text-sm">
                    <p><b>{formatMoney(payout.amount_minor, payout.currency_code)}</b> · {payout.destination_type.replaceAll('_', ' ')} to {payoutRecipient ? personLabel(payoutRecipient) : 'verified bereaved-household representative'}{payout.destination_type === 'bank_transfer' && payout.beneficiary_id ? ` · ${directory.beneficiaries.find((bank) => bank.id === payout.beneficiary_id)?.account_holder_name ?? 'verified bank beneficiary'}` : ''} · <span className="capitalize">{payout.status.replaceAll('_', ' ')}</span></p>
                    {payout.receipt_reference && <p className="mt-1">Receipt: <span className="font-mono">{payout.receipt_reference}</span>{payout.provider_reference ? ` · Provider: ${payout.provider_reference}` : ''}</p>}
                    {payout.ledger_journal_id && <p className="mt-1">Ledger journal: <span className="font-mono">{payout.ledger_journal_id}</span></p>}
                    {payout.paid_at && <p className="mt-1">Settled: {new Date(payout.paid_at).toLocaleString()}</p>}
                    {payout.rejection_reason && <p className="mt-1 text-error">Rejected: {payout.rejection_reason}</p>}
                    {payout.status === 'awaiting_approval' && <div className="mt-3 flex flex-wrap gap-2">
                      {canApprovePayout && payout.requested_by !== user?.id && <><Button disabled={busy} onClick={approvePayout}>Approve and settle</Button><Button variant="secondary" disabled={busy} onClick={rejectPayout}>Reject request</Button></>}
                      {payout.requested_by === user?.id && <p className="text-text-secondary">A different authorised reviewer must approve this request.</p>}
                    </div>}
                    {payout.status === 'processing' && payout.approved_by === user?.id && canApprovePayout && <Button className="mt-3" disabled={busy} onClick={approvePayout}>Retry payout settlement</Button>}
                  </div>}
                </Card>;
              })()}
              <Card className="mt-4">
                <h3 className="font-semibold">Offline cash handover to bereaved household</h3>
                <p className="mt-1 text-sm text-text-secondary">Record an in-person cash handover against confirmed offline contributions. The recipient must be a verified representative of the bereaved household. A different reviewer must approve the receipt; this record does not post a wallet or ledger transfer.</p>
                {canRequestPayout && reportCollection?.status === 'closed' && report.offline_available_for_disbursement_minor > 0 && <form className="mt-3 grid gap-2 rounded-lg border border-border p-3 sm:grid-cols-2" onSubmit={(event) => requestOfflineDisbursement(event, report.collection_id)}>
                  <select className={selectClass} value={offlineCashRecipientActorId} onChange={(event) => setOfflineCashRecipientActorId(event.target.value)} required><option value="">Choose verified household representative</option>{reportCollection ? (workspace?.households.find((household) => household.id === reportCollection.target_household_id)?.representatives ?? []).filter((rep) => rep.status === 'verified').map((rep) => <option key={rep.actor_id} value={rep.actor_id}>{personLabel(rep)}</option>) : null}</select>
                  <input className={inputClass} type="number" min="0.01" step="0.01" max={(report.offline_available_for_disbursement_minor / 100).toFixed(2)} value={offlineCashAmount} onChange={(event) => setOfflineCashAmount(event.target.value)} placeholder={`Amount up to ${formatMoney(report.offline_available_for_disbursement_minor)}`} required />
                  <input className={inputClass} value={offlineCashReference} onChange={(event) => setOfflineCashReference(event.target.value)} placeholder="Handover receipt reference" minLength={3} maxLength={120} required />
                  <input className={inputClass} value={offlineCashEvidence} onChange={(event) => setOfflineCashEvidence(event.target.value)} placeholder="Handover evidence reference" minLength={3} maxLength={500} required />
                  <Button variant="secondary" disabled={busy || !offlineCashRecipientActorId || !offlineCashReference.trim() || !offlineCashEvidence.trim()}>Submit cash handover for review</Button>
                </form>}
                <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead><tr className="border-b text-text-secondary"><th className="py-2 pr-3">Date</th><th className="py-2 pr-3">Recipient</th><th className="py-2 pr-3">Amount</th><th className="py-2 pr-3">Reference / evidence</th><th className="py-2 pr-3">Status</th><th className="py-2">Review</th></tr></thead><tbody>{report.offline_disbursements.map((item) => { const household = workspace?.households.find((row) => row.id === reportCollection?.target_household_id); const recipient = household?.representatives.find((rep) => rep.actor_id === item.recipient_actor_id); return <tr key={item.id} className="border-b border-border"><td className="py-2 pr-3">{new Date(item.created_at).toLocaleString()}</td><td className="py-2 pr-3">{recipient ? personLabel(recipient) : 'Verified household representative'}</td><td className="py-2 pr-3">{formatMoney(item.amount_minor, item.currency_code)}</td><td className="py-2 pr-3"><div>{item.reference}</div><div className="text-xs text-text-secondary">{item.evidence_reference}</div></td><td className="py-2 pr-3 capitalize">{item.status.replaceAll('_', ' ')}{item.rejection_reason ? ` · ${item.rejection_reason}` : ''}</td><td className="py-2">{item.status === 'pending_review' && canApprovePayout && item.recorded_by !== user?.id && <div className="flex gap-2"><Button variant="secondary" disabled={busy} onClick={() => void mutate(`${API}/offline-disbursements/${item.id}/review`, 'post', { decision: 'confirmed' }, 'Cash handover independently confirmed.', () => { void loadReport(report.collection_id); })}>Confirm</Button><Button variant="secondary" disabled={busy} onClick={() => { const reason = window.prompt('Reason for rejecting this cash handover:')?.trim(); if (reason) void mutate(`${API}/offline-disbursements/${item.id}/review`, 'post', { decision: 'rejected', reason }, 'Cash handover rejected with reason.', () => { void loadReport(report.collection_id); }); }}>Reject</Button></div>}</td></tr>; })}</tbody></table>{report.offline_disbursements.length === 0 && <p className="py-4 text-sm text-text-secondary">No cash handovers have been recorded.</p>}</div>
                <p className="mt-2 text-sm text-text-secondary">Remaining confirmed offline contribution balance: <b>{formatMoney(report.offline_available_for_disbursement_minor)}</b></p>
              </Card>
              <Card className="mt-4">
                <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-semibold">Contribution transaction history</h3><Button variant="secondary" disabled={busy || !canReport} onClick={() => void exportTransactions(report.collection_id)}>Export CSV</Button></div>
                <p className="mt-1 text-sm text-text-secondary">Offline cash or other in-person contributions are recorded with a reference and require independent confirmation. They are not treated as ledger-settled payment funds.</p>
                {canManage && reportCollection?.status === 'active' && <form className="mt-3 grid gap-2 rounded-lg border border-border p-3 sm:grid-cols-2" onSubmit={(event) => recordOfflineContribution(event, report.collection_id)}>
                  <select className={selectClass} value={offlineHouseholdId} onChange={(event) => setOfflineHouseholdId(event.target.value)} required><option value="">Select unpaid household</option>{offlineOptions.map((option) => <option key={option.household_id} value={option.household_id}>{option.private_reference} · {formatMoney(option.amount_minor, option.currency_code)}</option>)}</select>
                  <input className={inputClass} value={offlineReference} onChange={(event) => setOfflineReference(event.target.value)} placeholder="Cash receipt / community reference" minLength={3} maxLength={120} required />
                  <input className={inputClass} value={offlineEvidence} onChange={(event) => setOfflineEvidence(event.target.value)} placeholder="Evidence reference (optional)" maxLength={500} />
                  <Button variant="secondary" disabled={busy || !canManage || !offlineHouseholdId || !offlineReference.trim()}>Record offline contribution for review</Button>
                </form>}
                <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[900px] text-left text-sm"><thead><tr className="border-b text-text-secondary"><th className="py-2 pr-3">Date</th><th className="py-2 pr-3">Channel</th><th className="py-2 pr-3">Reference / evidence</th><th className="py-2 pr-3">Amount</th><th className="py-2 pr-3">Fee</th><th className="py-2 pr-3">Status</th><th className="py-2">Review</th></tr></thead><tbody>{transactions.map((tx) => <tr key={tx.id ?? tx.payment_intent_id} className="border-b border-border"><td className="py-2 pr-3">{new Date(tx.occurred_at).toLocaleString()}</td><td className="py-2 pr-3">{tx.channel === 'offline' ? 'Offline' : 'OleKard app'}</td><td className="py-2 pr-3 font-mono text-xs">{tx.reference}{tx.evidence_reference && <div className="mt-1 font-sans text-text-secondary">Evidence: {tx.evidence_reference}</div>}</td><td className="py-2 pr-3">{formatMoney(tx.amount_minor, tx.currency_code)}</td><td className="py-2 pr-3">{formatMoney(tx.fee_minor, tx.currency_code)}</td><td className="py-2 pr-3 capitalize">{tx.status.replaceAll('_', ' ')}{tx.review_reason ? ` · ${tx.review_reason.replaceAll('_', ' ')}` : ''}</td><td className="py-2">{tx.channel === 'offline' && tx.status === 'pending_review' && canReview && tx.id && <div className="flex gap-2"><Button variant="secondary" disabled={busy} onClick={() => void mutate(`${API}/offline-contributions/${tx.id}/review`, 'post', { decision: 'confirmed' }, 'Offline contribution independently confirmed.', () => { void loadReport(report.collection_id); })}>Confirm</Button><Button variant="secondary" disabled={busy} onClick={() => { const reason = window.prompt('Reason for rejecting this offline contribution:')?.trim(); if (reason) void mutate(`${API}/offline-contributions/${tx.id}/review`, 'post', { decision: 'rejected', reason }, 'Offline contribution rejected with reason.', () => { void loadReport(report.collection_id); }); }}>Reject</Button></div>}</td></tr>)}</tbody></table>{transactions.length === 0 && <p className="py-4 text-sm text-text-secondary">No contribution records found.</p>}</div>
              </Card>
            </>}
            {workspace?.collections.filter((collection) => collection.status === 'closed').map((collection) => <Card key={`carry-${collection.id}`} className="mt-4">
              <h3 className="font-semibold">{collection.beneficiary_name}: unresolved liability handling</h3>
              <p className="mt-1 text-sm text-text-secondary">Carry-forward requires an evidence reference and approval by a different reviewer. {collection.carry_forward_reference ? `Current reference: ${collection.carry_forward_reference}` : 'No carry-forward requested.'}</p>
              {canManage && !collection.carry_forward_reference && <form className="mt-3 flex flex-col gap-2 sm:flex-row" onSubmit={(event) => { event.preventDefault(); void mutate(`${API}/collections/${collection.id}/carry-forward`, 'post', { carry_forward_reference: carryForwardReference.trim() }, 'Carry-forward request submitted for independent approval.', () => setCarryForwardReference('')); }}><input className={inputClass} value={carryForwardReference} onChange={(event) => setCarryForwardReference(event.target.value)} placeholder="Carry-forward evidence reference" required disabled={!readiness?.enabled} /><Button variant="secondary" disabled={busy || !readiness?.enabled || !carryForwardReference.trim()}>Request carry-forward</Button></form>}
              {canReview && collection.carry_forward_reference && collection.carry_forward_approved_by == null && collection.carry_forward_requested_by !== user?.id && <Button className="mt-3" variant="secondary" disabled={busy || !readiness?.enabled} onClick={() => void mutate(`${API}/collections/${collection.id}/carry-forward/approve`, 'post', {}, 'Carry-forward approved by an independent reviewer.')}>Approve carry-forward</Button>}
              {canManage && <Button className="mt-3" variant="secondary" disabled={busy || !readiness?.enabled || (collection.unresolved_liability_count ?? 1) > 0 && collection.carry_forward_approved_by == null} onClick={() => void mutate(`${API}/collections/${collection.id}/archive`, 'post', {}, 'Collection archived after liability review.')}>Archive collection</Button>}
            </Card>)}
          </>}
        </>
      )}
    </PageContainer>
  );
}
