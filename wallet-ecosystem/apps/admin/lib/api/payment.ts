import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { ApiResponse } from '@/lib/api/client';

// T061: Payment module TypeScript interfaces

export type ProviderEnvironment = 'sandbox' | 'production' | 'mock';
export type ReconciliationExceptionType =
  | 'missing_callback'
  | 'unknown_outcome'
  | 'amount_mismatch'
  | 'duplicate_transaction'
  | 'orphan_provider'
  | 'failed_refund'
  | 'unmatched_ledger';
export type ReconciliationExceptionStatus = 'open' | 'investigating' | 'escalated' | 'resolved';

export interface PaymentProvider {
  id: string;
  code: string;
  display_name: string;
  adapter_identifier: string;
  adapter_version: string | null;
  environment: ProviderEnvironment;
  is_enabled: boolean;
  is_suspended: boolean;
  is_default: boolean;
  sort_order: number;
  created_at: string;
  updated_at: string | null;
}

export interface PaymentProviderAccount {
  id: string;
  provider_id: string;
  merchant_account_reference: string | null;
  supported_currencies: string[];
  supported_payment_methods: string[];
  min_amount_minor: number;
  max_amount_minor: number;
  enabled_organisations: string[];
  enabled_modules: string[];
  country_restrictions: string[];
  callback_url: string | null;
  /** Reference path only — NEVER the raw secret value */
  callback_secret_reference: string | null;
  capability_flags: string[];
  fee_rule_ids: string[];
  daily_limit_minor: number | null;
  is_active: boolean;
}

