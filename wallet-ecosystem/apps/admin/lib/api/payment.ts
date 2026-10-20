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
}