export interface ReconciliationException {
  id: string;
  type: ReconciliationExceptionType;
  status: ReconciliationExceptionStatus;
  provider_code: string | null;
  amount_minor: number | null;
  notes: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface CreateProviderPayload {
  code: string;
  display_name: string;
  adapter_identifier: string;
  adapter_version?: string;
  environment: ProviderEnvironment;
  is_default?: boolean;
  sort_order?: number;
}

export interface UpdateProviderPayload {
  display_name?: string;
  is_enabled?: boolean;
  is_default?: boolean;
  sort_order?: number;
}

export interface UpdateExceptionPayload {
  status?: ReconciliationExceptionStatus;
  notes?: string;
}

export interface RotateCredentialPayload {
  new_secret_reference: string;
}

export interface HighRiskApprovalRequired {
  message: string;
  requires_approval: true;
  high_risk_fields: string[];
}

// P20 types
export type AccountLifecycleStatus =
  | 'draft'
  | 'pending_approval'
  | 'active'
  | 'suspended'
  | 'retired'
  | 'rejected';

export type LifecycleAction = 'activate' | 'suspend' | 'restore' | 'retire';

export type ConfigDraftStatus =
  | 'pending_approval'
  | 'approved'
  | 'rejected'
  | 'withdrawn';

export interface ConfigDraft {
  id: string;
  provider_id: string | null;
  change_type: 'high_risk_update' | 'credential_rotation' | 'module_config';
  change_payload: Record<string, unknown>;
  status: ConfigDraftStatus;
  drafted_by_actor_id: string;
  reviewed_by_actor_id: string | null;
  reviewed_at: string | null;
  review_notes: string | null;
  account_config_version_at_draft_time: number | null;
  created_at: string;
}

export interface ModuleConfig {
  id: string;
  module_key: string;
  display_name: string;
  is_enabled: boolean;
  display_order: number;
  description: string | null;
  updated_by_actor_id: string | null;
}

export interface UpdateAccountPayload {
  supported_currencies?: string[];
  supported_payment_methods?: string[];
  min_amount_minor?: number;
  max_amount_minor?: number;
  daily_limit_minor?: number | null;
  enabled_organisations?: string[];
  enabled_modules?: string[];
  enabled_purpose_types?: string[];
  country_restrictions?: string[];
  capability_flags?: string[];
  display_label?: string;
  display_description?: string | null;
  display_icon_key?: string | null;
  display_badge?: string | null;
  display_sort_order?: number;
  merchant_account_reference?: string;
  callback_secret_reference?: string;
  config_version: number;
}

export interface LifecycleTransitionPayload {
  action: LifecycleAction;
  reason: string;
}

export interface UpdateModuleConfigPayload {
  modules: Array<{
    module_key: string;
    is_enabled?: boolean;
    display_order?: number;
    description?: string | null;
  }>;
}

export interface HighRiskUpdateResponse {
  requires_approval: true;
  draft_id?: string;
  high_risk_fields: string[];
  message: string;
}

export interface PaymentProviderAccountDetail extends Omit<PaymentProviderAccount, 'callback_secret_reference'> {
  enabled_purpose_types: string[];
  account_lifecycle_status: AccountLifecycleStatus;
  config_version: number;
  display_label: string | null;
  display_description: string | null;
  display_icon_key: string | null;
  display_badge: string | null;
  display_sort_order: number;
  submitted_by_actor_id: string | null;
  approved_by_actor_id: string | null;
  approved_at: string | null;
  activated_at: string | null;
  suspended_at: string | null;
  retired_at: string | null;
  rejection_reason: string | null;
  /** Structured masked reference — the key name and its masked display value */
  callback_secret_reference: { key: string; masked: string } | null;
  created_at: string;
  updated_at: string | null;
}

// P21 types — Finance Dashboard & Operations Screens

export type IntentStatus = 'created' | 'captured' | 'fulfilled' | 'failed' | 'cancelled' | 'pending_ledger' | 'ledger_failed';
export type RefundStatus = 'pending_approval' | 'approved' | 'rejected' | 'processing' | 'completed' | 'failed';
export type PayoutRequestStatus = 'awaiting_approval' | 'reservation_pending' | 'approved' | 'rejected' | 'executing' | 'settled' | 'failed';
export type DisputeStatus = 'open' | 'on_hold' | 'under_review' | 'won' | 'lost' | 'closed_reversed';

export interface CurrencyVolume {
  currency_code: string;
  total_minor: number;
  count: number;
}

export interface DashboardSummary {
  volume_by_currency: CurrencyVolume[];
  attempt_counts_by_status: Record<string, number>;
  fulfilment_backlog_count: number;
  fetched_at: string;
}

export interface PaymentIntentSummary {
  id: string;
  status: IntentStatus;
  amount_minor: number;
  currency_code: string;
  provider_code: string | null;
  purpose_type: string;
  organisation_id: string;
  created_at: string;
  updated_at: string;
  has_pending_fulfilment: boolean;
}

export interface PaymentAttemptRow {
  id: string;
  attempt_number: number;
  status: string;
  provider_code: string;
  /** Masked to last-4 in display — never show raw value */
  provider_reference: string | null;
  submitted_at: string;
  resolved_at: string | null;
  failure_reason: string | null;
}

export interface LedgerLine {
  type: 'debit' | 'credit';
  account_name: string;
  amount_minor: number;
  currency_code: string;
}

export interface LedgerJournalRef {
  journal_id: string;
  posted_at: string;
  description: string;
  lines: LedgerLine[];
  audit_link: string | null;
}

export interface FulfilmentStatus {
  status: string;
  dispatched_at: string | null;
  completed_at: string | null;
}

export interface PaymentIntentTimeline {
  intent: PaymentIntentSummary;
  attempts: PaymentAttemptRow[];
  fulfilment: FulfilmentStatus | null;
  ledger_journals: LedgerJournalRef[];
  pending_accounting: boolean;
}

export interface RefundListItem {
  id: string;
  payment_intent_id: string;
  amount_minor: number;
  currency_code: string;
  status: RefundStatus;
  approval_required: boolean;
  requested_by: string;
  reason: string | null;
  rejection_reason: string | null;
  requested_at: string;
  approved_at: string | null;
  rejected_at: string | null;
}

export interface DisputeCaseSummary {
  id: string;
  case_type: string;
  status: DisputeStatus;
  payment_intent_id: string | null;
  payment_attempt_id: string | null;
  requires_finance_review: boolean;
  deadline_at: string | null;
  linked_support_case_id: string | null;
  created_at: string;
}

export interface PayoutRequestSummary {
  id: string;
  fund_reference_id: string;
  purpose_type: string;
  organisation_id: string;
  beneficiary_id: string;
  currency_code: string;
  computed_payable_minor: number;
  status: PayoutRequestStatus;
  created_by: string;
  created_at: string;
}

export interface ExportRequest {
  date_from: string;
  date_to: string;
  currency?: string;
  provider?: string;
  scope_type?: string;
  format?: 'csv';
}

export interface ExportStatus {
  export_id: string;
  status: 'pending' | 'processing' | 'downloadable' | 'error' | 'expired';
  row_count?: number;
  download_url?: string;
  expires_at?: string;
  created_at: string;
}

export interface RefundListParams {
  status?: RefundStatus;
  currency?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

export interface DisputeFilterParams {
  status?: DisputeStatus;
  case_type?: string;
  requires_finance_review?: boolean;
  deadline_before?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

export interface PayoutFilterParams {
  status?: PayoutRequestStatus;
  currency?: string;
  page?: number;
  per_page?: number;
}

export interface HistoryParams {
  status?: string;
  currency?: string;
  provider?: string;
  date_from?: string;
  date_to?: string;
  organisation_id?: string;
  has_pending_fulfilment?: boolean;
  page?: number;
  per_page?: number;
}

export interface HoldPayload {
  hold_reason: string;
  hold_notes?: string;
}

// T062: Payment API client
export class PaymentApiClient {
  constructor(private readonly client: AuthenticatedApiClient) {}

  listProviders(): Promise<ApiResponse<{ data: PaymentProvider[] }>> {
    return this.client.get('/api/v1/admin/payment/providers');
  }

  getProvider(id: string): Promise<ApiResponse<{ data: PaymentProvider }>> {
    return this.client.get(`/api/v1/admin/payment/providers/${id}`);
  }

  createProvider(
    payload: CreateProviderPayload,
  ): Promise<ApiResponse<{ data: PaymentProvider }>> {
    return this.client.post('/api/v1/admin/payment/providers', payload);
  }

  updateProvider(
    id: string,
    payload: UpdateProviderPayload,
  ): Promise<ApiResponse<{ data: PaymentProvider } | HighRiskApprovalRequired>> {
    return this.client.put(`/api/v1/admin/payment/providers/${id}`, payload);
  }

  suspendProvider(id: string): Promise<ApiResponse<{ data: PaymentProvider }>> {
    return this.client.post(`/api/v1/admin/payment/providers/${id}/suspend`, {});
  }

  rotateCredential(
    providerId: string,
    accountId: string,
    payload: RotateCredentialPayload,
  ): Promise<ApiResponse<{ message: string }>> {
    return this.client.post(
      `/api/v1/admin/payment/providers/${providerId}/accounts/${accountId}/rotate-credential`,
      payload,
    );
  }

  listReconciliationExceptions(
    params: { status?: ReconciliationExceptionStatus; type?: ReconciliationExceptionType; page?: number; per_page?: number } = {},
  ): Promise<ApiResponse<PaginatedResponse<ReconciliationException>>> {
    const qs = new URLSearchParams();
    if (params.status) qs.set('status', params.status);
    if (params.type) qs.set('type', params.type);
    if (params.page) qs.set('page', String(params.page));
    if (params.per_page) qs.set('per_page', String(params.per_page));
    return this.client.get(`/api/v1/admin/payment/reconciliation/exceptions?${qs}`);
  }

  updateExceptionStatus(
    id: string,
    payload: UpdateExceptionPayload,
  ): Promise<ApiResponse<{ data: ReconciliationException }>> {
    return this.client.patch(`/api/v1/admin/payment/reconciliation/exceptions/${id}`, payload);
  }

  // P20 account methods
  listProviderAccounts(providerId: string): Promise<ApiResponse<{ data: PaymentProviderAccountDetail[] }>> {
    return this.client.get(`/api/v1/admin/payment/providers/${providerId}/accounts`);
  }

  getProviderAccount(providerId: string, accountId: string): Promise<ApiResponse<{ data: PaymentProviderAccountDetail }>> {
    return this.client.get(`/api/v1/admin/payment/providers/${providerId}/accounts/${accountId}`);
  }

  updateProviderAccount(
    providerId: string,
    accountId: string,
    payload: UpdateAccountPayload,
  ): Promise<ApiResponse<{ data: PaymentProviderAccountDetail } | HighRiskUpdateResponse>> {
    return this.client.patch(`/api/v1/admin/payment/providers/${providerId}/accounts/${accountId}`, payload);
  }

  transitionAccountLifecycle(
    providerId: string,
    accountId: string,
    payload: LifecycleTransitionPayload,
  ): Promise<ApiResponse<{ data: PaymentProviderAccountDetail }>> {
    return this.client.post(`/api/v1/admin/payment/providers/${providerId}/accounts/${accountId}/lifecycle`, payload);
  }

  listConfigDrafts(
    providerId: string,
    params: { status?: ConfigDraftStatus; page?: number } = {},
  ): Promise<ApiResponse<PaginatedResponse<ConfigDraft>>> {
    const qs = new URLSearchParams();
    if (params.status) qs.set('status', params.status);
    if (params.page) qs.set('page', String(params.page));
    return this.client.get(`/api/v1/admin/payment/providers/${providerId}/config-changes?${qs}`);
  }

  getConfigDraft(providerId: string, draftId: string): Promise<ApiResponse<{ data: ConfigDraft }>> {
    return this.client.get(`/api/v1/admin/payment/providers/${providerId}/config-changes/${draftId}`);
  }

  getModuleConfigs(): Promise<ApiResponse<{ data: ModuleConfig[] }>> {
    return this.client.get('/api/v1/admin/payment/config/modules');
  }

  updateModuleConfigs(
    payload: UpdateModuleConfigPayload,
  ): Promise<ApiResponse<HighRiskUpdateResponse>> {
    return this.client.patch('/api/v1/admin/payment/config/modules', payload);
  }

  // P21 Finance Dashboard methods

  getDashboardSummary(): Promise<ApiResponse<DashboardSummary>> {
    return this.client.get('/api/v1/admin/payment/dashboard-summary');
  }

  getIntentTimeline(id: string): Promise<ApiResponse<PaymentIntentTimeline>> {
    return this.client.get(`/api/v1/admin/payment/intents/${id}/timeline`);
  }

  queryIntentStatus(id: string): Promise<ApiResponse<{ message: string }>> {
    return this.client.post(`/api/v1/admin/payment/intents/${id}/query-status`, {});
  }

  getPaymentHistory(params: HistoryParams = {}): Promise<ApiResponse<PaginatedResponse<PaymentIntentSummary>>> {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    });
    const query = qs.toString();
    return this.client.get(`/api/v1/admin/payment/history${query ? '?' + query : ''}`);
  }

  listRefundManagement(params: RefundListParams = {}): Promise<ApiResponse<PaginatedResponse<RefundListItem>>> {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    });
    const query = qs.toString();
    return this.client.get(`/api/v1/admin/payment/refund-management${query ? '?' + query : ''}`);
  }

  approveRefund(id: string, idempotencyKey: string): Promise<ApiResponse<{ message: string }>> {
    return this.client.post(`/api/v1/admin/payment/refund-management/${id}/approve`, {}, { idempotencyKey });
  }

  rejectRefund(id: string, reason: string, idempotencyKey: string): Promise<ApiResponse<{ message: string }>> {
    return this.client.post(`/api/v1/admin/payment/refund-management/${id}/reject`, { reason }, { idempotencyKey });
  }

  queryRefundStatus(id: string): Promise<ApiResponse<{ message: string }>> {
    return this.client.post(`/api/v1/admin/payment/refund-management/${id}/query-status`, {});
  }

  listDisputeCases(params: DisputeFilterParams = {}): Promise<ApiResponse<PaginatedResponse<DisputeCaseSummary>>> {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    });
    const query = qs.toString();
    return this.client.get(`/api/v1/admin/payment/disputes${query ? '?' + query : ''}`);
  }

  getDisputeCase(id: string): Promise<ApiResponse<DisputeCaseSummary>> {
    return this.client.get(`/api/v1/admin/payment/disputes/${id}`);
  }

  authoriseDisputeHold(id: string, payload: HoldPayload, idempotencyKey: string): Promise<ApiResponse<{ message: string }>> {
    return this.client.post(`/api/v1/admin/payment/disputes/${id}/hold`, payload, { idempotencyKey });
  }

  listPayoutRequests(params: PayoutFilterParams = {}): Promise<ApiResponse<PaginatedResponse<PayoutRequestSummary>>> {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    });
    const query = qs.toString();
    return this.client.get(`/api/v1/admin/payment/payout/requests${query ? '?' + query : ''}`);
  }

  getPayoutRequest(id: string): Promise<ApiResponse<PayoutRequestSummary>> {
    return this.client.get(`/api/v1/admin/payment/payout/requests/${id}`);
  }

  approvePayoutReservation(id: string, idempotencyKey: string): Promise<ApiResponse<{ message: string }>> {
    return this.client.post(`/api/v1/admin/payment/payout/requests/${id}/approve-reservation`, {}, { idempotencyKey });
  }

  rejectPayoutReservation(id: string, payload: { reason: string }): Promise<ApiResponse<{ message: string }>> {
    return this.client.post(`/api/v1/admin/payment/payout/requests/${id}/reject-reservation`, payload);
  }

  requestExport(payload: ExportRequest): Promise<ApiResponse<{ export_id: string; status: string }>> {
    return this.client.post('/api/v1/admin/payment/exports', payload);
  }

  getExportStatus(exportId: string): Promise<ApiResponse<ExportStatus>> {
    return this.client.get(`/api/v1/admin/payment/exports/${exportId}`);
  }
}
